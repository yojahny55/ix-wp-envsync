<?php
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase {
	private function input( array $over = [] ) {
		return $over + [
			'kind' => 'push', 'env' => 'staging', 'url' => 'https://s.test', 'created' => 1789000000, 'direction' => 'push',
			'baseline_at' => null, 'first_deploy' => true, 'scope' => 'everything', 'scope_full' => true,
			'tables' => [
				'wp_posts' => [ 'push' => 2, 'insert' => 489, 'delete' => 0, 'prod_wins' => 0, 'kept_prod' => 0 ],
				'wp_comments' => [ 'push' => 0, 'insert' => 0, 'delete' => 0, 'prod_wins' => 0, 'kept_prod' => 0 ],
			],
			'rows' => null,
			'files' => [
				'plugins/all-in-one-wp-security-and-firewall/wp-security.php', 'plugins/all-in-one-wp-security-and-firewall/readme.txt',
				'plugins/polylang/polylang.php', 'plugins/hello.php',
				'themes/nscs/style.css', 'uploads/2026/09/a.jpg', 'languages/es_ES.mo', 'wp-cache-config.php',
			],
			'deletes' => [],
			'sizes' => [ 'plugins/all-in-one-wp-security-and-firewall/wp-security.php' => 1000, 'plugins/all-in-one-wp-security-and-firewall/readme.txt' => 24, 'plugins/polylang/polylang.php' => 10, 'plugins/hello.php' => 5, 'themes/nscs/style.css' => 7, 'uploads/2026/09/a.jpg' => 2097152, 'languages/es_ES.mo' => 3, 'wp-cache-config.php' => 1 ],
			'before' => [ 'plugins' => [ 'polylang' => '3.6.1', 'akismet' => '5.3' ], 'themes' => [ 'twentytwentyfive' => '1.2' ], 'stylesheet' => 'twentytwentyfive' ],
			'source' => [ 'plugins' => [ 'polylang' => '3.7.0', 'all-in-one-wp-security-and-firewall' => '5.4.0', 'hello.php' => '1.7.2' ], 'themes' => [ 'nscs' => '1.0.0' ], 'stylesheet' => 'nscs' ],
			'active_before' => [ 'akismet/akismet.php', 'polylang/polylang.php' ],
			'active_after' => [ 'polylang/polylang.php', 'all-in-one-wp-security-and-firewall/wp-security.php' ],
			'stylesheet_after' => 'nscs',
			'conflicts' => [], 'warnings' => [],
		];
	}
	private function by( array $list, $key, $val ) { foreach ( $list as $x ) if ( $x[ $key ] === $val ) return $x; return null; }

	public function test_plugin_rows_versions_and_activation() {
		$r = IXES_Report::build( $this->input() );
		$aio = $this->by( $r['plugins'], 'slug', 'all-in-one-wp-security-and-firewall' );
		$this->assertSame( [ 'before' => null, 'after' => '5.4.0' ], $aio['version'] );
		$this->assertSame( 'turns on', $aio['change'] );
		$this->assertSame( 2, $aio['files'] ); $this->assertSame( 1024, $aio['bytes'] );
		$poly = $this->by( $r['plugins'], 'slug', 'polylang' );
		$this->assertSame( [ 'before' => '3.6.1', 'after' => '3.7.0' ], $poly['version'] );
		$this->assertSame( 'stays on', $poly['change'] );
		$ak = $this->by( $r['plugins'], 'slug', 'akismet' );
		$this->assertSame( 0, $ak['files'] );
		$this->assertSame( 'turns off', $ak['change'] );
		$this->assertSame( [ 'before' => '5.3', 'after' => '5.3' ], $ak['version'] );
		$this->assertNotNull( $this->by( $r['plugins'], 'slug', 'hello.php' ) );
	}

	public function test_theme_becomes_active() {
		$r = IXES_Report::build( $this->input() );
		$t = $this->by( $r['themes'], 'slug', 'nscs' );
		$this->assertSame( 'becomes active', $t['change'] );
		$this->assertSame( [ 'before' => null, 'after' => '1.0.0' ], $t['version'] );
		$old = $this->by( $r['themes'], 'slug', 'twentytwentyfive' );
		$this->assertSame( 'stops being active', $old['change'] );
	}

	public function test_other_groups_tables_and_summary() {
		$r = IXES_Report::build( $this->input() );
		$this->assertSame( [ 'uploads/2026/', 'languages/', 'wp-content root' ], array_column( $r['other'], 'group' ) );
		$this->assertSame( [ 'wp_posts' ], array_column( $r['tables'], 'name' ) );
		$this->assertSame( 8, $r['summary']['files'] );
		$this->assertSame( 491, $r['summary']['rows'] );
		$this->assertSame( 1, $r['schema'] );
	}

	public function test_unknown_remote_inventory_and_sizes() {
		$r = IXES_Report::build( $this->input( [ 'before' => null, 'sizes' => null ] ) );
		$poly = $this->by( $r['plugins'], 'slug', 'polylang' );
		$this->assertSame( '?', $poly['version']['before'] );
		$this->assertNull( $poly['bytes'] );
		$this->assertNull( $r['summary']['bytes'] );
	}

	public function test_render_text_is_aligned_and_keeps_grep_strings() {
		$in = $this->input( [ 'scope' => 'themes', 'scope_full' => false, 'conflicts' => [ [ 'type' => 'row', 'table' => 'wp_posts', 'id' => '7', 'title' => 'Home' ] ] ] );
		$t = IXES_Report::render_text( IXES_Report::build( $in ) );
		$this->assertStringContainsString( 'scope: themes', $t );
		$this->assertStringContainsString( 'CONFLICTS (prod wins)', $t );
		$this->assertStringContainsString( 'first deploy', $t );
		$this->assertStringContainsString( '3.6.1 → 3.7.0', $t );
		$lines = array_values( array_filter( explode( "\n", $t ), function ( $l ) { return strpos( $l, '| all-in-one' ) === 0 || strpos( $l, '| polylang' ) === 0; } ) );
		$this->assertCount( 2, $lines );
		$this->assertSame( mb_strlen( $lines[0] ), mb_strlen( $lines[1] ) );
	}

	public function test_pull_direction_lists_table_rows() {
		$in = $this->input( [ 'kind' => 'pull', 'direction' => 'pull', 'first_deploy' => false, 'tables' => [ 'wp_posts' => [ 'rows' => 500 ] ], 'rows' => 500 ] );
		$r = IXES_Report::build( $in );
		$this->assertSame( [ [ 'name' => 'wp_posts', 'rows' => 500 ] ], $r['tables'] );
		$this->assertStringContainsString( 'staging  →  local', IXES_Report::render_text( $r ) );
	}
}
