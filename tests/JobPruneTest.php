<?php
use PHPUnit\Framework\TestCase;

class JobPruneTest extends TestCase {
	private $root;
	private $saved;

	protected function setUp(): void {
		$this->saved = $GLOBALS['ixes_test_storage'] ?? null;
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-prune-' . uniqid();
		$this->root = ixes_storage_dir() . '/jobs';
		unset( $GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ] );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $GLOBALS['ixes_test_storage'] ) );
		$GLOBALS['ixes_test_storage'] = $this->saved;
		unset( $GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ] );
	}

	private function job( $started ) {
		$job = date( 'Ymd-His', $started ) . '-' . substr( md5( (string) $started ), 0, 6 );
		mkdir( $this->root . '/' . $job . '/files/uploads', 0777, true );
		file_put_contents( $this->root . '/' . $job . '/files/uploads/a.jpg', str_repeat( 'x', 100 ) );
		file_put_contents( $this->root . '/' . $job . '/meta.json', '{}' );
		return $job;
	}

	private function left() {
		$d = array_map( 'basename', glob( $this->root . '/*', GLOB_ONLYDIR ) ?: [] );
		sort( $d );
		return $d;
	}

	public function test_no_jobs_folder_is_nothing_to_do() {
		$this->assertSame( [], IXES_Applier::prune_jobs() );
		$this->assertDirectoryDoesNotExist( $this->root );
	}

	/** Before, only a push that finished pruned, and it kept three: an aborted push's snapshot stayed for good. */
	public function test_keeps_only_the_newest_job() {
		$now = time();
		$old = $this->job( $now - 3 * 86400 );
		$mid = $this->job( $now - 2 * 86400 );
		$new = $this->job( $now - 3600 );
		$this->assertEqualsCanonicalizing( [ $old, $mid ], IXES_Applier::prune_jobs( false, $now ) );
		$this->assertSame( [ $new ], $this->left() );
	}

	public function test_a_kept_job_past_the_ttl_goes_too() {
		$now = time();
		$this->job( $now - 8 * 86400 );
		IXES_Applier::prune_jobs( false, $now );
		$this->assertSame( [], $this->left() );
	}

	public function test_the_locked_job_stays_and_does_not_use_up_the_keep() {
		$now = time();
		$done = $this->job( $now - 86400 );
		$live = $this->job( $now - 30 * 86400 ); // older than the TTL, but a push still holds it
		set_transient( IXES_Applier::LOCK, IXES_Applier::lock_value( $live, $now - 60 ) );
		IXES_Applier::prune_jobs( false, $now );
		$this->assertEqualsCanonicalizing( [ $done, $live ], $this->left() );
	}

	/** An aborted push is rolled back: keeping it as "the one" dropped the last good push, and a later rollback replayed it. */
	public function test_a_rolled_back_job_goes_and_the_last_good_one_stays() {
		$now = time();
		$good = $this->job( $now - 7200 );
		$bad  = $this->job( $now - 3600 );
		file_put_contents( $this->root . '/' . $bad . '/meta.json', json_encode( [ 'rolled_back' => $now - 60 ] ) );
		$this->assertSame( [ $bad ], IXES_Applier::prune_jobs( false, $now ) );
		$this->assertSame( [ $good ], $this->left() );
	}

	public function test_dry_run_deletes_nothing() {
		$now = time();
		$old = $this->job( $now - 2 * 86400 );
		$this->job( $now - 3600 );
		$this->assertSame( [ $old ], IXES_Applier::prune_jobs( true, $now ) );
		$this->assertCount( 2, $this->left() );
	}

	public function test_usage_counts_folders_and_bytes() {
		$now = time();
		$this->job( $now - 3600 );
		$this->job( $now - 7200 );
		$this->assertSame( [ 'count' => 2, 'bytes' => 204 ], IXES_Applier::jobs_usage() );
	}
}
