<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Dropping a whole table, on whichever side runs this (the remote during a push, the hub during a pull).
 * Nothing is dropped unless blockers() finds no reason to keep it and snapshot() kept it first.
 */
class IXES_Droptable {

	const MAX_SCAN_BYTES = 4194304; // a PHP file bigger than this is a bundle, not code that names a table

	/** "wpda_logs, wp_foo" -> [ 'wp_wpda_logs', 'wp_foo' ]: a name without the prefix gets it. */
	public static function table_list( $csv, $prefix ) {
		$out = [];
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $csv ) ) ) as $t ) $out[] = strpos( $t, $prefix ) === 0 ? $t : $prefix . $t;
		return array_values( array_unique( $out ) );
	}

	/**
	 * Why each table must stay: live code names it, or another table, view or trigger depends on it.
	 * $scan_code false skips the code scan (the slow part), for a re-check moments after a full one.
	 * @param string[] $tables this site's table names
	 * @return array table => reasons (only tables with at least one reason)
	 */
	public static function blockers( array $tables, $scan_code = true ) {
		global $wpdb;
		$out = [];
		$needles = [];
		foreach ( $tables as $t ) {
			$bare = strpos( $t, $wpdb->prefix ) === 0 ? substr( $t, strlen( $wpdb->prefix ) ) : $t;
			if ( $bare !== '' ) $needles[ $t ] = $bare;
		}
		$core = array_flip( array_values( $wpdb->tables( 'all', true ) ) );
		$all  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		foreach ( $tables as $t ) {
			if ( isset( $core[ $t ] ) ) $out[ $t ][] = 'WordPress core table';
			$other = self::other_install( $t, $wpdb->prefix, $all );
			if ( $other !== null ) $out[ $t ][] = "belongs to another WordPress install ({$other})";
		}
		if ( $scan_code ) foreach ( self::code_hits( $needles, self::code_roots() ) as $t => $file ) $out[ $t ][] = "named in {$file}";
		foreach ( $tables as $t ) foreach ( self::sql_dependents( $t ) as $why ) $out[ $t ][] = $why;
		return $out;
	}

	/** Folders of the code that runs on this site: active plugins, the active theme and its parent, mu-plugins. */
	public static function code_roots() {
		$roots = [];
		foreach ( (array) get_option( 'active_plugins', [] ) as $file ) {
			$d = dirname( (string) $file );
			$roots[] = WP_PLUGIN_DIR . '/' . ( $d === '.' ? $file : $d );
		}
		if ( is_multisite() ) foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) as $file ) {
			$d = dirname( (string) $file );
			$roots[] = WP_PLUGIN_DIR . '/' . ( $d === '.' ? $file : $d );
		}
		$roots[] = get_stylesheet_directory();
		$roots[] = get_template_directory();
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) $roots[] = WPMU_PLUGIN_DIR;
		// this plugin names every table it syncs; its own code is never a reason to keep one
		$own = realpath( dirname( IXES_FILE ) );
		return array_values( array_filter( array_unique( $roots ), function ( $r ) use ( $own ) { return file_exists( $r ) && realpath( $r ) !== $own; } ) );
	}

	/**
	 * First PHP file under $roots that contains each needle (the table name without prefix).
	 * Pure over the filesystem, so tests can hand in a temp folder.
	 * @param array $needles table => bare name
	 * @return array table => file where it was found
	 */
	public static function code_hits( array $needles, array $roots ) {
		$hits = [];
		if ( ! $needles ) return $hits;
		$low = array_map( 'strtolower', $needles );
		foreach ( $roots as $root ) {
			$files = is_file( $root ) ? [ new SplFileInfo( $root ) ] : new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $f ) {
				if ( ! $f->isFile() || strtolower( $f->getExtension() ) !== 'php' || $f->getSize() > self::MAX_SCAN_BYTES ) continue;
				$src = @file_get_contents( $f->getPathname() );
				if ( $src === false ) continue;
				$src = strtolower( $src ); // once per file, not once per table
				foreach ( $low as $t => $bare ) {
					if ( isset( $hits[ $t ] ) || strpos( $src, $bare ) === false ) continue;
					$hits[ $t ] = self::short_path( $f->getPathname() );
				}
				if ( count( $hits ) === count( $needles ) ) return $hits;
			}
		}
		return $hits;
	}

	private static function short_path( $abs ) {
		$c = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/' : '';
		return $c !== '' && strpos( $abs, $c ) === 0 ? substr( $abs, strlen( $c ) ) : $abs;
	}

	/** Foreign keys from other tables, views and other tables' triggers that use $table. */
	public static function sql_dependents( $table ) {
		global $wpdb;
		$why = [];
		$fk = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s AND TABLE_NAME <> %s', $table, $table ) );
		foreach ( (array) $fk as $t ) $why[] = "foreign key from {$t}";
		$like = '%' . $wpdb->esc_like( $table ) . '%';
		$views = $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND VIEW_DEFINITION LIKE %s', $like ) );
		foreach ( (array) $views as $v ) $why[] = "view {$v}";
		$trg = $wpdb->get_col( $wpdb->prepare( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE <> %s AND ACTION_STATEMENT LIKE %s', $table, $like ) );
		foreach ( (array) $trg as $g ) $why[] = "trigger {$g}";
		return $why;
	}

	/**
	 * The longer prefix of another WordPress install sharing this database (wp_old_, a multisite's wp_2_) that $table
	 * belongs to, or null. Such a table starts with this site's prefix but is not this site's. An install has options,
	 * posts and postmeta under its prefix; one or two of those names alone is a plugin's (WP All Import's pmxi_posts).
	 * @param string[] $all every table name in the database under $prefix
	 */
	public static function other_install( $table, $prefix, array $all ) {
		if ( strpos( $table, $prefix ) !== 0 ) return null;
		$names = array_flip( $all );
		$rest  = substr( $table, strlen( $prefix ) );
		for ( $i = strpos( $rest, '_' ); $i !== false; $i = strpos( $rest, '_', $i + 1 ) ) {
			$p = $prefix . substr( $rest, 0, $i + 1 );
			if ( isset( $names[ $p . 'options' ], $names[ $p . 'posts' ], $names[ $p . 'postmeta' ] ) ) return $p;
		}
		return null;
	}

	/**
	 * Row hashes of $table in the form the planner compares (pk => hash, or a list without a primary key),
	 * each row hashed as the hub sees it.
	 * @return array|WP_Error
	 */
	public static function hashes( $table, array $pairs, $algo ) {
		$out = []; $next = null;
		$pk = IXES_Transfer::pk_of( $table );
		do {
			$r = IXES_Transfer::hash_rows( $table, $next, 5000, $pairs, $algo );
			if ( is_wp_error( $r ) ) return $r;
			if ( $pk ) $out += $r['rows']; else foreach ( $r['rows'] as $h ) $out[] = $h;
			$next = $r['next'];
		} while ( $next !== null );
		return $out;
	}

	/** Same rows as the plan saw: same keys and hashes; a table without a primary key compares as a multiset. */
	public static function same_rows( array $now, array $expect, $has_pk ) {
		return self::digest( $now, $has_pk ) === self::digest( $expect, $has_pk );
	}

	/** One short fingerprint of a table's rows, so a step carries 50 bytes instead of every row hash. */
	public static function digest( array $hashes, $has_pk ) {
		if ( $has_pk ) {
			$lines = [];
			foreach ( $hashes as $k => $h ) $lines[] = $k . '=' . $h;
		} else {
			$lines = array_map( 'strval', array_values( $hashes ) );
		}
		sort( $lines, SORT_STRING );
		return count( $lines ) . ':' . sha1( implode( "\n", $lines ) );
	}

	/**
	 * Why a CREATE TABLE this site produced could not be run again to bring the table back (null: it can).
	 * Its own definition, not one a hub sent: a plain CREATE for exactly that name under this prefix.
	 */
	public static function unrestorable( $table, $create, $prefix ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table ) || strpos( (string) $table, $prefix ) !== 0 || strpos( (string) $table, $prefix . 'ixes_' ) === 0 ) return 'table name refused';
		if ( ! preg_match( '/^CREATE TABLE `' . preg_quote( (string) $table, '/' ) . '` \(/', (string) $create ) ) return 'not a CREATE TABLE for that table';
		return null;
	}

	/**
	 * Copies $table to $jsonl (line 1: table and CREATE; then one row per line, raw) and, if $sql is given, to a .sql
	 * file too, page by page so a big table never sits in memory. Nothing may be dropped unless this returns an array.
	 * @return array{table:string,create:string,rows:int}|WP_Error
	 */
	public static function snapshot_to( $table, $jsonl, $sql = null ) {
		global $wpdb;
		if ( ! IXES_Transfer::valid_table( $table ) ) return new WP_Error( 'bad_table', 'unknown table', [ 'status' => 400 ] );
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		if ( ! $create || empty( $create[1] ) ) return new WP_Error( 'snapshot_failed', "cannot read the definition of {$table}" );
		$why = self::unrestorable( $table, $create[1], $wpdb->prefix );
		if ( $why ) return new WP_Error( 'snapshot_failed', "{$table} could not be recreated from its copy: {$why}" );
		$out = [ 'table' => $table, 'create' => $create[1], 'rows' => 0 ];
		$j = self::open_copy( $jsonl ); $q = $sql === null ? null : self::open_copy( $sql );
		if ( ! $j || ( $sql !== null && ! $q ) ) { self::discard( [ $j, $q ], [ $jsonl, $sql ] ); return new WP_Error( 'snapshot_failed', 'cannot write the copy of ' . $table ); }
		$ok = self::put( $j, json_encode( [ 'table' => $table, 'create' => $create[1] ] ) . "\n" ) && ( ! $q || self::put( $q, self::sql_head( $table, $create[1] ) ) );
		$pk = IXES_Transfer::pk_of( $table ); $last = null; $off = 0;
		while ( $ok ) {
			$page = $pk
				? $wpdb->get_results( $last === null ? "SELECT * FROM `{$table}` ORDER BY `{$pk}` LIMIT 5000" : $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` > %s ORDER BY `{$pk}` LIMIT 5000", $last ), ARRAY_A )
				: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT 5000 OFFSET %d", $off ), ARRAY_A );
			$page = (array) $page;
			if ( ! $page ) break;
			foreach ( $page as $row ) {
				$line = json_encode( $row );
				if ( $line === false ) { $ok = false; break; } // bytes JSON cannot carry: the copy would not be the table
				if ( ! self::put( $j, $line . "\n" ) ) { $ok = false; break; }
			}
			if ( $ok && $q ) $ok = self::put( $q, self::sql_insert( $table, $page ) );
			$out['rows'] += count( $page );
			if ( count( $page ) < 5000 ) break;
			if ( $pk ) $last = end( $page )[ $pk ]; else $off += 5000;
		}
		if ( ! $ok || ! fclose( $j ) || ( $q && ! fclose( $q ) ) ) { self::discard( [], [ $jsonl, $sql ] ); return new WP_Error( 'snapshot_failed', 'cannot write the copy of ' . $table ); }
		return $out;
	}

	private static function open_copy( $file ) {
		if ( ! wp_mkdir_p( dirname( $file ) ) ) return false;
		return @fopen( $file, 'wb' );
	}
	private static function put( $h, $s ) { return fwrite( $h, $s ) === strlen( $s ); }
	private static function discard( array $handles, array $files ) {
		foreach ( $handles as $h ) if ( $h ) @fclose( $h );
		foreach ( $files as $f ) if ( $f !== null && is_file( $f ) ) @unlink( $f );
	}

	/**
	 * Recreates a dropped table from its snapshot_to() copy. A table that exists again is left alone.
	 * @return true|WP_Error
	 */
	public static function restore_file( $jsonl ) {
		global $wpdb;
		$h = is_file( $jsonl ) ? @fopen( $jsonl, 'rb' ) : false;
		if ( ! $h ) return new WP_Error( 'restore_failed', "copy {$jsonl} is missing" );
		$meta  = json_decode( (string) fgets( $h ), true );
		$table = (string) ( $meta['table'] ?? '' );
		$why   = self::unrestorable( $table, (string) ( $meta['create'] ?? '' ), $wpdb->prefix );
		if ( $why ) { fclose( $h ); return new WP_Error( 'restore_failed', "{$jsonl}: {$why}" ); }
		if ( IXES_Transfer::valid_table( $table ) ) { fclose( $h ); return true; }
		if ( $wpdb->query( (string) $meta['create'] ) === false ) { fclose( $h ); return new WP_Error( 'restore_failed', "cannot recreate {$table}: {$wpdb->last_error}" ); }
		$lost = 0;
		while ( ( $line = fgets( $h ) ) !== false ) {
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) || ! $wpdb->insert( $table, $row ) ) $lost++;
		}
		fclose( $h );
		return $lost ? new WP_Error( 'restore_failed', "{$table} recreated, but {$lost} row(s) did not go back; the copy is {$jsonl}" ) : true;
	}

	/** Start of the .sql copy: a person loads it with mysql or wp db import. */
	public static function sql_head( $table, $create ) {
		return "-- ix-wp-envsync backup of `{$table}` taken " . gmdate( 'Y-m-d H:i:s' ) . " UTC, before dropping it\nDROP TABLE IF EXISTS `{$table}`;\n" . rtrim( (string) $create, ';' ) . ";\n";
	}

	/** INSERT statements for one page of rows, 200 per statement. */
	public static function sql_insert( $table, array $rows ) {
		$o = '';
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$cols = array_keys( $chunk[0] );
			$vals = [];
			foreach ( $chunk as $row ) {
				$cells = [];
				foreach ( $cols as $c ) $cells[] = $row[ $c ] === null ? 'NULL' : "'" . str_replace( [ '\\', "'", "\n", "\r", "\0" ], [ '\\\\', "\\'", '\\n', '\\r', '\\0' ], (string) $row[ $c ] ) . "'";
				$vals[] = '(' . implode( ',', $cells ) . ')';
			}
			$o .= "INSERT INTO `{$table}` (`" . implode( '`,`', $cols ) . '`) VALUES ' . implode( ",\n", $vals ) . ";\n";
		}
		return $o;
	}

	/** Where the hub keeps its own copy of every dropped table: --backup-dir, ENVSYNC_BACKUP_DIR, else the storage dir. */
	public static function backup_dir( $override = '' ) {
		if ( $override !== '' ) return rtrim( $override, '/' );
		if ( defined( 'ENVSYNC_BACKUP_DIR' ) && ENVSYNC_BACKUP_DIR ) return rtrim( ENVSYNC_BACKUP_DIR, '/' );
		return ixes_storage_dir() . '/backups';
	}

	public static function backup_file( $dir, $label, $table ) {
		return rtrim( $dir, '/' ) . '/' . preg_replace( '/[^A-Za-z0-9_.-]/', '_', $label ) . '-' . $table . '.sql';
	}

	/**
	 * GET each url; a url that answers below 500 without WordPress's fatal-error page is healthy.
	 * $get( $url ) returns a wp_remote_* response or WP_Error.
	 * @return array url => true | reason, in the order of $urls
	 */
	public static function smoke( array $urls, callable $get ) {
		$out = [];
		foreach ( $urls as $u ) {
			$r = $get( $u );
			if ( is_wp_error( $r ) ) { $out[ $u ] = $r->get_error_message(); continue; }
			$code = (int) wp_remote_retrieve_response_code( $r );
			$body = (string) wp_remote_retrieve_body( $r );
			if ( $code >= 500 ) $out[ $u ] = "HTTP {$code}";
			elseif ( preg_match( '/There has been a critical error|<b>Fatal error<\/b>|PHP Fatal error/i', $body ) ) $out[ $u ] = 'fatal error in the page';
			else $out[ $u ] = true;
		}
		return $out;
	}

	/**
	 * What a drop broke: urls healthy before and not now. The two rounds use different nonces (a cache could
	 * answer the second with the first's page), so they are matched by position.
	 */
	public static function regressions( array $before, array $after ) {
		$b = array_values( $before ); $bad = []; $i = 0;
		foreach ( $after as $u => $ok ) {
			if ( $ok !== true && ( $b[ $i ] ?? true ) === true ) $bad[ $u ] = $ok;
			$i++;
		}
		return $bad;
	}

	/** Home, login and REST index; a fresh $nonce per round gets past page caches, which would hide a fatal. */
	public static function smoke_urls( $base, $nonce ) {
		$base = untrailingslashit( $base ); $q = 'ixes_smoke=' . rawurlencode( (string) $nonce );
		return [ "{$base}/?{$q}", "{$base}/wp-login.php?{$q}", "{$base}/?rest_route=/&{$q}" ];
	}
}
