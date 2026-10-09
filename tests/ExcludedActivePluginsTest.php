<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BinCellsWpdb.php';
if ( ! function_exists( 'esc_sql' ) ) { function esc_sql( $s ) { return addslashes( (string) $s ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'wp_cache_flush' ) ) { function wp_cache_flush() { return true; } }

/** A remote in memory holding only wp_options, with its own active plugin list. */
class ActivePluginsRemote extends IXES_Client {
	public $remote; public $hub; public $active = [];

	public function info( $timeout = null ) {
		return [ 'url' => 'https://p.test', 'abspath' => '/srv/p', 'prefix' => 'wp_', 'algos' => [ 'sha1' ], 'plugin' => IXES_VERSION,
			'caps' => [ 'binary', 'schema', 'exclude_options', IXES_Hasher::CAP ], 'active_plugins' => $this->active,
			'tables' => [ [ 'name' => 'wp_options', 'pk' => 'option_id', 'rows' => count( $this->remote->rows['wp_options'] ) ] ] ];
	}
	public function caps() { return $this->info()['caps']; }

	public function post( $route, $body, $opts = [] ) {
		$body = json_decode( json_encode( $body ), true );
		$GLOBALS['wpdb'] = $this->remote;
		try {
			$from = IXES_Rest::cursor_in( $body );
			if ( $route === '/hash/rows' ) $r = IXES_Transfer::hash_rows( $body['table'], $from, (int) $body['limit'], [], 'sha1', ! empty( $body['cells'] ) );
			else return new WP_Error( 'unscripted', $route );
		} finally {
			$GLOBALS['wpdb'] = $this->hub;
		}
		return json_decode( json_encode( IXES_Rest::cursor_out( $r ) ), true );
	}
}

class ExcludedActivePluginsTest extends TestCase {
	private $hub; private $remote;

	protected function setUp(): void {
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-ap-' . getmypid() . '-' . uniqid();
		mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
		$this->hub = new BinCellsWpdb(); $this->remote = new BinCellsWpdb();
		foreach ( [ $this->hub, $this->remote ] as $db ) {
			$db->table( 'wp_options', [ 'option_id', 'option_name', 'option_value', 'autoload' ], [ 'option_id' ] );
			$db->insert( 'wp_options', [ 'option_id' => '1', 'option_name' => 'blogname', 'option_value' => 'Shop', 'autoload' => 'yes' ] );
		}
		$GLOBALS['wpdb'] = $this->hub;
		$GLOBALS['ixes_test_options']['active_plugins'] = [ 'a/a.php', 'seo/seo.php' ];
	}
	protected function tearDown(): void { unset( $GLOBALS['wpdb'], $GLOBALS['ixes_test_options']['active_plugins'] ); }

	private function plan( array $exclude_options ) {
		$c = new ActivePluginsRemote( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$c->remote = $this->remote; $c->hub = $this->hub; $c->active = [ 'a/a.php' ];
		$env = [ 'name' => 'p', 'url' => 'https://p.test', 'extra_replace' => [], 'excludes' => [] ];
		if ( $exclude_options ) $env['exclude_options'] = $exclude_options;
		$plan = IXES_Planner::build( $env, $c, IXES_Scope::from_array( [ 'only' => [ 'db' ] ], 'wp_' ) );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		return $plan;
	}

	public function test_a_changed_active_plugins_list_is_pushed() {
		$this->assertSame( [ 'a/a.php', 'seo/seo.php' ], $this->plan( [] )['active_plugins'] );
	}

	public function test_an_excluded_active_plugins_list_plans_no_step() {
		$this->assertNull( $this->plan( [ 'active_plugins' ] )['active_plugins'], 'the remote refuses that step and the push rolls back' );
	}

	public function test_a_glob_that_covers_active_plugins_plans_no_step() {
		$this->assertNull( $this->plan( [ 'active_*' ] )['active_plugins'] );
	}

	public function test_an_unrelated_exclude_keeps_the_step() {
		$this->assertSame( [ 'a/a.php', 'seo/seo.php' ], $this->plan( [ 'imunify_*' ] )['active_plugins'] );
	}
}
