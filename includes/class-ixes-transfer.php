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
			'active_plugins'  => (array) get_option( 'active_plugins', [] ),
		];
	}

	public static function pk_of( $table ) {
		global $wpdb;
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( count( $keys ) !== 1 ) return null;
		return $keys[0]['Column_name'];
	}

	public static function dump( $table, $from_pk, $limit ) {
		global $wpdb;
		$pk = self::pk_of( $table );
		if ( $pk ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` > %s ORDER BY `{$pk}` LIMIT %d", $from_pk === null ? '' : $from_pk, $limit ), ARRAY_A );
			$next = count( $rows ) === $limit ? end( $rows )[ $pk ] : null;
		} else {
			$off  = (int) $from_pk;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $limit, $off ), ARRAY_A );
			$next = count( $rows ) === $limit ? $off + $limit : null;
		}
		return [ 'rows' => $rows, 'next' => $next ];
	}

	public static function hash_rows( $table, $from_pk, $limit, array $pairs, $algo ) {
		global $wpdb;
		$d  = self::dump( $table, $from_pk, $limit );
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

	public static function excluded_path( $rel, array $excludes ) {
		if ( strpos( $rel, 'envsync/' ) === 0 ) return true;
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

	public static function file_manifest( $cursor, $limit, array $excludes, $algo ) {
		$all = self::all_files( $excludes );
		$start = 0;
		if ( $cursor !== null && $cursor !== '' ) {
			$i = array_search( $cursor, $all, true );
			$start = $i === false ? 0 : $i + 1;
		}
		$slice = array_slice( $all, $start, $limit );
		$files = [];
		foreach ( $slice as $rel ) {
			$p = WP_CONTENT_DIR . '/' . $rel;
			if ( is_readable( $p ) ) $files[ $rel ] = IXES_Hasher::hash_file( $p, $algo );
		}
		$next = ( $start + $limit < count( $all ) ) ? end( $slice ) : null;
		return [ 'files' => $files, 'next' => $next ];
	}

	public static function safe_rel( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );
		if ( $rel === '' || $rel[0] === '/' || strpos( $rel, '..' ) !== false ) return null;
		return $rel;
	}

	public static function file_chunk( $rel, $offset, $size ) {
		$rel = self::safe_rel( $rel );
		if ( ! $rel || self::excluded_path( $rel, IXES_Env::default_excludes() ) ) return new WP_Error( 'bad_path', 'path refused', [ 'status' => 400 ] );
		$p = WP_CONTENT_DIR . '/' . $rel;
		if ( ! is_file( $p ) ) return new WP_Error( 'not_found', 'no such file', [ 'status' => 404 ] );
		$fh = fopen( $p, 'rb' ); fseek( $fh, $offset ); $data = fread( $fh, $size ); fclose( $fh );
		return [ 'data' => base64_encode( $data === false ? '' : $data ), 'size' => strlen( (string) $data ), 'total' => filesize( $p ), 'sha256' => hash_file( 'sha256', $p ) ];
	}

	// ---------- hub side ----------

	public static function tmp_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . 'ixes_tmp_' . substr( $table, strlen( $wpdb->prefix ) );
	}

	public static function import_begin( $table ) {
		global $wpdb;
		$tmp = self::tmp_name( $table );
		$wpdb->query( "DROP TABLE IF EXISTS `{$tmp}`" );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) return new WP_Error( 'no_table', "local table {$table} missing; schema must match (v0.1)" );
		$wpdb->query( "CREATE TABLE `{$tmp}` LIKE `{$table}`" );
		return true;
	}

	public static function import_rows( $table, array $rows, array $pairs ) {
		global $wpdb;
		if ( ! $rows ) return 0;
		$tmp  = self::tmp_name( $table );
		$cols = array_keys( $rows[0] );
		$vals = [];
		foreach ( $rows as $r ) {
			$cells = [];
			foreach ( $cols as $c ) {
				$v = isset( $r[ $c ] ) ? $r[ $c ] : null;
				if ( $v === null ) { $cells[] = 'NULL'; continue; }
				$v = IXES_Hasher::normalize( $v, $pairs );
				$cells[] = "'" . esc_sql( (string) $v ) . "'";
			}
			$vals[] = '(' . implode( ',', $cells ) . ')';
		}
		$sql = "INSERT INTO `{$tmp}` (`" . implode( '`,`', $cols ) . "`) VALUES " . implode( ',', $vals );
		$wpdb->query( $sql );
		return count( $rows );
	}

	public static function import_commit( array $tables ) {
		global $wpdb;
		$parts = [];
		foreach ( $tables as $t ) {
			$tmp = self::tmp_name( $t );
			$parts[] = "`{$t}` TO `{$t}_ixes_old`, `{$tmp}` TO `{$t}`";
		}
		$wpdb->query( 'RENAME TABLE ' . implode( ', ', $parts ) );
		foreach ( $tables as $t ) $wpdb->query( "DROP TABLE IF EXISTS `{$t}_ixes_old`" );
	}

	public static function write_file_chunk( $rel, $offset, $data, $final, $sha256 ) {
		$rel = self::safe_rel( $rel );
		if ( ! $rel ) return new WP_Error( 'bad_path', 'path refused' );
		$dest = WP_CONTENT_DIR . '/' . $rel;
		$tmp  = $dest . '.ixes-tmp';
		wp_mkdir_p( dirname( $dest ) );
		$fh = fopen( $tmp, $offset === 0 ? 'wb' : 'ab' );
		if ( ! $fh ) return new WP_Error( 'io', "cannot open {$tmp}" );
		fwrite( $fh, $data ); fclose( $fh );
		if ( $final ) {
			if ( hash_file( 'sha256', $tmp ) !== $sha256 ) { unlink( $tmp ); return new WP_Error( 'checksum', "checksum mismatch {$rel}" ); }
			rename( $tmp, $dest );
		}
		return true;
	}

	public static function delete_file( $rel ) {
		$rel = self::safe_rel( $rel );
		if ( $rel && is_file( WP_CONTENT_DIR . '/' . $rel ) ) unlink( WP_CONTENT_DIR . '/' . $rel );
	}

	public static function local_manifest( array $excludes, $algo ) {
		$out = [];
		foreach ( self::all_files( $excludes ) as $rel ) $out[ $rel ] = IXES_Hasher::hash_file( WP_CONTENT_DIR . '/' . $rel, $algo );
		return $out;
	}

	public static function offset_auto_increment() {
		global $wpdb;
		$map = [ $wpdb->posts => 'ID', $wpdb->postmeta => 'meta_id', $wpdb->terms => 'term_id', $wpdb->term_taxonomy => 'term_taxonomy_id', $wpdb->comments => 'comment_ID', $wpdb->users => 'ID' ];
		foreach ( $map as $t => $pk ) {
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
