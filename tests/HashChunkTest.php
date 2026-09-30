<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BinCellsWpdb.php';

/** Counts the row reads hash_rows() makes, with the LIMIT of each. */
class HashChunkWpdb extends BinCellsWpdb {
	public $limits = [];
	public function get_results( $q, $t = null ) {
		if ( preg_match( '/^SELECT \* FROM .* LIMIT (\d+)/s', $q, $m ) ) $this->limits[] = (int) $m[1];
		return parent::get_results( $q, $t );
	}
}

class HashChunkTest extends TestCase {
	const ROWS = 1300;
	private $db;

	protected function setUp(): void {
		$this->db = new HashChunkWpdb();
		$this->db->table( 'wp_big', [ 'k', 'v' ], [ 'k' ] );
		$this->db->table( 'wp_nokey', [ 'a', 'b' ], [] );
		for ( $i = 0; $i < self::ROWS; $i++ ) {
			$this->db->insert( 'wp_big', [ 'k' => sprintf( 'k%05d', $i ), 'v' => str_repeat( 'x', 50 ) . $i ] );
			$this->db->insert( 'wp_nokey', [ 'a' => (string) $i, 'b' => 'v' . $i ] );
		}
		$GLOBALS['wpdb'] = $this->db;
	}
	protected function tearDown(): void { unset( $GLOBALS['wpdb'] ); }

	public function test_a_page_is_read_in_chunks_never_larger_than_hash_chunk() {
		$r = IXES_Transfer::hash_rows( 'wp_big', null, 5000, [], 'sha1' );
		$this->assertCount( self::ROWS, $r['rows'] );
		$this->assertNull( $r['next'] );
		$this->assertGreaterThan( 1, count( $this->db->limits ) );
		$this->assertLessThanOrEqual( IXES_Transfer::HASH_CHUNK, max( $this->db->limits ) );
	}

	public function test_a_page_smaller_than_the_table_stops_at_limit_and_resumes_where_it_stopped() {
		$first = IXES_Transfer::hash_rows( 'wp_big', null, 1000, [], 'sha1' );
		$this->assertCount( 1000, $first['rows'] );
		$this->assertSame( 'k00999', $first['next'] );
		$rest = IXES_Transfer::hash_rows( 'wp_big', $first['next'], 1000, [], 'sha1' );
		$this->assertCount( self::ROWS - 1000, $rest['rows'] );
		$this->assertNull( $rest['next'] );
		$this->assertSame( [], array_intersect_key( $first['rows'], $rest['rows'] ) );
	}

	public function test_digests_match_hashing_the_rows_in_one_read() {
		$r = IXES_Transfer::hash_rows( 'wp_big', null, 5000, [], 'sha1' );
		$d = IXES_Transfer::dump( 'wp_big', null, 5000 );
		$want = [];
		foreach ( $d['rows'] as $row ) $want[ $row['k'] ] = IXES_Hasher::hash_row( $row, [], 'sha1', false );
		$this->assertSame( $want, $r['rows'] );
	}

	public function test_a_table_without_a_key_pages_by_offset() {
		$first = IXES_Transfer::hash_rows( 'wp_nokey', null, 600, [], 'sha1' );
		$this->assertCount( 600, $first['rows'] );
		$this->assertSame( 600, $first['next'] );
		$rest = IXES_Transfer::hash_rows( 'wp_nokey', $first['next'], 5000, [], 'sha1' );
		$this->assertCount( self::ROWS - 600, $rest['rows'] );
		$this->assertNull( $rest['next'] );
	}
}
