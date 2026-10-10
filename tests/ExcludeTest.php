<?php
use PHPUnit\Framework\TestCase;

class ExcludeTest extends TestCase {
	private function ex( $rel ) { return IXES_Transfer::excluded_path( $rel, IXES_Env::default_excludes() ); }

	public function test_folder_excludes_are_anchored_at_wp_content() {
		$this->assertTrue( $this->ex( 'cache/page/index.html' ) );
		$this->assertTrue( $this->ex( 'upgrade/x.zip' ) );
		$this->assertTrue( $this->ex( 'uploads/wc-logs/a.log' ) );
		// a plugin's own folder that happens to be called cache must sync (polylang 3.7 fataled without it)
		$this->assertFalse( $this->ex( 'plugins/polylang/src/integrations/cache/load.php' ) );
		$this->assertFalse( $this->ex( 'plugins/google-site-kit/third-party/psr/cache/CacheItemInterface.php' ) );
		$this->assertFalse( $this->ex( 'plugins/some/upgrade/Upgrader.php' ) );
	}

	public function test_page_caches_and_generated_css_never_sync() {
		$this->assertTrue( $this->ex( 'wp-cloudflare-super-page-cache/remote.test/index.html' ) );
		$this->assertTrue( $this->ex( 'et-cache/12/et-core-unified-12.min.css' ) );
		$this->assertTrue( $this->ex( 'litespeed/css/a.css' ) );
		$this->assertTrue( $this->ex( 'uploads/elementor/css/post-12.css' ) );
		// Elementor's other uploads are content
		$this->assertFalse( $this->ex( 'uploads/elementor/screenshots/a.png' ) );
		$this->assertFalse( $this->ex( 'plugins/litespeed-cache/litespeed-cache.php' ) );
	}

	public function test_dev_artifacts_match_at_any_depth() {
		$this->assertTrue( $this->ex( 'themes/mk/node_modules/x/index.js' ) );
		$this->assertTrue( $this->ex( 'plugins/p/.git/HEAD' ) );
		$this->assertTrue( $this->ex( 'node_modules/x.js' ) );
	}

	public function test_file_excludes_match_by_name_anywhere() {
		$this->assertTrue( $this->ex( 'debug.log' ) );
		$this->assertTrue( $this->ex( 'plugins/p/debug.log' ) );
		$this->assertTrue( $this->ex( 'advanced-cache.php' ) );
	}

	public function test_user_folder_excludes_are_anchored() {
		$this->assertTrue( IXES_Transfer::excluded_path( 'ai1wm-backups/a.wpress', [ 'ai1wm-backups/' ] ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/rank-math/x.json', [ 'uploads/rank-math/' ] ) );
		$this->assertFalse( IXES_Transfer::excluded_path( 'plugins/x/ai1wm-backups/a.php', [ 'ai1wm-backups/' ] ) );
	}
}
