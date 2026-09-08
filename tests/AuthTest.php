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
}
