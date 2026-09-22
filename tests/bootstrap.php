<?php
// Minimal stubs so pure classes load outside WordPress.
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/fake-wp/' );
if ( ! function_exists( 'wp_hash' ) ) { function wp_hash( $s ) { return hash( 'sha256', 'salt' . $s ); } }
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $v ) {
		if ( ! is_string( $v ) ) return $v;
		if ( $v === 'b:0;' ) return false;
		if ( ! preg_match( '/^[aOsibdN]:/', $v ) ) return $v;
		$r = @unserialize( $v );
		return $r === false ? $v : $r;
	}
}
if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $v ) { return is_string( $v ) && ( $v === 'b:0;' || preg_match( '/^[aOsibdN]:/', $v ) ); }
}
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $v ) { return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : $v; }
}
// Storage dir for IXES_Hashcache; tests point $GLOBALS['ixes_test_storage'] at a temp dir.
if ( ! function_exists( 'ixes_storage_dir' ) ) {
	function ixes_storage_dir() {
		if ( empty( $GLOBALS['ixes_test_storage'] ) ) {
			$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-test-' . getmypid();
		}
		if ( ! is_dir( $GLOBALS['ixes_test_storage'] ) ) mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
		return $GLOBALS['ixes_test_storage'];
	}
}

if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $t = null ) { return date( $f, $t === null ? time() : $t ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $msg; private $data;
		public function __construct( $code = '', $msg = '', $data = null ) { $this->code = $code; $this->msg = $msg; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->msg; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; } }
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) { function wp_remote_retrieve_header( $r, $h ) { return $r['headers'][ strtolower( $h ) ] ?? ''; } }
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) { function wp_remote_retrieve_headers( $r ) { return $r['headers'] ?? []; } }

if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['ixes_test_options'][ $k ] ?? $d; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['ixes_test_transients'][ $k ] ?? false; } }
if ( ! defined( 'IXES_VERSION' ) ) define( 'IXES_VERSION', '0.4.0' );
if ( ! function_exists( 'home_url' ) ) { function home_url() { return 'http://hub.test'; } }

spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'IXES_' ) !== 0 ) return;
	$file = __DIR__ . '/../includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( file_exists( $file ) ) require_once $file;
} );
