<?php
/**
 * A $wpdb double that keeps rows and enforces a (composite) primary key, for the few statements the row paths issue.
 * INSERT/REPLACE are parsed back from the SQL, so a hex literal and a quoted string land as the bytes MySQL would store.
 */
class BinCellsWpdb {
	public $prefix = 'wp_'; public $options = 'wp_options'; public $last_error = '';
	public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $terms = 'wp_terms'; public $term_taxonomy = 'wp_term_taxonomy'; public $comments = 'wp_comments'; public $users = 'wp_users';
	public $cols = [];  // table => column list
	public $pk = [];    // table => primary key columns
	public $rows = [];  // table => [ key => row ]
	public $sql = [];

	public function table( $name, array $cols, array $pk ) { $this->cols[ $name ] = $cols; $this->pk[ $name ] = $pk; $this->rows[ $name ] = []; }
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $q, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ]; return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'"; }, $q );
	}
	public function get_col( $q ) {
		if ( preg_match( "/SHOW TABLES LIKE '([^']*)'/", $q, $m ) ) {
			$p = rtrim( str_replace( [ '\\_', '\\%' ], [ '_', '%' ], stripslashes( $m[1] ) ), '%' );
			return array_values( array_filter( array_keys( $this->cols ), function ( $n ) use ( $p ) { return strpos( $n, $p ) === 0; } ) );
		}
		if ( preg_match( '/SHOW COLUMNS FROM `([^`]+)`/', $q, $m ) ) return $this->cols[ $m[1] ] ?? [];
		return [];
	}
	public function get_var( $q ) { $r = $this->get_col( $q ); return $r ? $r[0] : null; }
	public function get_row( $q, $t = null ) { return null; }
	public function get_results( $q, $t = null ) {
		if ( strpos( $q, 'SELECT' ) === 0 ) $this->sql[] = $q;
		if ( preg_match( '/SHOW KEYS FROM `([^`]+)`/', $q, $m ) ) return array_map( function ( $c ) { return [ 'Column_name' => $c ]; }, $this->pk[ $m[1] ] ?? [] );
		if ( preg_match( "/SELECT \\* FROM `([^`]+)` WHERE `([^`]+)` > (0x[0-9a-f]*|'.*') ORDER BY `[^`]+` LIMIT (\\d+)$/s", $q, $m ) ) {
			$from = $m[3][0] === '0' ? hex2bin( substr( $m[3], 2 ) ) : stripslashes( substr( $m[3], 1, -1 ) ); $col = $m[2];
			$rows = array_values( array_filter( $this->rows[ $m[1] ] ?? [], function ( $r ) use ( $col, $from ) { return strcmp( (string) $r[ $col ], $from ) > 0; } ) );
			usort( $rows, function ( $a, $b ) use ( $col ) { return strcmp( (string) $a[ $col ], (string) $b[ $col ] ); } );
			return array_slice( $rows, 0, (int) $m[4] );
		}
		if ( preg_match( '/SELECT \* FROM `([^`]+)` LIMIT (\d+) OFFSET (\d+)/', $q, $m ) ) return array_slice( array_values( $this->rows[ $m[1] ] ?? [] ), (int) $m[3], (int) $m[2] );
		return [];
	}
	public function insert( $table, array $row ) { return $this->put( $table, $row, false ) ? 1 : false; }
	public function replace( $table, array $row ) { return $this->put( $table, $row, true ) ? 1 : false; }
	public function query( $sql ) {
		$this->sql[] = $sql;
		if ( preg_match( '/^CREATE TABLE `([^`]+)` LIKE `([^`]+)`/', $sql, $m ) ) { $this->table( $m[1], $this->cols[ $m[2] ], $this->pk[ $m[2] ] ); return true; }
		if ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m ) ) { unset( $this->cols[ $m[1] ], $this->pk[ $m[1] ], $this->rows[ $m[1] ] ); return true; }
		if ( preg_match( '/^(INSERT|REPLACE) INTO `([^`]+)` \(`(.+?)`\) VALUES (.+)$/s', $sql, $m ) ) {
			$cols = explode( '`,`', $m[3] ); $n = 0;
			foreach ( self::tuples( $m[4] ) as $vals ) {
				if ( ! $this->put( $m[2], array_combine( $cols, $vals ), $m[1] === 'REPLACE' ) ) return false;
				$n++;
			}
			return $n;
		}
		return true;
	}
	private function put( $table, array $row, $replace ) {
		$key = implode( '-', array_map( function ( $c ) use ( $row ) { return (string) $row[ $c ]; }, $this->pk[ $table ] ?: array_keys( $row ) ) );
		if ( ! $replace && isset( $this->rows[ $table ][ $key ] ) ) { $this->last_error = "Duplicate entry '{$key}' for key 'PRIMARY'"; return false; }
		$this->rows[ $table ][ $key ] = $row;
		return true;
	}
	/** VALUES (...),(...) -> list of cell lists: NULL, 0x<hex>, or a quoted string with backslash escapes. */
	private static function tuples( $s ) {
		$out = []; $i = 0; $len = strlen( $s );
		while ( $i < $len ) {
			if ( $s[ $i ] !== '(' ) { $i++; continue; }
			$i++; $cells = [];
			while ( $s[ $i ] !== ')' ) {
				if ( substr( $s, $i, 4 ) === 'NULL' ) { $cells[] = null; $i += 4; }
				elseif ( substr( $s, $i, 2 ) === '0x' ) { preg_match( '/0x([0-9a-f]*)/A', $s, $h, 0, $i ); $cells[] = hex2bin( $h[1] ); $i += strlen( $h[0] ); }
				else {
					$i++; $v = '';
					while ( $s[ $i ] !== "'" ) { if ( $s[ $i ] === '\\' ) { $i++; $v .= $s[ $i ] === '0' ? "\0" : $s[ $i ]; } else { $v .= $s[ $i ]; } $i++; }
					$cells[] = $v; $i++;
				}
				if ( $s[ $i ] === ',' ) $i++;
			}
			$i++; $out[] = $cells;
		}
		return $out;
	}
}
