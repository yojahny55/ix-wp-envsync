<?php
use PHPUnit\Framework\TestCase;

class SnapshotEstimateTest extends TestCase {
	public function test_counts_only_files_the_remote_already_holds() {
		$s = IXES_Planner::snapshot_estimate( [ 'uploads/a.jpg', 'uploads/new.jpg', 'uploads/gone.jpg' ], [ 'uploads/a.jpg' => 'h1', 'uploads/gone.jpg' => 'h2' ], [ 'uploads/a.jpg' => 100, 'uploads/gone.jpg' => 50 ] );
		$this->assertSame( [ 'files' => 2, 'bytes' => 150 ], $s );
	}

	public function test_a_remote_without_sizes_gives_no_bytes() {
		$this->assertSame( [ 'files' => 1, 'bytes' => null ], IXES_Planner::snapshot_estimate( [ 'uploads/a.jpg' ], [ 'uploads/a.jpg' => 'h' ], null ) );
	}

	/** A --mirror push of uploads deleted 5,427 remote files and left a 768 MB rollback copy nobody was told about. */
	public function test_warns_past_either_threshold() {
		$this->assertNull( IXES_Planner::snapshot_warning( [ 'files' => 40, 'bytes' => 3 * 1048576 ], 'staging' ) );
		$this->assertStringContainsString( '5427 file(s)', (string) IXES_Planner::snapshot_warning( [ 'files' => 5427, 'bytes' => null ], 'staging' ) );
		$w = (string) IXES_Planner::snapshot_warning( [ 'files' => 12, 'bytes' => 768 * 1048576 ], 'staging' );
		$this->assertStringContainsString( '768 MB', $w );
		$this->assertStringStartsWith( 'staging keeps a rollback copy', $w );
	}
}
