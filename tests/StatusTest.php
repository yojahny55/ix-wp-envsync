<?php
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase {
	private $now = 1758600000;

	private function ctx( array $over = [] ) {
		return $over + [
			'now' => $this->now, 'hub_version' => '0.4.0', 'token_issued' => false, 'token_pending' => false,
			'envs' => [ 'prod' => [ 'name' => 'prod', 'url' => 'https://p.test', 'label' => 'prod', 'excludes' => [] ] ],
			'baseline' => function ( $n ) { return [ 'created_at' => $this->now - 86400, 'partial_at' => null, 'partial_scope' => null ]; },
			'pull_state' => function ( $n ) { return null; },
		];
	}
	private function info( array $over = [] ) {
		return function ( $env ) use ( $over ) { return $over + [ 'plugin' => '0.4.0', 'lock' => null, 'auth_via' => 'authorization', 'url' => $env['url'] ]; };
	}
	private function next( array $ctx, $info ) { return IXES_Status::build( null, $info, $ctx )['next']; }

	public function test_unconfigured_site() {
		$r = IXES_Status::build( null, $this->info(), $this->ctx( [ 'envs' => [] ] ) );
		$this->assertSame( 'unconfigured', $r['role'] );
		$this->assertStringContainsString( 'not set up', $r['next']['why'] );
	}
	public function test_remote_only_role() {
		$r = IXES_Status::build( null, $this->info(), $this->ctx( [ 'envs' => [], 'token_issued' => true ] ) );
		$this->assertSame( 'remote', $r['role'] );
		$this->assertStringContainsString( 'remote', $r['next']['why'] );
	}
	public function test_unreachable_env() {
		$info = function () { return new WP_Error( 'http', 'cURL error 7' ); };
		$n = $this->next( $this->ctx(), $info );
		$this->assertStringContainsString( 'env add prod', $n['command'] );
		$this->assertStringContainsString( 'cURL error 7', $n['why'] );
	}
	public function test_crashing_remote_suggests_rescue() {
		$info = function () { return new WP_Error( 'remote_500', 'remote 500 on /info: <p>There has been a critical error on this website.</p>' ); };
		$n = $this->next( $this->ctx(), $info );
		$this->assertSame( 'wp envsync rescue prod', $n['command'] );
	}
	public function test_old_remote_version() {
		$n = $this->next( $this->ctx(), $this->info( [ 'plugin' => '0.3.0' ] ) );
		$this->assertStringContainsString( 'upload the release zip', $n['command'] );
	}
	public function test_stale_lock_beats_interrupted_pull() {
		$ctx = $this->ctx( [ 'pull_state' => function () { return [ 'started' => $this->now - 600, 'table' => 'wp_posts', 'cursor' => '5', 'files_done' => 1, 'files_total' => 9 ]; } ] );
		$n = $this->next( $ctx, $this->info( [ 'lock' => [ 'job' => 'j1', 'started' => $this->now - 1200 ] ] ) );
		$this->assertSame( 'wp envsync unlock prod', $n['command'] );
	}
	public function test_bare_lock_with_no_started_is_treated_as_stale() {
		$r = IXES_Status::build( null, $this->info( [ 'lock' => [ 'job' => 'j1', 'started' => null ] ] ), $this->ctx() );
		$this->assertSame( 'wp envsync unlock prod', $r['next']['command'] );
		$this->assertNull( $r['envs']['prod']['remote_lock']['age_minutes'] );
	}
	public function test_unknown_env_name_yields_no_such_environment() {
		$r = IXES_Status::build( 'nonexistent', $this->info(), $this->ctx() );
		$this->assertSame( 'no such environment', $r['next']['why'] );
		$this->assertSame( [], $r['envs'] );
	}
	public function test_young_lock_does_not_change_next() {
		$n = $this->next( $this->ctx(), $this->info( [ 'lock' => [ 'job' => 'j1', 'started' => $this->now - 60 ] ] ) );
		$this->assertSame( 'wp envsync diff prod', $n['command'] );
	}
	public function test_interrupted_pull() {
		$ctx = $this->ctx( [ 'pull_state' => function () { return [ 'started' => $this->now - 600, 'table' => null, 'cursor' => null, 'files_done' => 3, 'files_total' => 9 ]; } ] );
		$n = $this->next( $ctx, $this->info() );
		$this->assertSame( 'wp envsync pull prod', $n['command'] );
		$this->assertStringContainsString( 'resume', $n['why'] );
	}
	public function test_no_baseline() {
		$ctx = $this->ctx( [ 'baseline' => function () { return [ 'created_at' => null, 'partial_at' => null, 'partial_scope' => null ]; } ] );
		$n = $this->next( $ctx, $this->info() );
		$this->assertSame( 'wp envsync pull prod', $n['command'] );
		$this->assertStringContainsString( 'no baseline', $n['why'] );
	}
	public function test_no_baseline_and_fresh_remote_suggests_first_deploy() {
		$ctx = $this->ctx( [ 'baseline' => function () { return [ 'created_at' => null, 'partial_at' => null, 'partial_scope' => null ]; } ] );
		$tables = [ [ 'name' => 'wp_posts', 'pk' => 'ID', 'rows' => 3 ], [ 'name' => 'wp_users', 'pk' => 'ID', 'rows' => 1 ] ];
		$n = $this->next( $ctx, $this->info( [ 'prefix' => 'wp_', 'tables' => $tables ] ) );
		$this->assertSame( 'wp envsync push prod --force --dry-run', $n['command'] );
		$this->assertStringContainsString( 'fresh install (3 posts)', $n['why'] );
		$tables[0]['rows'] = 400;
		$this->assertSame( 'wp envsync pull prod', $this->next( $ctx, $this->info( [ 'prefix' => 'wp_', 'tables' => $tables ] ) )['command'] );
	}

	public function test_old_baseline() {
		$ctx = $this->ctx( [ 'baseline' => function () { return [ 'created_at' => $this->now - 10 * 86400, 'partial_at' => null, 'partial_scope' => null ]; } ] );
		$n = $this->next( $ctx, $this->info() );
		$this->assertSame( 'wp envsync diff prod', $n['command'] );
		$this->assertStringContainsString( '10 days', $n['why'] );
	}
	public function test_ready() {
		$r = IXES_Status::build( null, $this->info(), $this->ctx() );
		$this->assertSame( 'hub', $r['role'] );
		$this->assertSame( [ 'command' => 'wp envsync diff prod', 'why' => 'ready', 'env' => 'prod' ], $r['next'] );
		$this->assertTrue( $r['envs']['prod']['reachable'] );
		$this->assertTrue( $r['envs']['prod']['version_ok'] );
		$this->assertSame( 1, $r['envs']['prod']['baseline']['age_days'] );
	}
	public function test_first_non_ready_env_decides() {
		$ctx = $this->ctx( [ 'envs' => [
			'prod'    => [ 'name' => 'prod', 'url' => 'https://p.test', 'label' => 'prod', 'excludes' => [] ],
			'staging' => [ 'name' => 'staging', 'url' => 'https://s.test', 'label' => 'staging', 'excludes' => [] ],
		] ] );
		$info = function ( $env ) { return $env['name'] === 'staging' ? new WP_Error( 'http', 'down' ) : [ 'plugin' => '0.4.0', 'lock' => null, 'auth_via' => 'authorization', 'url' => $env['url'] ]; };
		$this->assertSame( 'staging', $this->next( $ctx, $info )['env'] );
	}
	public function test_render_text_contains_next_line() {
		$r = IXES_Status::build( null, $this->info(), $this->ctx() );
		$t = IXES_Status::render_text( $r );
		$this->assertStringContainsString( 'prod  https://p.test  (prod)', $t );
		$this->assertStringContainsString( 'Next: wp envsync diff prod', $t );
	}
}
