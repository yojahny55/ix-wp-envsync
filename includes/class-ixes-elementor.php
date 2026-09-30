<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Elementor keeps rendered HTML (_elementor_element_cache post meta) and generated CSS (uploads/elementor/css,
 * _elementor_css) outside the object cache, so a sync that rewrites its data leaves the site rendering the old
 * output until those expire. Clearing them is what Elementor > Tools > Clear Files & Data does.
 */
class IXES_Elementor {
	/** null when Elementor is not loaded here, 'cleared', or 'failed: <why>'. */
	public static function clear_cache() {
		if ( ! function_exists( 'did_action' ) || ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) return null;
		$files = \Elementor\Plugin::$instance->files_manager ?? null;
		if ( ! $files || ! method_exists( $files, 'clear_cache' ) ) return null;
		try {
			$files->clear_cache();
			// older Elementor versions leave the element cache meta behind
			delete_post_meta_by_key( '_elementor_element_cache' );
		} catch ( \Throwable $e ) {
			return 'failed: ' . $e->getMessage();
		}
		return 'cleared';
	}

	/** One line for the CLI output, or null when there is nothing to say. */
	public static function line( $status ) {
		if ( $status === null ) return null;
		if ( $status === 'cleared' ) return 'elementor: cache cleared';
		return 'warning: elementor: cache not cleared (' . substr( (string) $status, strlen( 'failed: ' ) ) . '); clear it in Elementor > Tools';
	}
}
