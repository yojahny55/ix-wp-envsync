<?php
use PHPUnit\Framework\TestCase;

class ApplierLockTest extends TestCase {
	public function test_lock_value_round_trips() {
		$v = IXES_Applier::lock_value( '20260922-101500-ab12cd', 1758535000 );
		$this->assertSame( '20260922-101500-ab12cd|1758535000', $v );
		$this->assertSame( [ 'job' => '20260922-101500-ab12cd', 'started' => 1758535000 ], IXES_Applier::parse_lock( $v ) );
	}
	public function test_parse_lock_accepts_pre_04_bare_job() {
		$this->assertSame( [ 'job' => '20260922-101500-ab12cd', 'started' => null ], IXES_Applier::parse_lock( '20260922-101500-ab12cd' ) );
	}
	public function test_parse_lock_of_nothing() {
		$this->assertSame( [ 'job' => '', 'started' => null ], IXES_Applier::parse_lock( false ) );
	}
	public function test_plan_meta_shape_survives_garbage() {
		$this->assertSame( [ 'tables' => [], 'files' => [ 'push' => [], 'delete' => [] ] ], IXES_Applier::plan_meta_shape( 'not an array' ) );
		$s = IXES_Applier::plan_meta_shape( [ 'tables' => [ 'wp_posts' => [ 'pk' => 'ID' ] ], 'files' => [ 'push' => 'x' ] ] );
		$this->assertSame( [ 'wp_posts' => [ 'pk' => 'ID' ] ], $s['tables'] );
		$this->assertSame( [ 'x' ], $s['files']['push'] );
		$this->assertSame( [], $s['files']['delete'] );
	}
	private function upgrading_for( array $server ) {
		$f = tempnam( sys_get_temp_dir(), 'ixes' );
		file_put_contents( $f, IXES_Applier::maintenance_body( 1758535000 ) );
		$saved = $_SERVER; $_SERVER = $server;
		include $f;
		$_SERVER = $saved; unlink( $f );
		return $upgrading;
	}
	public function test_maintenance_blocks_visitors_but_not_health_checks_or_our_calls() {
		$this->assertSame( 1758535000, $this->upgrading_for( [ 'REMOTE_ADDR' => '172.18.0.5', 'REQUEST_URI' => '/' ] ) );
		$this->assertSame( 1758535000, $this->upgrading_for( [ 'REMOTE_ADDR' => '172.18.0.5', 'REQUEST_URI' => '/wp-json/envsync/v1/job/step' ] ) );
		$this->assertSame( 1, $this->upgrading_for( [ 'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_URI' => '/' ] ) );
		$this->assertSame( 1, $this->upgrading_for( [ 'REMOTE_ADDR' => '::1', 'REQUEST_URI' => '/' ] ) );
		$this->assertSame( 1, $this->upgrading_for( [ 'REMOTE_ADDR' => '172.18.0.5', 'REQUEST_URI' => '/wp-json/envsync/v1/job/step', 'HTTP_X_ENVSYNC_SIG' => 'x' ] ) );
	}
}
