<?php
use PHPUnit\Framework\TestCase;

/** Every plugin on either side shows up, with where it lives, orphan folders and active entries whose folder is gone. */
class PluginInventoryTest extends TestCase {
	private function by( array $list, $slug ) { foreach ( $list as $x ) if ( $x['slug'] === $slug ) return $x; return null; }

	private function sides() {
		return [
			// local: shared plugin, a local-only one, and an orphan folder left by a half-deleted plugin
			[ 'plugins' => [ 'polylang' => '3.7.0', 'dev-tools' => '1.0' ], 'themes' => [], 'stylesheet' => 't', 'orphans' => [ 'half-gone' ] ],
			// remote: shared plugin, a remote-only one, and an orphan folder with no readable header
			[ 'plugins' => [ 'polylang' => '3.6.1', 'forms' => '2.0' ], 'themes' => [], 'stylesheet' => 't', 'orphans' => [ 'leftover' ] ],
			[ 'polylang/polylang.php', 'dev-tools/dev.php' ],
			// 'ghost' is active on the remote but its folder is gone
			[ 'polylang/polylang.php', 'forms/forms.php', 'ghost/ghost.php' ],
		];
	}

	public function test_inventory_lists_every_plugin_on_either_side() {
		list( $l, $r, $al, $ar ) = $this->sides();
		$rows = IXES_Report::plugin_inventory( $l, $r, $al, $ar );
		$this->assertSame( [ 'dev-tools', 'forms', 'ghost', 'half-gone', 'leftover', 'polylang' ], array_column( $rows, 'slug' ) );

		$poly = $this->by( $rows, 'polylang' );
		$this->assertSame( 'both', $poly['presence'] );
		$this->assertSame( [ 'local' => '3.7.0', 'remote' => '3.6.1' ], $poly['version'] );
		$this->assertSame( [ 'local' => true, 'remote' => true ], $poly['active'] );
		$this->assertSame( [], $poly['orphan'] );
		$this->assertSame( [], $poly['active_missing'] );

		$this->assertSame( 'local-only', $this->by( $rows, 'dev-tools' )['presence'] );
		$forms = $this->by( $rows, 'forms' );
		$this->assertSame( 'remote-only', $forms['presence'] );
		$this->assertSame( [ 'local' => false, 'remote' => true ], $forms['active'] );
	}

	public function test_orphan_folders_and_missing_active_folders_are_flagged() {
		list( $l, $r, $al, $ar ) = $this->sides();
		$rows = IXES_Report::plugin_inventory( $l, $r, $al, $ar );

		$leftover = $this->by( $rows, 'leftover' );
		$this->assertSame( 'remote-only', $leftover['presence'] );
		$this->assertSame( [ 'remote' ], $leftover['orphan'] );
		$this->assertSame( [ 'local' => null, 'remote' => null ], $leftover['version'] );
		$this->assertSame( [ 'local' ], $this->by( $rows, 'half-gone' )['orphan'] );

		$ghost = $this->by( $rows, 'ghost' );
		$this->assertSame( 'none', $ghost['presence'] );
		$this->assertSame( [ 'remote' ], $ghost['active_missing'] );
	}

	public function test_unknown_side_leaves_presence_open() {
		list( $l, , $al ) = $this->sides();
		$rows = IXES_Report::plugin_inventory( $l, null, $al, null );
		$poly = $this->by( $rows, 'polylang' );
		$this->assertNull( $poly['presence'] );
		$this->assertSame( '?', $poly['version']['remote'] );
		$this->assertNull( $poly['active']['remote'] );
		$this->assertSame( [], $poly['active_missing'] );
	}

	public function test_render_inventory_shows_notes() {
		list( $l, $r, $al, $ar ) = $this->sides();
		$t = IXES_Report::render_inventory( IXES_Report::plugin_inventory( $l, $r, $al, $ar ), 'staging' );
		$this->assertStringContainsString( 'staging', $t );
		$this->assertMatchesRegularExpression( '/\| leftover .*orphan folder \(remote\)/', $t );
		$this->assertMatchesRegularExpression( '/\| ghost .*active, folder missing \(remote\)/', $t );
		$this->assertMatchesRegularExpression( '/\| forms .*remote only/', $t );
	}

	// ---------- plans ----------

