<?php
/**
 * Plugin Name: IX WP EnvSync
 * Description: Pull full snapshots from prod/staging, push a 3-way diffed delta back. Prod always wins. Preview before every sync.
 * Version: 0.5.6
 * Author: Yojahny Chavez
 * License: GPL-2.0-or-later
 * Text Domain: ix-wp-envsync
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'IXES_VERSION', '0.5.6' );
define( 'IXES_FILE', __FILE__ );
define( 'IXES_PATH', plugin_dir_path( __FILE__ ) );
define( 'IXES_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'IXES_' ) !== 0 ) return;
	$file = IXES_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( ! file_exists( $file ) ) $file = IXES_PATH . 'admin/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( file_exists( $file ) ) require_once $file;
} );

function ixes_storage_dir() {
	// unguessable name: job snapshots hold wp_users rows and nginx ignores .htaccess
	$suffix = get_option( 'ixes_storage_suffix' );
	if ( ! is_string( $suffix ) || ! preg_match( '/^[0-9a-f]{16}$/', $suffix ) ) {
		$suffix = bin2hex( random_bytes( 8 ) );
		update_option( 'ixes_storage_suffix', $suffix, false );
	}
	$dir = WP_CONTENT_DIR . '/envsync-' . $suffix;
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', "<?php // silence" );
		file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
	}
	return $dir;
}

register_activation_hook( __FILE__, function () {
	if ( is_multisite() ) wp_die( 'EnvSync does not support multisite yet.', 'EnvSync', [ 'back_link' => true ] );
	ixes_storage_dir();
	if ( ! get_option( 'ixes_token_hash' ) ) {
		IXES_Auth::install_token();
	}
} );

add_action( 'rest_api_init', [ 'IXES_Rest', 'register' ] );
// A hub request through an HTTP Basic Auth proxy arrives with PHP_AUTH_USER set to the proxy's user.
// WordPress would try it as an application password and fail the request with 401 before EnvSync
// checks its token, so application passwords are skipped for requests that carry an EnvSync token.
add_filter( 'application_password_is_api_request', function ( $is_api ) {
	return isset( $_SERVER['HTTP_X_ENVSYNC_TOKEN'] ) ? false : $is_api;
} );
add_action( 'init', function () {
	if ( defined( 'WP_CLI' ) && WP_CLI ) WP_CLI::add_command( 'envsync', 'IXES_CLI' );
} );
if ( is_admin() ) add_action( 'admin_menu', [ 'IXES_Admin', 'register' ] );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=ix-envsync' ) ) . '">' . esc_html__( 'Settings', 'ix-wp-envsync' ) . '</a>' );
	return $links;
} );
