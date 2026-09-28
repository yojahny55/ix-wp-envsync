<?php
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_sql' ) ) { function esc_sql( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }

require_once __DIR__ . '/BinCellsWpdb.php';

/** /hash/tables and /hash/rows answered from a script, for IXES_Planner::remote_hashes(). */
class BinCellsHashClient extends IXES_Client {
	public $answers = [];
	public function post( $route, $body, $opts = [] ) { return array_shift( $this->answers ); }
}

class BinaryCellsTest extends TestCase {
	const T = 'wp_bot_hourly_ip';
	private $db;

	protected function setUp(): void {
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-bin-' . getmypid() . '-' . uniqid();
		mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
		$this->db = new BinCellsWpdb();
		// a security plugin's bot table: varbinary IP inside a composite primary key
		$this->db->table( self::T, [ 'hour', 'kind', 'ip', 'hits' ], [ 'hour', 'kind', 'ip' ] );
		$this->db->table( 'wp_ixes_tmp_bot_hourly_ip', [ 'hour', 'kind', 'ip', 'hits' ], [ 'hour', 'kind', 'ip' ] );
		$GLOBALS['wpdb'] = $this->db;
	}
	protected function tearDown(): void { unset( $GLOBALS['wpdb'], $GLOBALS['ixes_test_transients'] ); IXES_Prefix::set_current( null ); }

	/** What the rows go through between the two sites: JSON out of the remote, JSON back in on the hub. */
	private static function wire( array $rows ) {
		$json = wp_json_encode( [ 'rows' => array_map( [ 'IXES_Hasher', 'cells_out' ], $rows ) ] );
		self::assertIsString( $json, 'wrapped rows always encode' );
		return IXES_Hasher::rows_in( json_decode( $json, true )['rows'] );
	}
	private static function ip_rows() {
		return [
			[ 'hour' => '497365', 'kind' => 'verified_ai_crawler', 'ip' => "3\x08f\xff", 'hits' => '1' ],
			[ 'hour' => '497365', 'kind' => 'verified_ai_crawler', 'ip' => "3\x08f\xfe", 'hits' => '2' ],
		];
	}

	public function test_every_byte_value_round_trips() {
		$all = implode( '', array_map( 'chr', range( 0, 255 ) ) );
		$row = [ 'id' => '1', 'blob' => $all, 'txt' => 'plain' ];
		$this->assertFalse( json_encode( $row ), 'raw bytes are exactly what JSON cannot carry' );
		$this->assertSame( [ $row ], self::wire( [ $row ] ) );
	}

	public function test_latin1_text_read_raw_round_trips() {
		$row = [ 'id' => '2', 'name' => "Caf\xe9 cr\xe8me" ];
		$this->assertTrue( IXES_Hasher::is_bytes( $row['name'] ) );
		$this->assertSame( [ $row ], self::wire( [ $row ] ) );
	}

	public function test_a_string_that_looks_like_the_wrapper_stays_a_string() {
		$row = [ 'id' => '3', 'v' => '{"b64":"AAE="}', 'w' => 'caf' . "\u{e9}", 'n' => null, 'e' => '' ];
		$this->assertSame( $row, IXES_Hasher::cells_out( $row ), 'valid UTF-8, empty and null cells go as they are' );
		$this->assertSame( [ $row ], self::wire( [ $row ] ) );
	}

	public function test_a_malformed_wrapper_is_refused_not_guessed() {
		$this->assertNull( IXES_Hasher::cells_in( [ 'v' => [ 'b64' => '***' ] ] ) );
		$this->assertNull( IXES_Hasher::cells_in( [ 'v' => [ 'b64' => 'AA==', 'x' => 1 ] ] ) );
		$this->assertNull( IXES_Hasher::cells_in( [ 'v' => [ 'hex' => '00' ] ] ) );
		$this->assertInstanceOf( WP_Error::class, IXES_Hasher::rows_in( [ [ 'v' => [ 'b64' => '***' ] ] ] ) );
	}

