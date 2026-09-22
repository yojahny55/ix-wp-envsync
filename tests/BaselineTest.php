<?php
use PHPUnit\Framework\TestCase;

class BaselineTest extends TestCase {
	private $path;
	protected function setUp(): void { $this->path = sys_get_temp_dir() . '/ixes-bl-' . uniqid() . '.sqlite'; }
	protected function tearDown(): void { @unlink( $this->path ); @unlink( $this->path . '.json' ); }

	public function test_roundtrip_rows_files_meta() {
		$b = new IXES_Baseline( $this->path );
		$b->reset();
		$b->write_rows( 'wp_posts', [ 1 => 'a', 2 => 'b' ] );
		$b->write_rows( 'wp_posts', [ 2 => 'c', 3 => 'd' ] ); // upsert
		$b->write_files( [ 'themes/x/style.css' => 'f1' ] );
		$b->meta( 'algo', 'sha1' );
		$b->commit();
		$b2 = new IXES_Baseline( $this->path );
		$this->assertTrue( $b2->exists() );
		$this->assertSame( [ 1 => 'a', 2 => 'c', 3 => 'd' ], $b2->rows( 'wp_posts' ) );
		$this->assertSame( [ 'themes/x/style.css' => 'f1' ], $b2->files() );
		$this->assertSame( 'sha1', $b2->meta( 'algo' ) );
		$this->assertSame( [ 'wp_posts' ], $b2->tables() );
		$this->assertIsInt( $b2->meta( 'created_at' ) );
	}
	public function test_missing_is_not_exists() {
		$this->assertFalse( ( new IXES_Baseline( $this->path ) )->exists() );
	}
	public function test_reset_does_not_mark_created_until_commit() {
		$bl = new IXES_Baseline( $this->path );
		$bl->reset();
		$this->assertFalse( $bl->exists(), 'reset alone must not claim a fresh baseline' );
		$bl->commit();
		$this->assertTrue( $bl->exists() );
	}
	public function test_delete_table_removes_only_that_table() {
		$bl = new IXES_Baseline( $this->path );
		$bl->write_rows( 'wp_posts', [ 1 => 'a' ] ); $bl->write_rows( 'wp_terms', [ 1 => 'b' ] );
		$bl->delete_table( 'wp_posts' );
		$this->assertSame( [], $bl->rows( 'wp_posts' ) );
		$this->assertSame( [ 1 => 'b' ], $bl->rows( 'wp_terms' ) );
	}
}
