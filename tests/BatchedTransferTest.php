<?php
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); } }

/** A remote in memory: /file/batch and /file/get served from $files, every request logged with the wave it went out in. */
class FileBatchFakeClient extends IXES_Client {
	public $files = [];      // rel => bytes on the "remote"
	public $calls = [];      // [ route, body, wave ] per request
	public $waves = [];      // size of every transport_multi() call
	public $fail  = [];      // first path of a batch => HTTP code to answer it with (once per entry in the list)
	public $slept = [];
	public $version = '0.8.0';

	protected function transport( $url, array $args ) { return $this->serve( $url, $args, 0 ); }
	protected function transport_multi( array $wire ) {
		$this->waves[] = count( $wire );
		$out = [];
		foreach ( $wire as $k => $w ) $out[ $k ] = $this->serve( $w[0], $w[1], count( $this->waves ) );
		return $out;
	}
	protected function sleep_s( $s ) { $this->slept[] = $s; }

	private function serve( $url, array $args, $wave ) {
		$route = substr( $url, strpos( $url, '/envsync/v1' ) + strlen( '/envsync/v1' ) );
		$body  = json_decode( (string) ( $args['body'] ?? '' ), true );
		$this->calls[] = [ $route, $body, $wave, $args ];
		if ( $route === '/info' ) return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'plugin' => $this->version, 'caps' => [] ] ), 'headers' => [] ];
		if ( $route === '/file/get' ) {
			$d = substr( $this->files[ $body['path'] ], $body['offset'], $body['size'] );
			return [ 'response' => [ 'code' => 200 ], 'body' => $d, 'headers' => [ 'x-envsync-total' => (string) strlen( $this->files[ $body['path'] ] ), 'x-envsync-sha256' => hash( 'sha256', $this->files[ $body['path'] ] ) ] ];
		}
		if ( $route === '/file/batch' ) {
			$first = $body['paths'][0];
			if ( ! empty( $this->fail[ $first ] ) ) return [ 'response' => [ 'code' => array_shift( $this->fail[ $first ] ) ], 'body' => '{"message":"nope"}', 'headers' => [] ];
			$items = [];
			foreach ( $body['paths'] as $rel ) $items[] = isset( $this->files[ $rel ] ) ? [ [ 'path' => $rel, 'sha256' => hash( 'sha256', $this->files[ $rel ] ) ], $this->files[ $rel ] ] : [ [ 'path' => $rel, 'err' => 'not_found' ], '' ];
			$raw = IXES_Batch::encode( $items );
			return [ 'response' => [ 'code' => 200 ], 'body' => $body['deflate'] ? gzdeflate( $raw ) : $raw, 'headers' => [ 'content-type' => 'application/octet-stream', 'x-envsync-enc' => $body['deflate'] ? 'deflate' : 'identity' ] ];
		}
		if ( $route === '/job/step' ) return [ 'response' => [ 'code' => 200 ], 'body' => '{"ok":true,"refused":[]}', 'headers' => [] ];
		throw new RuntimeException( "unscripted {$route}" );
	}
}

class BatchedTransferTest extends TestCase {
	private $written;

	protected function setUp(): void {
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-bt-' . getmypid() . '-' . uniqid();
		mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
		$this->written = [];
	}

	// WP_CONTENT_DIR is shared with other tests that walk it: leave nothing behind
	protected function tearDown(): void {
		if ( defined( 'WP_CONTENT_DIR' ) ) { foreach ( glob( WP_CONTENT_DIR . '/plugins/ok/*' ) ?: [] as $f ) unlink( $f ); @rmdir( WP_CONTENT_DIR . '/plugins/ok' ); }
	}

