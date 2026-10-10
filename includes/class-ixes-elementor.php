<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Elementor keeps rendered HTML (_elementor_element_cache post meta) and generated CSS (uploads/elementor/css,
 * _elementor_css) outside the object cache, so a sync that rewrites its data leaves the site rendering the old
 * output until those expire. Clearing them is what Elementor > Tools > Clear Files & Data does.
 */
class IXES_Elementor {
	/** null when Elementor is not on this site, 'cleared', or 'failed: <why>'. */
	public static function clear_cache() {
		if ( ! function_exists( 'did_action' ) || ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) return self::clear_unloaded();
		$files = \Elementor\Plugin::$instance->files_manager ?? null;
		if ( ! $files || ! method_exists( $files, 'clear_cache' ) ) return self::clear_unloaded();
		try {
			$files->clear_cache();
			// older Elementor versions leave the element cache meta behind
			delete_post_meta_by_key( '_elementor_element_cache' );
		} catch ( \Throwable $e ) {
			return 'failed: ' . $e->getMessage();
		}
		return 'cleared';
	}

	/**
	 * The same clear without Elementor's code. A pull into a fresh install brings Elementor and its active_plugins
	 * entry along, but the process running the pull booted without it, so there is no files manager to ask.
	 */
	private static function clear_unloaded() {
		$dir = self::css_dir();
		$active = in_array( 'elementor/elementor.php', (array) get_option( 'active_plugins', [] ), true );
		if ( ! $active && ( $dir === null || ! is_dir( $dir ) ) ) return null;
		$left = 0;
		if ( $dir !== null && is_dir( $dir ) ) {
			foreach ( glob( $dir . '/*.css' ) ?: [] as $f ) if ( ! @unlink( $f ) ) $left++;
		}
		delete_post_meta_by_key( '_elementor_css' );
		delete_post_meta_by_key( '_elementor_element_cache' );
		delete_option( '_elementor_global_css' );
		delete_option( 'elementor-custom-breakpoints-files' );
		return $left ? "failed: {$left} file(s) in uploads/elementor/css could not be deleted" : 'cleared';
	}

	private static function css_dir() {
		if ( ! function_exists( 'wp_upload_dir' ) ) return null;
		$u = wp_upload_dir( null, false );
		return empty( $u['basedir'] ) ? null : untrailingslashit( $u['basedir'] ) . '/elementor/css';
	}

	/** One line for the CLI output, or null when there is nothing to say. */
	public static function line( $status ) {
		if ( $status === null ) return null;
		if ( $status === 'cleared' ) return 'elementor: cache cleared';
		return 'warning: elementor: cache not cleared (' . substr( (string) $status, strlen( 'failed: ' ) ) . '); clear it in Elementor > Tools';
	}
}
