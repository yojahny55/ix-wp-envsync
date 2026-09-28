<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BinCellsWpdb.php';
if ( ! function_exists( 'esc_sql' ) ) { function esc_sql( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'wp_cache_flush' ) ) { function wp_cache_flush() { return true; } }

/**
 * A remote in memory with its own database: /hash/rows, /hash/tables and /dump go through the same cursor handling as
 * IXES_Rest, and the answer through JSON the way WordPress sends it, including wp_json_encode()'s fallback that turns
 * bytes JSON cannot carry into '?'. $old: a remote before 0.9.3 (no cap, raw cursors, no byte_keys, no wrapped cells).
 */
class CursorRemote extends IXES_Client {
	public $remote; public $hub; public $old = false; public $baseline_caps = []; public $tables = []; public $calls = [];

	public function info( $timeout = null ) {
		return [ 'url' => 'https://p.test', 'abspath' => '/srv/p', 'prefix' => 'wp_', 'algos' => [ 'sha1' ], 'plugin' => $this->old ? '0.9.2' : '0.9.3',
			'caps' => $this->old ? [ 'binary', 'schema' ] : [ 'binary', 'schema', IXES_Hasher::CAP ], 'tables' => $this->tables, 'active_plugins' => [] ];
	}
	public function caps() { return $this->info()['caps']; }

	private static function wp_json( $v ) {
		$j = json_encode( $v );
		return json_decode( $j !== false ? $j : json_encode( self::conv( $v ) ), true );
	}
	private static function conv( $v ) {
		if ( is_string( $v ) ) return mb_convert_encoding( $v, 'UTF-8', 'UTF-8' );
		if ( ! is_array( $v ) ) return $v;
		$o = [];
		foreach ( $v as $k => $x ) $o[ is_string( $k ) ? self::conv( $k ) : $k ] = self::conv( $x );
		return $o;
	}

	public function post( $route, $body, $opts = [] ) {
		$body = self::wp_json( $body );
		$this->calls[] = [ $route, $body['table'] ?? null ];
		if ( count( $this->calls ) > 40 ) return new WP_Error( 'runaway', 'the hub kept paging' );
		$GLOBALS['wpdb'] = $this->remote;
		try {
			$from  = $this->old ? ( $body['from'] ?? null ) : IXES_Rest::cursor_in( $body );
			$cells = ! $this->old && ! empty( $body['cells'] );
			if ( $route === '/hash/rows' ) $r = IXES_Transfer::hash_rows( $body['table'], $from, (int) $body['limit'], [], 'sha1', $cells );
			elseif ( $route === '/dump' ) $r = IXES_Transfer::dump( $body['table'], $from, (int) $body['limit'], 0, $cells );
			else return new WP_Error( 'unscripted', $route );
			if ( $this->old ) unset( $r['byte_keys'] );
			else $r = IXES_Rest::cursor_out( $r );
		} finally {
			$GLOBALS['wpdb'] = $this->hub;
		}
		return self::wp_json( $r );
	}
}

class CursorProgressTest extends TestCase {
	const ROWS = 6000; // more than one 5000-row page
	private $hub; private $remote;

	protected function setUp(): void {
		if ( ! function_exists( 'mb_convert_encoding' ) ) $this->markTestSkipped( 'mbstring' );
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-cur-' . getmypid() . '-' . uniqid();
		mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
		$this->hub = new BinCellsWpdb(); $this->remote = new BinCellsWpdb();
		foreach ( [ $this->hub, $this->remote ] as $db ) {
			// a security plugin's file-mods table: binary(16) digest as the only key
			$db->table( 'wp_filemods', [ 'md5', 'path' ], [ 'md5' ] );
			$db->table( 'wp_ascii', [ 'k', 'v' ], [ 'k' ] );
		}
		for ( $i = 0; $i < self::ROWS; $i++ ) {
			$this->remote->insert( 'wp_filemods', [ 'md5' => "\xff" . pack( 'N', $i ), 'path' => "f{$i}.php" ] );
			$this->remote->insert( 'wp_ascii', [ 'k' => sprintf( 'a%05d', $i ), 'v' => (string) $i ] );
		}
		$GLOBALS['wpdb'] = $this->hub;
	}
	protected function tearDown(): void { unset( $GLOBALS['wpdb'] ); }

