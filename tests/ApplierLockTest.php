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
}
