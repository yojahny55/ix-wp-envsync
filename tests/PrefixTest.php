<?php
use PHPUnit\Framework\TestCase;

class PrefixTest extends TestCase {
	// the remote runs ab_, the hub wp_: local = ab_, peer = wp_
	private function remote() { return new IXES_Prefix( 'ab_', 'wp_' ); }

	protected function tearDown(): void { IXES_Prefix::set_current( null ); }

	public function test_table_names_translate_both_ways() {
		$m = $this->remote();
		$this->assertSame( 'ab_posts', $m->table_in( 'wp_posts' ) );
		$this->assertSame( 'wp_posts', $m->table_out( 'ab_posts' ) );
		$this->assertSame( 'ab_wc_orders', $m->table_in( 'wp_wc_orders' ) );
		$this->assertNull( $m->table_in( 'other_posts' ), 'a name without the peer prefix is not ours to map' );
		$this->assertSame( 'usermeta', $m->bare( 'ab_usermeta' ) );
	}

	public function test_usermeta_keys_translate_and_round_trip() {
		$m = $this->remote();
		$out = $m->row_out( 'usermeta', [ 'umeta_id' => '3', 'meta_key' => 'ab_capabilities', 'meta_value' => 'a:1:{s:13:"administrator";b:1;}' ] );
		$this->assertSame( 'wp_capabilities', $out['meta_key'] );
		$this->assertSame( '3', $out['umeta_id'] );
		$this->assertSame( 'a:1:{s:13:"administrator";b:1;}', $out['meta_value'] );
		$this->assertSame( 'ab_capabilities', $m->row_in( 'usermeta', $out )['meta_key'] );
		$this->assertSame( 'nickname', $m->row_out( 'usermeta', [ 'meta_key' => 'nickname' ] )['meta_key'] );
	}

	public function test_a_key_with_the_other_sides_prefix_is_an_orphan() {
		$m = $this->remote();
		$this->assertNull( $m->row_out( 'usermeta', [ 'meta_key' => 'wp_capabilities' ] ), 'left over from an old prefix on the remote' );
		$this->assertNull( $m->row_in( 'usermeta', [ 'meta_key' => 'ab_capabilities' ] ), 'a hub row carrying the remote prefix' );
	}

	public function test_user_roles_option_and_its_orphan() {
		$m = $this->remote();
		$this->assertSame( 'wp_user_roles', $m->row_out( 'options', [ 'option_name' => 'ab_user_roles' ] )['option_name'] );
		$this->assertSame( 'ab_user_roles', $m->row_in( 'options', [ 'option_name' => 'wp_user_roles' ] )['option_name'] );
		$this->assertNull( $m->row_out( 'options', [ 'option_name' => 'wp_user_roles' ] ) );
		$this->assertSame( 'wp_page_for_privacy_policy', $m->row_out( 'options', [ 'option_name' => 'wp_page_for_privacy_policy' ] )['option_name'], 'only user_roles carries the prefix in options' );
		$this->assertSame( 'ab_capabilities', $m->row_out( 'postmeta', [ 'meta_key' => 'ab_capabilities' ] )['meta_key'], 'other tables are untouched' );
	}

	public function test_overlapping_prefixes_round_trip_exactly() {
		foreach ( [ [ 'wp_', 'wp_abc_' ], [ 'wp_abc_', 'wp_' ] ] as $pair ) {
			list( $local, $peer ) = $pair;
			$m = new IXES_Prefix( $local, $peer );
			foreach ( [ $local . 'capabilities', $local . 'abc_x', $local . 'user-settings', 'plain_key' ] as $k ) {
				$out = $m->row_out( 'usermeta', [ 'meta_key' => $k ] );
				if ( $out === null ) continue;
				$this->assertSame( $k, $m->row_in( 'usermeta', $out )['meta_key'], "{$local}->{$peer}: {$k}" );
			}
			$this->assertSame( $local . 'posts', $m->table_in( $peer . 'posts' ) );
		}
		$m = new IXES_Prefix( 'wp_', 'wp_abc_' );
		$this->assertSame( 'wp_abc_abc_x', $m->row_out( 'usermeta', [ 'meta_key' => 'wp_abc_x' ] )['meta_key'] );
		$m = new IXES_Prefix( 'wp_abc_', 'wp_' );
		$this->assertNull( $m->row_out( 'usermeta', [ 'meta_key' => 'wp_capabilities' ] ), 'wp_ key on a wp_abc_ site is an orphan' );
	}

