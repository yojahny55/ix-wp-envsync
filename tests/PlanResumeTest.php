<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CursorProgressTest.php';

/** A remote that drops the connection when asked for one table's hashes, for as long as $fail_on names it. */
class FlakyRemote extends CursorRemote {
	public $fail_on = null;
	protected function sleep_s( $s ) {}
	public function post( $route, $body, $opts = [] ) {
		if ( $route === '/hash/rows' && ( $body['table'] ?? null ) === $this->fail_on ) {
			$this->calls[] = [ $route, $body['table'] ];
			return new WP_Error( 'http', 'cURL error 6: Could not resolve host' );
		}
		return parent::post( $route, $body, $opts );
	}
}

/** A plan that fails partway resumes from the table it stopped at (#96). */
class PlanResumeTest extends TestCase {
	const TABLES = [ 'wp_a', 'wp_b', 'wp_c' ];
	private $hub; private $remote; private $said = [];

	protected function setUp(): void {
		if ( ! function_exists( 'mb_convert_encoding' ) ) $this->markTestSkipped( 'mbstring' );
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-resume-' . getmypid() . '-' . uniqid();
		mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
		$this->hub = new BinCellsWpdb(); $this->remote = new BinCellsWpdb();
		foreach ( [ $this->hub, $this->remote ] as $db ) {
			foreach ( self::TABLES as $t ) {
				$db->table( $t, [ 'k', 'v' ], [ 'k' ] );
				for ( $i = 0; $i < 10; $i++ ) $db->insert( $t, [ 'k' => sprintf( 'k%02d', $i ), 'v' => "{$t}-{$i}" ] );
			}
		}
		$this->hub->replace( 'wp_a', [ 'k' => 'k03', 'v' => 'changed here' ] );
		$GLOBALS['wpdb'] = $this->hub;
		$this->said = [];
	}
	protected function tearDown(): void { unset( $GLOBALS['wpdb'] ); }

	private function client( $fail_on = null, $rows = [] ) {
		$c = new FlakyRemote( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$c->remote = $this->remote; $c->hub = $this->hub; $c->fail_on = $fail_on;
		foreach ( self::TABLES as $t ) $c->tables[] = [ 'name' => $t, 'pk' => 'k', 'rows' => $rows[ $t ] ?? 10 ];
		return $c;
	}
	private static function env() { return [ 'name' => 'p', 'url' => 'https://p.test', 'extra_replace' => [], 'excludes' => [] ]; }
	private static function scope( array $a = [ 'only' => [ 'db' ] ] ) { return IXES_Scope::from_array( $a, 'wp_' ); }
	private function opts() { return [ 'resume' => true, 'say' => function ( $m ) { $this->said[] = $m; } ]; }
	private function build( FlakyRemote $c, $scope = null, $opts = null ) {
		return IXES_Planner::build( self::env(), $c, $scope ?: self::scope(), false, [], $opts === null ? $this->opts() : $opts );
	}
	private static function asked( FlakyRemote $c ) {
		return array_values( array_unique( array_column( array_filter( $c->calls, function ( $x ) { return $x[0] === '/hash/rows'; } ), 1 ) ) );
	}
	private function fail_at_c() {
		$r = $this->build( $this->client( 'wp_c' ) );
		$this->assertInstanceOf( WP_Error::class, $r );
	}

	public function test_a_relaunched_plan_does_not_ask_again_for_the_tables_it_finished() {
		$this->fail_at_c();
		$c = $this->client();
		$plan = $this->build( $c );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertSame( [ 'wp_c' ], self::asked( $c ) );
		$this->assertSame( [ 'resuming plan: 2 of 3 tables already hashed' ], $this->said );
		// the same plan a run without any failure makes
		$fresh = $this->build( $this->client(), null, [] );
		$this->assertSame( $fresh['tables'], $plan['tables'] );
		$this->assertSame( $fresh['remote_hashes'], $plan['remote_hashes'] );
		$this->assertArrayHasKey( 'wp_a', $plan['tables'] );
	}

	public function test_a_finished_plan_drops_what_it_saved() {
		$this->fail_at_c();
		$this->assertIsArray( $this->build( $this->client() ) );
		$c = $this->client();
		$this->assertIsArray( $this->build( $c ) );
		$this->assertSame( self::TABLES, self::asked( $c ) );
	}

	public function test_a_table_whose_remote_row_count_changed_is_hashed_again() {
		$this->fail_at_c();
		$this->remote->insert( 'wp_b', [ 'k' => 'k99', 'v' => 'new on the remote' ] );
		$c = $this->client( null, [ 'wp_b' => 11 ] );
		$plan = $this->build( $c );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertSame( [ 'wp_b', 'wp_c' ], self::asked( $c ) );
		$this->assertSame( [ 'resuming plan: 1 of 3 tables already hashed' ], $this->said );
	}

	public function test_an_expired_table_is_hashed_again() {
		$stash = new IXES_Planstash( 'p', [ 'k' ] );
		$stash->put( 'wp_a', 10, [ 'k00' => 'h' ], false, time() - IXES_Planstash::MAX_MIN * 60 - 1 );
		$this->assertNull( $stash->get( 'wp_a', 10 ) );
		$stash->put( 'wp_a', 10, [ 'k00' => 'h' ] );
		$this->assertSame( [ 'rows' => [ 'k00' => 'h' ], 'byte_keys' => false ], $stash->get( 'wp_a', 10 ) );
		$this->assertNull( $stash->get( 'wp_a', 11 ), 'another row count' );
	}

	public function test_another_scope_does_not_reuse_the_saved_tables() {
		$this->fail_at_c();
		$c = $this->client();
		$plan = $this->build( $c, self::scope( [ 'only' => [ 'db' ], 'tables' => [ 'wp_a', 'wp_b' ] ] ) );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertSame( [ 'wp_a', 'wp_b' ], self::asked( $c ) );
		$this->assertSame( [], $this->said );
	}

	public function test_another_environment_setting_does_not_reuse_the_saved_tables() {
		$this->fail_at_c();
		$c = $this->client();
		$env = [ 'extra_replace' => [ [ 'https://cdn.p.test', 'https://cdn.local.test' ] ] ] + self::env();
		$plan = IXES_Planner::build( $env, $c, self::scope(), false, [], $this->opts() );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertSame( self::TABLES, self::asked( $c ) );
	}

	public function test_a_plan_built_to_check_another_never_reads_the_saved_tables() {
		$this->fail_at_c();
		$c = $this->client();
		$this->assertIsArray( $this->build( $c, null, [] ) );
		$this->assertSame( self::TABLES, self::asked( $c ) );
	}

	public function test_forget_drops_every_scope_of_one_environment_only() {
		$p = new IXES_Planstash( 'p', [ 'one' ] ); $p2 = new IXES_Planstash( 'p', [ 'two' ] ); $q = new IXES_Planstash( 'q', [ 'one' ] );
		foreach ( [ $p, $p2, $q ] as $s ) $s->put( 'wp_a', 1, [ 'x' => 'h' ] );
		IXES_Planstash::forget( 'p' );
		$this->assertNull( $p->get( 'wp_a', 1 ) );
		$this->assertNull( $p2->get( 'wp_a', 1 ) );
		$this->assertNotNull( $q->get( 'wp_a', 1 ) );
		$this->assertDirectoryDoesNotExist( ixes_storage_dir() . '/plan-resume/p' );
	}
}
