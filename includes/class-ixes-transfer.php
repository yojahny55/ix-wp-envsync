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
}
