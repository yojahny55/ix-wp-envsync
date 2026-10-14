<?php
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $m; private $route; private $h; private $body;
		public function __construct( $m, $route, array $h, $body ) { $this->m = $m; $this->route = $route; $this->h = array_change_key_case( $h ); $this->body = $body; }
		public function get_method() { return $this->m; }
		public function get_route() { return $this->route; }
		public function get_header( $k ) { return isset( $this->h[ strtolower( $k ) ] ) ? (string) $this->h[ strtolower( $k ) ] : null; }
		public function get_body() { return $this->body; }
	}
}
if ( ! function_exists( 'is_ssl' ) ) { function is_ssl() { return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['ixes_test_transients'][ $k ] ); return true; } }

/** A hub whose transport is a remote: every request goes through the real IXES_Rest::auth(). */
class SessionHub extends IXES_Client {
	public $calls = [];
	public $old_remote = false; // a remote from before sessions: no /session route, no public key, no cap
	protected function transport( $url, array $args ) {
		$this->calls[] = $args['headers'];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		if ( ! isset( $q['rest_route'] ) ) return self::res( 200, [ 'ok' => true ] ); // rescue.php
		$route = (string) $q['rest_route'];
		$short = substr( $route, strlen( '/' . IXES_Rest::NS ) );
		if ( $this->old_remote && $short === '/session' ) return self::res( 404, [ 'code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.' ] );
		$ok = IXES_Rest::auth( new WP_REST_Request( $args['method'], $route, $args['headers'], $args['body'] ?? '' ) );
		if ( $ok instanceof WP_Error ) return self::res( $ok->get_error_data()['status'], [ 'code' => $ok->get_error_code(), 'message' => $ok->get_error_message() ] );
		if ( $this->old_remote ) $GLOBALS['ixes_test_options'][ IXES_Auth::PUB_OPTION ] = '';
		switch ( $short ) {
			case '/session':
				$r = IXES_Rest::session();
				return $r instanceof WP_Error ? self::res( $r->get_error_data()['status'], [ 'message' => $r->get_error_message() ] ) : self::res( 200, $r );
			case '/info':
				$caps = [ 'nonce' ];
				if ( IXES_Auth::current_pub() !== '' ) $caps[] = IXES_Auth::CAP;
				return self::res( 200, [ 'plugin' => '1.0', 'caps' => $caps ] );
			default:
				return self::res( 200, [ 'ok' => true, 'time' => 1, 'auth_via' => IXES_Rest::auth_via() ] );
		}
	}
	protected function sleep_s( $s ) {}
	private static function res( $code, array $body ) { return [ 'response' => [ 'code' => $code ], 'body' => json_encode( $body ), 'headers' => [] ]; }
	public function rescue_call() { return $this->rescue( 'status' ); }
}

class SessionTest extends TestCase {
	private $tok;

	protected function setUp(): void {
		if ( ! IXES_Auth::can_sign() ) $this->markTestSkipped( 'no sodium: set SODIUM_COMPAT' );
		$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? (object) [ 'prefix' => 'wp_' ];
		$GLOBALS['ixes_test_transients'] = [];
		$this->tok = IXES_Auth::generate_token();
		ixes_test_set_token( $this->tok, false );
	}

