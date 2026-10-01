<?php
use PHPUnit\Framework\TestCase;

class RelPathTest extends TestCase {
	public function test_posix_paths_are_unchanged() {
		$this->assertSame( 'uploads/2026/08/file.svg', IXES_Transfer::rel_path( '/srv/site/wp-content', '/srv/site/wp-content/uploads/2026/08/file.svg' ) );
	}

	public function test_windows_backslashes_become_forward_slashes() {
		// RecursiveDirectoryIterator on Windows: a nested file must match the remote's forward-slash path
		$this->assertSame( 'uploads/2026/08/file.svg', IXES_Transfer::rel_path( 'C:\\wamp\\www\\wp-content', 'C:\\wamp\\www\\wp-content\\uploads\\2026\\08\\file.svg' ) );
	}

	public function test_windows_root_with_mixed_separators() {
		// ABSPATH . 'wp-content' on Windows yields "C:\wamp\www/wp-content"; the offset must still line up
		$this->assertSame( 'uploads/2026/08/file.svg', IXES_Transfer::rel_path( 'C:\\wamp\\www/wp-content', 'C:\\wamp\\www/wp-content\\uploads\\2026\\08\\file.svg' ) );
	}

	public function test_top_level_file() {
		$this->assertSame( 'index.php', IXES_Transfer::rel_path( 'C:\\wamp\\www\\wp-content', 'C:\\wamp\\www\\wp-content\\index.php' ) );
	}
}
