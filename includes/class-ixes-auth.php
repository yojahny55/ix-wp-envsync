<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Auth {
	const SKEW = 300;

	public static function generate_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	public static function install_token() {
		$token = self::generate_token();
		update_option( 'ixes_token_hash', wp_hash( $token ), false );
		// the old token must stop opening rescue.php's bare path too
		if ( function_exists( 'ixes_storage_dir' ) ) IXES_Mu::write_key( ixes_storage_dir(), $token, defined( 'ENVSYNC_ALLOW_HTTP' ) && ENVSYNC_ALLOW_HTTP );
		set_transient( 'ixes_token_show', $token, 600 );
		return $token;
	}

	// $step: raw X-Envsync-Step header or ''. Old clients send none; the message then ends exactly
	// as it did in 0.2 (no trailing "\n"), so their signatures keep verifying.
	// $prefix: the hub's table prefix when it differs from the remote's (X-Envsync-Prefix), else ''.
	// $excl: the raw X-Envsync-Exclude-Options header, else ''. Signed so nobody in between can strip or widen it.
	// $nonce: X-Envsync-Nonce, sent only to a remote with the 'nonce' cap. Signed, so it cannot be stripped to dodge seen_nonce().
	public static function sign( $token, $method, $path, $ts, $body, $step = '', $prefix = '', $excl = '', $nonce = '' ) {
		$msg = strtoupper( $method ) . "\n" . $path . "\n" . (int) $ts . "\n" . hash( 'sha256', (string) $body );
		if ( (string) $step !== '' ) $msg .= "\n" . $step;
		if ( (string) $prefix !== '' ) $msg .= "\nprefix:" . $prefix;
		if ( (string) $excl !== '' ) $msg .= "\nexclude-options:" . $excl;
		if ( (string) $nonce !== '' ) $msg .= "\nnonce:" . $nonce;
		return hash_hmac( 'sha256', $msg, $token );
	}

	public static function verify( $token_hash, $presented_token, $method, $path, $ts, $body, $sig, $now = null, $step = '', $prefix = '', $excl = '', $nonce = '' ) {
		if ( $now === null ) $now = time();
		if ( ! is_string( $token_hash ) || ! is_string( $presented_token ) || $presented_token === '' ) return false;
		if ( ! hash_equals( $token_hash, wp_hash( $presented_token ) ) ) return false;
		if ( abs( $now - (int) $ts ) > self::SKEW ) return false;
		$expected = self::sign( $presented_token, $method, $path, $ts, $body, $step, $prefix, $excl, $nonce );
		return hash_equals( $expected, (string) $sig );
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