	private function pull_input( array $over = [] ) {
		list( $l, $r, $al, $ar ) = $this->sides();
		return $over + [
			'kind' => 'pull', 'env' => 'staging', 'url' => 'https://s.test', 'created' => 1789000000, 'direction' => 'pull',
			'baseline_at' => null, 'first_deploy' => false, 'scope' => 'plugins', 'scope_full' => false,
			'tables' => [], 'rows' => 0, 'files' => [ 'plugins/leftover/x.js' ], 'deletes' => [], 'sizes' => null,
			'before' => $l, 'source' => $r, 'active_before' => $al, 'active_after' => $al,
			'active_local' => $al, 'active_remote' => $ar, 'list_plugins' => true,
			'stylesheet_after' => 't', 'conflicts' => [], 'warnings' => [],
		];
	}

	public function test_plan_lists_every_plugin_with_presence() {
		$rep = IXES_Report::build( $this->pull_input() );
		$this->assertSame( [ 'dev-tools', 'forms', 'ghost', 'half-gone', 'leftover', 'polylang' ], array_column( $rep['plugins'], 'slug' ) );
		$forms = $this->by( $rep['plugins'], 'forms' );
		$this->assertSame( 'remote-only', $forms['presence'] );
		$this->assertSame( 0, $forms['files'] );
		$this->assertSame( [ 'remote' ], $this->by( $rep['plugins'], 'leftover' )['orphan'] );
		$this->assertSame( [ 'remote' ], $this->by( $rep['plugins'], 'ghost' )['active_missing'] );
	}

	public function test_push_plan_maps_sides_the_other_way() {
		list( $l, $r, $al, $ar ) = $this->sides();
		$rep = IXES_Report::build( $this->pull_input( [ 'kind' => 'push', 'direction' => 'push', 'before' => $r, 'source' => $l, 'active_before' => $ar, 'active_after' => $ar ] ) );
		$this->assertSame( 'local-only', $this->by( $rep['plugins'], 'dev-tools' )['presence'] );
		$this->assertSame( 'remote-only', $this->by( $rep['plugins'], 'forms' )['presence'] );
	}

	public function test_plan_outside_plugin_scope_keeps_only_moving_plugins() {
		$rep = IXES_Report::build( $this->pull_input( [ 'list_plugins' => false ] ) );
		$this->assertSame( [ 'leftover' ], array_column( $rep['plugins'], 'slug' ) );
		$this->assertSame( [ 'remote' ], $rep['plugins'][0]['orphan'] );
	}

	public function test_plan_text_hides_quiet_plugins_but_shows_notes() {
		$t = IXES_Report::render_text( IXES_Report::build( $this->pull_input() ) );
		$this->assertStringNotContainsString( '| polylang ', $t );
		$this->assertMatchesRegularExpression( '/\| forms .*remote only/', $t );
		$this->assertMatchesRegularExpression( '/\| ghost .*active, folder missing \(remote\)/', $t );
	}

	public function test_nothing_to_pull_ignores_quiet_plugins() {
		$in = $this->pull_input( [ 'files' => [], 'before' => [ 'plugins' => [ 'a' => '1' ], 'themes' => [], 'stylesheet' => 't' ], 'source' => [ 'plugins' => [ 'a' => '1' ], 'themes' => [], 'stylesheet' => 't' ], 'active_before' => [], 'active_after' => [], 'active_local' => [], 'active_remote' => [] ] );
		$this->assertStringContainsString( 'Nothing to pull.', IXES_Report::render_text( IXES_Report::build( $in ) ) );
	}

	// ---------- inventory side ----------

	public function test_orphan_dirs_are_folders_without_a_plugin_header() {
		$d = sys_get_temp_dir() . '/ixes-orphans-' . uniqid();
		foreach ( [ 'akismet', 'leftover', '.git' ] as $x ) mkdir( "{$d}/{$x}", 0777, true );
		touch( "{$d}/hello.php" );
		$this->assertSame( [ 'leftover' ], IXES_Transfer::orphan_plugin_dirs( $d, [ 'akismet' => '5.3', 'hello.php' => '1.7' ] ) );
		$this->assertSame( [], IXES_Transfer::orphan_plugin_dirs( "{$d}/missing", [] ) );
		array_map( 'rmdir', glob( "{$d}/{,.}[!.]*", GLOB_BRACE | GLOB_ONLYDIR ) ); unlink( "{$d}/hello.php" ); rmdir( $d );
	}
}
