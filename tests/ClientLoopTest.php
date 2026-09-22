<?php
use PHPUnit\Framework\TestCase;

class FakeClient extends IXES_Client {
	public $script = [];   // list of callables ($url,$args) => response array | WP_Error
	public $calls  = [];
	public $slept  = [];
	protected function transport( $url, array $args ) {
		$this->calls[] = $args;
		$fn = array_shift( $this->script );
		if ( ! $fn ) throw new RuntimeException( 'unscripted call' );
		return $fn( $url, $args );
	}
	protected function sleep_s( $s ) { $this->slept[] = $s; }
}

class ClientLoopTest extends TestCase {
	private function client() { return new FakeClient( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] ); }
	private static function bin( $body, $total, $sha ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => $body, 'headers' => [ 'x-envsync-total' => (string) $total, 'x-envsync-size' => (string) strlen( $body ), 'x-envsync-sha256' => $sha ] ];
	}

	public function test_fetch_file_streams_chunks_to_writer_and_stops_at_total() {
		$c = $this->client();
		$c->set_caps( [ 'binary' ] );
		$sha = hash( 'sha256', 'helloworld' );
		$c->script = [
			function () use ( $sha ) { return self::bin( 'hello', 10, $sha ); },
			function () use ( $sha ) { return self::bin( 'world', 10, $sha ); },
		];
		$got = [];
		$r = $c->fetch_file( 'a.txt', function ( $offset, $data, $final, $s ) use ( &$got ) { $got[] = [ $offset, $data, $final, $s ]; return true; } );
		$this->assertTrue( $r );
		$this->assertSame( [ [ 0, 'hello', false, $sha ], [ 5, 'world', true, $sha ] ], $got );
		$this->assertSame( 'application/octet-stream', $c->calls[0]['headers']['Accept'] );
		$body = json_decode( $c->calls[1]['body'], true );
		$this->assertSame( 5, $body['offset'] );
	}

	public function test_fetch_file_retries_same_offset_with_smaller_chunk_on_413() {
		$c = $this->client();
		$c->set_caps( [ 'binary' ] );
		$sha = hash( 'sha256', 'abc' );
		$c->script = [
			function () { return [ 'response' => [ 'code' => 413 ], 'body' => 'too big', 'headers' => [] ]; },
			function () use ( $sha ) { return self::bin( 'abc', 3, $sha ); },
		];
		$r = $c->fetch_file( 'a.txt', function () { return true; } );
		$this->assertTrue( $r );
		$first  = json_decode( $c->calls[0]['body'], true );
		$second = json_decode( $c->calls[1]['body'], true );
		$this->assertSame( 0, $second['offset'] );
		$this->assertSame( $first['size'] / 2, $second['size'] );
		$this->assertSame( [ 1 ], $c->slept );
	}

	public function test_fetch_file_gives_up_after_budget_with_offset_in_message() {
		$c = $this->client();
		$c->set_caps( [ 'binary' ] );
		for ( $i = 0; $i < 5; $i++ ) $c->script[] = function () { return new WP_Error( 'http_request_failed', 'cURL error 28' ); };
		$r = $c->fetch_file( 'a.txt', function () { return true; } );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'offset 0', $r->get_error_message() );
		$this->assertStringContainsString( 'cURL error 28', $r->get_error_message() );
	}

	public function test_fetch_file_falls_back_to_json_body_when_remote_lacks_binary() {
		$c = $this->client();
		$c->set_caps( [] ); // 0.2 remote
		$sha = hash( 'sha256', 'xy' );
		$c->script = [ function () use ( $sha ) { return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'data' => base64_encode( 'xy' ), 'size' => 2, 'total' => 2, 'sha256' => $sha ] ) ]; } ];
		$got = [];
		$this->assertTrue( $c->fetch_file( 'a.txt', function ( $o, $d, $f, $s ) use ( &$got ) { $got[] = [ $o, $d, $f ]; return true; } ) );
		$this->assertSame( [ [ 0, 'xy', true ] ], $got );
		$this->assertSame( 'application/json', $c->calls[0]['headers']['Accept'] );
	}

	public function test_fetch_file_does_not_treat_a_json_error_body_as_binary_bytes() {
		$c = $this->client();
		$c->set_caps( [ 'binary' ] );
		$c->script = [ function () {
			return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => json_encode( [ 'code' => 'bad_path', 'message' => 'path refused' ] ) ];
		} ];
		$called = false;
		$r = $c->fetch_file( 'a.txt', function () use ( &$called ) { $called = true; return true; } );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertFalse( $called, 'writer must not be called for an unrecognized response' );
	}

	public function test_fetch_file_errors_when_binary_response_is_missing_total_header() {
		$c = $this->client();
		$c->set_caps( [ 'binary' ] );
		$c->script = [ function () {
			return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => 'hello' ];
		} ];
		$called = false;
		$r = $c->fetch_file( 'a.txt', function () use ( &$called ) { $called = true; return true; } );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'X-Envsync-Total', $r->get_error_message() );
		$this->assertFalse( $called, 'writer must not be called when the total header is missing' );
	}

	public function test_send_file_puts_metadata_in_step_header_and_bytes_in_body() {
		$c = $this->client();
		$c->set_caps( [ 'binary' ] );
		$tmp = tempnam( sys_get_temp_dir(), 'ixes' ); file_put_contents( $tmp, 'payload' );
		$c->script = [ function () { return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'ok' => true ] ) ]; } ];
		$r = $c->send_file( 'job1', 'themes/x/a.txt', $tmp, [ 'expect' => 'h', 'algo' => 'sha1' ] );
		$this->assertSame( [ 'ok' => true, 'refused' => false ], $r );
		$a = $c->calls[0];
		$this->assertSame( 'payload', $a['body'] );
		$this->assertSame( 'application/octet-stream', $a['headers']['Content-Type'] );
		$step = json_decode( $a['headers']['X-Envsync-Step'], true );
		$this->assertSame( [ 'job' => 'job1', 'kind' => 'file', 'path' => 'themes/x/a.txt', 'offset' => 0, 'final' => true, 'sha256' => hash( 'sha256', 'payload' ), 'expect' => 'h', 'algo' => 'sha1' ], $step );
		unlink( $tmp );
	}

	public function test_send_file_reports_refusal_and_stops() {
		$c = $this->client();
		$c->set_caps( [ 'binary' ] );
		$tmp = tempnam( sys_get_temp_dir(), 'ixes' ); file_put_contents( $tmp, str_repeat( 'z', 3000000 ) );
		$c->script = [ function () { return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'ok' => false, 'refused' => [ 'themes/x/a.txt' ] ] ) ]; } ];
		$r = $c->send_file( 'job1', 'themes/x/a.txt', $tmp, [] );
		$this->assertSame( [ 'ok' => false, 'refused' => true ], $r );
		$this->assertCount( 1, $c->calls, 'no second chunk after a refusal' );
		unlink( $tmp );
	}

	public function test_send_file_json_fallback_for_old_remote() {
		$c = $this->client();
		$c->set_caps( [] );
		$tmp = tempnam( sys_get_temp_dir(), 'ixes' ); file_put_contents( $tmp, 'p' );
		$c->script = [ function () { return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'ok' => true ] ) ]; } ];
		$c->send_file( 'j', 'a.txt', $tmp, [] );
		$b = json_decode( $c->calls[0]['body'], true );
		$this->assertSame( base64_encode( 'p' ), $b['data'] );
		$this->assertSame( 'file', $b['kind'] );
		unlink( $tmp );
	}
}
