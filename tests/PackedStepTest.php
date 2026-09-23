<?php
use PHPUnit\Framework\TestCase;

class PackedStepTest extends TestCase {
	public function test_packed_step_round_trips_serialized_rows() {
		$step = [ 'job' => 'j1', 'kind' => 'rows', 'table' => 'wp_actionscheduler_actions', 'pk' => 'action_id',
			'rows' => [ [ 'action_id' => '10', 'schedule' => 'O:32:"ActionScheduler_IntervalSchedule":5:{s:22:"' . "\0" . '*' . "\0" . 'scheduled_timestamp";i:1;}' ] ] ];
		$p = IXES_Rest::unpack_step( [ 'job' => 'j1', 'kind' => 'packed' ], gzdeflate( json_encode( $step ) ) );
		$this->assertSame( $step, $p );
	}

	public function test_plain_binary_step_keeps_its_body() {
		$this->assertSame( [ 'job' => 'j1', 'kind' => 'files', 'bin' => "raw\x00" ], IXES_Rest::unpack_step( [ 'job' => 'j1', 'kind' => 'files' ], "raw\x00" ) );
	}

	public function test_garbage_or_nested_packed_is_refused() {
		$this->assertSame( [], IXES_Rest::unpack_step( [ 'kind' => 'packed' ], 'not deflate' ) );
		$this->assertSame( [], IXES_Rest::unpack_step( [ 'kind' => 'packed' ], gzdeflate( json_encode( [ 'kind' => 'packed' ] ) ) ) );
	}
}
