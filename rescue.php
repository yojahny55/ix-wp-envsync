<?php
/**
 * Rescue endpoint for a remote that a push left broken (a plugin fatals on every request, so REST is down too).
 *
 * WordPress boots here in installer mode: no plugins, no theme, no maintenance screen. The request is
 * authenticated exactly like the REST API (token + HMAC signature over the body), then one action runs:
 * status, plugins_off (keep only EnvSync active), rollback (restore a push's snapshot, clear its lock) or
 * restore_self (put back the plugin folder a self-update replaced; self_backup names its version). Both run before the main plugin
 * file loads, with only IXES_Auth and IXES_Selfupdate: the code it recovers from may be what fatals.
 * Must-use plugins and drop-ins still load there, so quarantine_mu skips WordPress altogether: it finds the
 * storage folder next to wp-content/plugins, checks the token against the key the last push left there
 * (IXES_Mu::write_key()) and moves the job's mu-plugins and drop-ins aside, touching files only.
 *
 * phpcs:ignoreFile -- standalone entry point that bootstraps WordPress itself
 */

// before WordPress: a crashing mu-plugin or drop-in would take this request down too
$ixes_body = (string) file_get_contents( 'php://input' );
$ixes_p = json_decode( $ixes_body, true );
$ixes_p = is_array( $ixes_p ) ? $ixes_p : [];
if ( ( $ixes_p['action'] ?? '' ) === 'quarantine_mu' ) {
	$ixes_bare = function ( $code, array $data ) { http_response_code( $code ); header( 'Content-Type: application/json; charset=utf-8' ); echo json_encode( $data ); exit; };
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) $ixes_bare( 405, [ 'message' => 'POST only' ] );
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' ); // only so the classes below load; nothing reads it
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private $c; private $m; private $d;
			public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; }
			public function get_error_code() { return $this->c; }
			public function get_error_message() { return $this->m; }
			public function get_error_data() { return $this->d; }
		}
	}
	require_once __DIR__ . '/includes/class-ixes-auth.php';
	require_once __DIR__ . '/includes/class-ixes-batch.php';
	require_once __DIR__ . '/includes/class-ixes-mu.php';
	// wp-content is two levels above this plugin's folder, as served (a symlinked plugin resolves to the site through SCRIPT_FILENAME)
	$ixes_content = dirname( isset( $_SERVER['SCRIPT_FILENAME'] ) ? $_SERVER['SCRIPT_FILENAME'] : __FILE__, 3 );
	$ixes_https = ( ! empty( $_SERVER['HTTPS'] ) && strtolower( (string) $_SERVER['HTTPS'] ) !== 'off' ) || (string) ( $_SERVER['SERVER_PORT'] ?? '' ) === '443';
	$ixes_auth = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
	list( $ixes_token ) = IXES_Auth::token_from_headers( $ixes_auth, (string) ( $_SERVER['HTTP_X_ENVSYNC_TOKEN'] ?? '' ) );
	if ( $ixes_token === '' ) $ixes_bare( 401, [ 'message' => 'missing token' ] );
	$ixes_store = IXES_Mu::bare_auth( $ixes_content, $ixes_token, (int) ( $_SERVER['HTTP_X_ENVSYNC_TS'] ?? 0 ), $ixes_body, (string) ( $_SERVER['HTTP_X_ENVSYNC_SIG'] ?? '' ), $ixes_https );
	$ixes_r = $ixes_store instanceof WP_Error ? $ixes_store : IXES_Mu::quarantine( $ixes_content, $ixes_store, isset( $ixes_p['job'] ) ? (string) $ixes_p['job'] : null );
	if ( $ixes_r instanceof WP_Error ) { $ixes_d = $ixes_r->get_error_data(); $ixes_bare( (int) ( $ixes_d['status'] ?? 500 ), [ 'code' => $ixes_r->get_error_code(), 'message' => $ixes_r->get_error_message() ] ); }
	$ixes_bare( 200, [ 'ok' => true ] + $ixes_r );
}

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
require_once __DIR__ . '/includes/class-ixes-auth.php';
require_once __DIR__ . '/includes/class-ixes-selfupdate.php';

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
if ( $ixes_token === '' ) ixes_rescue_send( 401, [ 'message' => 'missing token' ] );
$ixes_ok = IXES_Auth::verify(
	(string) get_option( 'ixes_token_hash' ), $ixes_token, 'POST', '/envsync/v1/rescue', // IXES_Rest::NS, spelled out: that class is not loaded yet
	(int) ( $_SERVER['HTTP_X_ENVSYNC_TS'] ?? 0 ), $ixes_body, (string) ( $_SERVER['HTTP_X_ENVSYNC_SIG'] ?? '' )
);
if ( ! $ixes_ok ) ixes_rescue_send( 401, [ 'message' => 'bad signature' ] );

if ( ( $ixes_p['action'] ?? '' ) === 'self_backup' ) {
	$ixes_r = [ 'ok' => true, 'version' => IXES_Selfupdate::backup_version() ];
} elseif ( ( $ixes_p['action'] ?? '' ) === 'restore_self' ) {
	$ixes_r = IXES_Selfupdate::restore( isset( $ixes_p['from'] ) ? (string) $ixes_p['from'] : null, [ 'by' => 'rescue', 'ip' => substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 64 ) ] );
} else {
	require_once __DIR__ . '/ix-wp-envsync.php';
	switch ( $ixes_p['action'] ?? '' ) {
		case 'status':      $ixes_r = IXES_Applier::rescue_status(); break;
		case 'plugins_off': $ixes_r = IXES_Applier::rescue_plugins_off(); break;
		case 'rollback':    $ixes_r = IXES_Applier::rescue_rollback( isset( $ixes_p['job'] ) ? (string) $ixes_p['job'] : null ); break;
		default:            $ixes_r = new WP_Error( 'bad_action', 'unknown action', [ 'status' => 400 ] );
	}
}
if ( is_wp_error( $ixes_r ) ) {
	$ixes_d = $ixes_r->get_error_data();
	ixes_rescue_send( is_array( $ixes_d ) && isset( $ixes_d['status'] ) ? (int) $ixes_d['status'] : 500, [ 'code' => $ixes_r->get_error_code(), 'message' => $ixes_r->get_error_message() ] );
}
ixes_rescue_send( 200, $ixes_r );
