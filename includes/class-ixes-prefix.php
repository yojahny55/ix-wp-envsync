<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lets two sites with different $table_prefix sync. WordPress puts the prefix in table names and inside a few
 * rows: the <prefix>user_roles option and usermeta keys such as <prefix>capabilities. Only the remote translates,
 * at its REST boundary; the hub always works in its own names. "local" is this site's prefix, "peer" the other's.
 */
class IXES_Prefix {
	private static $current = null;
	private $local;
	private $peer;

	public function __construct( $local, $peer ) { $this->local = (string) $local; $this->peer = (string) $peer; }

	/** The translation for the request being served, or null when both sides share a prefix (always null on the hub). */
	public static function current() { return self::$current; }
	public static function set_current( IXES_Prefix $p = null ) { self::$current = $p; }
	public static function valid( $prefix ) { return is_string( $prefix ) && preg_match( '/\A[A-Za-z0-9_]+\z/', $prefix ) === 1; }

	public function local() { return $this->local; }
	public function peer() { return $this->peer; }

	/** Peer table name -> local; null when the name does not carry the peer prefix. */
	public function table_in( $name ) { return self::swap( (string) $name, $this->peer, $this->local ); }
	public function table_out( $name ) { return self::swap( (string) $name, $this->local, $this->peer ); }
	public function bare( $local_name ) { return strpos( (string) $local_name, $this->local ) === 0 ? substr( $local_name, strlen( $this->local ) ) : (string) $local_name; }

	/** Peer-form row -> local form; null for an orphan that must stay out of the sync. */
	public function row_in( $bare, array $row ) { return self::row( $bare, $row, $this->peer, $this->local ); }
	public function row_out( $bare, array $row ) { return self::row( $bare, $row, $this->local, $this->peer ); }

	public function sql_in( $sql ) {
		$re = '/\b(CREATE TABLE|REFERENCES)(\s+)`' . preg_quote( $this->peer, '/' ) . '([A-Za-z0-9_]*)`/i';
		return preg_replace_callback( $re, function ( $m ) { return $m[1] . $m[2] . '`' . $this->local . $m[3] . '`'; }, (string) $sql );
	}

	/** A job step in the hub's names -> this site's. Orphan rows are dropped and named in 'prefix_refused'. */
	public function step_in( array $p ) {
		$kind = $p['kind'] ?? '';
		if ( $kind === 'drop_check' ) {
			$p['tables'] = array_map( function ( $n ) { $t = $this->table_in( (string) $n ); return $t === null ? '' : $t; }, array_values( (array) ( $p['tables'] ?? [] ) ) );
		}
		if ( in_array( $kind, [ 'rows', 'delete_rows', 'delete_set', 'create_table', 'drop_table' ], true ) ) {
			$t = $this->table_in( (string) ( $p['table'] ?? '' ) );
			$p['table'] = $t === null ? '' : $t;
		}
		if ( $kind === 'rows' && $p['table'] !== '' ) {
			$bare = $this->bare( $p['table'] );
			$rows = []; $refused = [];
			foreach ( (array) ( $p['rows'] ?? [] ) as $row ) {
				$r = is_array( $row ) ? $this->row_in( $bare, $row ) : null;
				if ( $r === null ) { $refused[] = (string) ( $row['meta_key'] ?? ( $row['option_name'] ?? '' ) ); continue; }
				$rows[] = $r;
			}
			$p['rows'] = $rows;
			$p['prefix_refused'] = $refused;
		}
		if ( $kind === 'create_table' ) $p['sql'] = $this->sql_in( (string) ( $p['sql'] ?? '' ) );
		if ( $kind === 'option' && isset( $p['name'] ) ) {
			$r = $this->row_in( 'options', [ 'option_name' => (string) $p['name'] ] );
			if ( $r !== null ) $p['name'] = $r['option_name'];
		}
		return $p;
	}

	/** job/start: plan tables are keyed by the hub's names. */
	public function start_in( array $p ) {
		if ( ! isset( $p['plan_meta']['tables'] ) || ! is_array( $p['plan_meta']['tables'] ) ) return $p;
		$tables = [];
		foreach ( $p['plan_meta']['tables'] as $name => $t ) {
			$n = $this->table_in( (string) $name );
			if ( $n !== null ) $tables[ $n ] = $t;
		}
		$p['plan_meta']['tables'] = $tables;
		return $p;
	}

	private static function swap( $s, $from, $to ) {
		return strpos( $s, $from ) === 0 ? $to . substr( $s, strlen( $from ) ) : null;
	}

	// From prefix first, so overlapping prefixes (wp_ / wp_abc_) round-trip; a key that only carries the target
	// prefix belongs to the other site (left over from an old prefix) and is an orphan.
	private static function key( $k, $from, $to ) {
		$s = self::swap( $k, $from, $to );
		if ( $s !== null ) return $s;
		return strpos( $k, $to ) === 0 ? null : $k;
	}

	private static function row( $bare, array $row, $from, $to ) {
		if ( $bare === 'options' && isset( $row['option_name'] ) ) {
			if ( $row['option_name'] === $from . 'user_roles' ) $row['option_name'] = $to . 'user_roles';
			elseif ( $row['option_name'] === $to . 'user_roles' ) return null;
		}
		if ( $bare === 'usermeta' && isset( $row['meta_key'] ) ) {
			$k = self::key( (string) $row['meta_key'], $from, $to );
			if ( $k === null ) return null;
			$row['meta_key'] = $k;
		}
		return $row;
	}
}
