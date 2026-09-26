<?php
use PHPUnit\Framework\TestCase;

class BatchFakeClient extends IXES_Client {
	public $script = [];   // list of callables ( array $body ) => array response data
	public $bodies = [];
	protected function transport( $url, array $args ) {
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$body['_route'] = (string) parse_url( $url, PHP_URL_QUERY );
		$this->bodies[] = $body;
		$fn = array_shift( $this->script );
		if ( ! $fn ) throw new RuntimeException( 'unscripted call' );
		return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( $fn( $body ) ) ];
	}
	protected function sleep_s( $s ) {}
}

class FewerRequestsTest extends TestCase {
	private function client() { return new BatchFakeClient( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] ); }
	private static function t( $name, $rows, $pk = 'id' ) { return [ 'name' => $name, 'pk' => $pk, 'rows' => $rows ]; }

	public function test_empty_remote_tables_cost_no_request() {
		$c = $this->client();
		$out = IXES_Planner::remote_hashes( $c, [ self::t( 'wp_a', 0 ), self::t( 'wp_b', 0, null ) ], [ 'hash_batch' ], 'sha1', [] );
		$this->assertSame( [ 'wp_a' => [], 'wp_b' => [] ], $out );
		$this->assertSame( [], $c->bodies );
	}

	public function test_old_remote_skips_empty_tables_and_leaves_the_rest_to_the_caller() {
		$c = $this->client();
		$out = IXES_Planner::remote_hashes( $c, [ self::t( 'wp_a', 0 ), self::t( 'wp_b', 3 ) ], [], 'sha1', [] );
		$this->assertSame( [ 'wp_a' => [] ], $out );
		$this->assertSame( [], $c->bodies );
	}

	public function test_large_tables_are_left_to_the_caller() {
		$c = $this->client();
		$out = IXES_Planner::remote_hashes( $c, [ self::t( 'wp_big', IXES_Planner::BATCH_ROWS + 1 ) ], [ 'hash_batch' ], 'sha1', [] );
		$this->assertSame( [], $out );
		$this->assertSame( [], $c->bodies );
	}

	public function test_small_tables_share_one_request() {
		$c = $this->client();
		$c->script = [ function ( $b ) {
			return [ 'tables' => [ 'wp_a' => [ 'rows' => [ '1' => 'h1', '2' => 'h2' ], 'next' => null ], 'wp_b' => [ 'rows' => [ 'x', 'y' ], 'next' => null ] ] ];
		} ];
		$out = IXES_Planner::remote_hashes( $c, [ self::t( 'wp_a', 2 ), self::t( 'wp_b', 2, null ), self::t( 'wp_e', 0 ) ], [ 'hash_batch' ], 'sha1', [ 'X' ] );
		$this->assertCount( 1, $c->bodies );
		$this->assertSame( [ 'wp_a', 'wp_b' ], $c->bodies[0]['tables'] );
		$this->assertSame( [ 'X' ], $c->bodies[0]['extra'] );
		$this->assertSame( [ 1 => 'h1', 2 => 'h2' ], $out['wp_a'] );
		$this->assertSame( [ 'x', 'y' ], $out['wp_b'] );
		$this->assertSame( [], $out['wp_e'] );
	}

	public function test_tables_the_remote_did_not_reach_go_in_the_next_batch_and_cut_ones_continue_by_page() {
		$c = $this->client();
		$c->script = [
			function ( $b ) { return [ 'tables' => [ 'wp_a' => [ 'rows' => [ '1' => 'h1' ], 'next' => '1' ] ] ]; },
			function ( $b ) { return [ 'tables' => [ 'wp_b' => [ 'rows' => [ '7' => 'h7' ], 'next' => null ] ] ]; },
			function ( $b ) { return [ 'rows' => [ '2' => 'h2' ], 'next' => null ]; },
		];
		$out = IXES_Planner::remote_hashes( $c, [ self::t( 'wp_a', 2 ), self::t( 'wp_b', 1 ) ], [ 'hash_batch' ], 'sha1', [] );
		$this->assertSame( [ 'wp_b' ], $c->bodies[1]['tables'] );
		$this->assertStringContainsString( 'hash/rows', $c->bodies[2]['_route'] );
		$this->assertSame( 'wp_a', $c->bodies[2]['table'] );
		$this->assertSame( '1', $c->bodies[2]['from'] );
		$this->assertSame( [ 1 => 'h1', 2 => 'h2' ], $out['wp_a'] );
		$this->assertSame( [ 7 => 'h7' ], $out['wp_b'] );
	}

	public function test_a_batch_answer_without_the_asked_tables_is_an_error_not_a_loop() {
		$c = $this->client();
		$c->script = [ function ( $b ) { return [ 'tables' => [ 'wp_other' => [ 'rows' => [], 'next' => null ] ] ]; } ];
		$out = IXES_Planner::remote_hashes( $c, [ self::t( 'wp_a', 2 ) ], [ 'hash_batch' ], 'sha1', [] );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'hash_batch', $out->get_error_code() );
	}

	public function test_batches_hold_at_most_batch_tables() {
		$c = $this->client();
		$tables = [];
		for ( $i = 0; $i < IXES_Planner::BATCH_TABLES + 5; $i++ ) $tables[] = self::t( "wp_t{$i}", 1 );
		$answer = function ( $b ) { $o = []; foreach ( $b['tables'] as $n ) $o[ $n ] = [ 'rows' => [ '1' => 'h' ], 'next' => null ]; return [ 'tables' => $o ]; };
		$c->script = [ $answer, $answer ];
		$out = IXES_Planner::remote_hashes( $c, $tables, [ 'hash_batch' ], 'sha1', [] );
		$this->assertCount( IXES_Planner::BATCH_TABLES, $c->bodies[0]['tables'] );
		$this->assertCount( 5, $c->bodies[1]['tables'] );
		$this->assertCount( IXES_Planner::BATCH_TABLES + 5, $out );
	}

	private function s( array $assoc ) { return IXES_Scope::from_assoc( $assoc, 'wp_' ); }

	public function test_roots_follow_only_and_paths() {
		$this->assertSame( [], $this->s( [] )->roots() );
		$this->assertSame( [], $this->s( [ 'only' => 'db' ] )->roots() );
		$this->assertSame( [], $this->s( [ 'only' => 'files' ] )->roots() );
		$this->assertSame( [ 'uploads/' ], $this->s( [ 'only' => 'db,uploads' ] )->roots() );
		$this->assertSame( [ 'themes/', 'uploads/' ], $this->s( [ 'only' => 'uploads,themes' ] )->roots() );
		$this->assertSame( [ 'themes/mk/', 'uploads/2026/' ], $this->s( [ 'paths' => 'themes/mk/,uploads/2026/*' ] )->roots() );
		$this->assertSame( [ 'themes/' ], $this->s( [ 'paths' => 'themes/*/style.css,themes/mk/' ] )->roots() );
		// a pattern that can match in any folder cannot narrow the walk
		$this->assertSame( [], $this->s( [ 'paths' => 'themes/mk/,*.css' ] )->roots() );
	}

	public function test_every_path_in_scope_lies_under_a_root() {
		$paths = [ 'uploads/2026/a.jpg', 'themes/mk/style.css', 'themes/mk/inc/a.php', 'themes/mkx/style.css', 'plugins/x/x.php', 'index.php', 'mu-plugins/a.php' ];
		foreach ( [ [ 'only' => 'db,uploads' ], [ 'only' => 'themes,mu-plugins' ], [ 'paths' => 'themes/mk/,uploads/2026/*' ], [ 'paths' => 'themes/m*/style.css' ] ] as $assoc ) {
			$s = $this->s( $assoc ); $roots = $s->roots();
			$this->assertNotSame( [], $roots );
			foreach ( $paths as $p ) {
				if ( ! $s->path_in( $p ) ) continue;
				$under = false;
				foreach ( $roots as $r ) if ( strpos( $p, $r ) === 0 ) $under = true;
				$this->assertTrue( $under, "$p in scope of " . json_encode( $assoc ) . ' but outside ' . json_encode( $roots ) );
			}
		}
	}

	public function test_all_files_walks_only_the_roots() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ixes-wpc-' . getmypid() );
		$d = WP_CONTENT_DIR;
		foreach ( [ 'uploads/2026/a.jpg', 'uploads/b.jpg', 'plugins/x/x.php', 'themes/t/style.css', 'index.php' ] as $f ) {
			@mkdir( dirname( "$d/$f" ), 0777, true ); file_put_contents( "$d/$f", $f );
		}
		$this->assertSame( [ 'index.php', 'plugins/x/x.php', 'themes/t/style.css', 'uploads/2026/a.jpg', 'uploads/b.jpg' ], IXES_Transfer::all_files( [] ) );
		$this->assertSame( [ 'uploads/2026/a.jpg', 'uploads/b.jpg' ], IXES_Transfer::all_files( [], [ 'uploads/' ] ) );
		$this->assertSame( [ 'themes/t/style.css', 'uploads/2026/a.jpg', 'uploads/b.jpg' ], IXES_Transfer::all_files( [], [ 'uploads/', 'themes/', 'missing/' ] ) );
		// excludes still apply inside a root, and a root that climbs out of wp-content is ignored
		$this->assertSame( [ 'uploads/b.jpg' ], IXES_Transfer::all_files( [ 'uploads/2026/' ], [ 'uploads/', '../' ] ) );
	}

	public function test_a_root_through_a_symlinked_folder_is_walked_as_the_full_walk_would_not() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ixes-wpc-' . getmypid() );
		$d = WP_CONTENT_DIR;
		@mkdir( "$d/real/sub", 0777, true ); file_put_contents( "$d/real/sub/a.php", 'a' );
		@mkdir( "$d/plugins", 0777, true ); if ( ! is_link( "$d/plugins/linked" ) ) symlink( "$d/real", "$d/plugins/linked" );
		$full = array_values( array_filter( IXES_Transfer::all_files( [] ), function ( $r ) { return strpos( $r, 'plugins/' ) === 0; } ) );
		$this->assertNotContains( 'plugins/linked/sub/a.php', $full );
		$this->assertSame( [], IXES_Transfer::all_files( [], [ 'plugins/linked/' ] ) );
		$this->assertSame( [], IXES_Transfer::all_files( [], [ 'plugins/linked/sub/' ] ) );
		$this->assertSame( $full, IXES_Transfer::all_files( [], [ 'plugins/' ] ) );
	}
}
