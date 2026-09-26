<?php
use PHPUnit\Framework\TestCase;

class DropTableTest extends TestCase {

	public function test_table_drops_reasons() {
		$r = IXES_Differ::table_drops( [ 'wp_a', 'wp_b' ], [ 'wp_a', 'wp_c', 'wp_d' ], false, [ 'wp_d' ] );
		$this->assertSame( [ 'wp_a' => 'baseline', 'wp_d' => 'asked' ], $r['drop'] );
		$this->assertSame( [ 'wp_c' ], $r['kept'], 'a table the baseline never saw is the remote\'s own' );
	}

	public function test_table_drops_mirror_takes_everything_only_far() {
		$r = IXES_Differ::table_drops( [], [ 'wp_x', 'wp_y' ], true, [] );
		$this->assertSame( [ 'wp_x' => 'mirror', 'wp_y' => 'mirror' ], $r['drop'] );
		$this->assertSame( [], $r['kept'] );
	}

	public function test_table_drops_nothing_without_baseline_or_flags() {
		$r = IXES_Differ::table_drops( [], [ 'wp_x' ], false, [] );
		$this->assertSame( [], $r['drop'] );
	}

	public function test_same_rows_with_pk_ignores_order_and_key_type() {
		$this->assertTrue( IXES_Droptable::same_rows( [ 2 => 'b', 1 => 'a' ], [ '1' => 'a', '2' => 'b' ], true ) );
		$this->assertFalse( IXES_Droptable::same_rows( [ 1 => 'a' ], [ 1 => 'a', 2 => 'b' ], true ) );
		$this->assertFalse( IXES_Droptable::same_rows( [ 1 => 'a' ], [ 1 => 'z' ], true ) );
		$this->assertTrue( IXES_Droptable::same_rows( [], [], true ), 'two empty tables are the same' );
	}

	public function test_same_rows_without_pk_is_a_multiset() {
		$this->assertTrue( IXES_Droptable::same_rows( [ 'b', 'a', 'a' ], [ 'a', 'b', 'a' ], false ) );
		$this->assertFalse( IXES_Droptable::same_rows( [ 'a', 'a' ], [ 'a' ], false ) );
	}

	public function test_code_hits_finds_the_bare_name_in_php_only() {
		$d = sys_get_temp_dir() . '/ixes-code-' . uniqid();
		mkdir( $d . '/inc', 0777, true );
		file_put_contents( $d . '/inc/a.php', '<?php $wpdb->prefix . "wpda_logging";' );
		file_put_contents( $d . '/readme.txt', 'wpforms_tasks_meta' );
		$hits = IXES_Droptable::code_hits( [ 'wp_wpda_logging' => 'wpda_logging', 'wp_wpforms_tasks_meta' => 'wpforms_tasks_meta' ], [ $d ] );
		$this->assertSame( [ 'wp_wpda_logging' ], array_keys( $hits ) );
		$this->assertStringEndsWith( 'inc/a.php', $hits['wp_wpda_logging'] );
		array_map( 'unlink', [ $d . '/inc/a.php', $d . '/readme.txt' ] ); rmdir( $d . '/inc' ); rmdir( $d );
	}

	public function test_code_hits_accepts_a_single_file_plugin() {
		$f = sys_get_temp_dir() . '/ixes-one-' . uniqid() . '.php';
		file_put_contents( $f, '<?php // uses smush_dir_images' );
		$this->assertArrayHasKey( 'wp_smush_dir_images', IXES_Droptable::code_hits( [ 'wp_smush_dir_images' => 'smush_dir_images' ], [ $f ] ) );
		unlink( $f );
	}

	public function test_smoke_flags_5xx_and_fatal_pages() {
		$answers = [
			'u1' => [ 'response' => [ 'code' => 200 ], 'body' => '<html>ok</html>' ],
			'u2' => [ 'response' => [ 'code' => 500 ], 'body' => '' ],
			'u3' => [ 'response' => [ 'code' => 200 ], 'body' => '<p>There has been a critical error on this website.</p>' ],
			'u4' => new WP_Error( 'http', 'timed out' ),
			'u5' => [ 'response' => [ 'code' => 404 ], 'body' => 'not found' ],
		];
		$r = IXES_Droptable::smoke( array_keys( $answers ), function ( $u ) use ( $answers ) { return $answers[ $u ]; } );
		$this->assertTrue( $r['u1'] );
		$this->assertSame( 'HTTP 500', $r['u2'] );
		$this->assertSame( 'fatal error in the page', $r['u3'] );
		$this->assertSame( 'timed out', $r['u4'] );
		$this->assertTrue( $r['u5'], 'a 404 is an answer, not a broken site' );
	}

