<?php
use PHPUnit\Framework\TestCase;

class SeedTest extends TestCase {
	// every wp-content-relative folder any test in this file writes to, so tearDown can remove it:
	// WP_CONTENT_DIR here is shared with FewerRequestsTest's fixtures, and its exact-listing
	// assertions must never see a leftover from a write=true test that ran before it.
	const WRITABLE = [ 'plugins/demo', 'plugins/demo6', 'plugins/apply1' ];

	protected function tearDown(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) return;
		foreach ( self::WRITABLE as $rel ) $this->rrmdir( WP_CONTENT_DIR . '/' . $rel );
	}

	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) return;
		foreach ( array_diff( scandir( $dir ), [ '.', '..' ] ) as $e ) {
			$p = $dir . '/' . $e;
			is_dir( $p ) ? $this->rrmdir( $p ) : unlink( $p );
		}
		rmdir( $dir );
	}

	private function zip_with( array $files ) {
		$path = tempnam( sys_get_temp_dir(), 'ixes-fixture-' );
		$za = new ZipArchive();
		$za->open( $path, ZipArchive::OVERWRITE );
		foreach ( $files as $rel => $content ) $za->addFromString( $rel, $content );
		$za->close();
		$body = file_get_contents( $path );
		unlink( $path );
		return $body;
	}

	private function ok( $body ) { return [ 'response' => [ 'code' => 200 ], 'body' => $body ]; }
	private function code( $c ) { return [ 'response' => [ 'code' => $c ], 'body' => '' ]; }
	private function wp_content() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ixes-wpc-' . getmypid() );
		if ( ! is_dir( WP_CONTENT_DIR ) ) mkdir( WP_CONTENT_DIR, 0777, true );
		return WP_CONTENT_DIR;
	}

	public function test_candidates_groups_by_slug_and_needs_a_known_version() {
		$transfer = [ 'plugins/akismet/akismet.php', 'plugins/akismet/readme.txt', 'themes/twentytwentyfive/style.css', 'plugins/hello.php', 'mu-plugins/x.php' ];
		$inv = [ 'plugins' => [ 'akismet' => '5.3', 'hello' => '1.0' ], 'themes' => [] ];
		$c = IXES_Seed::candidates( $transfer, $inv );
		$this->assertSame( [ 'plugins/akismet' ], array_keys( $c ), 'a theme with no known remote version and a single-file plugin are not seeded' );
		$this->assertSame( [ 'plugins/akismet/akismet.php', 'plugins/akismet/readme.txt' ], $c['plugins/akismet']['paths'] );
		$this->assertSame( '5.3', $c['plugins/akismet']['version'] );
	}

	public function test_candidates_skips_a_slug_the_remote_reports_as_unknown() {
		$c = IXES_Seed::candidates( [ 'plugins/old/x.php' ], [ 'plugins' => [ 'old' => '?' ] ] );
		$this->assertSame( [], $c );
	}

	public function test_matching_file_is_seeded_and_a_hash_mismatch_is_left_for_the_remote() {
		$d = $this->wp_content();
		$zip = $this->zip_with( [ 'demo/a.php' => 'hello', 'demo/b.php' => 'stale-local-only' ] );
		$hash = hash( 'sha1', 'hello' );
		$cands = [ 'plugins/demo' => [ 'type' => 'plugins', 'slug' => 'demo', 'version' => '1.0', 'paths' => [ 'plugins/demo/a.php', 'plugins/demo/b.php' ] ] ];
		$remote_hashes = [ 'plugins/demo/a.php' => $hash, 'plugins/demo/b.php' => hash( 'sha1', 'different-remote-content' ) ];
		$seen = [];
		$fetch = function ( $url ) use ( &$seen, $zip ) { $seen[] = $url; return $this->ok( $zip ); };
		$res = IXES_Seed::run( $cands, $remote_hashes, 'sha1', $fetch, true );
		$this->assertSame( [ 'https://downloads.wordpress.org/plugin/demo.1.0.zip' ], $seen );
		$this->assertSame( [ 'plugins/demo/a.php' => true ], $res['matched'] );
		$this->assertSame( 5, $res['bytes'] );
		$this->assertSame( 'hello', file_get_contents( $d . '/plugins/demo/a.php' ) );
		$this->assertFileDoesNotExist( $d . '/plugins/demo/b.php', 'a hash mismatch must never be written; it stays queued for the normal transfer' );
	}

	public function test_run_never_leaks_the_extraction_tempdir_even_when_only_part_of_the_zip_matches() {
		$zip = $this->zip_with( [ 'demo/a.php' => 'hello', 'demo/b.php' => 'stale-local-only', 'demo/unrelated/extra.txt' => 'x' ] );
		$before = glob( sys_get_temp_dir() . '/ixes-seed-demo-*' );
		$cands = [ 'plugins/demo' => [ 'type' => 'plugins', 'slug' => 'demo', 'version' => '1.0', 'paths' => [ 'plugins/demo/a.php' ] ] ];
		$fetch = function () use ( $zip ) { return $this->ok( $zip ); };
		IXES_Seed::run( $cands, [ 'plugins/demo/a.php' => hash( 'sha1', 'hello' ) ], 'sha1', $fetch, true );
		$after = glob( sys_get_temp_dir() . '/ixes-seed-demo-*' );
		$this->assertSame( $before, $after, 'the whole extraction tempdir is removed, not just the detected plugin subfolder' );
	}

	public function test_theme_zip_uses_the_theme_path_and_kind() {
		$zip = $this->zip_with( [ 'skin/style.css' => 'body{}' ] );
		$seen = [];
		$fetch = function ( $url ) use ( &$seen, $zip ) { $seen[] = $url; return $this->ok( $zip ); };
		$cands = [ 'themes/skin' => [ 'type' => 'themes', 'slug' => 'skin', 'version' => '2.1', 'paths' => [ 'themes/skin/style.css' ] ] ];
		$res = IXES_Seed::run( $cands, [ 'themes/skin/style.css' => hash( 'sha1', 'body{}' ) ], 'sha1', $fetch, false );
		$this->assertSame( [ 'https://downloads.wordpress.org/theme/skin.2.1.zip' ], $seen );
		$this->assertSame( [ 'themes/skin/style.css' => true ], $res['matched'] );
	}

	public function test_dry_run_matches_without_writing_to_disk() {
		$d = $this->wp_content();
		$zip = $this->zip_with( [ 'demo2/a.php' => 'hi' ] );
		$cands = [ 'plugins/demo2' => [ 'type' => 'plugins', 'slug' => 'demo2', 'version' => '2.0', 'paths' => [ 'plugins/demo2/a.php' ] ] ];
		$fetch = function () use ( $zip ) { return $this->ok( $zip ); };
		$res = IXES_Seed::run( $cands, [ 'plugins/demo2/a.php' => hash( 'sha1', 'hi' ) ], 'sha1', $fetch, false );
		$this->assertSame( [ 'plugins/demo2/a.php' => true ], $res['matched'], 'write = false still reports what WOULD be seeded' );
		$this->assertFileDoesNotExist( $d . '/plugins/demo2/a.php', 'but never writes it' );
	}

	public function test_404_on_both_urls_is_skipped_quietly() {
		$cands = [ 'plugins/premium' => [ 'type' => 'plugins', 'slug' => 'premium', 'version' => '9.9', 'paths' => [ 'plugins/premium/a.php' ] ] ];
		$calls = 0;
		$fetch = function () use ( &$calls ) { $calls++; return $this->code( 404 ); };
		$res = IXES_Seed::run( $cands, [ 'plugins/premium/a.php' => 'whatever' ], 'sha1', $fetch, true );
		$this->assertSame( [], $res['matched'] );
		$this->assertGreaterThanOrEqual( 1, $calls, 'a premium/unknown slug is tried and then quietly given up on' );
	}

	public function test_network_error_falls_back_without_throwing() {
		$cands = [ 'plugins/demo3' => [ 'type' => 'plugins', 'slug' => 'demo3', 'version' => '1.0', 'paths' => [ 'plugins/demo3/a.php' ] ] ];
		$fetch = function () { return new WP_Error( 'http_request_failed', 'timed out' ); };
		$res = IXES_Seed::run( $cands, [ 'plugins/demo3/a.php' => 'x' ], 'sha1', $fetch, true );
		$this->assertSame( [], $res['matched'], 'a wordpress.org outage never fails the pull; it just falls back to the remote' );
	}

	public function test_unversioned_zip_is_only_tried_when_it_equals_the_latest_version() {
		$zip = $this->zip_with( [ 'demo4/a.php' => 'z' ] );
		$cands = [ 'plugins/demo4' => [ 'type' => 'plugins', 'slug' => 'demo4', 'version' => '3.0', 'paths' => [ 'plugins/demo4/a.php' ] ] ];
		$urls = [];
		$fetch = function ( $url ) use ( &$urls, $zip ) {
			$urls[] = $url;
			if ( strpos( $url, 'demo4.3.0.zip' ) !== false ) return $this->code( 404 );
			if ( strpos( $url, 'api.wordpress.org' ) !== false ) return $this->ok( json_encode( [ 'version' => '3.0' ] ) );
			if ( strpos( $url, 'demo4.zip' ) !== false ) return $this->ok( $zip );
			return $this->code( 404 );
		};
		$res = IXES_Seed::run( $cands, [ 'plugins/demo4/a.php' => hash( 'sha1', 'z' ) ], 'sha1', $fetch, false );
		$this->assertSame( [ 'plugins/demo4/a.php' => true ], $res['matched'] );
		$this->assertSame( [
			'https://downloads.wordpress.org/plugin/demo4.3.0.zip',
			'https://api.wordpress.org/plugins/info/1.0/demo4.json',
			'https://downloads.wordpress.org/plugin/demo4.zip',
		], $urls );
	}

	public function test_unversioned_zip_is_not_tried_when_the_remote_runs_an_older_version() {
		$cands = [ 'plugins/demo5' => [ 'type' => 'plugins', 'slug' => 'demo5', 'version' => '1.0', 'paths' => [ 'plugins/demo5/a.php' ] ] ];
		$urls = [];
		$fetch = function ( $url ) use ( &$urls ) {
			$urls[] = $url;
			if ( strpos( $url, 'api.wordpress.org' ) !== false ) return $this->ok( json_encode( [ 'version' => '9.0' ] ) );
			return $this->code( 404 );
		};
		$res = IXES_Seed::run( $cands, [ 'plugins/demo5/a.php' => 'h' ], 'sha1', $fetch, true );
		$this->assertSame( [], $res['matched'] );
		$this->assertCount( 2, $urls, 'no unversioned zip fetch once the latest version does not match' );
	}

	public function test_no_seed_flag_and_constant_disable_it() {
		$this->assertFalse( IXES_Seed::enabled( [ 'no_seed' => true ] ) );
		$this->assertTrue( IXES_Seed::enabled( [] ) );
	}

	public function test_seed_transfer_only_verifies_and_never_writes_before_the_pull_is_confirmed() {
		$d = $this->wp_content();
		$zip = $this->zip_with( [ 'demo6/a.php' => 'aa' ] );
		$info = [ 'inventory' => [ 'plugins' => [ 'demo6' => '1.0' ], 'themes' => [] ] ];
		$remote = [ 'plugins/demo6/a.php' => hash( 'sha1', 'aa' ), 'themes/x/style.css' => 'unrelated' ];
		$transfer = [ 'plugins/demo6/a.php', 'themes/x/style.css' ];
		$sizes = [ 'plugins/demo6/a.php' => 2, 'themes/x/style.css' => 20 ];
		$fetch = function () use ( $zip ) { return $this->ok( $zip ); };
		list( $left, $left_sizes, $summary ) = IXES_Pull::seed_transfer( $info, $transfer, $remote, $sizes, 'sha1', [ 'fetch' => $fetch ] );
		$this->assertSame( [ 'themes/x/style.css' ], $left, 'the seeded plugin file drops out; the untouched theme file still comes from the remote' );
		$this->assertSame( [ 'themes/x/style.css' => 20 ], $left_sizes );
		$this->assertSame( [ 'files' => 1, 'bytes' => 2, 'left' => 1, 'paths' => [ 'plugins/demo6/a.php' ], 'sizes' => [ 'plugins/demo6/a.php' => 2 ] ], $summary );
		$this->assertFileDoesNotExist( $d . '/plugins/demo6/a.php', 'building the plan (even for a real, non-dry pull) must never write to wp-content; only a confirmed apply_seed() does' );
	}

	public function test_apply_seed_writes_the_verified_paths_into_place_once_confirmed() {
		$d = $this->wp_content();
		$zip = $this->zip_with( [ 'apply1/a.php' => 'yes' ] );
		$plan = [
			'info' => [ 'inventory' => [ 'plugins' => [ 'apply1' => '1.0' ] ] ],
			'algo' => 'sha1',
			'files' => [ 'transfer' => [ 'themes/x/style.css' ], 'remote' => [ 'plugins/apply1/a.php' => hash( 'sha1', 'yes' ), 'themes/x/style.css' => 'unrelated' ] ],
			'sizes' => [ 'themes/x/style.css' => 20 ],
			'seed' => [ 'files' => 1, 'bytes' => 3, 'left' => 1, 'paths' => [ 'plugins/apply1/a.php' ], 'sizes' => [ 'plugins/apply1/a.php' => 3 ] ],
		];
		$fetch = function () use ( $zip ) { return $this->ok( $zip ); };
		$out = IXES_Pull::apply_seed( $plan, [ 'fetch' => $fetch ] );
		$this->assertSame( 'yes', file_get_contents( $d . '/plugins/apply1/a.php' ) );
		$this->assertSame( [ 'themes/x/style.css' ], $out['files']['transfer'], 'the successfully-seeded path is not put back' );
		$this->assertSame( [ 'themes/x/style.css' => 20 ], $out['sizes'] );
	}

	public function test_apply_seed_returns_a_path_to_the_transfer_list_when_the_second_download_fails() {
		$plan = [
			'info' => [ 'inventory' => [ 'plugins' => [ 'apply2' => '1.0' ] ] ],
			'algo' => 'sha1',
			'files' => [ 'transfer' => [ 'themes/x/style.css' ], 'remote' => [ 'plugins/apply2/a.php' => 'h', 'themes/x/style.css' => 'unrelated' ] ],
			'sizes' => [ 'themes/x/style.css' => 20 ],
			'seed' => [ 'files' => 1, 'bytes' => 3, 'left' => 1, 'paths' => [ 'plugins/apply2/a.php' ], 'sizes' => [ 'plugins/apply2/a.php' => 3 ] ],
		];
		// wordpress.org is unreachable this time round: apply_seed() must not lose the file
		$fetch = function () { return new WP_Error( 'http_request_failed', 'timed out' ); };
		$out = IXES_Pull::apply_seed( $plan, [ 'fetch' => $fetch ] );
		$this->assertSame( [ 'plugins/apply2/a.php', 'themes/x/style.css' ], $out['files']['transfer'], 'a path verified in the plan but not re-seedable falls back to the normal transfer instead of vanishing' );
		$this->assertSame( [ 'themes/x/style.css' => 20, 'plugins/apply2/a.php' => 3 ], $out['sizes'] );
	}

	public function test_apply_seed_is_a_no_op_without_a_seed_summary() {
		$plan = [ 'files' => [ 'transfer' => [ 'a.php' ] ], 'sizes' => null ];
		$this->assertSame( $plan, IXES_Pull::apply_seed( $plan ) );
	}

	public function test_resuming_after_a_seeded_plan_finds_nothing_left_to_seed() {
		// simulates the saved plan a resumed pull loads: the transfer list is already the post-seed one
		$info = [ 'inventory' => [ 'plugins' => [], 'themes' => [] ] ];
		$left = [ 'themes/x/style.css' ];
		$sizes = [ 'themes/x/style.css' => 20 ];
		$fetch = function () { throw new RuntimeException( 'must not be called: nothing left to seed' ); };
		$again = IXES_Pull::seed_transfer( $info, $left, [ 'themes/x/style.css' => 'unrelated' ], $sizes, 'sha1', [ 'fetch' => $fetch ] );
		$this->assertSame( [ $left, $sizes, null ], $again );
	}

	public function test_no_seed_option_short_circuits_seed_transfer_without_any_request() {
		$info = [ 'inventory' => [ 'plugins' => [ 'demo7' => '1.0' ] ] ];
		$transfer = [ 'plugins/demo7/a.php' ];
		$fetch = function () { throw new RuntimeException( 'must not be called when seeding is disabled' ); };
		list( $left, $sizes, $summary ) = IXES_Pull::seed_transfer( $info, $transfer, [ 'plugins/demo7/a.php' => 'h' ], null, 'sha1', [ 'fetch' => $fetch, 'no_seed' => true ] );
		$this->assertSame( $transfer, $left );
		$this->assertNull( $summary );
	}
}
