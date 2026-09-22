<?php
use PHPUnit\Framework\TestCase;

class AttemptTest extends TestCase {
	private function flaky( array $results ) {
		return function () use ( &$results ) { return array_shift( $results ); };
	}

	public function test_no_menu_rolls_back_on_first_error() {
		$choice = null;
		$r = IXES_Applier::attempt( $this->flaky( [ new WP_Error( 'x', 'boom' ), [ 'ok' => true ] ] ), null, null, $choice );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'rollback', $choice );
	}

	public function test_retry_then_success() {
		$asked = 0; $choice = null;
		$r = IXES_Applier::attempt( $this->flaky( [ new WP_Error( 'x', 'boom' ), [ 'ok' => true ] ] ), function () use ( &$asked ) { $asked++; return 'retry'; }, null, $choice );
		$this->assertSame( [ 'ok' => true ], $r );
		$this->assertSame( 1, $asked );
	}

	public function test_plugins_off_runs_then_retries() {
		$off = 0; $choice = null;
		$r = IXES_Applier::attempt( $this->flaky( [ new WP_Error( 'x', 'boom' ), [ 'ok' => true ] ] ), function () { return 'plugins_off'; }, function () use ( &$off ) { $off++; }, $choice );
		$this->assertSame( [ 'ok' => true ], $r );
		$this->assertSame( 1, $off );
	}

	public function test_leave_returns_the_error() {
		$choice = null;
		$r = IXES_Applier::attempt( $this->flaky( [ new WP_Error( 'x', 'boom' ) ] ), function () { return 'leave'; }, null, $choice );
		$this->assertSame( 'boom', $r->get_error_message() );
		$this->assertSame( 'leave', $choice );
	}
}