	public function test_regressions_match_rounds_by_position_and_only_blame_what_was_healthy() {
		$before = [ 'h?n=1' => true, 'l?n=1' => 'HTTP 500', 'r?n=1' => true ];
		$after  = [ 'h?n=2' => 'HTTP 500', 'l?n=2' => 'HTTP 500', 'r?n=2' => true ];
		$this->assertSame( [ 'h?n=2' => 'HTTP 500' ], IXES_Droptable::regressions( $before, $after ) );
	}

	public function test_smoke_urls_bust_page_caches() {
		$u = IXES_Droptable::smoke_urls( 'https://s.test/', 'n1' );
		$this->assertSame( [ 'https://s.test/?ixes_smoke=n1', 'https://s.test/wp-login.php?ixes_smoke=n1', 'https://s.test/?rest_route=/&ixes_smoke=n1' ], $u );
	}

	public function test_sql_copy_recreates_and_escapes() {
		$sql = IXES_Droptable::sql_head( 'wp_t', 'CREATE TABLE `wp_t` (`id` int)' ) . IXES_Droptable::sql_insert( 'wp_t', [ [ 'id' => '1', 'v' => "it's\n" ], [ 'id' => '2', 'v' => null ] ] );
		$this->assertStringContainsString( "DROP TABLE IF EXISTS `wp_t`;", $sql );
		$this->assertStringContainsString( 'CREATE TABLE `wp_t` (`id` int);', $sql );
		$this->assertStringContainsString( "('1','it\\'s\\n')", $sql );
		$this->assertStringContainsString( "('2',NULL)", $sql );
		$this->assertSame( '', IXES_Droptable::sql_insert( 'wp_t', [] ), 'an empty page adds no INSERT' );
	}

	public function test_digest_matches_same_rows_in_any_order_and_differs_otherwise() {
		$this->assertSame( IXES_Droptable::digest( [ 2 => 'b', 1 => 'a' ], true ), IXES_Droptable::digest( [ '1' => 'a', '2' => 'b' ], true ) );
		$this->assertNotSame( IXES_Droptable::digest( [ 1 => 'a', 2 => 'b' ], true ), IXES_Droptable::digest( [ 1 => 'b', 2 => 'a' ], true ), 'rows swapped between keys are a change' );
		$this->assertStringStartsWith( '0:', IXES_Droptable::digest( [], true ) );
		$this->assertNotSame( IXES_Droptable::digest( [ 'a', 'a' ], false ), IXES_Droptable::digest( [ 'a' ], false ) );
	}

	public function test_other_install_spots_a_longer_prefix_sharing_the_database() {
		$all = [ 'wp_options', 'wp_posts', 'wp_old_options', 'wp_old_posts', 'wp_old_postmeta', 'wp_2_options', 'wp_2_posts', 'wp_2_postmeta', 'wp_2_wpda_logs', 'wp_wpda_logs', 'wp_wc_orders', 'wp_pmxi_posts', 'wp_pmxi_hash' ];
		$this->assertSame( 'wp_old_', IXES_Droptable::other_install( 'wp_old_posts', 'wp_', $all ) );
		$this->assertSame( 'wp_2_', IXES_Droptable::other_install( 'wp_2_wpda_logs', 'wp_', $all ) );
		$this->assertNull( IXES_Droptable::other_install( 'wp_wpda_logs', 'wp_', $all ) );
		$this->assertNull( IXES_Droptable::other_install( 'wp_wc_orders', 'wp_', $all ) );
		$this->assertNull( IXES_Droptable::other_install( 'wp_pmxi_hash', 'wp_', $all ), 'a plugin with its own *_posts table is not an install' );
	}

	public function test_unrestorable_accepts_a_real_definition_with_select_in_it() {
		$create = "CREATE TABLE `wp_forms` (\n `type` enum('text','select') NOT NULL COMMENT 'a; b'\n) ENGINE=InnoDB";
		$this->assertNull( IXES_Droptable::unrestorable( 'wp_forms', $create, 'wp_' ), 'its own definition must come back even with SELECT or ; inside' );
		$this->assertNotNull( IXES_Droptable::unrestorable( 'wp_forms', 'CREATE TABLE `wp_other` (x int)', 'wp_' ) );
		$this->assertNotNull( IXES_Droptable::unrestorable( 'wp_ixes_x', 'CREATE TABLE `wp_ixes_x` (x int)', 'wp_' ) );
	}

	public function test_table_list_adds_the_prefix() {
		$this->assertSame( [ 'wp_wpda_logs', 'wp_foo' ], IXES_Droptable::table_list( ' wpda_logs, wp_foo ,wpda_logs', 'wp_' ) );
	}

	public function test_prefix_maps_drop_steps() {
		$m = new IXES_Prefix( 'rem_', 'wp_' );
		$this->assertSame( [ 'rem_a', 'rem_b' ], $m->step_in( [ 'kind' => 'drop_check', 'tables' => [ 'wp_a', 'wp_b' ] ] )['tables'] );
		$this->assertSame( 'rem_a', $m->step_in( [ 'kind' => 'drop_table', 'table' => 'wp_a' ] )['table'] );
	}
}
