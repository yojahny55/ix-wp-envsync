<?php
use PHPUnit\Framework\TestCase;

class OwnDirTest extends TestCase {
	public function test_plain_paths() {
		$this->assertSame( 'plugins/ix-wp-envsync/', IXES_Transfer::rel_dir( '/nowhere/site/wp-content', '/nowhere/site/wp-content/plugins/ix-wp-envsync' ) );
	}

	public function test_dot_segment_from_relative_wp_path() {
		// `wp --path=.` yields "/site/./wp-content"; the plugin must still find itself
		$this->assertSame( 'plugins/ix-wp-envsync/', IXES_Transfer::rel_dir( '/nowhere/site/./wp-content', '/nowhere/site/wp-content/plugins/ix-wp-envsync' ) );
		$this->assertSame( 'plugins/ix-wp-envsync/', IXES_Transfer::rel_dir( '/nowhere/site/./././wp-content/', '/nowhere/site/wp-content/plugins/ix-wp-envsync/' ) );
	}

	public function test_real_dirs_resolve_through_realpath() {
		$base = sys_get_temp_dir() . '/ixes-own-' . getmypid();
		@mkdir( $base . '/wp-content/plugins/ix-wp-envsync', 0777, true );
		$this->assertSame( 'plugins/ix-wp-envsync/', IXES_Transfer::rel_dir( $base . '/./wp-content', $base . '/wp-content/plugins/ix-wp-envsync' ) );
		@rmdir( $base . '/wp-content/plugins/ix-wp-envsync' ); @rmdir( $base . '/wp-content/plugins' ); @rmdir( $base . '/wp-content' ); @rmdir( $base );
	}

	public function test_outside_and_prefix_lookalike() {
		$this->assertSame( '', IXES_Transfer::rel_dir( '/nowhere/site/wp-content', '/elsewhere/ix-wp-envsync' ) );
		$this->assertSame( '', IXES_Transfer::rel_dir( '/nowhere/site/wp-content', '/nowhere/site/wp-content2/plugins/x' ) );
	}
}
