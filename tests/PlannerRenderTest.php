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
		$this->assertStringContainsString( 'kept-remote 2', $txt );
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
}