	public function test_hash_of_raw_bytes_equals_hash_after_the_trip() {
		$pp = IXES_Hasher::placeholders( 'https://prod.test', '/srv/prod' );
		$lp = IXES_Hasher::placeholders( 'http://hub.test', '/srv/hub' );
		$prod = [ 'id' => '9', 'ip' => "\x00\xff\x10", 'url' => 'https://prod.test/a' ];
		$hub  = self::wire( [ [ 'id' => '9', 'ip' => "\x00\xff\x10", 'url' => 'http://hub.test/a' ] ] )[0];
		$this->assertSame( IXES_Hasher::hash_row( $prod, $pp, 'sha1', true ), IXES_Hasher::hash_row( $hub, $lp, 'sha1', true ) );
		// a wrapped row hashes like its raw bytes, so a caller that forgot to unwrap still agrees
		$this->assertSame( IXES_Hasher::hash_row( $prod, $pp, 'sha1', true ), IXES_Hasher::hash_row( IXES_Hasher::cells_out( $prod ), $pp, 'sha1', true ) );
	}

	public function test_utf8_rows_keep_the_hash_they_had_before() {
		$pp  = IXES_Hasher::placeholders( 'https://prod.test', '/srv/prod' );
		$row = [ 'ID' => '7', 'guid' => 'https://prod.test/?p=7', 'post_content' => "caf\u{e9} \u{2615} " . serialize( [ 'u' => 'https://prod.test/x' ] ), 'n' => null ];
		// pinned: the 0.9.1 hash of this row
		$this->assertSame( 'fac17e533c7a243995baa4fbd5ad296bdf152081', IXES_Hasher::hash_row( $row, $pp, 'sha1' ) );
		$this->assertSame( 'fac17e533c7a243995baa4fbd5ad296bdf152081', IXES_Hasher::hash_row( $row, $pp, 'sha1', true ) );
		// and a byte row still hashes as before for an older remote
		$this->assertSame( '46de86d8b94a7079cc2b53dafee536e7ed076aa4', IXES_Hasher::hash_row( [ 'hour' => '497365', 'kind' => 'verified', 'ip' => "3\x08f\xff" ], $pp, 'sha1' ) );
	}

	public function test_bytes_mode_sees_a_change_inside_a_byte_cell() {
		list( $a, $b ) = self::ip_rows(); $b['hits'] = $a['hits'];
		$this->assertSame( IXES_Hasher::hash_row( $a, [], 'sha1' ), IXES_Hasher::hash_row( $b, [], 'sha1' ), 'the old hash saw both IPs as null' );
		$this->assertNotSame( IXES_Hasher::hash_row( $a, [], 'sha1', true ), IXES_Hasher::hash_row( $b, [], 'sha1', true ) );
	}

	public function test_normalize_leaves_byte_cells_alone() {
		$pp  = IXES_Hasher::placeholders( 'https://prod.test', '/srv/prod' );
		$raw = "\xff\x00https://prod.test/x\xfe";
		$this->assertSame( $raw, IXES_Hasher::normalize( $raw, $pp ) );
		$ser = serialize( [ 'k' => "\xff https://prod.test" ] );
		$this->assertSame( $ser, IXES_Hasher::normalize( $ser, $pp ) );
		$this->assertSame( 'see {{URL}}/x', IXES_Hasher::normalize( 'see https://prod.test/x', $pp ), 'text is still rewritten' );
	}

	public function test_two_ips_differing_only_in_non_utf8_bytes_stay_distinct_on_import() {
		$rows = self::wire( self::ip_rows() );
		$n = IXES_Transfer::import_rows( self::T, $rows, [], false ); // composite key: pk_of() is null, so plain INSERT
		$this->assertSame( 2, $n, (string) $this->db->last_error );
		$this->assertStringContainsString( ',0x330866ff,', end( $this->db->sql ) );
		$got = array_column( array_values( $this->db->rows['wp_ixes_tmp_bot_hourly_ip'] ), 'ip' );
		sort( $got );
		$this->assertSame( [ "3\x08f\xfe", "3\x08f\xff" ], $got );
	}

