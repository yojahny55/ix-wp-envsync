<?php
/**
 * Rescue endpoint for a remote that a push left broken (a plugin fatals on every request, so REST is down too).
 *
 * WordPress boots here in installer mode: no plugins, no theme, no maintenance screen. The request is
 * authenticated exactly like the REST API (token + HMAC signature over the body), then one action runs:
 * status, plugins_off (keep only EnvSync active) or rollback (restore a push's snapshot, clear its lock).
 * Must-use plugins and drop-ins still load; a crash there needs the host's file manager.
 *
 * phpcs:ignoreFile -- standalone entry point that bootstraps WordPress itself
 */

define( 'WP_INSTALLING', true );

// SCRIPT_FILENAME, not __DIR__: a symlinked plugin must find the site that served the request, not the symlink target
$ixes_dir  = dirname( isset( $_SERVER['SCRIPT_FILENAME'] ) ? $_SERVER['SCRIPT_FILENAME'] : __FILE__ );
$ixes_load = null;
for ( $ixes_i = 0; $ixes_i < 6 && ! $ixes_load; $ixes_i++ ) {
	$ixes_dir = dirname( $ixes_dir );
	if ( is_file( $ixes_dir . '/wp-load.php' ) ) $ixes_load = $ixes_dir . '/wp-load.php';
}
if ( ! $ixes_load ) { http_response_code( 500 ); header( 'Content-Type: application/json' ); echo '{"message":"wp-load.php not found"}'; exit; }
require $ixes_load;
require_once __DIR__ . '/ix-wp-envsync.php';

function ixes_rescue_send( $code, array $data ) {
	status_header( $code );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo wp_json_encode( $data );
	exit;
}

if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) ixes_rescue_send( 405, [ 'message' => 'POST only' ] );
if ( ! is_ssl() && ! ( defined( 'ENVSYNC_ALLOW_HTTP' ) && ENVSYNC_ALLOW_HTTP ) ) ixes_rescue_send( 403, [ 'message' => 'https required' ] );

$ixes_auth = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
list( $ixes_token ) = IXES_Auth::token_from_headers( $ixes_auth, (string) ( $_SERVER['HTTP_X_ENVSYNC_TOKEN'] ?? '' ) );
$ixes_body = (string) file_get_contents( 'php://input' );
if ( $ixes_token === '' ) ixes_rescue_send( 401, [ 'message' => 'missing token' ] );
$ixes_ok = IXES_Auth::verify(
	(string) get_option( 'ixes_token_hash' ), $ixes_token, 'POST', '/' . IXES_Rest::NS . '/rescue',
	(int) ( $_SERVER['HTTP_X_ENVSYNC_TS'] ?? 0 ), $ixes_body, (string) ( $_SERVER['HTTP_X_ENVSYNC_SIG'] ?? '' )
);
if ( ! $ixes_ok ) ixes_rescue_send( 401, [ 'message' => 'bad signature' ] );

$ixes_p = json_decode( $ixes_body, true );
$ixes_p = is_array( $ixes_p ) ? $ixes_p : [];
switch ( $ixes_p['action'] ?? '' ) {
	case 'status':      $ixes_r = IXES_Applier::rescue_status(); break;
	case 'plugins_off': $ixes_r = IXES_Applier::rescue_plugins_off(); break;
	case 'rollback':    $ixes_r = IXES_Applier::rescue_rollback( isset( $ixes_p['job'] ) ? (string) $ixes_p['job'] : null ); break;
	default:            $ixes_r = new WP_Error( 'bad_action', 'unknown action', [ 'status' => 400 ] );
}
if ( is_wp_error( $ixes_r ) ) {
	$ixes_d = $ixes_r->get_error_data();
	ixes_rescue_send( is_array( $ixes_d ) && isset( $ixes_d['status'] ) ? (int) $ixes_d['status'] : 500, [ 'code' => $ixes_r->get_error_code(), 'message' => $ixes_r->get_error_message() ] );
}
ixes_rescue_send( 200, $ixes_r );
