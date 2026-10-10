<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Transfer {

	// a page of dumped rows never asks for more than this many serialized bytes, on top of the row 'limit':
	// a local MariaDB with a 16M max_allowed_packet still choked on a 5000-row page of wp_posts
	const DUMP_BYTE_BUDGET = 4194304; // ~4 MB
	const HASH_CHUNK = 250; // rows hash_rows() holds in memory at once

	private static $max_packet = null;

	/** MySQL's own limit on one statement/packet here. Cached per request; 1 MiB (MySQL's historic default) if the query fails. */
	public static function max_allowed_packet() {
		if ( self::$max_packet === null ) {
			global $wpdb;
			$v = $wpdb ? (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' ) : 0;
			self::$max_packet = $v > 0 ? $v : 1048576;
		}
		return self::$max_packet;
	}
	/** Tests only: the cached max_allowed_packet survives across tests otherwise. */
	public static function forget_max_allowed_packet() { self::$max_packet = null; }

	/** 75% of max_allowed_packet: room for the query text and connector overhead around the raw cell bytes. */
	public static function packet_budget() { return (int) floor( self::max_allowed_packet() * 0.75 ); }

	/** Rough size of one row once it becomes SQL cells (quotes, comma, NULL) -- close enough to size a statement by. */
	public static function row_bytes( array $row ) {
		$n = 2; // surrounding parens
		foreach ( $row as $v ) $n += ( $v === null ? 4 : strlen( (string) $v ) + 2 ) + 1; // 'value' + separator
		return $n;
	}

	/**
	 * Splits $items into groups whose size stays at or under $budget, so one INSERT/REPLACE (or one HTTP step)
	 * built from a group never asks for more than $budget allows. A single item over budget goes out alone:
	 * there is no smaller unit to fall back to. $sizer( $item ): byte size of one item; default row_bytes()
	 * (a rough estimate for a row array). Pass 'strlen' when $items are already the literal strings that will
	 * make up the statement -- that is exact, where row_bytes() on the source row would undercount whatever
	 * escaping or rewriting happens between the row and the string.
	 * @return array[] item groups, order preserved
	 */
	public static function row_batches( array $items, $budget, ?callable $sizer = null ) {
		$sizer = $sizer ?: [ __CLASS__, 'row_bytes' ];
		$budget = max( 1, (int) $budget );
		$out = []; $batch = []; $size = 0;
		foreach ( $items as $item ) {
			$n = $sizer( $item );
			if ( $batch && $size + $n > $budget ) { $out[] = $batch; $batch = []; $size = 0; }
			$batch[] = $item; $size += $n;
		}
		if ( $batch ) $out[] = $batch;
		return $out;
	}

	/**
	 * Trims $rows to a byte budget: once the next row would cross it, the page stops there. Never empties a
	 * non-empty page -- one row over budget still goes out alone.
	 * @return array{rows:array,cut:bool} cut: true when the budget, not $rows itself, ended the page
	 */
	public static function budget_page( array $rows, $byte_budget ) {
		if ( $byte_budget <= 0 ) return [ 'rows' => $rows, 'cut' => false ];
		$size = 0; $out = [];
		foreach ( $rows as $row ) {
			$n = self::row_bytes( $row );
			if ( $out && $size + $n > $byte_budget ) return [ 'rows' => $out, 'cut' => true ];
			$out[] = $row; $size += $n;
		}
		return [ 'rows' => $out, 'cut' => false ];
	}

	public static function info() {
		global $wpdb;
		$tables = [];
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $t ) {
			if ( strpos( $t, $wpdb->prefix . 'ixes_' ) === 0 ) continue;
			// columns too, so a pull can tell a plugin added one here without a second request per table
			$tables[] = [ 'name' => $t, 'pk' => self::pk_of( $t ), 'rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ), 'columns' => self::local_columns( $t ) ];
		}
		return [
			'wp_version'      => get_bloginfo( 'version' ),
			'prefix'          => $wpdb->prefix,
			'url'             => IXES_Env::local_url(),
			'abspath'         => IXES_Env::local_abspath(),
			'algos'           => hash_algos(),
			'tables'          => $tables,
			'php'             => [ 'time_limit' => (int) ini_get( 'max_execution_time' ), 'memory' => ini_get( 'memory_limit' ), 'version' => PHP_VERSION ],
			'plugin'          => IXES_VERSION,
			'caps'            => array_merge( [ 'binary', 'scope', 'batch', 'create_table', 'rescue', 'prefix_map', 'delete_set', 'hash_batch', 'drop_table', 'schema', 'file_batch', 'exclude_options', 'nonce', IXES_Hasher::CAP ], function_exists( 'gzinflate' ) ? [ 'packed' ] : [], IXES_Selfupdate::caps() ),
			'self_dir'        => basename( dirname( IXES_FILE ) ), // a self-update zip's top folder must be this
			'active_plugins'  => (array) get_option( 'active_plugins', [] ),
			'lock'            => IXES_Applier::lock_info(),
			'auth_via'        => IXES_Rest::auth_via(),
			'inventory'       => self::inventory(),
			'rescue_url'      => plugins_url( 'rescue.php', IXES_FILE ),
		];
	}

	/** Installed plugin and theme versions keyed by slug (plugin folder, or file name for single-file plugins). */
	public static function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = [];
		foreach ( get_plugins() as $file => $data ) $plugins[ IXES_Report::plugin_slug( $file ) ] = (string) $data['Version'];
		$themes = [];
		foreach ( wp_get_themes() as $slug => $theme ) $themes[ $slug ] = (string) $theme->get( 'Version' );
		return [ 'plugins' => $plugins, 'themes' => $themes, 'stylesheet' => get_stylesheet(), 'orphans' => self::orphan_plugin_dirs( WP_PLUGIN_DIR, $plugins ) ];
	}

	/** Plugin folders get_plugins() skipped because no file in them has a readable plugin header (a half-deleted plugin). */
	public static function orphan_plugin_dirs( $dir, array $plugins ) {
		$out = [];
		foreach ( glob( rtrim( $dir, '/' ) . '/*', GLOB_ONLYDIR ) ?: [] as $d ) {
			$slug = basename( $d );
			if ( ! isset( $plugins[ $slug ] ) ) $out[] = $slug;
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	public static function pk_of( $table ) {
		global $wpdb;
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( count( $keys ) !== 1 ) return null;
		return $keys[0]['Column_name'];
	}

	public static function valid_table( $name ) {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $t ) {
			if ( strpos( $t, $wpdb->prefix . 'ixes_' ) === 0 ) continue;
			if ( $t === $name ) return true;
		}
		return false;
	}

	/** $byte_budget (0 = off): a page also stops once its rows' estimated size crosses it, whichever comes first; an
	 *  old remote that does not read this parameter simply keeps paging by row count alone (still safe: the hub's
	 *  own import_rows() splits its INSERT/REPLACE statements regardless of how big a page it was handed).
	 *  $bytes: the hub asked for byte cells as wrappers (IXES_Hasher::CAP); an older hub never does, and gets rows as before. */
	public static function dump( $table, $from_pk, $limit, $byte_budget = 0, $bytes = false ) {
		global $wpdb;
		if ( ! self::valid_table( $table ) ) return new WP_Error( 'bad_table', 'unknown table', [ 'status' => 400 ] );
		$pk = self::pk_of( $table );
		if ( $pk ) {
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE `{$pk}` > " . self::key_literal( $from_pk === null ? '' : $from_pk ) . " ORDER BY `{$pk}` LIMIT " . (int) $limit, ARRAY_A );
			$more = count( $rows ) === $limit;
			$page = self::budget_page( $rows, (int) $byte_budget );
			$rows = $page['rows']; $more = $more || $page['cut'];
			$next = ( $more && $rows ) ? end( $rows )[ $pk ] : null;
		} else {
			$off  = (int) $from_pk;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $limit, $off ), ARRAY_A );
			$more = count( $rows ) === $limit;
			$page = self::budget_page( $rows, (int) $byte_budget );
			$rows = $page['rows']; $more = $more || $page['cut'];
			$next = $more ? $off + count( $rows ) : null;
		}
		// never transfer environment-local options (siteurl/home/cron/transients/ixes_*)
		if ( $table === $wpdb->options ) {
			$rows = array_values( array_filter( $rows, function ( $r ) { return ! IXES_Env::option_excluded( $r['option_name'] ); } ) );
		}
		// serving a hub with another prefix: rows leave in its names, before hash_rows() hashes them
		$map = IXES_Prefix::current();
		if ( $map ) {
			$bare = $map->bare( $table ); $out = [];
			foreach ( $rows as $r ) { $t = $map->row_out( $bare, $r ); if ( $t !== null ) $out[] = $t; }
			$rows = $out;
		}
		if ( $bytes ) $rows = array_map( [ 'IXES_Hasher', 'cells_out' ], $rows );
		return [ 'rows' => $rows, 'next' => $next ];
	}

	/**
	 * A key cursor as a SQL literal: a binary key as hex, so no invalid UTF-8 ever sits inside the query text
	 * (wpdb may strip or refuse it on a table whose collation it does not trust); anything else quoted as before.
	 */
	public static function key_literal( $v ) {
		global $wpdb;
		if ( IXES_Hasher::is_bytes( $v ) ) return '0x' . bin2hex( $v );
		return $wpdb->prepare( '%s', (string) $v );
	}

	/** Columns of a CREATE TABLE whose type holds raw bytes: binary, varbinary, the blobs, bit. */
	public static function byte_columns( $sql ) {
		$out = [];
		foreach ( self::column_defs_from_create( $sql ) as $col => $def ) {
			if ( preg_match( '/^`[^`]+`\s+(?:(?:tiny|medium|long)?blob|(?:var)?binary|bit)\b/i', $def ) ) $out[] = $col;
		}
		return $out;
	}

	/**
	 * Against a remote without IXES_Hasher::CAP, rows still travel as before: say which tables have byte columns, whose
	 * values may arrive with '?' where the bytes were. $create: table => CREATE TABLE text for tables not here yet.
	 * @return string[]
	 */
	public static function byte_warnings( array $caps, array $tables, $env, array $create = [] ) {
		if ( in_array( IXES_Hasher::CAP, $caps, true ) ) return [];
		$warn = [];
		foreach ( $tables as $n ) {
			$sql  = $create[ $n ] ?? ( self::valid_table( $n ) ? self::create_table_sql( $n ) : null );
			$cols = $sql ? self::byte_columns( $sql ) : [];
			if ( $cols ) $warn[] = "{$n}: binary column(s) " . implode( ', ', $cols ) . " may not travel intact: {$env} runs a plugin older than 0.9.3, and bytes that are not valid UTF-8 arrive as '?' (distinct keys can collide). Upload 0.9.3 or newer there.";
		}
		return $warn;
	}

	/** One cell as a SQL literal. Bytes that are not valid UTF-8 go as a hex literal: quoted, a utf8mb4 connection may reject or mangle them. */
	public static function sql_cell( $v ) {
		if ( $v === null ) return 'NULL';
		if ( IXES_Hasher::is_bytes( $v ) ) return '0x' . bin2hex( $v );
		return "'" . esc_sql( (string) $v ) . "'";
	}

	/** INSERT/REPLACE of one row through sql_cell(), for a row with byte cells ($wpdb->insert() quotes them as text). Only real columns. */
	public static function write_row( $table, array $row, $verb = 'INSERT' ) {
		global $wpdb;
		if ( ! $row || array_diff( array_keys( $row ), self::local_columns( $table ) ) ) return false;
		return $wpdb->query( ( $verb === 'REPLACE' ? 'REPLACE' : 'INSERT' ) . " INTO `{$table}` (`" . implode( '`,`', array_keys( $row ) ) . '`) VALUES (' . implode( ',', array_map( [ __CLASS__, 'sql_cell' ], $row ) ) . ')' );
	}

	/** $wpdb->delete() for a row with byte cells. */
	public static function delete_row( $table, array $row ) {
		global $wpdb;
		if ( ! $row || array_diff( array_keys( $row ), self::local_columns( $table ) ) ) return false;
		$where = [];
		foreach ( $row as $col => $v ) $where[] = $v === null ? "`{$col}` IS NULL" : "`{$col}` = " . self::sql_cell( $v );
		return $wpdb->query( "DELETE FROM `{$table}` WHERE " . implode( ' AND ', $where ) );
	}

	/** A row with at least one byte cell: written through write_row(), not $wpdb. */
	public static function has_bytes( array $row ) {
		foreach ( $row as $v ) if ( IXES_Hasher::is_bytes( $v ) ) return true;
		return false;
	}

	/**
	 * Digests of up to $limit rows after $from_pk. Rows are read HASH_CHUNK at a time and dropped once hashed, so a
	 * page of 5000 wp_posts rows full of revisions never sits in memory at once: that exhausted memory_limit on hosts
	 * with a large posts table and answered /hash/tables with a 500.
	 */
	public static function hash_rows( $table, $from_pk, $limit, array $pairs, $algo, $bytes = false ) {
		global $wpdb;
		if ( ! self::valid_table( $table ) ) return new WP_Error( 'bad_table', 'unknown table', [ 'status' => 400 ] );
		$pk = self::pk_of( $table );
		$out = []; $byte_keys = false;
		$is_options = ( $table === $wpdb->options );
		$limit = max( 1, (int) $limit ); $done = 0; $next = $from_pk;
		do {
			$n = min( self::HASH_CHUNK, $limit - $done );
			$d = self::dump( $table, $next, $n );
			if ( is_wp_error( $d ) ) return $d;
			foreach ( $d['rows'] as $r ) {
				if ( $is_options && IXES_Env::option_excluded( $r['option_name'] ) ) continue;
				$h = IXES_Hasher::hash_row( $r, $pairs, $algo, $bytes );
				if ( $pk ) $out[ $r[ $pk ] ] = $h; else $out[] = $h;
				if ( $pk && IXES_Hasher::is_bytes( $r[ $pk ] ) ) $byte_keys = true;
			}
			$next = $d['next']; $done += $n;
			unset( $d );
		} while ( $next !== null && $done < $limit );
		// a key JSON cannot carry: the hub must not compare this table by key (see IXES_Planner::build())
		return [ 'rows' => $out, 'next' => $next ] + ( $byte_keys ? [ 'byte_keys' => true ] : [] );
	}

	/** This plugin's own directory, relative to wp-content, with a trailing slash. */
	public static function own_dir() {
		if ( ! defined( 'IXES_FILE' ) ) return '';
		return self::rel_dir( WP_CONTENT_DIR, dirname( IXES_FILE ) );
	}

	/** $dir relative to $root with a trailing slash, '' when it lies outside. */
	public static function rel_dir( $root, $dir ) {
		// `wp --path=.` makes WP_CONTENT_DIR "/site/./wp-content" while __FILE__ is resolved: without
		// normalising, the plugin stopped excluding itself and a pull deleted its own files
		$norm = function ( $p ) {
			$p = str_replace( '\\', '/', $p );
			$r = @realpath( $p );
			if ( $r !== false ) $p = str_replace( '\\', '/', $r );
			return rtrim( preg_replace( '#/(?:\./)+#', '/', $p . '/' ), '/' );
		};
		$root = $norm( $root );
		$dir  = $norm( $dir );
		if ( strpos( $dir, $root . '/' ) !== 0 ) return '';
		return substr( $dir, strlen( $root ) + 1 ) . '/';
	}

	public static function excluded_path( $rel, array $excludes ) {
		// our own storage dir, both the legacy name and the randomised one, on either side
		if ( strpos( $rel, 'envsync/' ) === 0 || strpos( $rel, 'envsync-' ) === 0 ) return true;
		// and our own code: a sync must never overwrite the plugin running it, in either
		// direction. Deploy plugin updates as a zip. See self::own_dir().
		$own = self::own_dir();
		if ( $own !== '' && strpos( $rel, $own ) === 0 ) return true;
		foreach ( $excludes as $ex ) {
			$ex = ltrim( $ex, '/' );
			if ( substr( $ex, -1 ) === '/' ) {
				// folders are anchored at wp-content: 'cache/' must not swallow plugins/polylang/src/integrations/cache/
				if ( strpos( $rel, $ex ) === 0 ) return true;
				if ( in_array( $ex, self::ANY_DEPTH, true ) && strpos( $rel, '/' . $ex ) !== false ) return true;
			}
			elseif ( $rel === $ex || basename( $rel ) === $ex ) return true;
		}
		return false;
	}

	/** Dev artifacts that are never deployable wherever they sit (a theme's node_modules, a plugin's .git). */
	const ANY_DEPTH = [ '.git/', 'node_modules/' ];

	/** Files under wp-content, sorted. $roots (wp-content folders with a trailing slash) limits the walk; [] walks everything. */
	public static function all_files( array $excludes, array $roots = [] ) {
		$root = untrailingslashit( WP_CONTENT_DIR );
		$out = [];
		foreach ( $roots ? $roots : [ '' ] as $base ) {
			if ( $base !== '' ) {
				$base = self::safe_rel( $base );
				if ( $base === null ) continue;
				$base = untrailingslashit( $base ) . '/';
				if ( ! is_dir( $root . '/' . $base ) || self::excluded_path( $base, $excludes ) || self::through_link( $root, $base ) ) continue;
			}
			$it = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root . ( $base === '' ? '' : '/' . untrailingslashit( $base ) ), FilesystemIterator::SKIP_DOTS ),
				function ( $f ) use ( $root, $excludes ) {
					$rel = self::rel_path( $root, $f->getPathname() );
					if ( $f->isDir() ) $rel .= '/';
					return ! self::excluded_path( $rel, $excludes );
				}
			) );
			foreach ( $it as $f ) {
				if ( $f->isFile() ) $out[] = self::rel_path( $root, $f->getPathname() );
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * $path relative to $root, with forward slashes. On Windows getPathname() returns backslashes;
	 * left as is, every file below wp-content's top level missed its remote twin and a mirrored push
	 * deleted it. The swap keeps the length, so $root's offset holds even when it mixes separators.
	 */
	public static function rel_path( $root, $path ) {
		return ltrim( substr( str_replace( '\\', '/', $path ), strlen( $root ) ), '/' );
	}

	/** Whether $rel (under $root) passes through a symlinked folder, which a walk of all of wp-content never enters. */
	private static function through_link( $root, $rel ) {
		$p = $root;
		foreach ( explode( '/', untrailingslashit( $rel ) ) as $part ) {
			$p .= '/' . $part;
			if ( is_link( $p ) ) return true;
		}
		return false;
	}

	/**
	 * Absolute path of $rel when reading it stays inside wp-content, else null. A symlinked folder on the way is
	 * refused (the walk never enters one, so no listed path goes through it), and so is a symlinked file that
	 * resolves outside wp-content: a signed request names any path, not only the ones the manifest listed.
	 * The walk still lists such a file link: left out, the differ would take it for deleted and remove it on
	 * the other side. A pull skips it as bad_path instead.
	 */
	public static function served_path( $rel ) {
		$root = untrailingslashit( WP_CONTENT_DIR );
		$dir  = dirname( $rel );
		if ( $dir !== '.' && self::through_link( $root, $dir ) ) return null;
		$p = $root . '/' . $rel;
		if ( ! is_link( $p ) ) return $p;
		$real = realpath( $p ); $base = realpath( $root );
		if ( $real === false || $base === false ) return null;
		$real = str_replace( '\\', '/', $real ); $base = rtrim( str_replace( '\\', '/', $base ), '/' );
		return strpos( $real, $base . '/' ) === 0 ? $p : null;
	}

	public static function file_manifest( $cursor, $limit, array $excludes, $algo, $with_sizes = false, array $roots = [] ) {
		$all = self::all_files( $excludes, $roots );
		$start = 0;
		if ( $cursor !== null && $cursor !== '' ) {
			$i = array_search( $cursor, $all, true );
			if ( $i !== false ) {
				$start = $i + 1;
			} else {
				// cursor no longer present (file added/removed mid-manifest): resume at first path sorted after it
				$start = count( $all );
				foreach ( $all as $idx => $rel ) {
					if ( strcmp( $rel, $cursor ) > 0 ) { $start = $idx; break; }
				}
				if ( $start >= count( $all ) ) return [ 'files' => [], 'next' => null ];
			}
		}
		$slice = array_slice( $all, $start, $limit );
		$files = []; $sizes = [];
		foreach ( $slice as $rel ) {
			$p = WP_CONTENT_DIR . '/' . $rel;
			if ( ! is_readable( $p ) ) continue;
			$h = IXES_Hashcache::hash( $p, $rel, $algo );
			if ( $h !== false ) { $files[ $rel ] = $h; if ( $with_sizes ) $sizes[ $rel ] = (int) filesize( $p ); }
		}
		IXES_Hashcache::save();
		$next = ( $start + $limit < count( $all ) ) ? end( $slice ) : null;
		return $with_sizes ? [ 'files' => $files, 'sizes' => $sizes, 'next' => $next ] : [ 'files' => $files, 'next' => $next ];
	}

	public static function safe_rel( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );
		if ( $rel === '' || $rel[0] === '/' || strpos( $rel, '..' ) !== false ) return null;
		return $rel;
	}

	public static function file_chunk( $rel, $offset, $size, $as_binary = false ) {
		$rel = self::safe_rel( $rel );
		if ( ! $rel || self::excluded_path( $rel, IXES_Env::default_excludes() ) ) return new WP_Error( 'bad_path', 'path refused', [ 'status' => 400 ] );
		$p = self::served_path( $rel );
		if ( $p === null ) return new WP_Error( 'bad_path', 'path refused', [ 'status' => 400 ] );
		if ( ! is_file( $p ) ) return new WP_Error( 'not_found', 'no such file', [ 'status' => 404 ] );
		$fh = fopen( $p, 'rb' ); fseek( $fh, $offset ); $data = fread( $fh, $size ); fclose( $fh );
		if ( $data === false ) $data = '';
		// cached: this used to rehash the whole file on every 2 MB chunk (O(n^2) on big media)
		$sha = IXES_Hashcache::hash( $p, $rel, 'sha256' );
		IXES_Hashcache::save();
		if ( $sha === false ) return new WP_Error( 'io', 'cannot hash file', [ 'status' => 500 ] );
		if ( $as_binary ) return [ 'bin' => $data, 'size' => strlen( $data ), 'total' => filesize( $p ), 'sha256' => $sha ];
		return [ 'data' => base64_encode( $data ), 'size' => strlen( $data ), 'total' => filesize( $p ), 'sha256' => $sha ];
	}

	/**
	 * Many small files for one /file/batch answer, in IXES_Batch's format. Each item carries the sha256 of the
	 * bytes sent; a path refused here, gone, or grown past $max since the plan carries 'err' and no bytes.
	 */
	public static function file_batch( array $paths, $max = 2 * IXES_Batch::MAX_BYTES ) {
		$items = []; $bytes = 0;
		foreach ( $paths as $rel ) {
			$rel  = (string) $rel;
			$safe = self::safe_rel( $rel );
			if ( ! $safe || self::excluded_path( $safe, IXES_Env::default_excludes() ) ) { $items[] = [ [ 'path' => $rel, 'err' => 'bad_path' ], '' ]; continue; }
			$p = self::served_path( $safe );
			if ( $p === null ) { $items[] = [ [ 'path' => $rel, 'err' => 'bad_path' ], '' ]; continue; }
			if ( ! is_file( $p ) || ! is_readable( $p ) ) { $items[] = [ [ 'path' => $rel, 'err' => 'not_found' ], '' ]; continue; }
			// grown since the plan: the hub fetches it alone, in chunks
			if ( $bytes + (int) filesize( $p ) > $max ) { $items[] = [ [ 'path' => $rel, 'err' => 'later' ], '' ]; continue; }
			$data = @file_get_contents( $p );
			if ( $data === false ) { $items[] = [ [ 'path' => $rel, 'err' => 'io' ], '' ]; continue; }
			$bytes += strlen( $data );
			$items[] = [ [ 'path' => $rel, 'sha256' => hash( 'sha256', $data ) ], $data ];
		}
		return IXES_Batch::encode( $items );
	}

	// ---------- hub side ----------

	public static function tmp_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . 'ixes_tmp_' . substr( $table, strlen( $wpdb->prefix ) );
	}

	public static function tmp_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::tmp_name( $table ) ) );
	}

	private static $local_columns = [];

	public static function local_columns( $table ) {
		global $wpdb;
		if ( ! isset( self::$local_columns[ $table ] ) ) {
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
			self::$local_columns[ $table ] = is_array( $cols ) ? $cols : [];
		}
		return self::$local_columns[ $table ];
	}

	// validate a primary key name against the real columns; sanitize_key() would lowercase `ID`
	public static function safe_pk( $table, $pk ) {
		return in_array( (string) $pk, self::local_columns( $table ), true ) ? (string) $pk : null;
	}

	public static function create_table_sql( $table ) {
		global $wpdb;
		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		return $row ? (string) $row[1] : null;
	}

	/**
	 * Column name => its own definition line (no trailing comma), read off a `SHOW CREATE TABLE`. MySQL puts one
	 * column or key per line; a line is a column only when it opens with a backtick name, which PRIMARY KEY/KEY/
	 * CONSTRAINT lines never do.
	 */
	public static function column_defs_from_create( $sql ) {
		$out = [];
		foreach ( preg_split( '/\r?\n/', (string) $sql ) as $line ) {
			$line = rtrim( trim( $line ), ',' );
			if ( preg_match( '/^`([A-Za-z0-9_]+)`\s+\S/', $line, $m ) ) $out[ $m[1] ] = $line;
		}
		return $out;
	}

	/** A pull found a table the remote has that this side lacks (a plugin's own table); create it from the remote's own CREATE TABLE. */
	public static function create_missing_table( $table, $sql ) {
		global $wpdb;
		$why = IXES_Applier::create_table_refusal( $table, $sql, $wpdb->prefix, self::valid_table( $table ) );
		if ( $why ) return new WP_Error( 'bad_create', $why );
		if ( $wpdb->query( $sql ) === false ) return new WP_Error( 'create_failed', "cannot create {$table}: {$wpdb->last_error}" );
		return true;
	}

	/** A pull found a column the remote has that this side's copy of an existing table lacks (a plugin added one there). */
	public static function add_missing_column( $table, $column, $def ) {
		global $wpdb;
		$why = IXES_Applier::add_column_refusal( $table, $column, $def, $wpdb->prefix, in_array( $column, self::local_columns( $table ), true ) );
		if ( $why ) return new WP_Error( 'bad_column', $why );
		if ( $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$def}" ) === false ) return new WP_Error( 'alter_failed', "table {$table}: cannot add column {$column}: {$wpdb->last_error}" );
		unset( self::$local_columns[ $table ] );
		return true;
	}

	/**
	 * On resume, a fresh table skips import_begin() and keeps its tmp table from the earlier attempt, so a schema
	 * fix made to the real table in between (by hand, or by add_missing_column() on a later pull) never reaches it.
	 * Bring the tmp table's columns up to the real one's before rows resume.
	 */
	public static function reconcile_tmp( $table ) {
		global $wpdb;
		$tmp = self::tmp_name( $table );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tmp ) ) ) return true; // nothing was left to resume into
		$real = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
		$have = $wpdb->get_col( "SHOW COLUMNS FROM `{$tmp}`" );
		$missing = array_values( array_diff( $real, $have ) );
		if ( ! $missing ) return true;
		$defs = self::column_defs_from_create( (string) self::create_table_sql( $table ) );
		foreach ( $missing as $col ) {
			if ( ! isset( $defs[ $col ] ) ) continue; // SHOW COLUMNS and SHOW CREATE TABLE always agree; stay defensive anyway
			if ( $wpdb->query( "ALTER TABLE `{$tmp}` ADD COLUMN {$defs[ $col ]}" ) === false ) return new WP_Error( 'reconcile_failed', "table {$table}: cannot bring the resumed copy's schema up to date: {$wpdb->last_error}" );
		}
		unset( self::$local_columns[ $table ] );
		return true;
	}

	public static function import_begin( $table ) {
		global $wpdb;
		if ( ! self::valid_table( $table ) ) return new WP_Error( 'bad_table', 'unknown table', [ 'status' => 400 ] );
		$tmp = self::tmp_name( $table );
		$wpdb->query( "DROP TABLE IF EXISTS `{$tmp}`" );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) return new WP_Error( 'no_table', "local table {$table} missing; schema must match (v0.1)" );
		$wpdb->query( "CREATE TABLE `{$tmp}` LIKE `{$table}`" );
		self::local_columns( $table ); // prime cache once per import
		return true;
	}

	public static function import_rows( $table, array $rows, array $pairs, $replace = false ) {
		global $wpdb;
		if ( ! $rows ) return 0;
		$tmp   = self::tmp_name( $table );
		$local = self::local_columns( $table );
		$cols  = array_keys( $rows[0] );
		foreach ( $cols as $c ) {
			if ( ! in_array( $c, $local, true ) ) return new WP_Error( 'bad_columns', "table {$table}: remote column '{$c}' does not exist locally" );
		}
		// build every row's literal SQL fragment first: esc_sql() (quotes/backslashes double) and the URL/path
		// rewrite in normalize() can both grow a value well past its raw length, and a batch sized on the raw
		// rows undercounts exactly the wide, quote-heavy rows (serialized arrays, JSON) this splitting is for
		$frags = [];
		foreach ( $rows as $r ) {
			$cells = [];
			foreach ( $cols as $c ) {
				$v = isset( $r[ $c ] ) ? $r[ $c ] : null;
				// normalize() leaves byte cells alone; sql_cell() sends them as hex so they land byte for byte
				$cells[] = self::sql_cell( $v === null ? null : IXES_Hasher::normalize( $v, $pairs ) );
			}
			$frags[] = '(' . implode( ',', $cells ) . ')';
		}
		$inserted = 0;
		// one statement per batch, sized to this MariaDB's own max_allowed_packet by the fragments' real bytes
		foreach ( self::row_batches( $frags, self::packet_budget(), 'strlen' ) as $batch ) {
			$sql = ( $replace ? 'REPLACE' : 'INSERT' ) . " INTO `{$tmp}` (`" . implode( '`,`', $cols ) . "`) VALUES " . implode( ',', $batch );
			$ok  = $wpdb->query( $sql );
			if ( $ok === false ) return new WP_Error( 'import_failed', "table {$table}: " . $wpdb->last_error );
			$inserted += count( $batch );
		}
		return $inserted;
	}

	// keep this site's own excluded options (env registry, token, siteurl/home, cron, transients) across a full pull
	public static function preserve_local_options( array $tables ) {
		global $wpdb;
		if ( ! in_array( $wpdb->options, $tables, true ) ) return;
		$tmp = self::tmp_name( $wpdb->options );
		$pk  = self::pk_of( $wpdb->options );
		foreach ( $wpdb->get_results( "SELECT * FROM `{$wpdb->options}`", ARRAY_A ) as $r ) {
			if ( ! IXES_Env::option_excluded( $r['option_name'] ) ) continue;
			if ( $pk ) unset( $r[ $pk ] );
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$tmp}` WHERE option_name = %s", $r['option_name'] ) );
			$wpdb->insert( $tmp, $r );
		}
	}

	public static function import_commit( array $tables ) {
		global $wpdb;
		if ( ! $tables ) return true;
		$parts = [];
		foreach ( $tables as $t ) {
			$tmp = self::tmp_name( $t );
			$parts[] = "`{$t}` TO `{$t}_ixes_old`, `{$tmp}` TO `{$t}`";
		}
		$ok = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $parts ) );
		if ( $ok === false ) return new WP_Error( 'commit_failed', $wpdb->last_error );
		foreach ( $tables as $t ) $wpdb->query( "DROP TABLE IF EXISTS `{$t}_ixes_old`" );
		return true;
	}

	public static function drop_tmp_tables( array $tables ) {
		global $wpdb;
		foreach ( $tables as $t ) $wpdb->query( "DROP TABLE IF EXISTS `" . self::tmp_name( $t ) . "`" );
	}

	/**
	 * Turn a bare I/O failure into something the operator can act on. Mixed ownership
	 * (files installed through the browser as the web-server user, synced from a shell
	 * as another user) is by far the most common cause.
	 */
	private static function io_hint( $msg, $dir ) {
		$probe = $dir;
		while ( $probe && ! is_dir( $probe ) && strlen( $probe ) > strlen( WP_CONTENT_DIR ) ) $probe = dirname( $probe );
		if ( ! is_dir( $probe ) || is_writable( $probe ) ) return $msg;
		$owner = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( fileowner( $probe ) ) : null;
		$me    = function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ? posix_getpwuid( posix_geteuid() ) : null;
		return sprintf(
			'%s — %s is not writable by %s (owned by %s, mode %s). Fix ownership on wp-content and retry; nothing was changed.',
			$msg,
			$probe,
			$me ? $me['name'] : 'this user',
			$owner ? $owner['name'] : 'another user',
			substr( sprintf( '%o', fileperms( $probe ) ), -4 )
		);
	}

	/** $root: where $rel lands, WP_CONTENT_DIR unless the applier stages it (see IXES_Applier::write_root()). */
	public static function write_file_chunk( $rel, $offset, $data, $final, $sha256, $root = null ) {
		$rel = self::safe_rel( $rel );
		if ( ! $rel || self::excluded_path( $rel, IXES_Env::default_excludes() ) ) return new WP_Error( 'bad_path', 'path refused' );
		$dest = ( $root === null ? WP_CONTENT_DIR : $root ) . '/' . $rel;
		$tmp  = $dest . '.ixes-tmp';
		$dir  = dirname( $dest );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return new WP_Error( 'io', self::io_hint( "cannot create directory {$dir}", $dir ) );
		// a retried chunk (after a 502/503/504/408) must overwrite at $offset, not append,
		// or the bytes land twice and the final sha256 check fails
		$fh = @fopen( $tmp, $offset === 0 ? 'wb' : 'c+b' );
		if ( ! $fh ) return new WP_Error( 'io', self::io_hint( "cannot write {$rel}", $dir ) );
		if ( $offset > 0 ) { fseek( $fh, $offset ); ftruncate( $fh, $offset ); }
		$w = fwrite( $fh, $data );
		fclose( $fh );
		if ( $w === false || $w < strlen( $data ) ) { @unlink( $tmp ); return new WP_Error( 'io', self::io_hint( "short write on {$rel} (disk full?)", $dir ) ); }
		if ( $final ) {
			if ( hash_file( 'sha256', $tmp ) !== $sha256 ) { @unlink( $tmp ); return new WP_Error( 'checksum', "checksum mismatch {$rel}" ); }
			if ( ! @rename( $tmp, $dest ) ) { @unlink( $tmp ); return new WP_Error( 'io', self::io_hint( "cannot replace {$rel}", $dir ) ); }
		}
		return true;
	}

	/** Copies an already hash-verified local file (from a wordpress.org seed) into place. No chunking: the whole file is on disk already. */
	public static function seed_file( $rel, $src ) {
		$rel = self::safe_rel( $rel );
		if ( ! $rel || self::excluded_path( $rel, IXES_Env::default_excludes() ) ) return false;
		$dest = WP_CONTENT_DIR . '/' . $rel;
		$dir  = dirname( $dest );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return false;
		return (bool) @copy( $src, $dest );
	}

	/** @return bool true when the file is gone (or was never there) */
	public static function delete_file( $rel ) {
		$rel = self::safe_rel( $rel );
		if ( ! $rel || self::excluded_path( $rel, IXES_Env::default_excludes() ) ) return true;
		$p = WP_CONTENT_DIR . '/' . $rel;
		if ( ! is_file( $p ) ) return true;
		return @unlink( $p );
	}

	public static function local_manifest( array $excludes, $algo, array $roots = [] ) {
		$out = [];
		foreach ( self::all_files( $excludes, $roots ) as $rel ) {
			$h = IXES_Hashcache::hash( WP_CONTENT_DIR . '/' . $rel, $rel, $algo );
			if ( $h !== false ) $out[ $rel ] = $h;
		}
		IXES_Hashcache::save();
		return $out;
	}

	/**
	 * Top-level wp-content children with file counts and byte sizes.
	 * Returns [ 'dirs' => [ [ 'path' => 'name/', 'files' => n, 'bytes' => n ], ... ],
	 *           'root' => [ 'files' => n, 'bytes' => n ] ] where 'root' is the GRAND TOTAL
	 * across all of wp-content, not the loose files at its root -- those are one entry in
	 * 'dirs', labelled "(files at wp-content root)".
	 * Deliberately ignores the exclude list -- the point is to show what excluding a folder
	 * would save, including already-excluded ones. Never hashes; stat only.
	 */
	public static function dir_sizes() {
		$root  = untrailingslashit( WP_CONTENT_DIR );
		$dirs  = [];
		$loose = [ 'path' => '(files at wp-content root)', 'files' => 0, 'bytes' => 0 ];
		$dh    = @opendir( $root );
		if ( ! $dh ) return [ 'dirs' => [], 'root' => [ 'files' => 0, 'bytes' => 0 ] ];
		while ( ( $n = readdir( $dh ) ) !== false ) {
			if ( $n === '.' || $n === '..' ) continue;
			$p = $root . '/' . $n;
			if ( is_dir( $p ) ) {
				if ( $n === 'envsync' || strpos( $n, 'envsync-' ) === 0 ) continue; // our own storage dir
				$s      = self::dir_stat( $p );
				$dirs[] = [ 'path' => $n . '/', 'files' => $s[0], 'bytes' => $s[1] ];
			} elseif ( is_file( $p ) ) {
				$loose['files']++;
				$loose['bytes'] += (int) @filesize( $p );
			}
		}
		closedir( $dh );
		usort( $dirs, function ( $a, $b ) { return $b['bytes'] === $a['bytes'] ? 0 : ( $b['bytes'] < $a['bytes'] ? -1 : 1 ); } );
		if ( $loose['files'] ) $dirs[] = $loose;
		$files = 0; $bytes = 0;
		foreach ( $dirs as $d ) { $files += $d['files']; $bytes += $d['bytes']; }
		return [ 'dirs' => $dirs, 'root' => [ 'files' => $files, 'bytes' => $bytes ] ];
	}

	private static function dir_stat( $abs ) {
		$files = 0; $bytes = 0;
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $abs, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $it as $f ) {
				if ( ! $f->isFile() || $f->isLink() ) continue;
				$files++;
				$bytes += (int) $f->getSize();
			}
		} catch ( Exception $e ) {
			// unreadable subtree: report what we counted so far
		}
		return [ $files, $bytes ];
	}

	public static function offset_auto_increment( ?array $imported = null ) {
		global $wpdb;
		$map = [ $wpdb->posts => 'ID', $wpdb->postmeta => 'meta_id', $wpdb->terms => 'term_id', $wpdb->term_taxonomy => 'term_taxonomy_id', $wpdb->comments => 'comment_ID', $wpdb->users => 'ID' ];
		foreach ( $map as $t => $pk ) {
			if ( $imported !== null && ! in_array( $t, $imported, true ) ) continue;
			$max = (int) $wpdb->get_var( "SELECT MAX(`{$pk}`) FROM `{$t}`" );
			$wpdb->query( "ALTER TABLE `{$t}` AUTO_INCREMENT = " . ( $max + 1000000 ) );
		}
	}

	public static function after_import( $url, $abspath ) {
		update_option( 'siteurl', $url ); update_option( 'home', $url );
		wp_cache_flush();
		flush_rewrite_rules();
	}
}
