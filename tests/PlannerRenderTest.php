<?php
use PHPUnit\Framework\TestCase;

class PlannerRenderTest extends TestCase {
	public function test_render_text_shows_counts_and_conflicts() {
		$plan = [
			'env' => 'prod', 'created' => 1, 'baseline_at' => 1, 'algo' => 'sha1', 'two_way' => false,
			'tables' => [
				'wp_posts' => [ 'pk' => 'ID', 'push' => [ 1, 2 ], 'insert' => [ 3 ], 'delete' => [], 'conflict' => [ 9 ], 'kept' => [ 9, 10 ], 'set_insert' => [] ],
			],
			'files' => [ 'push' => [ 'themes/k/style.css', 'themes/k/a.php' ], 'delete' => [], 'conflict' => [], 'kept' => [ 'plugins/acf/acf.php' ] ],
			'active_plugins' => null, 'remote_hashes' => [], 'conflict_detail' => [ 'wp_posts' => [ 9 => 'Services' ] ],
		];
		$txt = IXES_Planner::render_text( $plan );
		$this->assertStringContainsString( 'wp_posts', $txt );
		$this->assertStringContainsString( 'push 2', $txt );
		$this->assertStringContainsString( 'insert 1', $txt );
		$this->assertStringContainsString( 'remote-wins 1', $txt );
		$this->assertStringContainsString( 'kept-remote 1', $txt, 'row 9 counts once, as remote-wins' );
		$this->assertStringContainsString( 'same 0', $txt );
		$this->assertStringContainsString( 'themes/k/', $txt );
		$this->assertStringContainsString( 'CONFLICTS', $txt );
		$this->assertStringContainsString( '#9', $txt );
		$this->assertFalse( IXES_Planner::is_empty( $plan ) );
	}
	public function test_empty_plan() {
		$plan = [ 'tables' => [], 'files' => [ 'push' => [], 'delete' => [], 'conflict' => [], 'kept' => [] ], 'active_plugins' => null ];
		$this->assertTrue( IXES_Planner::is_empty( $plan ) );
	}

	public function test_active_plugins_null_is_empty_but_list_is_not() {
		$files = [ 'push' => [], 'delete' => [], 'conflict' => [], 'kept' => [] ];
		$plan_null = [ 'tables' => [], 'files' => $files, 'active_plugins' => null ];
		$this->assertTrue( IXES_Planner::is_empty( $plan_null ) );
		$plan_list = [ 'tables' => [], 'files' => $files, 'active_plugins' => [ 'foo/foo.php' ] ];
		$this->assertFalse( IXES_Planner::is_empty( $plan_list ) );
	}

	private function plan() {
		return [
			'env' => 'prod', 'baseline_at' => 1, 'two_way' => false, 'tables' => [],
			'files' => [ 'push' => [], 'delete' => [], 'conflict' => [], 'kept' => [] ],
			'active_plugins' => null, 'conflict_detail' => [],
		];
	}

	public function test_render_shows_scope_when_not_full() {
		$plan = $this->plan();
		$plan['scope'] = [ 'only' => [ 'themes' ], 'tables' => [], 'paths' => [ 'themes/mk/' ] ];
		$this->assertStringContainsString( 'scope: themes, paths themes/mk/', IXES_Planner::render_text( $plan ) );
		unset( $plan['scope'] );
		$this->assertStringNotContainsString( 'scope:', IXES_Planner::render_text( $plan ) );
	}

	public function test_render_text_names_the_timezone_of_the_baseline() {
		$plan = [
			'env' => 'prod', 'created' => 1, 'baseline_at' => 1790377984, 'algo' => 'sha1', 'two_way' => false,
			'tables' => [], 'files' => [ 'push' => [], 'delete' => [], 'conflict' => [], 'kept' => [] ],
			'active_plugins' => null, 'remote_hashes' => [], 'conflict_detail' => [],
		];
		$this->assertStringContainsString( 'baseline: ' . date( 'Y-m-d H:i T', 1790377984 ), IXES_Planner::render_text( $plan ) );
		$this->assertMatchesRegularExpression( '/baseline: \\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2} \\S+/', IXES_Planner::render_text( $plan ) );
	}
	public function test_render_without_baseline_says_where_rows_are() {
		$plan = [
			'env' => 'staging', 'created' => 1, 'baseline_at' => null, 'algo' => 'sha1', 'two_way' => true,
			'tables' => [ 'wp_posts' => [ 'pk' => 'ID', 'push' => [], 'insert' => [ 3 ], 'delete' => [], 'conflict' => [ 9 ], 'kept' => [ 9, 10 ], 'same' => 5, 'set_insert' => [] ] ],
			'files' => [ 'push' => [], 'delete' => [], 'conflict' => [ 'themes/k/a.php' ], 'kept' => [ 'themes/k/a.php', 'uploads/x.jpg' ] ],
			'active_plugins' => null, 'remote_hashes' => [], 'conflict_detail' => [],
		];
		$txt = IXES_Planner::render_text( $plan );
		$this->assertStringContainsString( 'local-only 1', $txt );
		$this->assertStringContainsString( 'differs 1', $txt );
		$this->assertStringContainsString( 'remote-only 1', $txt );
		$this->assertStringContainsString( 'same 5', $txt );
		$this->assertStringContainsString( 'themes/k/                                differs 1', $txt );
		$this->assertStringContainsString( 'uploads/x.jpg/                           remote-only 1', $txt );
		$this->assertStringContainsString( 'DIFFERENT ON BOTH SIDES', $txt );
		$this->assertStringNotContainsString( 'remote-wins', $txt );
	}
	public function test_without_baseline_keyless_rows_only_here_count_as_local_only() {
		$plan = [
			'env' => 'staging', 'created' => 1, 'baseline_at' => null, 'algo' => 'sha1', 'two_way' => true,
			'tables' => [ 'wp_nokey' => [ 'pk' => null, 'push' => [], 'insert' => [], 'delete' => [], 'conflict' => [], 'kept' => [], 'same' => 2, 'set_insert' => [ 'h1', 'h2', 'h3' ] ] ],
			'files' => [ 'push' => [], 'delete' => [], 'conflict' => [], 'kept' => [] ], 'active_plugins' => null, 'remote_hashes' => [], 'conflict_detail' => [],
		];
		$this->assertStringContainsString( 'local-only 3', IXES_Planner::render_text( $plan ) );
		$r = IXES_Report::build( [ 'kind' => 'diff', 'env' => 'staging', 'url' => '', 'created' => 1, 'direction' => 'push', 'baseline_at' => null, 'first_deploy' => true, 'scope' => 'everything', 'scope_full' => true,
			'tables' => [ 'wp_nokey' => IXES_Report::table_counts( $plan['tables']['wp_nokey'] ) ], 'rows' => null, 'files' => [], 'deletes' => [], 'sizes' => [], 'before' => null, 'source' => [ 'plugins' => [], 'themes' => [], 'stylesheet' => null ],
			'active_before' => [], 'active_after' => [], 'stylesheet_after' => null, 'conflicts' => [], 'warnings' => [] ] );
		$this->assertMatchesRegularExpression( '/wp_nokey\s*\|\s*3\s*\|\s*0\s*\|\s*0\s*\|\s*2/', IXES_Report::render_text( $r ) );
	}
}