	private function client( $old = false ) {
		$c = new CursorRemote( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$c->remote = $this->remote; $c->hub = $this->hub; $c->old = $old;
		$c->tables = [ [ 'name' => 'wp_filemods', 'pk' => 'md5', 'rows' => self::ROWS ], [ 'name' => 'wp_ascii', 'pk' => 'k', 'rows' => self::ROWS ] ];
		return $c;
	}
	private static function env() { return [ 'name' => 'p', 'url' => 'https://p.test', 'extra_replace' => [], 'excludes' => [] ]; }
	private static function db_only() { return IXES_Scope::from_array( [ 'only' => [ 'db' ] ], 'wp_' ); }
	private function legacy_baseline() {
		$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-p.sqlite' );
		$bl->reset(); $bl->meta( 'created_at', time() ); $bl->meta( 'algo', 'sha1' ); $bl->meta( 'baseline_scope', '' );
	}
	private function hash_calls( CursorRemote $c, $table ) {
		return count( array_filter( $c->calls, function ( $x ) use ( $table ) { return $x[0] === '/hash/rows' && $x[1] === $table; } ) );
	}

	public function test_diff_ends_and_skips_a_byte_keyed_table_in_bytes_mode() {
		$c = $this->client();
		$plan = IXES_Planner::build( self::env(), $c, self::db_only() );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertTrue( $plan['bytes_hash'] );
		$this->assertSame( 1, $this->hash_calls( $c, 'wp_filemods' ), 'stops paging once the table is known to be skipped' );
		$this->assertArrayNotHasKey( 'wp_filemods', $plan['tables'] );
		$this->assertNotEmpty( preg_grep( '/wp_filemods: primary key md5 holds bytes/', $plan['warnings'] ) );
		$this->assertCount( self::ROWS, $plan['tables']['wp_ascii']['kept'], 'an ASCII key still pages through every row' );
	}

	public function test_diff_ends_when_the_baseline_predates_byte_hashing() {
		$this->legacy_baseline();
		$c = $this->client();
		$plan = IXES_Planner::build( self::env(), $c, self::db_only() );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertFalse( $plan['bytes_hash'] );
		$this->assertSame( 1, $this->hash_calls( $c, 'wp_filemods' ) );
		$this->assertArrayNotHasKey( 'wp_filemods', $plan['tables'] );
		$this->assertCount( self::ROWS, $plan['tables']['wp_ascii']['kept'] );
	}

	public function test_diff_against_an_older_remote_stops_with_an_error_instead_of_looping() {
		$c = $this->client( true );
		$plan = IXES_Planner::build( self::env(), $c, self::db_only() );
		$this->assertInstanceOf( WP_Error::class, $plan );
		$this->assertSame( 'no_progress', $plan->get_error_code() );
		$this->assertStringContainsString( 'wp_filemods', $plan->get_error_message() );
		$this->assertLessThan( 5, count( $c->calls ) );
	}

	private function pull( CursorRemote $c, IXES_Scope $scope ) {
		$plan = [ 'created' => time(), 'env' => 'p', 'algo' => 'sha1', 'info' => $c->info(), 'bytes_hash' => false,
			'tables' => [ [ 'name' => 'wp_filemods', 'pk' => 'md5', 'rows' => self::ROWS ] ], 'new_tables' => [], 'schema_changes' => [],
			'files' => [ 'transfer' => [], 'delete' => [], 'remote' => [] ], 'sizes' => [], 'seed' => null, 'pairs' => [], 'excludes' => [],
			'extra_replace' => [], 'scope' => $scope->to_array(), 'warnings' => [], 'drop_local' => [] ];
		return IXES_Pull::run( self::env(), $c, $plan, function () {}, null, new IXES_Progress( 'summary', function () {} ) );
	}

	public function test_pull_copies_every_row_of_a_byte_keyed_table() {
		$r = $this->pull( $this->client(), self::db_only() );
		$this->assertTrue( $r, is_wp_error( $r ) ? $r->get_error_message() : '' );
		$got = $this->hub->rows['wp_ixes_tmp_filemods'];
		$this->assertCount( self::ROWS, $got );
		$this->assertSame( array_column( array_values( $this->remote->rows['wp_filemods'] ), 'md5' ), array_column( array_values( $got ), 'md5' ) );
	}

	public function test_scoped_pull_over_an_older_baseline_copies_every_row_too() {
		$this->legacy_baseline();
		$r = $this->pull( $this->client(), IXES_Scope::from_array( [ 'only' => [ 'db' ], 'tables' => [ 'filemods' ] ], 'wp_' ) );
		$this->assertTrue( $r, is_wp_error( $r ) ? $r->get_error_message() : '' );
		$this->assertCount( self::ROWS, $this->hub->rows['wp_ixes_tmp_filemods'] );
	}

	public function test_pull_from_an_older_remote_stops_with_an_error_instead_of_looping() {
		$c = $this->client( true );
		$r = $this->pull( $c, self::db_only() );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'no_progress', $r->get_error_code() );
		$this->assertLessThan( 5, count( $c->calls ) );
	}

	public function test_a_byte_cursor_goes_into_sql_as_hex() {
		$this->hub->table( 'wp_k', [ 'k' ], [ 'k' ] );
		IXES_Transfer::dump( 'wp_k', "\xff\x01", 10 );
		$this->assertStringContainsString( "WHERE `k` > 0xff01 ORDER BY", end( $this->hub->sql ) );
		IXES_Transfer::dump( 'wp_k', "it's", 10 );
		$this->assertStringContainsString( "WHERE `k` > 'it\\'s' ORDER BY", end( $this->hub->sql ) );
	}
}
