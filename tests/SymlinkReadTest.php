<?php
use PHPUnit\Framework\TestCase;

class SymlinkReadTest extends TestCase {
	private $out;

	protected function setUp(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ixes-wpc-' . getmypid() );
		$d = WP_CONTENT_DIR;
		$this->out = sys_get_temp_dir() . '/ixes-out-' . getmypid();
		@mkdir( "$this->out/dir", 0777, true ); file_put_contents( "$this->out/secret.txt", 'secret' ); file_put_contents( "$this->out/dir/s.txt", 's' );
		@mkdir( "$d/ixes-links", 0777, true ); file_put_contents( "$d/ixes-links/real.txt", 'real' );
		symlink( "$this->out/secret.txt", "$d/ixes-links/out.txt" );
		symlink( "$d/ixes-links/real.txt", "$d/ixes-links/in.txt" );
		symlink( "$this->out/dir", "$d/ixes-links/outdir" );
	}

	protected function tearDown(): void {
		// WP_CONTENT_DIR is shared across the run, and another test asserts an exact listing over it
		$d = WP_CONTENT_DIR;
		foreach ( [ 'out.txt', 'in.txt', 'outdir', 'real.txt' ] as $f ) @unlink( "$d/ixes-links/$f" );
		@rmdir( "$d/ixes-links" );
		@unlink( "$this->out/dir/s.txt" ); @rmdir( "$this->out/dir" ); @unlink( "$this->out/secret.txt" ); @rmdir( $this->out );
	}

	public function test_served_path_stays_inside_wp_content() {
		$this->assertSame( WP_CONTENT_DIR . '/ixes-links/real.txt', IXES_Transfer::served_path( 'ixes-links/real.txt' ) );
		$this->assertSame( WP_CONTENT_DIR . '/ixes-links/in.txt', IXES_Transfer::served_path( 'ixes-links/in.txt' ), 'a link inside wp-content is fine' );
		$this->assertNull( IXES_Transfer::served_path( 'ixes-links/out.txt' ), 'a file link out of wp-content' );
		$this->assertNull( IXES_Transfer::served_path( 'ixes-links/outdir/s.txt' ), 'through a symlinked folder' );
	}

	public function test_file_chunk_and_batch_refuse_what_leaves_wp_content() {
		$r = IXES_Transfer::file_chunk( 'ixes-links/out.txt', 0, 100 );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'bad_path', $r->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, IXES_Transfer::file_chunk( 'ixes-links/outdir/s.txt', 0, 100 ) );
		$this->assertSame( 'real', base64_decode( IXES_Transfer::file_chunk( 'ixes-links/in.txt', 0, 100 )['data'] ) );
		$batch = IXES_Transfer::file_batch( [ 'ixes-links/out.txt', 'ixes-links/outdir/s.txt', 'ixes-links/real.txt' ] );
		$this->assertStringNotContainsString( 'secret', $batch );
		$this->assertStringContainsString( 'real', $batch );
	}

	public function test_walk_leaves_out_a_file_link_out_of_wp_content() {
		$all = IXES_Transfer::all_files( [], [ 'ixes-links/' ] );
		$this->assertSame( [ 'ixes-links/in.txt', 'ixes-links/real.txt' ], $all );
	}
}
