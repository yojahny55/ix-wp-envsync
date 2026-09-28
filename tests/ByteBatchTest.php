<?php
use PHPUnit\Framework\TestCase;

class ByteBatchTest extends TestCase {

	public function test_row_bytes_counts_quotes_and_null() {
		// 2 surrounding parens + ('ab' -> quoted 4 bytes + 1 separator) + (null -> 4 bytes + 1 separator)
		$this->assertSame( 12, IXES_Transfer::row_bytes( [ 'a' => 'ab', 'b' => null ] ) );
		$this->assertGreaterThan( IXES_Transfer::row_bytes( [ 'a' => null ] ), IXES_Transfer::row_bytes( [ 'a' => str_repeat( 'x', 50 ) ] ) );
	}

	public function test_row_batches_splits_once_a_batch_would_cross_budget() {
		$rows = [ [ 'v' => str_repeat( 'x', 10 ) ], [ 'v' => str_repeat( 'x', 10 ) ], [ 'v' => str_repeat( 'x', 10 ) ] ];
		$one = IXES_Transfer::row_bytes( $rows[0] );
		$batches = IXES_Transfer::row_batches( $rows, $one * 2 ); // room for two rows, not three
		$this->assertCount( 2, $batches );
		$this->assertCount( 2, $batches[0] );
		$this->assertCount( 1, $batches[1] );
	}

	public function test_row_batches_never_splits_a_single_row_further() {
		$huge = [ 'v' => str_repeat( 'x', 1000 ) ];
		$batches = IXES_Transfer::row_batches( [ $huge ], 10 ); // budget far smaller than one row
		$this->assertCount( 1, $batches );
		$this->assertCount( 1, $batches[0] );
	}

	public function test_row_batches_one_batch_when_everything_fits() {
		$rows = array_fill( 0, 20, [ 'v' => 'x' ] );
		$this->assertCount( 1, IXES_Transfer::row_batches( $rows, 1000000 ) );
	}

	public function test_row_batches_preserves_row_order() {
		$rows = [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3 ], [ 'id' => 4 ] ];
		$flat = array_merge( ...IXES_Transfer::row_batches( $rows, IXES_Transfer::row_bytes( $rows[0] ) ) );
		$this->assertSame( [ 1, 2, 3, 4 ], array_column( $flat, 'id' ) );
	}

	public function test_row_batches_empty_input() {
		$this->assertSame( [], IXES_Transfer::row_batches( [], 1000 ) );
	}

	/** import_rows() batches already-escaped SQL fragments by their exact strlen, not the row estimate -- escaping
	 *  (quotes/backslashes double) and the URL/path rewrite can grow a value past what row_bytes() would guess. */
	public function test_row_batches_takes_a_custom_sizer_for_pre_built_strings() {
		$frags = [ "('aa')", "('bb')", "('cc')" ]; // 6 bytes each
		$batches = IXES_Transfer::row_batches( $frags, 12, 'strlen' ); // room for two, not three
		$this->assertSame( [ [ "('aa')", "('bb')" ], [ "('cc')" ] ], $batches );
	}
	public function test_row_batches_custom_sizer_never_splits_one_oversized_item() {
		$batches = IXES_Transfer::row_batches( [ str_repeat( 'x', 1000 ) ], 10, 'strlen' );
		$this->assertCount( 1, $batches );
		$this->assertCount( 1, $batches[0] );
	}

	public function test_budget_page_stops_before_crossing_budget() {
		$rows = [ [ 'v' => str_repeat( 'x', 10 ) ], [ 'v' => str_repeat( 'x', 10 ) ], [ 'v' => str_repeat( 'x', 10 ) ] ];
		$one = IXES_Transfer::row_bytes( $rows[0] );
		$page = IXES_Transfer::budget_page( $rows, $one * 2 );
		$this->assertCount( 2, $page['rows'] );
		$this->assertTrue( $page['cut'] );
	}

	public function test_budget_page_zero_budget_is_no_limit() {
		$rows = array_fill( 0, 50, [ 'v' => str_repeat( 'x', 1000 ) ] );
		$page = IXES_Transfer::budget_page( $rows, 0 );
		$this->assertSame( $rows, $page['rows'] );
		$this->assertFalse( $page['cut'] );
	}

	public function test_budget_page_one_oversized_row_still_goes_out() {
		$rows = [ [ 'v' => str_repeat( 'x', 1000 ) ] ];
		$page = IXES_Transfer::budget_page( $rows, 10 );
		$this->assertCount( 1, $page['rows'] );
		$this->assertFalse( $page['cut'], 'nothing was left out, so this was not a cut page' );
	}

	public function test_budget_page_everything_fits() {
		$rows = [ [ 'v' => 'a' ], [ 'v' => 'b' ] ];
		$page = IXES_Transfer::budget_page( $rows, 1000000 );
		$this->assertSame( $rows, $page['rows'] );
		$this->assertFalse( $page['cut'] );
	}

	public function test_packet_budget_is_75_percent_of_max_allowed_packet() {
		IXES_Transfer::forget_max_allowed_packet();
		// no $wpdb in this suite, so max_allowed_packet() falls back to its floor (1 MiB)
		$this->assertSame( 1048576, IXES_Transfer::max_allowed_packet() );
		$this->assertSame( (int) floor( 1048576 * 0.75 ), IXES_Transfer::packet_budget() );
	}
}
