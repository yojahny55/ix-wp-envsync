<?php
use PHPUnit\Framework\TestCase;

class RetryClient extends IXES_Client {
	public $script = [];   // list of responses (array | WP_Error), one per transport call
	public $calls  = [];
	public $slept  = [];
	protected function transport( $url, array $args ) {
		$this->calls[] = [ $url, $args ];
		if ( ! $this->script ) throw new RuntimeException( 'unscripted call' );
		return array_shift( $this->script );
	}
	protected function sleep_s( $s ) { $this->slept[] = $s; }
}

class RetryTest extends TestCase {
	private function client( array $script ) {
		$c = new RetryClient( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$c->script = $script;
		return $c;
	}
	private static function ok( array $json = [ 'ok' => true ] ) { return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $json ), 'headers' => [] ]; }
	private static function status( $code ) { return [ 'response' => [ 'code' => $code ], 'body' => '<p>Bad gateway</p>', 'headers' => [] ]; }
	private static function curl( $msg ) { return new WP_Error( 'http_request_failed', $msg ); }

	public function test_a_resolve_timeout_is_retried_on_any_route() {
		$c = $this->client( [ self::curl( 'cURL error 28: Resolving timed out after 10001 milliseconds' ), self::ok() ] );
		$this->assertSame( [ 'ok' => true ], $c->post( '/job/step', [ 'job' => 'j' ] ) );
		$this->assertCount( 2, $c->calls );
		$this->assertSame( [ 2 ], $c->slept ); // first contact: a wrong host fails as fast as before
	}

	public function test_connect_failures_are_retried_on_a_write_route() {
		$c = $this->client( [
			self::ok(),
			self::curl( 'cURL error 6: Could not resolve host: p.test' ),
			self::curl( 'cURL error 7: Failed to connect to p.test port 443' ),
			self::curl( 'cURL error 28: Connection timed out after 10001 milliseconds' ),
			self::ok(),
		] );
		$c->get( '/ping' );
		$this->assertSame( [ 'ok' => true ], $c->post( '/job/start', [] ) );
		$this->assertSame( [ 5, 15, 30 ], $c->slept ); // the remote answered before: the network dropped, wait longer
	}

	public function test_a_connect_failure_gives_up_after_five_retries_and_says_how_many_attempts() {
		$e = self::curl( 'cURL error 28: Connection timed out after 10001 milliseconds' );
		$c = $this->client( [ self::ok(), $e, $e, $e, $e, $e, $e ] );
		$c->get( '/ping' );
		$r = $c->post( '/job/step', [ 'job' => 'j' ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( [ 5, 15, 30, 60, 120 ], $c->slept );
		$this->assertStringContainsString( 'after 6 attempts', $r->get_error_message() );
		$this->assertStringContainsString( 'Connection timed out', $r->get_error_message() );
	}

	public function test_a_wrong_host_on_first_contact_gives_up_after_the_short_waits() {
		$e = self::curl( 'cURL error 6: Could not resolve host: p.test' );
		$c = $this->client( [ $e, $e, $e, $e ] );
		$r = $c->get( '/info' );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( [ 2, 5, 15 ], $c->slept );
		$this->assertStringContainsString( 'after 4 attempts', $r->get_error_message() );
	}

	public function test_an_error_status_counts_as_the_remote_answering() {
		$c = $this->client( [ self::status( 404 ), self::curl( 'cURL error 7: Failed to connect to p.test port 443' ), self::ok() ] );
		$c->get( '/missing' );
		$this->assertSame( [ 'ok' => true ], $c->post( '/job/step', [ 'job' => 'j' ] ) );
		$this->assertSame( [ 5 ], $c->slept );
	}

	public function test_a_read_timeout_still_uses_the_short_waits() {
		$e = self::curl( 'cURL error 28: Operation timed out after 120000 milliseconds with 0 bytes received' );
		$c = $this->client( [ $e, $e, $e, $e ] );
		$this->assertInstanceOf( WP_Error::class, $c->post( '/hash/tables', [] ) );
		$this->assertSame( [ 2, 5, 15 ], $c->slept );
	}

	public function test_no_retry_skips_the_network_wait_too() {
		$c = $this->client( [ self::curl( 'cURL error 7: Failed to connect to p.test port 443' ) ] );
		$this->assertInstanceOf( WP_Error::class, $c->get( '/jobs', [ 'no_retry' => true ] ) );
		$this->assertSame( [], $c->slept );
	}

	public function test_a_gateway_error_is_retried_on_a_read_route() {
		$c = $this->client( [ self::status( 503 ), self::status( 502 ), self::ok( [ 'rows' => [] ] ) ] );
		$this->assertSame( [ 'rows' => [] ], $c->post( '/hash/rows', [ 'table' => 'wp_posts' ] ) );
		$this->assertCount( 3, $c->calls );
	}

	public function test_a_gateway_error_on_a_write_route_is_not_retried() {
		// the remote may have applied it: the applier decides, not the transport
		$c = $this->client( [ self::status( 504 ), self::ok() ] );
		$r = $c->post( '/job/step', [ 'job' => 'j' ] );
		$this->assertSame( 'remote_504', $r->get_error_code() );
		$this->assertCount( 1, $c->calls );
	}

	public function test_an_operation_timeout_on_a_write_route_is_not_retried() {
		$c = $this->client( [ self::curl( 'cURL error 28: Operation timed out after 300000 milliseconds with 0 bytes received' ), self::ok() ] );
		$this->assertInstanceOf( WP_Error::class, $c->post( '/job/finish', [ 'job' => 'j' ] ) );
		$this->assertCount( 1, $c->calls );
	}

	public function test_a_500_is_never_retried() {
		// WordPress's critical-error page: a real crash, not the network
		$c = $this->client( [ self::status( 500 ), self::ok() ] );
		$this->assertSame( 'remote_500', $c->post( '/hash/rows', [ 'table' => 'wp_posts' ] )->get_error_code() );
		$this->assertCount( 1, $c->calls );
	}

	public function test_info_is_retried() {
		$c = $this->client( [ self::curl( 'cURL error 28: Resolving timed out after 10001 milliseconds' ), self::ok( [ 'plugin' => '0.9.5', 'algos' => [ 'sha1' ], 'tables' => [], 'prefix' => 'wp_' ] ) ] );
		$this->assertSame( '0.9.5', $c->get( '/info' )['plugin'] );
		$this->assertCount( 2, $c->calls );
	}

	public function test_paging_halves_the_page_after_a_timeout_and_keeps_the_cursor() {
		$c = $this->client( [
			self::ok( [ 'rows' => [ 1 => 'a' ], 'next' => 1 ] ),
			self::curl( 'cURL error 28: Operation timed out after 300000 milliseconds with 3766839 bytes received' ),
			self::ok( [ 'rows' => [ 2 => 'b' ], 'next' => null ] ),
		] );
		$rows = [];
		$r = $c->paged( '/dump', [ 'table' => 'wp_posts', 'limit' => 5000, 'bytes' => 8388608 ], function ( $res ) use ( &$rows ) { $rows += $res['rows']; } );
		$this->assertNull( $r );
		$this->assertSame( [ 1 => 'a', 2 => 'b' ], $rows );
		$retry = json_decode( $c->calls[2][1]['body'], true );
		$this->assertSame( 1, $retry['from'] );
		$this->assertLessThan( 5000, $retry['limit'] );
		$this->assertSame( 4194304, $retry['bytes'] );
	}

	public function test_paging_reports_the_table_and_cursor_when_it_gives_up() {
		$e = self::curl( 'cURL error 28: Operation timed out after 300000 milliseconds with 3766839 bytes received' );
		$c = $this->client( [ self::ok( [ 'rows' => [], 'next' => 7 ] ), $e, $e, $e, $e ] );
		$r = $c->paged( '/dump', [ 'table' => 'wp_postmeta', 'limit' => 5000 ], function () {} );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'wp_postmeta', $r->get_error_message() );
		$this->assertStringContainsString( '--timeout', $r->get_error_message() );
	}

	public function test_paging_shrinks_the_page_after_a_500_and_never_grows_back() {
		$c = $this->client( [
			self::status( 500 ),
			self::ok( [ 'rows' => [ 1 => 'a' ], 'next' => 1 ] ),
			self::ok( [ 'rows' => [ 2 => 'b' ], 'next' => null ] ),
		] );
		$rows = [];
		$r = $c->paged( '/dump', [ 'table' => 'wp_posts', 'limit' => 5000, 'bytes' => 4194304 ], function ( $res ) use ( &$rows ) { $rows += $res['rows']; } );
		$this->assertNull( $r );
		$this->assertSame( [ 1 => 'a', 2 => 'b' ], $rows );
		$retry = json_decode( $c->calls[1][1]['body'], true );
		$this->assertNull( $retry['from'] );
		$this->assertSame( 1250, $retry['limit'] );
		$this->assertSame( 1048576, $retry['bytes'] );
		// a fast page would double the limit again, but not past the size that crashed
		$this->assertSame( 1250, json_decode( $c->calls[2][1]['body'], true )['limit'] );
	}

	public function test_paging_gives_up_on_a_500_at_the_page_floor() {
		$c = $this->client( [ self::status( 500 ), self::ok() ] );
		$r = $c->paged( '/dump', [ 'table' => 'wp_posts', 'limit' => 100 ], function () {} );
		$this->assertSame( 'remote_500', $r->get_error_code() );
		$this->assertCount( 1, $c->calls );
	}

	public function test_paging_names_memory_limit_when_smaller_pages_still_crash() {
		$e = self::status( 500 );
		$c = $this->client( [ $e, $e, $e, $e ] );
		$r = $c->paged( '/dump', [ 'table' => 'wp_posts', 'limit' => 5000 ], function () {} );
		$this->assertSame( 'remote_500', $r->get_error_code() );
		$this->assertStringContainsString( 'memory_limit', $r->get_error_message() );
	}

	public function test_paging_does_not_retry_a_500_on_a_write_route() {
		$c = $this->client( [ self::status( 500 ), self::ok() ] );
		$r = $c->paged( '/job/step', [ 'limit' => 5000 ], function () {} );
		$this->assertSame( 'remote_500', $r->get_error_code() );
		$this->assertCount( 1, $c->calls );
	}

	public function test_paging_retries_a_failed_first_page() {
		$c = $this->client( [ self::status( 503 ), self::ok( [ 'rows' => [ 1 => 'a' ], 'next' => null ] ) ] );
		$rows = [];
		$this->assertNull( $c->paged( '/dump', [ 'table' => 'wp_posts', 'limit' => 5000 ], function ( $res ) use ( &$rows ) { $rows += $res['rows']; } ) );
		$this->assertSame( [ 1 => 'a' ], $rows );
	}
	/** #93: a connect or DNS failure says nothing about the page size; it waits longer for the network instead. */
	public function test_paging_keeps_the_page_and_waits_longer_when_the_network_drops() {
		$c = $this->client( [
			self::ok( [ 'rows' => [ 1 => 'a' ], 'next' => 1 ] ),
			self::curl( 'cURL error 28: Connection timed out after 10000 milliseconds' ),
			self::curl( 'cURL error 28: Resolving timed out after 10001 milliseconds' ),
			self::curl( 'cURL error 6: Could not resolve host: p.test' ),
			self::curl( 'cURL error 7: Failed to connect to p.test port 443' ),
			self::ok( [ 'rows' => [ 2 => 'b' ], 'next' => null ] ),
		] );
		$rows = [];
		$r = $c->paged( '/hash/rows', [ 'table' => 'wp_posts', 'limit' => 5000, 'bytes' => 8388608 ], function ( $res ) use ( &$rows ) { $rows += $res['rows']; } );
		$this->assertNull( $r );
		$this->assertSame( [ 1 => 'a', 2 => 'b' ], $rows );
		$this->assertSame( [ 5, 15, 30, 60 ], $c->slept );
		$first = json_decode( $c->calls[1][1]['body'], true );
		foreach ( [ 2, 3, 4, 5 ] as $n ) {
			$retry = json_decode( $c->calls[ $n ][1]['body'], true );
			$this->assertSame( 1, $retry['from'] );
			$this->assertSame( $first['limit'], $retry['limit'] );
			$this->assertSame( 8388608, $retry['bytes'] );
		}
	}

	public function test_paging_gives_up_after_the_network_wait_and_says_to_relaunch() {
		$e = self::curl( 'cURL error 28: Resolving timed out after 10001 milliseconds' );
		$c = $this->client( [ self::ok( [ 'rows' => [], 'next' => 637 ] ), $e, $e, $e, $e, $e, $e ] );
		$r = $c->paged( '/hash/rows', [ 'table' => 'wp_posts', 'limit' => 5000 ], function () {} );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( [ 5, 15, 30, 60, 120 ], $c->slept );
		$this->assertSame( 5000, json_decode( $c->calls[6][1]['body'], true )['limit'] );
		$m = $r->get_error_message();
		$this->assertStringContainsString( 'after 6 attempt(s)', $m );
		$this->assertStringContainsString( 'page after 637', $m );
		$this->assertStringContainsString( 'network dropped', $m );
		$this->assertStringContainsString( 'Relaunch', $m );
		$this->assertStringNotContainsString( '--timeout', $m );
	}

	public function test_paging_still_halves_the_page_on_a_transfer_timeout_with_the_short_wait() {
		$e = self::curl( 'cURL error 28: Operation timed out after 120000 milliseconds with 0 bytes received' );
		$c = $this->client( [ self::ok( [ 'rows' => [], 'next' => 1 ] ), $e, $e, $e, $e ] );
		$r = $c->paged( '/hash/rows', [ 'table' => 'wp_posts', 'limit' => 5000 ], function () {} );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( [ 2, 5, 15 ], $c->slept );
		$this->assertSame( 625, json_decode( $c->calls[4][1]['body'], true )['limit'] );
		$this->assertStringContainsString( 'larger --timeout', $r->get_error_message() );
		$this->assertStringNotContainsString( 'network dropped', $r->get_error_message() );
	}

	public function test_unreached_tells_connect_failures_from_transfer_timeouts() {
		foreach ( [ 'cURL error 6: Could not resolve host: p.test', 'cURL error 7: Failed to connect to p.test port 443', 'cURL error 28: Connection timed out after 10000 milliseconds', 'cURL error 28: Resolving timed out after 10001 milliseconds' ] as $m ) {
			$this->assertTrue( IXES_Client::unreached( self::curl( $m ) ), $m );
		}
		foreach ( [ 'cURL error 28: Operation timed out after 120000 milliseconds with 0 bytes received', 'cURL error 56: Connection reset by peer', 'cURL error 52: Empty reply from server' ] as $m ) {
			$this->assertFalse( IXES_Client::unreached( self::curl( $m ) ), $m );
		}
		$this->assertFalse( IXES_Client::unreached( new WP_Error( 'remote_503', 'remote 503 on /dump: Connection timed out', [ 'status' => 503 ] ) ) );
	}

	public function test_connect_timeout_defaults_to_30_and_never_exceeds_the_request_timeout() {
		$c = $this->client( [ self::ok() ] );
		$c->post( '/job/finish', [] );
		$this->assertSame( 120, $c->calls[0][1]['timeout'] );
		$this->assertSame( 30, $c->calls[0][1]['connect_timeout'] );
		$this->assertSame( 30, $c->connect_timeout( 600 ) );
		$this->assertSame( 5, $c->connect_timeout( 5 ) );
	}

	public function test_connect_timeout_comes_from_the_env() {
		$c = new RetryClient( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ), 'timeout' => 600, 'connect_timeout' => 90 ] );
		$c->script = [ self::ok() ];
		$c->post( '/job/finish', [] );
		$this->assertSame( 90, $c->calls[0][1]['connect_timeout'] );
		$this->assertSame( 20, $c->connect_timeout( 20 ) );
	}
}
