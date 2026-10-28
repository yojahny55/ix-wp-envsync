<?php
use PHPUnit\Framework\TestCase;

/** The hub's view of the push snapshots a remote holds: status, its next command, and the note after a push. */
class RemoteJobsTest extends TestCase {
	private $now = 1758600000;
	private $calls = 0;

	private function ctx( $jobs ) {
		return [
			'now' => $this->now, 'hub_version' => '0.9.22', 'token_issued' => false, 'token_pending' => false,
			'envs' => [ 'prod' => [ 'name' => 'prod', 'url' => 'https://p.test', 'label' => 'prod', 'excludes' => [] ] ],
			'baseline' => function ( $n ) { return [ 'created_at' => $this->now - 86400, 'partial_at' => null, 'partial_scope' => null ]; },
			'pull_state' => function ( $n ) { return null; },
			'running' => function ( $n ) { return null; },
			'jobs' => function ( $env ) use ( $jobs ) { $this->calls++; return $jobs; },
		];
	}
	private function info( array $caps ) {
		return function ( $env ) use ( $caps ) { return [ 'plugin' => '0.9.22', 'lock' => null, 'auth_via' => 'session', 'url' => $env['url'], 'caps' => $caps ]; };
	}
	private function usage( $count, $mb ) { return [ 'ok' => true, 'jobs' => [], 'usage' => [ 'count' => $count, 'bytes' => $mb * 1048576 ] ]; }

	public function test_heavy_snapshots_make_prune_jobs_the_next_command() {
		$r = IXES_Status::build( null, $this->info( [ 'jobs' ] ), $this->ctx( $this->usage( 3, 768 ) ) );
		$this->assertSame( [ 'count' => 3, 'bytes' => 768 * 1048576 ], $r['envs']['prod']['snapshots'] );
		$this->assertSame( 'wp envsync prune-jobs prod --keep=1', $r['next']['command'] );
		$this->assertStringContainsString( '3 push snapshot(s)', $r['next']['why'] );
		$this->assertStringContainsString( 'snapshots: 3 push job(s)', IXES_Status::render_text( $r ) );
	}

	public function test_light_snapshots_leave_the_site_ready() {
		$r = IXES_Status::build( null, $this->info( [ 'jobs' ] ), $this->ctx( $this->usage( 2, 40 ) ) );
		$this->assertSame( 'ready', $r['next']['why'] );
		$this->assertSame( 2, $r['envs']['prod']['snapshots']['count'] );
	}

	public function test_anything_pending_comes_before_the_prune() {
		$ctx = $this->ctx( $this->usage( 3, 900 ) );
		$ctx['baseline'] = function ( $n ) { return [ 'created_at' => null, 'partial_at' => null, 'partial_scope' => null ]; };
		$this->assertSame( 'wp envsync pull prod', IXES_Status::build( null, $this->info( [ 'jobs' ] ), $ctx )['next']['command'] );
	}

	public function test_a_remote_without_the_cap_is_not_asked() {
		$r = IXES_Status::build( null, $this->info( [ 'binary' ] ), $this->ctx( $this->usage( 3, 900 ) ) );
		$this->assertSame( 0, $this->calls );
		$this->assertNull( $r['envs']['prod']['snapshots'] );
		$this->assertSame( 'ready', $r['next']['why'] );
	}

	public function test_a_failing_jobs_route_shows_nothing_and_breaks_nothing() {
		$r = IXES_Status::build( null, $this->info( [ 'jobs' ] ), $this->ctx( new WP_Error( 'remote_500', 'remote 500 on /jobs' ) ) );
		$this->assertNull( $r['envs']['prod']['snapshots'] );
		$this->assertSame( 'ready', $r['next']['why'] );
	}

	public function test_the_note_after_a_push_names_the_command_past_the_threshold() {
		$this->assertNull( IXES_Status::snapshots_note( null, 'prod' ), 'a remote older than 0.9.22 reports nothing' );
		$this->assertNull( IXES_Status::snapshots_note( [ 'count' => 3, 'bytes' => 499 * 1048576 ], 'prod' ) );
		$note = IXES_Status::snapshots_note( [ 'count' => 3, 'bytes' => 500 * 1048576 ], 'prod' );
		$this->assertStringContainsString( 'prod holds 3 push snapshot(s)', $note );
		$this->assertStringContainsString( 'wp envsync prune-jobs prod --keep=1', $note );
		$this->assertNull( IXES_Status::snapshots_note( [ 'count' => 1, 'bytes' => 900 * 1048576 ], 'prod' ), 'one snapshot: --keep=1 would free nothing' );
	}

	public function test_one_heavy_snapshot_leaves_the_site_ready() {
		$this->assertSame( 'ready', IXES_Status::build( null, $this->info( [ 'jobs' ] ), $this->ctx( $this->usage( 1, 900 ) ) )['next']['why'] );
	}
}
