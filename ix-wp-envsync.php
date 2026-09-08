<?php
/**
 * Plugin Name: IX WP EnvSync
 * Description: Pull full snapshots from prod/staging, push a 3-way diffed delta back. Prod always wins. Preview before every sync.
 * Version: 0.1.0
 * Author: Yojahny Chavez
 * License: GPL-2.0-or-later
 * Text Domain: ix-wp-envsync
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'IXES_VERSION', '0.1.0' );
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
	$dir = WP_CONTENT_DIR . '/envsync';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', "<?php // silence" );
		file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
	}
	return $dir;
}

register_activation_hook( __FILE__, function () {
	ixes_storage_dir();
	if ( ! get_option( 'ixes_token_hash' ) ) {
		IXES_Auth::install_token();
	}
} );

add_action( 'rest_api_init', [ 'IXES_Rest', 'register' ] );
add_action( 'init', function () {
	if ( defined( 'WP_CLI' ) && WP_CLI ) WP_CLI::add_command( 'envsync', 'IXES_CLI' );
} );
if ( is_admin() ) add_action( 'admin_menu', [ 'IXES_Admin', 'register' ] );
