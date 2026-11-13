<?php
use PHPUnit\Framework\TestCase;

class JobLabelTest extends TestCase {
	private $saved;

	protected function setUp(): void {
		$this->saved = $GLOBALS['ixes_test_storage'] ?? null;
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-label-' . uniqid();
		unset( $GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ] );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $GLOBALS['ixes_test_storage'] ) );
		$GLOBALS['ixes_test_storage'] = $this->saved;
		unset( $GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ] );
	}

	private function job( $job, $meta ) {
		$dir = ixes_storage_dir() . '/jobs/' . $job;
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/meta.json', is_string( $meta ) ? $meta : json_encode( $meta ) );
	}

	public function test_reads_the_label_back_from_meta() {
		$this->job( '20260101-000000-aaaaaa', [ 'job' => 'x', 'plan' => IXES_Applier::plan_meta_shape( [ 'label' => 'prod' ] ) ] );
		$this->assertSame( 'prod', IXES_Applier::job_label( '20260101-000000-aaaaaa' ) );
	}

	public function test_empty_job_means_the_locked_job() {
		$this->job( '20260101-000000-bbbbbb', [ 'plan' => [ 'label' => 'staging' ] ] );
		$this->job( '20260102-000000-cccccc', [ 'plan' => [ 'label' => 'prod' ] ] );
		set_transient( IXES_Applier::LOCK, '20260101-000000-bbbbbb|1758535000' );
		$this->assertSame( 'staging', IXES_Applier::job_label( '' ) );
		$this->assertSame( 'staging', IXES_Applier::job_label() );
	}

	public function test_without_a_lock_the_most_recent_job_is_used() {
		$this->assertSame( '', IXES_Applier::job_label() );
		$this->job( '20260101-000000-bbbbbb', [ 'plan' => [ 'label' => 'staging' ] ] );
		$this->job( '20260102-000000-cccccc', [ 'plan' => [ 'label' => 'prod' ] ] );
		$this->assertSame( 'prod', IXES_Applier::job_label() );
	}

	public function test_missing_or_bad_data_is_empty() {
		$this->assertSame( '', IXES_Applier::job_label( 'nope' ) );
		$this->assertSame( '', IXES_Applier::job_label( '../../etc' ) );
		$this->job( 'garbage', 'not json' );
		$this->assertSame( '', IXES_Applier::job_label( 'garbage' ) );
		$this->job( 'old', [ 'plan' => [ 'env' => 'x' ] ] );
		$this->assertSame( '', IXES_Applier::job_label( 'old' ) );
		$this->job( 'odd', [ 'plan' => [ 'label' => 'production' ] ] );
		$this->assertSame( '', IXES_Applier::job_label( 'odd' ) );
	}
}
