<?php
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase {
	private $tok;
	protected function setUp(): void { $this->tok = IXES_Auth::generate_token(); }

	public function test_token_is_64_hex() { $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $this->tok ); }

	public function test_valid_signature_verifies() {
		$ts = 1000; $body = '{"a":1}';
		$sig = IXES_Auth::sign( $this->tok, 'post', '/envsync/v1/info', $ts, $body );
		$this->assertTrue( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/envsync/v1/info', $ts, $body, $sig, 1100 ) );
	}
	public function test_wrong_token_fails() {
		$sig = IXES_Auth::sign( $this->tok, 'GET', '/x', 1, '' );
		$this->assertFalse( IXES_Auth::verify( wp_hash( 'other' ), $this->tok, 'GET', '/x', 1, '', $sig, 1 ) );
	}
	public function test_tampered_body_fails() {
		$sig = IXES_Auth::sign( $this->tok, 'POST', '/x', 1, 'a' );
		$this->assertFalse( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/x', 1, 'b', $sig, 1 ) );
	}
	public function test_old_timestamp_fails() {
		$sig = IXES_Auth::sign( $this->tok, 'GET', '/x', 1000, '' );
		$this->assertFalse( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'GET', '/x', 1000, '', $sig, 1301 ) );
		$this->assertTrue(  IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'GET', '/x', 1000, '', $sig, 1300 ) );
	}
	public function test_https_required_unless_constant() {
		$this->assertTrue( IXES_Auth::https_ok( 'https://a.com' ) );
		$this->assertFalse( IXES_Auth::https_ok( 'http://a.local' ) );
		define( 'ENVSYNC_ALLOW_HTTP', true );
		$this->assertTrue( IXES_Auth::https_ok( 'http://a.local' ) );
	}
	public function test_step_header_is_signed() {
		$step = '{"job":"j1","kind":"file","path":"a.txt","offset":0}';
		$sig  = IXES_Auth::sign( $this->tok, 'POST', '/envsync/v1/job/step', 5, 'bytes', $step );
		$this->assertTrue( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/envsync/v1/job/step', 5, 'bytes', $sig, 5, $step ) );
		$this->assertFalse( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/envsync/v1/job/step', 5, 'bytes', $sig, 5, '{"job":"j2"}' ), 'tampered step header must fail' );
	}
	public function test_old_client_signature_without_step_still_verifies() {
		// a 0.2.x hub signs METHOD\nPATH\nTS\nsha256(body) with no fifth line
		$msg = "POST\n/x\n1\n" . hash( 'sha256', 'b' );
		$old = hash_hmac( 'sha256', $msg, $this->tok );
		$this->assertTrue( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/x', 1, 'b', $old, 1 ) );
	}
	public function test_token_from_authorization_header() {
		$this->assertSame( [ 'abc', 'authorization' ], IXES_Auth::token_from_headers( 'Bearer abc', '' ) );
	}
	public function test_token_falls_back_to_x_envsync_token() {
		$this->assertSame( [ 'abc', 'x-envsync-token' ], IXES_Auth::token_from_headers( '', 'abc' ) );
		$this->assertSame( [ 'abc', 'x-envsync-token' ], IXES_Auth::token_from_headers( 'Basic zzz', 'abc' ), 'a non-Bearer Authorization header is ignored' );
	}
	public function test_authorization_wins_when_both_present() {
		$this->assertSame( [ 'one', 'authorization' ], IXES_Auth::token_from_headers( 'Bearer one', 'two' ) );
	}
	public function test_no_token_in_either_header() {
		$this->assertSame( [ '', null ], IXES_Auth::token_from_headers( '', '' ) );
	}

	public function test_prefix_is_signed() {
		$sig = IXES_Auth::sign( $this->tok, 'POST', '/x', 1, 'b', '', 'ab_' );
		$this->assertTrue( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/x', 1, 'b', $sig, 1, '', 'ab_' ) );
		$this->assertFalse( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/x', 1, 'b', $sig, 1, '', '' ), 'header stripped' );
		$this->assertFalse( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/x', 1, 'b', $sig, 1, '', 'cd_' ), 'header swapped' );
	}
	public function test_no_prefix_keeps_the_old_signature() {
		$msg = "POST\n/x\n1\n" . hash( 'sha256', 'b' );
		$this->assertSame( hash_hmac( 'sha256', $msg, $this->tok ), IXES_Auth::sign( $this->tok, 'POST', '/x', 1, 'b', '', '' ) );
		$this->assertSame( hash_hmac( 'sha256', $msg . "\nstep", $this->tok ), IXES_Auth::sign( $this->tok, 'POST', '/x', 1, 'b', 'step' ) );
	}
}