	private function client( array $caps ) {
		$c = new FileBatchFakeClient( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$c->set_caps( $caps );
		return $c;
	}
	private function remote( FileBatchFakeClient $c, $n, $size = 100 ) {
		for ( $i = 0; $i < $n; $i++ ) $c->files[ sprintf( 'plugins/x/f%03d.php', $i ) ] = str_repeat( chr( 65 + $i % 26 ), $size );
		ksort( $c->files, SORT_STRING );
	}
	private function plan( FileBatchFakeClient $c ) {
		return [ 'files' => [ 'transfer' => array_keys( $c->files ) ], 'sizes' => array_map( 'strlen', $c->files ) ];
	}
	private function writer() {
		return function ( $rel, $offset, $data, $final, $sha ) {
			if ( strpos( $rel, 'cache/' ) === 0 ) return new WP_Error( 'bad_path', 'path refused' );
			$this->written[ $rel ] = ( $offset ? $this->written[ $rel ] : '' ) . $data;
			if ( $final && hash( 'sha256', $this->written[ $rel ] ) !== $sha ) return new WP_Error( 'checksum', 'mismatch' );
			return true;
		};
	}
	private function progress() { return new IXES_Progress( 'summary', function () {} ); }
	private function state() { return IXES_PullState::start( 'p', 'plan.json', '0.8.0', [] ); }
	private function routes( FileBatchFakeClient $c ) { return array_column( $c->calls, 0 ); }

	// ---------- format ----------

	public function test_batch_round_trips_through_deflate_and_open() {
		$items = [ [ [ 'path' => 'a.php', 'sha256' => hash( 'sha256', "<?php echo 1;\n" ) ], "<?php echo 1;\n" ], [ [ 'path' => 'b.bin', 'sha256' => hash( 'sha256', "\x00\n\xff" ) ], "\x00\n\xff" ], [ [ 'path' => 'gone.php', 'err' => 'not_found' ], '' ] ];
		$raw = IXES_Batch::encode( $items );
		foreach ( [ [ gzdeflate( $raw ), 'deflate' ], [ $raw, 'identity' ] ] as $case ) {
			$got = IXES_Batch::open( $case[0], $case[1], [ 'a.php', 'b.bin', 'gone.php' ] );
			$this->assertIsArray( $got );
			$this->assertSame( "<?php echo 1;\n", $got[0][1] );
			$this->assertSame( "\x00\n\xff", $got[1][1] );
			$this->assertSame( 'not_found', $got[2][0]['err'] );
		}
	}

	public function test_open_rejects_a_hash_mismatch_before_anything_is_written() {
		$raw = IXES_Batch::encode( [ [ [ 'path' => 'a.php', 'sha256' => hash( 'sha256', 'good' ) ], 'evil' ] ] );
		$r = IXES_Batch::open( gzdeflate( $raw ), 'deflate', [ 'a.php' ] );
		$this->assertSame( 'checksum', $r->get_error_code() );
		$this->assertSame( 'bad_batch', IXES_Batch::open( 'not deflate', 'deflate', [ 'a.php' ] )->get_error_code() );
		$this->assertSame( 'bad_batch', IXES_Batch::open( $raw, 'br', [ 'a.php' ] )->get_error_code() );
	}

	public function test_open_rejects_paths_that_were_not_asked_for() {
		$bad = IXES_Batch::encode( [ [ [ 'path' => '../../wp-config.php', 'sha256' => hash( 'sha256', 'x' ) ], 'x' ] ] );
		$this->assertSame( 'bad_path', IXES_Batch::open( $bad, 'identity', [ 'plugins/a.php' ] )->get_error_code() );
		// nor extra ones, nor the right ones in another order
		$two = IXES_Batch::encode( [ [ [ 'path' => 'b', 'sha256' => hash( 'sha256', '' ) ], '' ], [ [ 'path' => 'a', 'sha256' => hash( 'sha256', '' ) ], '' ] ] );
		$this->assertSame( 'bad_path', IXES_Batch::open( $two, 'identity', [ 'a', 'b' ] )->get_error_code() );
		$this->assertSame( 'bad_path', IXES_Batch::open( $two, 'identity', [ 'b' ] )->get_error_code() );
	}

	public function test_remote_refuses_traversal_and_excluded_paths_inside_a_batch() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ixes-wpc-' . getmypid() );
		@mkdir( WP_CONTENT_DIR . '/plugins/ok', 0777, true );
		file_put_contents( WP_CONTENT_DIR . '/plugins/ok/a.php', 'fine' );
		$items = IXES_Batch::decode( IXES_Transfer::file_batch( [ 'plugins/ok/a.php', '../wp-config.php', '/etc/passwd', 'cache/x.html', 'wp-config.php', 'plugins/ok/none.php' ] ) );
		$this->assertSame( 'fine', $items[0][1] );
		$this->assertSame( hash( 'sha256', 'fine' ), $items[0][0]['sha256'] );
		foreach ( [ 1, 2, 3, 4 ] as $i ) { $this->assertSame( 'bad_path', $items[ $i ][0]['err'] ); $this->assertSame( '', $items[ $i ][1] ); }
		$this->assertSame( 'not_found', $items[5][0]['err'] );
	}

