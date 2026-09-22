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
		set_transient( 'ixes_token_show', $token, 600 );
		return $token;
	}

	// $step: raw X-Envsync-Step header or ''. Old clients send none; the message then ends exactly
	// as it did in 0.2 (no trailing "\n"), so their signatures keep verifying.
	public static function sign( $token, $method, $path, $ts, $body, $step = '' ) {
		$msg = strtoupper( $method ) . "\n" . $path . "\n" . (int) $ts . "\n" . hash( 'sha256', (string) $body );
		if ( (string) $step !== '' ) $msg .= "\n" . $step;
		return hash_hmac( 'sha256', $msg, $token );
	}

	public static function verify( $token_hash, $presented_token, $method, $path, $ts, $body, $sig, $now = null, $step = '' ) {
		if ( $now === null ) $now = time();
		if ( ! is_string( $token_hash ) || ! is_string( $presented_token ) || $presented_token === '' ) return false;
		if ( ! hash_equals( $token_hash, wp_hash( $presented_token ) ) ) return false;
		if ( abs( $now - (int) $ts ) > self::SKEW ) return false;
		$expected = self::sign( $presented_token, $method, $path, $ts, $body, $step );
		return hash_equals( $expected, (string) $sig );
	}

	public static function https_ok( $url ) {
		if ( stripos( $url, 'https://' ) === 0 ) return true;
		return defined( 'ENVSYNC_ALLOW_HTTP' ) && ENVSYNC_ALLOW_HTTP;
	}
}
