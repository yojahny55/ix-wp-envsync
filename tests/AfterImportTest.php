<?php
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['ixes_test_options'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_cache_flush' ) ) { function wp_cache_flush() { return true; } }
if ( ! function_exists( 'flush_rewrite_rules' ) ) { function flush_rewrite_rules() {} }

class AfterImportTest extends TestCase {
	// the pull process booted without the plugins it just brought: rules it built now would miss their routes
	public function test_rewrite_rules_are_left_for_the_next_request_to_build() {
		$GLOBALS['ixes_test_options']['rewrite_rules'] = [ 'remote/(.+)/?$' => 'index.php?x=$matches[1]' ];
		IXES_Transfer::after_import( 'http://local.test', '/srv/local/' );
		$this->assertArrayNotHasKey( 'rewrite_rules', $GLOBALS['ixes_test_options'] );
		$this->assertSame( 'http://local.test', $GLOBALS['ixes_test_options']['home'] );
	}
}
