<?php
use PHPUnit\Framework\TestCase;

class HashcacheTest extends TestCase {

	private $work;

	protected function setUp(): void {
		$this->work = sys_get_temp_dir() . '/ixes-hc-' . getmypid() . '-' . uniqid();
		mkdir( $this->work . '/store', 0777, true );
		$GLOBALS['ixes_test_storage'] = $this->work . '/store';
		IXES_Hashcache::flush();
	}

	protected function tearDown(): void {
		foreach ( glob( $this->work . '/store/*' ) as $f ) @unlink( $f );
		foreach ( glob( $this->work . '/*' ) as $f ) { if ( is_file( $f ) ) @unlink( $f ); }
		@rmdir( $this->work . '/store' );
		@rmdir( $this->work );
	}

	/** point the cache at a second storage dir so the next call reloads from disk */
	private function reload() {
		$dir = $this->work . '/store';
		$GLOBALS['ixes_test_storage'] = $dir . '/..' . '/store'; // same path, different string
		clearstatcache();
	}

	private function write( $name, $content, $mtime = null ) {
		$p = $this->work . '/' . $name;
		file_put_contents( $p, $content );
		if ( $mtime !== null ) touch( $p, $mtime );
		clearstatcache( true, $p );
		return $p;
	}

	public function test_cold_miss_computes_and_stores() {
		$p = $this->write( 'a.txt', 'hello' );
		$h = IXES_Hashcache::hash( $p, 'a.txt', 'sha1' );
		$this->assertSame( sha1( 'hello' ), $h );
		IXES_Hashcache::save();
		$j = json_decode( file_get_contents( $this->work . '/store/hashcache.json' ), true );
		$this->assertSame( sha1( 'hello' ), $j['a.txt']['h']['sha1'] );
		$this->assertSame( 5, $j['a.txt']['s'] );
	}

	public function test_warm_hit_skips_reading_the_file() {
		$p = $this->write( 'a.txt', 'hello', 1000000 );
		$this->assertSame( sha1( 'hello' ), IXES_Hashcache::hash( $p, 'a.txt', 'sha1' ) );
		// same size, same mtime, different bytes: a cache hit must return the stale hash,
		// which is only possible if the file was not read again.
		$this->write( 'a.txt', 'world', 1000000 );
		$this->assertSame( sha1( 'hello' ), IXES_Hashcache::hash( $p, 'a.txt', 'sha1' ) );
		$this->assertNotSame( sha1( 'world' ), sha1( 'hello' ) );
	}

	public function test_changed_size_invalidates() {
		$p = $this->write( 'a.txt', 'hello', 1000000 );
		IXES_Hashcache::hash( $p, 'a.txt', 'sha1' );
		$this->write( 'a.txt', 'hello!', 1000000 ); // same mtime, bigger
		$this->assertSame( sha1( 'hello!' ), IXES_Hashcache::hash( $p, 'a.txt', 'sha1' ) );
	}

	public function test_changed_mtime_invalidates() {
		$p = $this->write( 'a.txt', 'hello', 1000000 );
		IXES_Hashcache::hash( $p, 'a.txt', 'sha1' );
		$this->write( 'a.txt', 'world', 2000000 ); // same size, newer
		$this->assertSame( sha1( 'world' ), IXES_Hashcache::hash( $p, 'a.txt', 'sha1' ) );
	}

	public function test_different_algo_is_computed_separately() {
		$p = $this->write( 'a.txt', 'hello', 1000000 );
		$this->assertSame( sha1( 'hello' ), IXES_Hashcache::hash( $p, 'a.txt', 'sha1' ) );
		$this->assertSame( hash( 'sha256', 'hello' ), IXES_Hashcache::hash( $p, 'a.txt', 'sha256' ) );
		IXES_Hashcache::save();
		$j = json_decode( file_get_contents( $this->work . '/store/hashcache.json' ), true );
		$this->assertSame( [ 'sha1', 'sha256' ], array_keys( $j['a.txt']['h'] ) );
	}

	public function test_persisted_cache_is_reused_across_loads() {
		$p = $this->write( 'a.txt', 'hello', 1000000 );
		IXES_Hashcache::hash( $p, 'a.txt', 'sha1' );
		IXES_Hashcache::save();
		$this->reload();
		$this->write( 'a.txt', 'world', 1000000 );
		$this->assertSame( sha1( 'hello' ), IXES_Hashcache::hash( $p, 'a.txt', 'sha1' ) );
	}

	public function test_corrupt_cache_file_is_survived() {
		file_put_contents( $this->work . '/store/hashcache.json', '{not json at all' );
		$this->reload();
		$p = $this->write( 'a.txt', 'hello' );
		$this->assertSame( sha1( 'hello' ), IXES_Hashcache::hash( $p, 'a.txt', 'sha1' ) );
		IXES_Hashcache::save();
		$j = json_decode( file_get_contents( $this->work . '/store/hashcache.json' ), true );
		$this->assertIsArray( $j );
		$this->assertSame( sha1( 'hello' ), $j['a.txt']['h']['sha1'] );
	}

	public function test_unreadable_file_returns_false() {
		$this->assertFalse( IXES_Hashcache::hash( $this->work . '/missing.txt', 'missing.txt', 'sha1' ) );
	}

	public function test_flush_removes_the_cache_file() {
		$p = $this->write( 'a.txt', 'hello' );
		IXES_Hashcache::hash( $p, 'a.txt', 'sha1' );
		IXES_Hashcache::save();
		$this->assertFileExists( $this->work . '/store/hashcache.json' );
		IXES_Hashcache::flush();
		$this->assertFileDoesNotExist( $this->work . '/store/hashcache.json' );
	}
}
