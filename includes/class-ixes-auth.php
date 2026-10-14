<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Auth {
	const SKEW = 300;
	// The remote's public key. The token never reaches it again once a hub signs with the matching private key.
	const PUB_OPTION = 'ixes_sign_pub';
	// /info lists this when the remote holds a public key and opens signed sessions: see open_session().
	const CAP = 'session';
	const SESSION_TTL = 21600; // six hours: a long push outlives one hour, and the hub opens a new one before this runs out

	public static function generate_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	public static function install_token() {
		$token = self::generate_token();
		update_option( 'ixes_token_hash', wp_hash( $token ), false );
		// the old key goes with the old token, and with it every session it opened (session() checks the key)
		update_option( self::PUB_OPTION, self::can_sign() ? self::public_key( $token ) : '', false );
		// the old token must stop opening rescue.php's bare path too
		if ( function_exists( 'ixes_storage_dir' ) ) IXES_Mu::write_key( ixes_storage_dir(), $token, defined( 'ENVSYNC_ALLOW_HTTP' ) && ENVSYNC_ALLOW_HTTP, (string) get_option( self::PUB_OPTION ) );
		set_transient( 'ixes_token_show', $token, 600 );
		return $token;
	}

	// $step: raw X-Envsync-Step header or ''. Old clients send none; the message then ends exactly
	// as it did in 0.2 (no trailing "\n"), so their signatures keep verifying.
	// $prefix: the hub's table prefix when it differs from the remote's (X-Envsync-Prefix), else ''.
	// $excl: the raw X-Envsync-Exclude-Options header, else ''. Signed so nobody in between can strip or widen it.
	// $nonce: X-Envsync-Nonce, sent only to a remote with the 'nonce' cap. Signed, so it cannot be stripped to dodge seen_nonce().
	public static function sign( $token, $method, $path, $ts, $body, $step = '', $prefix = '', $excl = '', $nonce = '' ) {
		return hash_hmac( 'sha256', self::message( $method, $path, $ts, $body, $step, $prefix, $excl, $nonce ), $token );
	}

	/** What every signature covers, whichever key signs it. */
	public static function message( $method, $path, $ts, $body, $step = '', $prefix = '', $excl = '', $nonce = '' ) {
		$msg = strtoupper( $method ) . "\n" . $path . "\n" . (int) $ts . "\n" . hash( 'sha256', (string) $body );
		if ( (string) $step !== '' ) $msg .= "\n" . $step;
		if ( (string) $prefix !== '' ) $msg .= "\nprefix:" . $prefix;
		if ( (string) $excl !== '' ) $msg .= "\nexclude-options:" . $excl;
		if ( (string) $nonce !== '' ) $msg .= "\nnonce:" . $nonce;
		return $msg;
	}

	public static function verify( $token_hash, $presented_token, $method, $path, $ts, $body, $sig, $now = null, $step = '', $prefix = '', $excl = '', $nonce = '' ) {
		if ( ! is_string( $token_hash ) || ! is_string( $presented_token ) || $presented_token === '' ) return false;
		if ( ! hash_equals( $token_hash, wp_hash( $presented_token ) ) ) return false;
		return self::verify_hmac( $presented_token, $method, $path, $ts, $body, $sig, $now, $step, $prefix, $excl, $nonce );
	}

	/** An HMAC under a key both sides already hold: the token (old hubs) or a session key. */
	public static function verify_hmac( $key, $method, $path, $ts, $body, $sig, $now = null, $step = '', $prefix = '', $excl = '', $nonce = '' ) {
		if ( $now === null ) $now = time();
		if ( ! is_string( $key ) || $key === '' || abs( $now - (int) $ts ) > self::SKEW ) return false;
		return hash_equals( self::sign( $key, $method, $path, $ts, $body, $step, $prefix, $excl, $nonce ), (string) $sig );
	}

	/**
	 * Ed25519, so the remote keeps only a public key: neither a captured request nor the remote's own database or
	 * storage folder can sign for the hub. WordPress ships sodium_compat, so this holds without the PHP extension,
	 * but there a signature costs ~25 ms: the hub signs once per session with it, and HMACs every request after.
	 */
	public static function can_sign() {
		return function_exists( 'sodium_crypto_sign_seed_keypair' ) && function_exists( 'sodium_crypto_sign_verify_detached' );
	}

	private static $keypairs = [];
	private static function keypair( $token ) {
		$k = hash( 'sha256', (string) $token );
		if ( ! isset( self::$keypairs[ $k ] ) ) self::$keypairs[ $k ] = sodium_crypto_sign_seed_keypair( hash( 'sha256', "ixes-sign\n" . $token, true ) );
		return self::$keypairs[ $k ];
	}

	/** The public half of the key the token derives, as 64 hex. */
	public static function public_key( $token ) {
		return bin2hex( sodium_crypto_sign_publickey( self::keypair( $token ) ) );
	}

	/** An Ed25519 signature (128 hex) by the token's private key over message(). */
	public static function sign_key( $token, $method, $path, $ts, $body, $step = '', $prefix = '', $excl = '', $nonce = '' ) {
		return bin2hex( sodium_crypto_sign_detached( self::message( $method, $path, $ts, $body, $step, $prefix, $excl, $nonce ), sodium_crypto_sign_secretkey( self::keypair( $token ) ) ) );
	}

	public static function verify_key( $pub, $method, $path, $ts, $body, $sig, $now = null, $step = '', $prefix = '', $excl = '', $nonce = '' ) {
		if ( $now === null ) $now = time();
		if ( ! self::can_sign() || ! preg_match( '/^[0-9a-f]{64}$/', (string) $pub ) || ! preg_match( '/^[0-9a-f]{128}$/', (string) $sig ) ) return false;
		if ( abs( $now - (int) $ts ) > self::SKEW ) return false;
		try {
			return sodium_crypto_sign_verify_detached( hex2bin( $sig ), self::message( $method, $path, $ts, $body, $step, $prefix, $excl, $nonce ), hex2bin( $pub ) );
		} catch ( Exception $e ) { return false; } catch ( Error $e ) { return false; }
	}

	/**
	 * A remote that predates the public key learns it from the first request that proves the token, i.e. the first
	 * one an older hub signs. Only when it has none: rotating sets both together, so a stored key is never stale.
	 */
	public static function learn_key( $token ) {
		if ( ! self::can_sign() || (string) get_option( self::PUB_OPTION ) !== '' ) return;
		update_option( self::PUB_OPTION, self::public_key( $token ), false );
	}

	/**
	 * A fresh session key, handed back in the body of an Ed25519-signed /session request. Response bodies stay out
	 * of the header logs that leaked the token. Bound to the public key, so rotating the token ends every session.
	 * @return array [ session, key, ttl ]
	 */
	public static function open_session( $pub ) {
		$id  = bin2hex( random_bytes( 16 ) );
		$key = bin2hex( random_bytes( 32 ) );
		set_transient( 'ixes_session_' . $id, [ 'key' => $key, 'pub' => (string) $pub ], self::SESSION_TTL );
		return [ 'session' => $id, 'key' => $key, 'ttl' => self::SESSION_TTL ];
	}

	/** The session's key, or '' for an unknown or expired session, or one opened under a key since rotated. */
	public static function session_key( $id ) {
		if ( ! preg_match( '/^[0-9a-f]{32}$/', (string) $id ) ) return '';
		$s = get_transient( 'ixes_session_' . $id );
		$pub = (string) get_option( self::PUB_OPTION );
		if ( ! is_array( $s ) || $pub === '' || ! hash_equals( $pub, (string) ( $s['pub'] ?? '' ) ) ) return '';
		return (string) ( $s['key'] ?? '' );
	}

	public static function new_nonce() { return bin2hex( random_bytes( 16 ) ); }

	/**
	 * Records $nonce in $dir; false when it was already there, i.e. the request is a replay. A file per nonce,
	 * created with 'x', is atomic without the database. Kept for twice SKEW, the longest a signed ts stays valid.
	 * @return bool|WP_Error true for a fresh nonce, false for a replay, WP_Error when the nonce cannot be recorded
	 */
	public static function fresh_nonce( $dir, $nonce, $now = null ) {
		if ( $now === null ) $now = time();
		if ( ! preg_match( '/^[0-9a-f]{32}$/', (string) $nonce ) ) return false;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return new WP_Error( 'nonce_io', 'cannot record the request nonce', [ 'status' => 500 ] );
		$f = $dir . '/' . $nonce;
		$fh = @fopen( $f, 'x' );
		if ( ! $fh ) return file_exists( $f ) ? false : new WP_Error( 'nonce_io', 'cannot record the request nonce', [ 'status' => 500 ] );
		fclose( $fh );
		// a push sends thousands of requests: prune now and then, not on each one
		if ( random_int( 1, 50 ) === 1 ) {
			foreach ( (array) glob( $dir . '/*' ) as $old ) if ( @filemtime( $old ) < $now - 2 * self::SKEW ) @unlink( $old );
		}
		return true;
	}

	public static function https_ok( $url ) {
		if ( stripos( $url, 'https://' ) === 0 ) return true;
		return defined( 'ENVSYNC_ALLOW_HTTP' ) && ENVSYNC_ALLOW_HTTP;
	}

	/**
	 * Some hosts strip the Authorization header before PHP sees it. The hub therefore also sends
	 * X-Envsync-Token; the remote prefers Authorization and falls back. Returns [ token, carrier ].
	 */
	public static function token_from_headers( $authorization, $x_token ) {
		$authorization = (string) $authorization; $x_token = trim( (string) $x_token );
		if ( $authorization !== '' && stripos( $authorization, 'Bearer ' ) === 0 ) return [ trim( substr( $authorization, 7 ) ), 'authorization' ];
		if ( $x_token !== '' ) return [ $x_token, 'x-envsync-token' ];
		return [ '', null ];
	}
}
