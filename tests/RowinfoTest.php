<?php
use PHPUnit\Framework\TestCase;

/** diff --table --id and --list: where a row is, and which rows sit in each column (#49). */
class RowinfoTest extends TestCase {
	private $lp; private $rp;
	protected function setUp(): void {
		$this->lp = IXES_Hasher::placeholders( 'https://site.local', '/srv/site' );
		$this->rp = IXES_Hasher::placeholders( 'https://staging.test', '/home/s' );
	}
	private function cmp( $l, $r ) { return IXES_Rowinfo::compare( $l, $r, $this->lp, $this->rp ); }

	public function test_where_a_row_is() {
		$this->assertSame( 'neither', $this->cmp( null, null )['where'] );
		$this->assertSame( 'local-only', $this->cmp( [ 'ID' => 1 ], null )['where'] );
		$this->assertSame( 'remote-only', $this->cmp( null, [ 'ID' => 1 ] )['where'] );
		$this->assertSame( 'same', $this->cmp( [ 'ID' => 1, 'u' => 'https://site.local/a' ], [ 'ID' => 1, 'u' => 'https://staging.test/a' ] )['where'] );
		$this->assertSame( 'differs', $this->cmp( [ 'ID' => 1, 't' => 'a' ], [ 'ID' => 1, 't' => 'b' ] )['where'] );
	}

	public function test_fields_list_what_differs_or_the_one_side_there_is() {
		$this->assertSame( [ [ 't', 'b', 'a' ] ], $this->cmp( [ 'ID' => 1, 't' => 'a', 'x' => 'same' ], [ 'ID' => 1, 't' => 'b', 'x' => 'same' ] )['fields'] );
		$this->assertSame( [ [ 'ID', '1', null ], [ 't', '{{URL}}/p', null ] ], $this->cmp( null, [ 'ID' => 1, 't' => 'https://staging.test/p' ] )['fields'] );
		$this->assertSame( [ [ 'ID', null, '1' ] ], $this->cmp( [ 'ID' => 1 ], null )['fields'] );
	}

	public function test_extra_pairs_count_as_the_same() {
		$lp = IXES_Hasher::placeholders( 'https://site.local', '/srv/site', [ 'https://cdn.local' ] );
		$rp = IXES_Hasher::placeholders( 'https://staging.test', '/home/s', [ 'https://cdn.test' ] );
		$this->assertSame( 'same', IXES_Rowinfo::compare( [ 'u' => 'https://cdn.local/a' ], [ 'u' => 'https://cdn.test/a' ], $lp, $rp )['where'] );
	}

	public function test_ids_per_column_and_the_side_that_has_them() {
		$t = [ 'push' => [ 1 ], 'insert' => [ 2 ], 'delete' => [ 3 ], 'conflict' => [ 4 ], 'kept' => [ 4, 5 ] ];
		$this->assertSame( [ 'ids' => [ '2' ], 'side' => 'local' ], IXES_Rowinfo::ids( $t, 'local-only' ) );
		$this->assertSame( [ 'ids' => [ '2' ], 'side' => 'local' ], IXES_Rowinfo::ids( $t, 'insert' ) );
		$this->assertSame( [ 'ids' => [ '5' ], 'side' => 'remote' ], IXES_Rowinfo::ids( $t, 'remote-only' ) );
		$this->assertSame( [ 'ids' => [ '5' ], 'side' => 'remote' ], IXES_Rowinfo::ids( $t, 'kept-remote' ) );
		$this->assertSame( [ 'ids' => [ '4' ], 'side' => 'local' ], IXES_Rowinfo::ids( $t, 'differs' ) );
		$this->assertSame( [ 'ids' => [ '4' ], 'side' => 'local' ], IXES_Rowinfo::ids( $t, 'remote-wins' ) );
		$this->assertSame( [ 'ids' => [ '3' ], 'side' => 'remote' ], IXES_Rowinfo::ids( $t, 'delete' ) );
		$this->assertSame( [ 'ids' => [ '1' ], 'side' => 'local' ], IXES_Rowinfo::ids( $t, 'push' ) );
		$this->assertNull( IXES_Rowinfo::ids( $t, 'bogus' ) );
	}

	public function test_label_columns_for_core_tables() {
		$this->assertSame( [ 'post_type', 'post_status', 'post_date', 'post_title' ], IXES_Rowinfo::label_columns( 'wp_posts', 'wp_' ) );
		$this->assertSame( [ 'post_id', 'meta_key' ], IXES_Rowinfo::label_columns( 'wp_postmeta', 'wp_' ) );
		$this->assertSame( [], IXES_Rowinfo::label_columns( 'wp_wc_orders', 'wp_' ) );
		$this->assertSame( 'page publish 2026-01-02 About', IXES_Rowinfo::label( [ 'ID' => 7, 'post_type' => 'page', 'post_status' => 'publish', 'post_date' => '2026-01-02', 'post_title' => 'About' ], [ 'post_type', 'post_status', 'post_date', 'post_title' ] ) );
	}

	public function test_a_remote_row_is_fetched_from_the_key_before_it() {
		$this->assertSame( 122, IXES_Rowinfo::cursor_before( '123' ) );
		$this->assertNull( IXES_Rowinfo::cursor_before( 'abc' ), 'a text key has no known predecessor' );
		$this->assertSame( -1, IXES_Rowinfo::cursor_before( '0' ) );
		$this->assertNull( IXES_Rowinfo::cursor_before( '1.5' ) );
	}
}