	private function hub( array $extra = [] ) {
		$c = new SessionHub( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => $this->tok ] + $extra );
		$c->hub_prefix = '';
		return $c;
	}

	private static function carries_token( array $h ) {
		return isset( $h['X-Envsync-Token'] ) || ( isset( $h['Authorization'] ) && stripos( $h['Authorization'], 'Bearer' ) === 0 );
	}

	public function test_an_upgraded_remote_learns_the_key_from_the_last_token_it_will_see() {
		$c = $this->hub();
		$this->assertIsArray( $c->info() );
		$this->assertTrue( self::carries_token( $c->calls[0] ), 'the first request still carries the token' );
		$this->assertSame( IXES_Auth::public_key( $this->tok ), get_option( IXES_Auth::PUB_OPTION ) );
		$this->assertTrue( $c->keyed() );
		$c->calls = [];
		$this->assertSame( 'session', $c->get( '/ping' )['auth_via'] );
		$this->assertSame( 'ed25519', $c->calls[0]['X-Envsync-Auth'], 'the session is opened with the key' );
		$this->assertArrayHasKey( 'X-Envsync-Session', $c->calls[1] );
		foreach ( $c->calls as $h ) $this->assertFalse( self::carries_token( $h ) );
		$c->get( '/ping' );
		$this->assertCount( 3, $c->calls, 'the session is reused' );
	}

	public function test_a_key_left_by_an_old_rotation_is_replaced_and_stops_signing() {
		$t1 = IXES_Auth::generate_token();
		ixes_test_set_token( $t1 );
		// rotated by a version from before the key: the hash moved to our token, the key stayed with t1
		$GLOBALS['ixes_test_options']['ixes_token_hash'] = wp_hash( $this->tok );
		$old = new SessionHub( [ 'name' => 'o', 'url' => 'https://p.test', 'token' => $t1, 'keyed' => true ] );
		$old->hub_prefix = '';
		$this->assertInstanceOf( WP_Error::class, $old->get( '/ping' ), 'the revoked token cannot open a session' );
		$c = $this->hub( [ 'probe' => true ] );
		$this->assertIsArray( $c->info() );
		$this->assertTrue( $c->keyed() );
		$this->assertSame( IXES_Auth::public_key( $this->tok ), IXES_Auth::current_pub() );
		$this->assertSame( 'session', $c->get( '/ping' )['auth_via'], 'the current token gets its session' );
	}

	public function test_after_env_add_a_remote_that_holds_the_key_never_sees_the_token() {
		ixes_test_set_token( $this->tok );
		$c = $this->hub( [ 'probe' => true ] );
		$this->assertIsArray( $c->info() );
		$this->assertTrue( $c->keyed() );
		$this->assertSame( 'ed25519', $c->calls[0]['X-Envsync-Auth'] );
		foreach ( $c->calls as $h ) $this->assertFalse( self::carries_token( $h ) );
	}

	public function test_an_old_remote_gets_the_token_as_before() {
		$c = $this->hub( [ 'probe' => true ] );
		$c->old_remote = true;
		$this->assertIsArray( $c->info() );
		$this->assertFalse( $c->keyed() );
		$this->assertTrue( self::carries_token( end( $c->calls ) ) );
		$n = count( $c->calls );
		$c->get( '/ping' );
		$this->assertCount( $n + 1, $c->calls, 'probed once, not before every request' );
	}

	public function test_a_keyed_hub_fails_closed_when_the_remote_lost_the_key() {
		$c = $this->hub( [ 'keyed' => true ] ); // remote restored from a backup taken before it learned the key
		$r = $c->info();
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'wp envsync env add p --token=', $r->get_error_message() );
		foreach ( $c->calls as $h ) $this->assertFalse( self::carries_token( $h ) );
	}

	public function test_an_expired_session_is_opened_again() {
		ixes_test_set_token( $this->tok );
		$c = $this->hub( [ 'keyed' => true ] );
		$c->get( '/ping' );
		$GLOBALS['ixes_test_transients'] = [];
		$this->assertSame( 'session', $c->get( '/ping' )['auth_via'] );
		$opens = array_filter( $c->calls, function ( $h ) { return isset( $h['X-Envsync-Auth'] ); } );
		$this->assertCount( 2, $opens );
	}

	public function test_rotating_the_token_ends_the_session() {
		ixes_test_set_token( $this->tok );
		$c = $this->hub( [ 'keyed' => true ] );
		$c->get( '/ping' );
		ixes_test_set_token( IXES_Auth::generate_token() );
		$this->assertInstanceOf( WP_Error::class, $c->get( '/ping' ) );
	}

	public function test_only_the_key_opens_a_session() {
		ixes_test_set_token( $this->tok );
		$this->hub()->info(); // signed with the token
		$this->assertSame( 403, IXES_Rest::session()->get_error_data()['status'] );
	}

	public function test_a_session_request_without_a_nonce_or_seen_twice_is_refused() {
		ixes_test_set_token( $this->tok );
		$s = IXES_Auth::open_session( IXES_Auth::current_pub() );
		$route = '/' . IXES_Rest::NS . '/ping'; $ts = time();
		$req = function ( $nonce ) use ( $s, $route, $ts ) {
			$h = [ 'X-Envsync-Session' => $s['session'], 'X-Envsync-Ts' => $ts, 'X-Envsync-Sig' => IXES_Auth::sign( $s['key'], 'GET', $route, $ts, '', '', '', '', $nonce ) ];
			if ( $nonce !== '' ) $h['X-Envsync-Nonce'] = $nonce;
			return IXES_Rest::auth( new WP_REST_Request( 'GET', $route, $h, '' ) );
		};
		$this->assertInstanceOf( WP_Error::class, $req( '' ) );
		$n = IXES_Auth::new_nonce();
		$this->assertTrue( $req( $n ) );
		$this->assertSame( 'replayed request', $req( $n )->get_error_message() );
	}

	public function test_a_keyed_rescue_sends_no_token() {
		$c = $this->hub( [ 'keyed' => true ] );
		$c->old_remote = false;
		ixes_test_set_token( $this->tok );
		$c->rescue_call(); // rescue.php is not routed here; only what the hub sends matters
		$h = $c->calls[0];
		$this->assertFalse( self::carries_token( $h ) );
		$this->assertSame( 'ed25519', $h['X-Envsync-Auth'] );
		$this->assertArrayNotHasKey( 'X-Envsync-Nonce', $h, 'rescue.php keeps no nonces' );
	}
}
