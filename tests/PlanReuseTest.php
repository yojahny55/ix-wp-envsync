<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CursorProgressTest.php';

/** A dry run's plan is kept, and a push reuses it while the local side and the flags are unchanged (#44). */
class PlanReuseTest extends TestCase {
	private $hub; private $remote;

	protected function setUp(): void {
		if ( ! function_exists( 'mb_convert_encoding' ) ) $this->markTestSkipped( 'mbstring' );
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-reuse-' . getmypid() . '-' . uniqid();
		mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
		$this->hub = new BinCellsWpdb(); $this->remote = new BinCellsWpdb();
		foreach ( [ $this->hub, $this->remote ] as $db ) {
			$db->table( 'wp_ascii', [ 'k', 'v' ], [ 'k' ] );
			for ( $i = 0; $i < 20; $i++ ) $db->insert( 'wp_ascii', [ 'k' => sprintf( 'a%03d', $i ), 'v' => (string) $i ] );
		}
		$this->hub->replace( 'wp_ascii', [ 'k' => 'a003', 'v' => 'changed here' ] );
		$GLOBALS['wpdb'] = $this->hub;
	}
	protected function tearDown(): void { unset( $GLOBALS['wpdb'] ); }

	private function client() {
		$c = new CursorRemote( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$c->remote = $this->remote; $c->hub = $this->hub;
		$c->tables = [ [ 'name' => 'wp_ascii', 'pk' => 'k', 'rows' => 20 ] ];
		return $c;
	}
	private static function env() { return [ 'name' => 'p', 'url' => 'https://p.test', 'extra_replace' => [], 'excludes' => [] ]; }
	private static function scope() { return IXES_Scope::from_array( [ 'only' => [ 'db' ] ], 'wp_' ); }
	private function plan() {
		$plan = IXES_Planner::build( self::env(), $this->client(), self::scope() );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		return $plan;
	}
	private static function want( array $plan, array $over = [] ) {
		return $over + [ 'scope' => self::scope()->to_array(), 'mirror' => false, 'drop' => [], 'env_key' => IXES_Planner::env_key( self::env() ),
			'baseline_at' => null, 'remote_version' => '0.9.3', 'algo' => 'sha1' ];
	}

	public function test_build_records_what_a_reuse_must_check() {
		$plan = $this->plan();
		$this->assertSame( IXES_VERSION, $plan['hub_version'] );
		$this->assertSame( '0.9.3', $plan['remote_version'] );
		$this->assertSame( IXES_Planner::env_key( self::env() ), $plan['env_key'] );
		$this->assertArrayHasKey( 'wp_ascii', $plan['local']['tables'] );
		$this->assertSame( [], $plan['local']['local_only'] );
		$this->assertNull( $plan['local']['files'], 'db-only scope hashes no files' );
	}

	public function test_an_unchanged_local_side_is_reported_unchanged() {
		$this->assertNull( IXES_Planner::local_change( $this->plan(), self::env() ) );
	}

	public function test_a_local_row_edit_is_a_change() {
		$plan = $this->plan();
		$this->hub->replace( 'wp_ascii', [ 'k' => 'a010', 'v' => 'edited after the dry run' ] );
		$this->assertStringContainsString( 'wp_ascii', (string) IXES_Planner::local_change( $plan, self::env() ) );
	}

	public function test_a_new_local_table_is_a_change() {
		$plan = $this->plan();
		$this->hub->table( 'wp_newplugin', [ 'id' ], [ 'id' ] );
		$this->assertStringContainsString( 'wp_newplugin', (string) IXES_Planner::local_change( $plan, self::env() ) );
	}

	public function test_a_plan_without_a_fingerprint_is_never_reused() {
		$plan = $this->plan(); unset( $plan['local'] );
		$this->assertStringContainsString( 'older', (string) IXES_Planner::local_change( $plan, self::env() ) );
		$this->assertStringContainsString( 'older', (string) IXES_Planner::reuse_refusal( $plan, self::want( $plan ), $plan['created'] ) );
	}

	public function test_reuse_refusal_accepts_a_matching_recent_plan() {
		$plan = $this->plan();
		$this->assertNull( IXES_Planner::reuse_refusal( $plan, self::want( $plan ), $plan['created'] + 60 ) );
	}

	/** @dataProvider mismatches */
	public function test_reuse_refusal_names_what_differs( $field, $value, $expect ) {
		$plan = $this->plan();
		$this->assertStringContainsString( $expect, (string) IXES_Planner::reuse_refusal( $plan, self::want( $plan, [ $field => $value ] ), $plan['created'] ) );
	}
	public function mismatches() {
		return [
			'scope'    => [ 'scope', IXES_Scope::from_array( [], 'wp_' )->to_array(), 'scope' ],
			'mirror'   => [ 'mirror', true, '--mirror' ],
			'drop'     => [ 'drop', [ 'wp_old' ], '--drop-tables' ],
			'env'      => [ 'env_key', 'other', 'environment settings' ],
			'baseline' => [ 'baseline_at', 1234, 'baseline' ],
			'remote'   => [ 'remote_version', '0.9.9', 'remote' ],
			'algo'     => [ 'algo', 'md5', 'algo' ],
		];
	}

	public function test_reuse_refusal_rejects_an_old_plan_and_another_hub_version() {
		$plan = $this->plan();
		$this->assertStringContainsString( 'min old', (string) IXES_Planner::reuse_refusal( $plan, self::want( $plan ), $plan['created'] + IXES_Planner::REUSE_MAX_MIN * 60 + 1 ) );
		$plan['hub_version'] = '0.0.1';
		$this->assertStringContainsString( 'hub', (string) IXES_Planner::reuse_refusal( $plan, self::want( $plan ), $plan['created'] ) );
	}

	public function test_latest_plan_round_trip_and_forget() {
		$plan = $this->plan();
		$path = IXES_Planner::save_latest( $plan );
		$this->assertFileExists( $path );
		$this->assertSame( $plan['local'], IXES_Planner::load_latest( 'p' )['local'] );
		IXES_Planner::forget_latest( 'p' );
		$this->assertNull( IXES_Planner::load_latest( 'p' ) );
	}
}