	public function test_remote_defers_a_file_that_grew_past_the_batch_to_the_single_file_path() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/ixes-wpc-' . getmypid() );
		@mkdir( WP_CONTENT_DIR . '/plugins/ok', 0777, true );
		file_put_contents( WP_CONTENT_DIR . '/plugins/ok/a.php', str_repeat( 'a', 10 ) );
		file_put_contents( WP_CONTENT_DIR . '/plugins/ok/b.php', str_repeat( 'b', 10 ) );
		$items = IXES_Batch::decode( IXES_Transfer::file_batch( [ 'plugins/ok/a.php', 'plugins/ok/b.php' ], 15 ) );
		$this->assertArrayNotHasKey( 'err', $items[0][0] );
		$this->assertSame( 'later', $items[1][0]['err'] );
	}

	public function test_budget_gives_every_parallel_request_work_within_bounds() {
		$this->assertSame( IXES_Batch::MIN_BYTES, IXES_Batch::budget( 1000, 4 ) );
		$this->assertSame( IXES_Batch::MAX_BYTES, IXES_Batch::budget( 1 << 30, 4 ) );
		$this->assertSame( 1250000, IXES_Batch::budget( 10000000, 4 ) );
	}

	// ---------- pull ----------

	public function test_pull_batches_small_files_in_parallel_and_verifies_each() {
		$c = $this->client( [ 'binary', 'file_batch' ] );
		$this->remote( $c, 1000 );
		$c->files['uploads/big.zip'] = str_repeat( 'z', IXES_Batch::SMALL + 1 );
		$s = $this->state();
		$r = IXES_Pull::pull_files( $c, $this->plan( $c ), $s, $this->progress(), 4, $this->writer() );
		$this->assertSame( [ 'skipped' => [] ], $r );
		$this->assertSame( $c->files, array_intersect_key( $this->written, $c->files ) );
		$this->assertCount( count( $c->files ), $this->written );
		$routes = array_count_values( $this->routes( $c ) );
		$this->assertSame( 3, $routes['/file/batch'] ); // 400 files per batch
		$this->assertSame( 1, $routes['/file/get'] );   // the big one, alone
		$this->assertSame( [ 3 ], $c->waves );
		$this->assertTrue( $c->calls[0][1]['deflate'] );
		$this->assertSame( count( $c->files ), $s->get( 'files_done' ) );
	}

	public function test_concurrent_requests_are_each_signed_over_their_own_body() {
		$c = $this->client( [ 'binary', 'file_batch' ] );
		$this->remote( $c, 900 );
		IXES_Pull::pull_files( $c, $this->plan( $c ), $this->state(), $this->progress(), 4, $this->writer() );
		foreach ( $c->calls as $call ) {
			$h = $call[3]['headers'];
			$this->assertSame( IXES_Auth::sign( str_repeat( 'a', 64 ), 'POST', '/envsync/v1/file/batch', $h['X-Envsync-Ts'], $call[3]['body'] ), $h['X-Envsync-Sig'] );
		}
	}

	public function test_resume_after_out_of_order_batches_skips_only_what_landed() {
		$c = $this->client( [ 'binary', 'file_batch' ] );
		$this->remote( $c, 1000 );
		$plan = $this->plan( $c );
		// the first batch fails for good, the two after it land in the same wave
		$c->fail['plugins/x/f000.php'] = [ 400 ];
		$s = $this->state();
		$r = IXES_Pull::pull_files( $c, $plan, $s, $this->progress(), 4, $this->writer() );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 0, $s->get( 'files_done' ) ); // the contiguous prefix did not move
		$this->assertSame( 600, $s->files_count() );
		$this->assertFalse( $s->file_done( 0 ) );
		$this->assertFalse( $s->file_done( 399 ) );
		$this->assertTrue( $s->file_done( 400 ) );
		$this->assertTrue( $s->file_done( 999 ) );
		// state survives a reload the way it would across two runs
		$s = IXES_PullState::load( 'p' );
		$this->assertSame( 600, $s->files_count() );
		$c->calls = []; $this->written = [];
		$r = IXES_Pull::pull_files( $c, $plan, $s, $this->progress(), 4, $this->writer() );
		$this->assertSame( [ 'skipped' => [] ], $r );
		$this->assertSame( array_slice( array_keys( $c->files ), 0, 400 ), array_keys( $this->written ) );
		$this->assertSame( 1000, $s->get( 'files_done' ) );
		$this->assertSame( [], $s->get( 'files_ranges' ) );
	}

	public function test_a_retryable_batch_failure_is_retried_alone() {
		$c = $this->client( [ 'binary', 'file_batch' ] );
		$this->remote( $c, 1000 );
		$c->fail['plugins/x/f400.php'] = [ 503, 502 ];
		$s = $this->state();
		$this->assertSame( [ 'skipped' => [] ], IXES_Pull::pull_files( $c, $this->plan( $c ), $s, $this->progress(), 4, $this->writer() ) );
		$this->assertSame( 5, count( array_keys( $this->routes( $c ), '/file/batch' ) ) );
		$this->assertSame( [ 1, 2 ], $c->slept );
		$this->assertSame( 1000, $s->get( 'files_done' ) );
	}

	public function test_files_refused_here_are_skipped_and_counted_done() {
		$c = $this->client( [ 'binary', 'file_batch' ] );
		$c->files = [ 'cache/page.html' => 'x', 'plugins/a.php' => 'a' ];
		$s = $this->state();
		$r = IXES_Pull::pull_files( $c, $this->plan( $c ), $s, $this->progress(), 4, $this->writer() );
		$this->assertSame( [ 'cache/page.html' ], $r['skipped'] );
		$this->assertSame( [ 'plugins/a.php' => 'a' ], $this->written );
		$this->assertSame( 2, $s->get( 'files_done' ) );
	}

	public function test_a_file_gone_from_the_remote_stops_the_pull_after_recording_the_rest() {
		$c = $this->client( [ 'binary', 'file_batch' ] );
		$c->files = [ 'a.php' => 'a', 'b.php' => 'b' ];
		$plan = $this->plan( $c );
		unset( $c->files['b.php'] );
		$s = $this->state();
		$r = IXES_Pull::pull_files( $c, $plan, $s, $this->progress(), 4, $this->writer() );
		$this->assertStringContainsString( 'b.php: gone from the remote', $r->get_error_message() );
		$this->assertTrue( $s->file_done( 0 ) );
		$this->assertFalse( $s->file_done( 1 ) );
	}

	public function test_old_remote_falls_back_to_one_request_per_file_and_says_why() {
		$c = $this->client( [ 'binary', 'batch', 'packed' ] );
		$c->version = '0.7.1';
		$this->remote( $c, 5 );
		$s = $this->state();
		$this->assertSame( [ 'skipped' => [] ], IXES_Pull::pull_files( $c, $this->plan( $c ), $s, $this->progress(), 4, $this->writer() ) );
		$this->assertSame( array_fill( 0, 5, '/file/get' ), $this->routes( $c ) );
		$this->assertSame( [], $c->waves );
		$this->assertSame( 5, $s->get( 'files_done' ) );
		$note = IXES_Pull::transfer_note( $c, 5, 4 );
		$this->assertStringContainsString( 'one request each', $note );
		$this->assertStringContainsString( '0.7.1', $note );
		$this->assertStringContainsString( '0.8.0', $note );
		$this->assertStringContainsString( 'batched, one request at a time', IXES_Pull::transfer_note( $c, 5, 4, true ) );
		$this->assertStringContainsString( '4 request(s) at a time', IXES_Pull::transfer_note( $this->client( [ 'binary', 'file_batch' ] ), 5, 4 ) );
	}

	public function test_a_plan_without_sizes_falls_back_too() {
		$c = $this->client( [ 'binary', 'file_batch' ] );
		$this->remote( $c, 3 );
		$plan = $this->plan( $c ); $plan['sizes'] = null;
		IXES_Pull::pull_files( $c, $plan, $this->state(), $this->progress(), 4, $this->writer() );
		$this->assertSame( array_fill( 0, 3, '/file/get' ), $this->routes( $c ) );
	}

	public function test_parallel_one_never_sends_concurrently() {
		$c = $this->client( [ 'binary', 'file_batch' ] );
		$this->remote( $c, 1000 );
		$s = $this->state();
		IXES_Pull::pull_files( $c, $this->plan( $c ), $s, $this->progress(), 1, $this->writer() );
		$this->assertSame( [], $c->waves );
		$this->assertSame( [ 0 ], array_values( array_unique( array_column( $c->calls, 2 ) ) ) );
		$this->assertSame( 1000, $s->get( 'files_done' ) );
		// parallel=1 against an old remote is exactly the pre-0.8 request sequence
		$old = $this->client( [ 'binary' ] );
		$this->remote( $old, 4 );
		IXES_Pull::pull_files( $old, $this->plan( $old ), $this->state(), $this->progress(), 1, $this->writer() );
		$bodies = array_column( $old->calls, 1 );
		$this->assertSame( array_keys( $old->files ), array_column( $bodies, 'path' ) );
		$this->assertSame( [ 0, 0, 0, 0 ], array_column( $bodies, 'offset' ) );
	}

	// ---------- push ----------

	public function test_push_batches_go_out_deflated_and_in_parallel_to_a_new_remote() {
		$c = $this->client( [ 'binary', 'batch', 'packed', 'file_batch' ] );
		$batches = [ [ 'a.php' ], [ 'b.php' ], [ 'c.php' ] ];
		$done = [];
		$r = $c->send_batches( 'job1', array_keys( $batches ), 3, function ( $k ) use ( $batches ) {
			return array_map( function ( $rel ) { return [ [ 'path' => $rel, 'sha256' => hash( 'sha256', $rel ) ], $rel ]; }, $batches[ $k ] );
		}, function ( $k ) use ( &$done ) { $done[] = $k; return true; } );
		$this->assertTrue( $r );
		$this->assertSame( [ 0, 1, 2 ], $done );
		$this->assertSame( [ 3 ], $c->waves );
		$args = $c->calls[1][3];
		$this->assertSame( [ 'job' => 'job1', 'kind' => 'files', 'enc' => 'deflate' ], json_decode( $args['headers']['X-Envsync-Step'], true ) );
		$items = IXES_Batch::decode( gzinflate( $args['body'] ) );
		$this->assertSame( 'b.php', $items[0][0]['path'] );
		// and the remote side inflates it back
		$p = IXES_Rest::unpack_step( [ 'job' => 'job1', 'kind' => 'files', 'enc' => 'deflate' ], $args['body'] );
		$this->assertSame( 'deflate', $p['enc'] );
	}

	public function test_push_to_a_new_remote_without_zlib_stays_plain_but_parallel() {
		$c = $this->client( [ 'binary', 'batch', 'file_batch' ] );
		$c->send_batches( 'job1', [ 0, 1 ], 2, function () { return [ [ [ 'path' => 'a.php', 'sha256' => hash( 'sha256', 'a' ) ], 'a' ] ]; }, function () { return true; } );
		$this->assertSame( [ 2 ], $c->waves );
		$this->assertSame( [ 'job' => 'job1', 'kind' => 'files' ], json_decode( $c->calls[0][3]['headers']['X-Envsync-Step'], true ) );
	}

	public function test_push_to_an_old_remote_stays_plain_and_serial() {
		$c = $this->client( [ 'binary', 'batch', 'packed' ] );
		$c->send_batches( 'job1', [ 0, 1 ], 4, function () { return [ [ [ 'path' => 'a.php', 'sha256' => hash( 'sha256', 'a' ) ], 'a' ] ]; }, function () { return true; } );
		$this->assertSame( [], $c->waves );
		$this->assertSame( [ 'job' => 'job1', 'kind' => 'files' ], json_decode( $c->calls[0][3]['headers']['X-Envsync-Step'], true ) );
		$this->assertSame( 'a.php', IXES_Batch::decode( $c->calls[0][3]['body'] )[0][0]['path'] );
	}

	// ---------- state ----------

	public function test_files_mark_merges_out_of_order_ranges_into_the_prefix() {
		$s = $this->state();
		$s->files_mark( range( 400, 799 ) );
		$this->assertSame( 0, $s->get( 'files_done' ) );
		$this->assertSame( [ [ 400, 800 ] ], $s->get( 'files_ranges' ) );
		$s->files_mark( range( 900, 949 ) );
		$s->files_mark( range( 0, 399 ) );
		$this->assertSame( 800, $s->get( 'files_done' ) );
		$this->assertSame( [ [ 900, 950 ] ], $s->get( 'files_ranges' ) );
		$this->assertSame( 850, $s->files_count() );
		$this->assertFalse( $s->file_done( 800 ) );
		$this->assertTrue( $s->file_done( 920 ) );
		$s->files_mark( range( 800, 899 ) );
		$this->assertSame( 950, $s->get( 'files_done' ) );
		$this->assertSame( [], $s->get( 'files_ranges' ) );
		$this->assertStringContainsString( '950/1000', $s->describe( 1000 ) );
	}

	public function test_a_state_written_before_0_8_still_resumes_from_its_prefix() {
		$s = $this->state();
		$s->files_done( 12 );
		$f = IXES_PullState::path( 'p' );
		$d = json_decode( file_get_contents( $f ), true ); unset( $d['files_ranges'] );
		file_put_contents( $f, json_encode( $d ) );
		$s = IXES_PullState::load( 'p' );
		$this->assertTrue( $s->file_done( 11 ) );
		$this->assertFalse( $s->file_done( 12 ) );
		$this->assertSame( 12, $s->files_count() );
	}
}
