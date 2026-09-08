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

spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'IXES_' ) !== 0 ) return;
	$file = __DIR__ . '/../includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( file_exists( $file ) ) require_once $file;
} );
