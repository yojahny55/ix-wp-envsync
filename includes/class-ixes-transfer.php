<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Transfer {

	public static function info() {
		global $wpdb;
		$tables = [];
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $t ) {
			if ( strpos( $t, $wpdb->prefix . 'ixes_' ) === 0 ) continue;
			$tables[] = [ 'name' => $t, 'pk' => self::pk_of( $t ), 'rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ) ];
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
			'caps'            => [ 'binary', 'scope', 'batch', 'create_table' ],
			'active_plugins'  => (array) get_option( 'active_plugins', [] ),
			'lock'            => IXES_Applier::lock_info(),
			'auth_via'        => IXES_Rest::auth_via(),
			'inventory'       => self::inventory(),
		];
	}

	/** Installed plugin and theme versions keyed by slug (plugin folder, or file name for single-file plugins). */
	public static function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = [];
		foreach ( get_plugins() as $file => $data ) $plugins[ IXES_Report::plugin_slug( $file ) ] = (string) $data['Version'];
		$themes = [];
		foreach ( wp_get_themes() as $slug => $theme ) $themes[ $slug ] = (string) $theme->get( 'Version' );
		return [ 'plugins' => $plugins, 'themes' => $themes, 'stylesheet' => get_stylesheet() ];
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

	public static function dump( $table, $from_pk, $limit ) {
		global $wpdb;
		if ( ! self::valid_table( $table ) ) return new WP_Error( 'bad_table', 'unknown table', [ 'status' => 400 ] );
		$pk = self::pk_of( $table );
		if ( $pk ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` > %s ORDER BY `{$pk}` LIMIT %d", $from_pk === null ? '' : $from_pk, $limit ), ARRAY_A );
			$next = count( $rows ) === $limit ? end( $rows )[ $pk ] : null;
		} else {
			$off  = (int) $from_pk;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $limit, $off ), ARRAY_A );
			$next = count( $rows ) === $limit ? $off + $limit : null;
		}
		// never transfer environment-local options (siteurl/home/cron/transients/ixes_*)
		if ( $table === $wpdb->options ) {
			$rows = array_values( array_filter( $rows, function ( $r ) { return ! IXES_Env::option_excluded( $r['option_name'] ); } ) );
		}
		return [ 'rows' => $rows, 'next' => $next ];
	}

	public static function hash_rows( $table, $from_pk, $limit, array $pairs, $algo ) {
		global $wpdb;
		if ( ! self::valid_table( $table ) ) return new WP_Error( 'bad_table', 'unknown table', [ 'status' => 400 ] );
		$d  = self::dump( $table, $from_pk, $limit );
		if ( is_wp_error( $d ) ) return $d;
		$pk = self::pk_of( $table );
		$out = [];
		$is_options = ( $table === $wpdb->options );
		foreach ( $d['rows'] as $r ) {
			if ( $is_options && IXES_Env::option_excluded( $r['option_name'] ) ) continue;
			$h = IXES_Hasher::hash_row( $r, $pairs, $algo );
			if ( $pk ) $out[ $r[ $pk ] ] = $h; else $out[] = $h;
		}
		return [ 'rows' => $out, 'next' => $d['next'] ];
	}

	/** This plugin's own directory, relative to wp-content, with a trailing slash. */
	public static function own_dir() {
		if ( ! defined( 'IXES_FILE' ) ) return '';
		$root = untrailingslashit( str_replace( '\\', '/', WP_CONTENT_DIR ) );
		$dir  = untrailingslashit( str_replace( '\\', '/', dirname( IXES_FILE ) ) );
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
			if ( substr( $ex, -1 ) === '/' ) { if ( strpos( $rel, $ex ) === 0 || strpos( $rel, '/' . $ex ) !== false ) return true; }
			elseif ( $rel === $ex || basename( $rel ) === $ex ) return true;
		}
		return false;
	}

	public static function all_files( array $excludes ) {
		$root = untrailingslashit( WP_CONTENT_DIR );
		$out = [];
		$it = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			function ( $f ) use ( $root, $excludes ) {
				$rel = ltrim( substr( $f->getPathname(), strlen( $root ) ), '/' );
				if ( $f->isDir() ) $rel .= '/';
				return ! self::excluded_path( $rel, $excludes );
			}
		) );
		foreach ( $it as $f ) {
			if ( $f->isFile() ) $out[] = ltrim( substr( $f->getPathname(), strlen( $root ) ), '/' );
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	public static function file_manifest( $cursor, $limit, array $excludes, $algo, $with_sizes = false ) {
		$all = self::all_files( $excludes );
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
		$p = WP_CONTENT_DIR . '/' . $rel;
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
		$inserted = 0;
		foreach ( array_chunk( $rows, 500 ) as $batch ) {
			$vals = [];
			foreach ( $batch as $r ) {
				$cells = [];
				foreach ( $cols as $c ) {
					$v = isset( $r[ $c ] ) ? $r[ $c ] : null;
					if ( $v === null ) { $cells[] = 'NULL'; continue; }
					$v = IXES_Hasher::normalize( $v, $pairs );
					$cells[] = "'" . esc_sql( (string) $v ) . "'";
				}
				$vals[] = '(' . implode( ',', $cells ) . ')';
			}
			$sql = ( $replace ? 'REPLACE' : 'INSERT' ) . " INTO `{$tmp}` (`" . implode( '`,`', $cols ) . "`) VALUES " . implode( ',', $vals );
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

	public static function write_file_chunk( $rel, $offset, $data, $final, $sha256 ) {
		$rel = self::safe_rel( $rel );
		if ( ! $rel || self::excluded_path( $rel, IXES_Env::default_excludes() ) ) return new WP_Error( 'bad_path', 'path refused' );
		$dest = WP_CONTENT_DIR . '/' . $rel;
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

	/** @return bool true when the file is gone (or was never there) */
	public static function delete_file( $rel ) {
		$rel = self::safe_rel( $rel );
		if ( ! $rel || self::excluded_path( $rel, IXES_Env::default_excludes() ) ) return true;
		$p = WP_CONTENT_DIR . '/' . $rel;
		if ( ! is_file( $p ) ) return true;
		return @unlink( $p );
	}

	public static function local_manifest( array $excludes, $algo ) {
		$out = [];
		foreach ( self::all_files( $excludes ) as $rel ) {
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

	public static function offset_auto_increment( array $imported = null ) {
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