	public function test_what_the_old_json_trip_did_to_those_ips() {
		if ( ! function_exists( 'mb_convert_encoding' ) ) $this->markTestSkipped( 'mbstring' );
		// wp_json_encode()'s fallback when json_encode() fails: invalid bytes become '?'
		$rows = array_map( function ( $r ) { $r['ip'] = mb_convert_encoding( $r['ip'], 'UTF-8', 'UTF-8' ); return $r; }, self::ip_rows() );
		$this->assertSame( $rows[0]['ip'], $rows[1]['ip'] );
		$r = IXES_Transfer::import_rows( self::T, $rows, [], false );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'Duplicate entry', $r->get_error_message() );
	}

	public function test_dump_wraps_byte_cells_only_when_the_hub_asks() {
		foreach ( self::ip_rows() as $r ) $this->db->insert( self::T, $r );
		$old = IXES_Transfer::dump( self::T, null, 10 );
		$this->assertSame( self::ip_rows(), $old['rows'], 'an older hub gets rows exactly as before' );
		$new = IXES_Transfer::dump( self::T, null, 10, 0, true );
		$this->assertSame( [ 'b64' => base64_encode( "3\x08f\xff" ) ], $new['rows'][0]['ip'] );
		$this->assertSame( '497365', $new['rows'][0]['hour'] );
		$this->assertSame( self::ip_rows(), IXES_Hasher::rows_in( $new['rows'] ) );
	}

	public function test_old_remote_gets_a_warning_for_tables_with_byte_columns() {
		$create = [ self::T => "CREATE TABLE `wp_bot_hourly_ip` (\n  `hour` int(10) unsigned NOT NULL,\n  `kind` varchar(32) NOT NULL,\n  `ip` varbinary(16) NOT NULL,\n  `hits` int NOT NULL,\n  `raw` mediumblob,\n  PRIMARY KEY (`hour`,`kind`,`ip`)\n) ENGINE=InnoDB DEFAULT CHARSET=ascii",
			'wp_plain' => "CREATE TABLE `wp_plain` (\n  `id` bigint NOT NULL,\n  `binary_note` text,\n  PRIMARY KEY (`id`)\n)" ];
		$this->assertSame( [ 'ip', 'raw' ], IXES_Transfer::byte_columns( $create[ self::T ] ) );
		$w = IXES_Transfer::byte_warnings( [ 'binary', 'schema' ], array_keys( $create ), 'prod', $create );
		$this->assertCount( 1, $w, 'a column merely named binary is not one' );
		$this->assertStringContainsString( 'wp_bot_hourly_ip: binary column(s) ip, raw', $w[0] );
		$this->assertStringContainsString( '0.9.3', $w[0] );
		$this->assertSame( [], IXES_Transfer::byte_warnings( [ IXES_Hasher::CAP ], array_keys( $create ), 'prod', $create ) );
	}

	public function test_bytes_mode_needs_the_cap_and_a_baseline_hashed_the_same_way() {
		$cap = [ 'binary', IXES_Hasher::CAP ];
		$this->assertTrue( IXES_Hasher::bytes_mode( $cap, false, null ), 'no baseline: both sides hash now' );
		$this->assertTrue( IXES_Hasher::bytes_mode( $cap, true, 'yes' ) );
		$this->assertFalse( IXES_Hasher::bytes_mode( $cap, true, null ), 'a baseline from before 0.9.3 holds byte cells as null' );
		$this->assertFalse( IXES_Hasher::bytes_mode( $cap, true, 'no' ) );
		$this->assertFalse( IXES_Hasher::bytes_mode( [ 'binary' ], false, null ), 'an older remote hashes as before' );
	}

	public function test_client_asks_for_byte_cells_only_with_the_cap() {
		$c = new IXES_Client( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$c->set_caps( [ 'binary', 'schema', 'file_batch' ] );
		$this->assertFalse( $c->cells() );
		$c->set_caps( [ 'binary', IXES_Hasher::CAP ] );
		$this->assertTrue( $c->cells() );
	}

	public function test_push_rows_step_writes_byte_cells_as_hex_on_the_remote() {
		$GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ] = IXES_Applier::lock_value( 'job-1', time() );
		$step = [ 'job' => 'job-1', 'kind' => 'rows', 'table' => self::T, 'pk' => null, 'rows' => array_map( [ 'IXES_Hasher', 'cells_out' ], self::ip_rows() ), 'expect' => [], 'pairs' => [], 'algo' => 'sha1', 'cells' => 1 ];
		// through JSON, as the step travels
		$r = IXES_Applier::job_step( json_decode( wp_json_encode( $step ), true ) );
		$this->assertSame( [], $r['stale'] );
		$this->assertCount( 2, $this->db->rows[ self::T ] );
		foreach ( $this->db->sql as $q ) $this->assertMatchesRegularExpression( '/^INSERT INTO `wp_bot_hourly_ip` .+,0x330866f[ef],/', $q );
		$step['rows'] = [ [ 'hour' => '1', 'kind' => 'k', 'ip' => [ 'b64' => '***' ], 'hits' => '0' ] ];
		$this->assertInstanceOf( WP_Error::class, IXES_Applier::job_step( $step ) );
	}

	private function baseline( $meta = null ) {
		$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-t-' . uniqid() . '.sqlite' );
		$bl->reset(); $bl->meta( 'created_at', time() );
		if ( $meta !== null ) $bl->meta( 'bytes_hash', $meta );
		return $bl;
	}

	public function test_scoped_pull_over_an_older_baseline_keeps_hashing_the_old_way() {
		$bl = $this->baseline(); // full baseline from before 0.9.3: no bytes_hash
		IXES_Pull::record_hash_mode( $bl, false, true ); // --tables pull, remote now has the cap
		$this->assertNull( $bl->meta( 'bytes_hash' ) );
		$this->assertFalse( IXES_Pull::hash_mode( $bl ), 'its rows join a baseline the planner compares the old way' );
	}

	public function test_scoped_pull_from_an_older_remote_keeps_a_bytes_baseline() {
		$bl = $this->baseline( 'yes' );
		IXES_Pull::record_hash_mode( $bl, false, false );
		$this->assertSame( 'yes', $bl->meta( 'bytes_hash' ), 'the tables it did not touch still hold raw-byte hashes' );
		$this->assertTrue( IXES_Pull::hash_mode( $bl ) );
	}

	public function test_a_baseline_pull_records_the_remote_mode() {
		$bl = $this->baseline();
		IXES_Pull::record_hash_mode( $bl, true, true );
		$this->assertTrue( IXES_Pull::hash_mode( $bl ) );
		IXES_Pull::record_hash_mode( $bl, true, false );
		$this->assertFalse( IXES_Pull::hash_mode( $bl ) );
	}

	private function single_key_table( array $keys ) {
		$this->db->table( 'wp_digests', [ 'k', 'v' ], [ 'k' ] );
		foreach ( $keys as $i => $k ) $this->db->insert( 'wp_digests', [ 'k' => $k, 'v' => (string) $i ] );
	}

	public function test_an_ascii_varbinary_key_is_hashed_and_pushed_as_before() {
		$this->single_key_table( [ 'a3f1', 'b7c2' ] );
		$r = IXES_Transfer::hash_rows( 'wp_digests', null, 10, [], 'sha1', true );
		$this->assertCount( 2, $r['rows'] );
		$this->assertArrayNotHasKey( 'byte_keys', $r );
		$plan = [ 'new_tables' => [] ];
		$this->assertNull( IXES_Planner::byte_key_skip( $plan, 'wp_digests', 'k', ! empty( $r['byte_keys'] ) ) );
	}

	public function test_a_key_that_is_not_utf8_skips_the_table_and_its_creation() {
		$this->single_key_table( [ "\xff\x01", 'ok' ] );
		$r = IXES_Transfer::hash_rows( 'wp_digests', null, 10, [], 'sha1', true );
		$this->assertCount( 2, $r['rows'] );
		$this->assertTrue( $r['byte_keys'] );
		$plan = [ 'new_tables' => [ 'wp_digests' => 'CREATE TABLE `wp_digests` (...)', 'wp_other' => 'CREATE TABLE `wp_other` (...)' ] ];
		$w = IXES_Planner::byte_key_skip( $plan, 'wp_digests', 'k', true );
		$this->assertStringContainsString( 'skipped, and not created there', $w );
		$this->assertSame( [ 'wp_other' ], array_keys( $plan['new_tables'] ) );
		$this->assertNull( IXES_Planner::byte_key_skip( $plan, 'wp_nokey', null, true ), 'a table without a key is compared as a set' );
	}

	public function test_remote_hashes_passes_on_which_tables_carry_byte_keys() {
		$c = new BinCellsHashClient( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$c->answers = [ [ 'tables' => [ 'wp_a' => [ 'rows' => [ 'x' => 'h' ], 'next' => null, 'byte_keys' => true ], 'wp_b' => [ 'rows' => [ 'y' => 'h' ], 'next' => null ] ] ] ];
		$keys = [];
		$out = IXES_Planner::remote_hashes( $c, [ [ 'name' => 'wp_a', 'pk' => 'k', 'rows' => 1 ], [ 'name' => 'wp_b', 'pk' => 'k', 'rows' => 1 ] ], [ 'hash_batch', IXES_Hasher::CAP ], 'sha1', [], true, $keys );
		$this->assertSame( [ 'wp_a', 'wp_b' ], array_keys( $out ) );
		$this->assertSame( [ 'wp_a' => true ], $keys );
	}
}
