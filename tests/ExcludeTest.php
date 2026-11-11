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

	public function test_glob_folder_excludes_match_the_leading_segments() {
		$ex = [ 'uploads/wpo_wcpdf_*/' ];
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/wpo_wcpdf_abc/x.pdf', $ex ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/wpo_wcpdf_abc/attachments/test.pdf', $ex ) );
		// the directory itself, as the walker sees it, so the walk never descends
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/wpo_wcpdf_abc/', $ex ) );
		$this->assertFalse( IXES_Transfer::excluded_path( 'uploads/other/x.pdf', $ex ) );
		// a file named like the folder is not inside it
		$this->assertFalse( IXES_Transfer::excluded_path( 'uploads/wpo_wcpdf_abc', $ex ) );
		// * does not cross a slash
		$this->assertFalse( IXES_Transfer::excluded_path( 'uploads/a/wpo_wcpdf_abc/x.pdf', [ 'uploads/*wpo_wcpdf_*/' ] ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/2024/05/a.jpg', [ 'uploads/20??/' ] ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/cache-b/a.jpg', [ 'uploads/cache-[ab]/' ] ) );
	}

	public function test_glob_folder_excludes_stay_anchored_at_wp_content() {
		$this->assertFalse( IXES_Transfer::excluded_path( 'plugins/x/cache-1/a.php', [ 'cache-*/' ] ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'cache-1/a.php', [ 'cache-*/' ] ) );
		$this->assertFalse( IXES_Transfer::excluded_path( 'plugins/p/uploads/wpo_wcpdf_a/x.pdf', [ 'uploads/wpo_wcpdf_*/' ] ) );
	}

	public function test_glob_file_excludes_match_the_path_or_the_name() {
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/a/b.log', [ '*.log' ] ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'b.log', [ '*.log' ] ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/export-3.csv', [ 'uploads/export-*.csv' ] ) );
		$this->assertFalse( IXES_Transfer::excluded_path( 'uploads/sub/export-3.csv', [ 'uploads/export-*.csv' ] ) );
		$this->assertFalse( IXES_Transfer::excluded_path( 'uploads/a/b.txt', [ '*.log' ] ) );
	}

	public function test_literal_excludes_keep_their_behavior() {
		// no glob characters: still a plain prefix or name match, nothing more
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/wpo_wcpdf_abc/x.pdf', [ 'uploads/wpo_wcpdf_abc/' ] ) );
		$this->assertFalse( IXES_Transfer::excluded_path( 'uploads/wpo_wcpdf_abcd/x.pdf', [ 'uploads/wpo_wcpdf_abc/' ] ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'plugins/p/notes.txt', [ 'notes.txt' ] ) );
		$this->assertFalse( IXES_Transfer::excluded_path( 'plugins/p/notes.txt.bak', [ 'notes.txt' ] ) );
	}

	public function test_exclude_matches_any_flags_a_dead_pattern() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ixes-wpc-' . getmypid() );
		$d = WP_CONTENT_DIR . '/uploads/wpo_wcpdf_ex89/attachments';
		@mkdir( $d, 0777, true );
		file_put_contents( $d . '/test.pdf', 'x' );
		try {
			$this->assertTrue( IXES_Transfer::exclude_matches_any( 'uploads/wpo_wcpdf_*/' ) );
			$this->assertTrue( IXES_Transfer::exclude_matches_any( 'uploads/wpo_wcpdf_ex89/' ) );
			$this->assertTrue( IXES_Transfer::exclude_matches_any( 'test.pdf' ) );
			$this->assertFalse( IXES_Transfer::exclude_matches_any( 'uploads/wpo_wcpdf_[0-9]*/' ) );
			$this->assertFalse( IXES_Transfer::exclude_matches_any( 'no-such-dir/' ) );
		} finally {
			unlink( $d . '/test.pdf' );
			rmdir( $d );
			rmdir( dirname( $d ) );
		}
	}

	public function test_literal_names_with_glob_characters_still_match_themselves() {
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/report[1].pdf', [ 'uploads/report[1].pdf' ] ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'plugins/foo[beta]/a.php', [ 'plugins/foo[beta]/' ] ) );
	}

	public function test_file_globs_never_match_a_folder() {
		// a walk and a flat file list must agree: uploads/tmp* names files, not what sits in uploads/tmp-x/
		$this->assertFalse( IXES_Transfer::excluded_path( 'uploads/tmp-x/', [ 'uploads/tmp*' ] ) );
		$this->assertFalse( IXES_Transfer::excluded_path( 'uploads/tmp-x/a.txt', [ 'uploads/tmp*' ] ) );
		$this->assertTrue( IXES_Transfer::excluded_path( 'uploads/tmp-x.txt', [ 'uploads/tmp*' ] ) );
	}

	public function test_any_depth_excludes_are_not_reported_dead() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ixes-wpc-' . getmypid() );
		$d = WP_CONTENT_DIR . '/themes/t89/node_modules';
		@mkdir( $d, 0777, true );
		file_put_contents( $d . '/x.js', 'x' );
		try {
			$this->assertTrue( IXES_Transfer::exclude_matches_any( 'node_modules/' ) );
		} finally {
			unlink( $d . '/x.js' );
			rmdir( $d );
			rmdir( dirname( $d ) );
		}
	}
}
