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
		$this->assertSame( [ 2 ], $c->slept );
	}

	public function test_connect_failures_are_retried_on_a_write_route() {
		$c = $this->client( [
			self::curl( 'cURL error 6: Could not resolve host: p.test' ),
			self::curl( 'cURL error 7: Failed to connect to p.test port 443' ),
			self::curl( 'cURL error 28: Connection timed out after 10001 milliseconds' ),
			self::ok(),
		] );
		$this->assertSame( [ 'ok' => true ], $c->post( '/job/start', [] ) );
		$this->assertSame( [ 2, 5, 15 ], $c->slept );
	}

	public function test_gives_up_after_three_retries_and_says_how_many_attempts() {
		$e = self::curl( 'cURL error 28: Connection timed out after 10001 milliseconds' );
		$c = $this->client( [ $e, $e, $e, $e ] );
		$r = $c->post( '/job/step', [ 'job' => 'j' ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'after 4 attempts', $r->get_error_message() );
		$this->assertStringContainsString( 'Connection timed out', $r->get_error_message() );
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
}