	public function test_sql_in_rewrites_create_and_references() {
		$m = $this->remote();
		$sql = "CREATE TABLE `wp_things` (\n  `id` bigint(20) NOT NULL,\n  `post_id` bigint(20),\n  PRIMARY KEY (`id`),\n  CONSTRAINT `fk` FOREIGN KEY (`post_id`) REFERENCES `wp_posts` (`ID`)\n) ENGINE=InnoDB";
		$out = $m->sql_in( $sql );
		$this->assertStringStartsWith( 'CREATE TABLE `ab_things` (', $out );
		$this->assertStringContainsString( 'REFERENCES `ab_posts` (`ID`)', $out );
		$this->assertStringContainsString( '`post_id` bigint(20)', $out );
		$this->assertNull( IXES_Applier::create_table_refusal( 'ab_things', $out, 'ab_', false ) );
	}

	public function test_step_in_translates_rows_and_refuses_orphans() {
		$m = $this->remote();
		$p = $m->step_in( [ 'kind' => 'rows', 'job' => 'j', 'table' => 'wp_usermeta', 'pk' => 'umeta_id', 'rows' => [
			[ 'umeta_id' => '1', 'meta_key' => 'wp_capabilities' ],
			[ 'umeta_id' => '2', 'meta_key' => 'ab_level_from_elsewhere' ],
			[ 'umeta_id' => '3', 'meta_key' => 'nickname' ],
		] ] );
		$this->assertSame( 'ab_usermeta', $p['table'] );
		$this->assertSame( [ 'ab_capabilities', 'nickname' ], array_column( $p['rows'], 'meta_key' ) );
		$this->assertSame( [ 'ab_level_from_elsewhere' ], $p['prefix_refused'] );

		$this->assertSame( 'ab_posts', $m->step_in( [ 'kind' => 'delete_rows', 'table' => 'wp_posts', 'ids' => [ 1 ] ] )['table'] );
		$this->assertSame( '', $m->step_in( [ 'kind' => 'rows', 'table' => 'zz_posts', 'rows' => [] ] )['table'], 'an unmapped name fails valid_table' );
		$c = $m->step_in( [ 'kind' => 'create_table', 'table' => 'wp_things', 'sql' => 'CREATE TABLE `wp_things` (`id` int)' ] );
		$this->assertSame( [ 'ab_things', 'CREATE TABLE `ab_things` (`id` int)' ], [ $c['table'], $c['sql'] ] );
		$this->assertSame( 'active_plugins', $m->step_in( [ 'kind' => 'option', 'name' => 'active_plugins' ] )['name'] );
		$this->assertSame( 'ab_user_roles', $m->step_in( [ 'kind' => 'option', 'name' => 'wp_user_roles' ] )['name'] );
		$this->assertSame( 'wp/x.php', $m->step_in( [ 'kind' => 'file', 'path' => 'wp/x.php' ] )['path'] );
	}

	public function test_start_in_rekeys_plan_tables() {
		$p = $this->remote()->start_in( [ 'plan_meta' => [ 'tables' => [ 'wp_posts' => [ 'pk' => 'ID' ], 'zz_other' => [] ], 'files' => [] ] ] );
		$this->assertSame( [ 'ab_posts' ], array_keys( $p['plan_meta']['tables'] ) );
		$this->assertSame( [ 'pk' => 'ID' ], $p['plan_meta']['tables']['ab_posts'] );
	}

	public function test_valid_and_current() {
		$this->assertTrue( IXES_Prefix::valid( 'Ab12Cd34_' ) );
		foreach ( [ '', 'wp-', 'wp_;', "wp_\n", 'wp `' ] as $bad ) $this->assertFalse( IXES_Prefix::valid( $bad ), var_export( $bad, true ) );
		$this->assertNull( IXES_Prefix::current() );
		IXES_Prefix::set_current( $this->remote() );
		$this->assertSame( 'ab_', IXES_Prefix::current()->local() );
	}
}
