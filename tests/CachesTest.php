<?php
use PHPUnit\Framework\TestCase;

class CachesTest extends TestCase {
	private $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/ixes-caches-' . getmypid() . '-' . uniqid();
		mkdir( $this->root, 0777, true );
	}

	protected function tearDown(): void { $this->rm( $this->root ); }

	private function rm( $p ) {
		if ( is_link( $p ) || is_file( $p ) ) { unlink( $p ); return; }
		if ( ! is_dir( $p ) ) return;
		foreach ( array_diff( scandir( $p ), [ '.', '..' ] ) as $n ) $this->rm( "{$p}/{$n}" );
		rmdir( $p );
	}

	private function put( $rel, $body = 'x' ) {
		$p = "{$this->root}/{$rel}";
		if ( ! is_dir( dirname( $p ) ) ) mkdir( dirname( $p ), 0777, true );
		file_put_contents( $p, $body );
	}

	public function test_empties_the_page_cache_folders_and_keeps_them() {
		$this->put( 'wp-cloudflare-super-page-cache/remote.test/index.html' );
		$this->put( 'wp-cloudflare-super-page-cache/main_config.php' );
		$this->put( 'cache/wp-rocket/site.test/index.html' );
		$this->put( 'uploads/2025/07/a.jpg' );
		$r = IXES_Caches::purge( $this->root );
		$this->assertSame( [ 'cache/', 'wp-cloudflare-super-page-cache/' ], $r['purged'] );
		$this->assertSame( 0, $r['left'] );
		$this->assertSame( [], array_values( array_diff( scandir( "{$this->root}/wp-cloudflare-super-page-cache" ), [ '.', '..' ] ) ) );
		$this->assertDirectoryExists( "{$this->root}/cache" );
		$this->assertFileExists( "{$this->root}/uploads/2025/07/a.jpg" );
		$this->assertSame( [ 'page cache emptied: cache/, wp-cloudflare-super-page-cache/' ], IXES_Caches::lines( $r ) );
	}

	public function test_nothing_to_say_without_a_page_cache() {
		$r = IXES_Caches::purge( $this->root );
		$this->assertSame( [ 'purged' => [], 'left' => 0 ], $r );
		$this->assertSame( [], IXES_Caches::lines( $r ) );
	}

	public function test_a_symlinked_cache_folder_is_left_alone() {
		$this->put( 'elsewhere/keep.html' );
		symlink( "{$this->root}/elsewhere", "{$this->root}/litespeed" );
		$this->assertSame( [], IXES_Caches::purge( $this->root )['purged'] );
		$this->assertFileExists( "{$this->root}/elsewhere/keep.html" );
	}

	public function test_an_unreadable_folder_is_counted_not_walked() {
		if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 ) $this->markTestSkipped( 'root reads any folder' );
		$this->put( 'cache/other-user/page.html' );
		chmod( "{$this->root}/cache/other-user", 0 );
		$r = IXES_Caches::purge( $this->root );
		chmod( "{$this->root}/cache/other-user", 0777 );
		$this->assertSame( 2, $r['left'], 'the folder it cannot read, and the folder holding it' );
		$this->assertFileExists( "{$this->root}/cache/other-user/page.html" );
	}

	public function test_undeletable_entries_are_counted_and_warned() {
		$this->assertSame( [ 'warning: 2 cached file(s) or folder(s) could not be deleted (check ownership under wp-content)' ], IXES_Caches::lines( [ 'purged' => [], 'left' => 2 ] ) );
	}
}
