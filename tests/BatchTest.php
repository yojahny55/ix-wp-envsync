<?php
use PHPUnit\Framework\TestCase;

class BatchTest extends TestCase {
	public function test_pack_splits_by_bytes_items_and_size() {
		$r = IXES_Batch::pack( [ 'a' => 40, 'b' => 40, 'big' => 1000, 'c' => 40, 'd' => 10 ], 100, 3, 500 );
		$this->assertSame( [ [ 'a', 'b' ], [ 'c', 'd' ] ], $r['batches'] );
		$this->assertSame( [ 'big' ], $r['large'] );
		$r = IXES_Batch::pack( [ 'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1 ], 100, 3, 500 );
		$this->assertSame( [ [ 'a', 'b', 'c' ], [ 'd' ] ], $r['batches'] );
	}

	public function test_round_trip_including_empty_and_binary_files() {
		$items = [ [ [ 'path' => 'x.php', 'sha256' => 'h1' ], "<?php\n" ], [ [ 'path' => 'empty.txt', 'sha256' => 'h2', 'expect' => null ], '' ], [ [ 'path' => 'bin', 'sha256' => 'h3' ], "\x00\n\xff" ] ];
		$d = IXES_Batch::decode( IXES_Batch::encode( $items ) );
		$this->assertCount( 3, $d );
		$this->assertSame( "<?php\n", $d[0][1] );
		$this->assertSame( '', $d[1][1] );
		$this->assertArrayHasKey( 'expect', $d[1][0] );
		$this->assertNull( $d[1][0]['expect'] );
		$this->assertSame( "\x00\n\xff", $d[2][1] );
	}

	public function test_decode_rejects_truncated_and_trailing() {
		$raw = IXES_Batch::encode( [ [ [ 'path' => 'x' ], 'hello' ] ] );
		$this->assertInstanceOf( WP_Error::class, IXES_Batch::decode( substr( $raw, 0, -1 ) ) );
		$this->assertInstanceOf( WP_Error::class, IXES_Batch::decode( $raw . 'x' ) );
		$this->assertInstanceOf( WP_Error::class, IXES_Batch::decode( 'no newline' ) );
	}
}
