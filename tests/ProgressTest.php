<?php
use PHPUnit\Framework\TestCase;

class ProgressTest extends TestCase {
	public function test_summary_mode_prints_one_line_per_stage() {
		$lines = []; $t = 100.0;
		$p = new IXES_Progress( 'summary', function ( $m ) use ( &$lines ) { $lines[] = $m; }, null, function () use ( &$t ) { return $t; } );
		$p->stage( 'Files', 4 * 1048576, 2 );
		$p->bytes( 2 * 1048576 ); $p->item( 'a.jpg' );
		$p->bytes( 2 * 1048576 ); $p->item( 'b.jpg' );
		$t = 104.0;
		$p->stage( 'Database', null, 3 );
		$p->item( 'wp_posts' );
		$p->end();
		$this->assertSame( [ 'files: 2 (4.0 MB) in 4s, 1.0 MB/s', 'database: 1 in 0s' ], $lines );
		$lines = [];
		$p->stage( 'Database', null, 0 ); $p->end();
		$this->assertSame( [], $lines, 'an empty stage prints nothing' );
	}

	public function test_bar_mode_ticks_in_kb_and_finishes() {
		$bar = new class { public $ticks = 0; public $done = false; public $msg = '';
			public function tick( $n = 1, $m = null ) { $this->ticks += $n; $this->msg = $m; }
			public function finish() { $this->done = true; } };
		$made = [];
		$p = new IXES_Progress( 'bar', function () {}, function ( $l, $c ) use ( $bar, &$made ) { $made[] = [ trim( $l ), $c ]; return $bar; } );
		$p->stage( 'Files', 3000, 1 );
		$p->bytes( 2048 ); $p->bytes( 952 );
		$p->end();
		$this->assertSame( [ [ 'Files', 3 ] ], $made );
		$this->assertSame( 2, $bar->ticks );
		$this->assertTrue( $bar->done );
		$this->assertStringContainsString( '/s', $bar->msg );
	}

	public function test_verbose_mode_lists_items() {
		$lines = [];
		$p = new IXES_Progress( 'verbose', function ( $m ) use ( &$lines ) { $lines[] = $m; } );
		$p->stage( 'file', 10, 2 ); $p->item( 'x.php' ); $p->end();
		$this->assertSame( [ 'file 1/2 x.php' ], $lines );
	}
}
