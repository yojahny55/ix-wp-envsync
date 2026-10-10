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

	private function tracked( &$t ) {
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-prog-' . getmypid() . '-' . uniqid();
		$p = new IXES_Progress( 'summary', function () {}, null, function () use ( &$t ) { return $t; } );
		$p->track( 'push', 'prod' );
		return $p;
	}
	private static function file_json() { return json_decode( (string) @file_get_contents( IXES_Progress::file_path( 'push', 'prod' ) ), true ); }

	public function test_tracked_run_writes_its_progress_file_per_phase() {
		$t = 100.0;
		$p = $this->tracked( $t );
		$j = self::file_json();
		$this->assertSame( 'plan', $j['phase'] );
		$this->assertSame( 'push', $j['kind'] );
		$this->assertSame( getmypid(), $j['pid'] );
		$p->stage( 'Files', 3000, 2 );
		$j = self::file_json();
		$this->assertSame( 'files', $j['phase'] );
		$this->assertSame( [ 0, 2, 0, 3000 ], [ $j['files_done'], $j['files_total'], $j['bytes_done'], $j['bytes_total'] ] );
		$p->bytes( 1000 ); $p->item( 'a.jpg' ); $p->bytes( 2000 );
		$this->assertSame( 0, self::file_json()['files_done'], 'writes at most every few seconds' );
		$t = 100.0 + IXES_Progress::FILE_EVERY;
		$p->item( 'b.jpg' );
		$j = self::file_json();
		$this->assertSame( [ 2, 3000 ], [ $j['files_done'], $j['bytes_done'] ] );
		$p->job( 'job-1' );
		$p->stage( 'Database', null, 3 );
		$p->item( 'wp_posts' );
		$j = self::file_json();
		$this->assertSame( [ 'db', 'job-1', 3, 2 ], [ $j['phase'], $j['job'], $j['tables_total'], $j['files_done'] ] );
		$p->finish();
		$this->assertFileDoesNotExist( IXES_Progress::file_path( 'push', 'prod' ) );
	}

	public function test_running_reads_a_live_run_and_ignores_a_dead_one() {
		$t = 100.0;
		$p = $this->tracked( $t );
		$r = IXES_Progress::running( 'prod' );
		$this->assertSame( 'push', $r['kind'] );
		$this->assertSame( 'plan', $r['phase'] );
		$this->assertNull( IXES_Progress::running( 'staging' ) );
		$j = self::file_json(); $j['pid'] = 999999999;
		file_put_contents( IXES_Progress::file_path( 'push', 'prod' ), json_encode( $j ) );
		$this->assertNull( IXES_Progress::running( 'prod' ), 'a killed run leaves its file behind' );
		$p->finish();
	}

	public function test_notes_are_kept_for_the_run_file() {
		$lines = [];
		$p = new IXES_Progress( 'summary', function ( $m ) use ( &$lines ) { $lines[] = $m; } );
		$p->note( 'elementor: cache cleared' );
		$p->note( 'warning: x' );
		$this->assertSame( [ 'elementor: cache cleared', 'warning: x' ], $p->notes() );
		$this->assertSame( $lines, $p->notes() );
	}
}
