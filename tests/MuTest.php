<?php
use PHPUnit\Framework\TestCase;

class MuTest extends TestCase {
	private $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/ixes-mu-' . getmypid() . '-' . mt_rand();
		mkdir( $this->tmp, 0777, true );
	}

	protected function tearDown(): void {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		rmdir( $this->tmp );
	}

	private function put( $rel, $body ) {
		$p = $this->tmp . '/' . $rel;
		if ( ! is_dir( dirname( $p ) ) ) mkdir( dirname( $p ), 0777, true );
		file_put_contents( $p, $body );
	}

	public function test_boot_paths_are_mu_plugins_and_drop_ins() {
		$this->assertTrue( IXES_Mu::is_boot_path( 'mu-plugins/wp-toolkit.php' ) );
		$this->assertTrue( IXES_Mu::is_boot_path( 'mu-plugins/wp-toolkit/UI/includes/locales/pt-BR.php' ) );
		$this->assertTrue( IXES_Mu::is_boot_path( 'db.php' ) );
		$this->assertTrue( IXES_Mu::is_boot_path( 'object-cache.php' ) );
		$this->assertTrue( IXES_Mu::is_boot_path( 'sunrise.php' ) );
		$this->assertFalse( IXES_Mu::is_boot_path( 'plugins/p/db.php' ) );
		$this->assertFalse( IXES_Mu::is_boot_path( 'wp-cache-config.php' ) );
		$this->assertFalse( IXES_Mu::is_boot_path( 'themes/t/mu-plugins/x.php' ) );
	}

	public function test_boot_files_go_last_and_each_loader_after_its_folder() {
		$in = [ 'mu-plugins/wp-toolkit.php', 'uploads/a.jpg', 'mu-plugins/wp-toolkit/a.php', 'db.php', 'plugins/p/p.php', 'mu-plugins/wp-toolkit/UI/b.php', 'mu-plugins/solo.php' ];
		$this->assertSame(
			[ 'uploads/a.jpg', 'plugins/p/p.php', 'mu-plugins/wp-toolkit/a.php', 'mu-plugins/wp-toolkit/UI/b.php', 'mu-plugins/wp-toolkit.php', 'mu-plugins/solo.php', 'db.php' ],
			IXES_Mu::boot_last( $in )
		);
	}

	public function test_deletes_take_the_loader_out_before_its_folder() {
		$in = [ 'mu-plugins/x/a.php', 'uploads/a.jpg', 'mu-plugins/x.php', 'db.php' ];
		$this->assertSame( [ 'mu-plugins/x.php', 'db.php', 'mu-plugins/x/a.php', 'uploads/a.jpg' ], IXES_Mu::loaders_first( $in ) );
	}

	public function test_ordered_runs_keep_order_and_send_large_files_alone() {
		$sizes = [ 'mu-plugins/x/a.php' => 10, 'mu-plugins/x/big.php' => IXES_Batch::SMALL + 1, 'mu-plugins/x/b.php' => 10, 'mu-plugins/x.php' => 5 ];
		$this->assertSame(
			[ [ 'batch' => [ 'mu-plugins/x/a.php' ] ], [ 'large' => 'mu-plugins/x/big.php' ], [ 'batch' => [ 'mu-plugins/x/b.php', 'mu-plugins/x.php' ] ] ],
			IXES_Mu::ordered_runs( $sizes )
		);
	}

	public function test_groups_one_row_per_file_or_top_folder_with_change() {
		$g = IXES_Mu::groups(
			[ 'mu-plugins/wp-toolkit.php', 'mu-plugins/wp-toolkit/a.php', 'mu-plugins/wp-toolkit/b/c.php', 'mu-plugins/own.php', 'uploads/x.jpg', 'db.php' ],
			[ 'mu-plugins/old.php' ],
			[ 'mu-plugins/wp-toolkit.php', 'mu-plugins/wp-toolkit/a.php', 'mu-plugins/wp-toolkit/b/c.php', 'db.php' ]
		);
		$this->assertSame( [
			[ 'slug' => 'mu-plugins/old.php', 'files' => 0, 'delete' => 1, 'change' => 'delete' ],
			[ 'slug' => 'mu-plugins/own.php', 'files' => 1, 'delete' => 0, 'change' => 'changed' ],
			[ 'slug' => 'mu-plugins/wp-toolkit.php', 'files' => 1, 'delete' => 0, 'change' => 'new' ],
			[ 'slug' => 'mu-plugins/wp-toolkit/', 'files' => 2, 'delete' => 0, 'change' => 'new' ],
			[ 'slug' => 'db.php (drop-in)', 'files' => 1, 'delete' => 0, 'change' => 'new' ],
		], $g );
	}

	public function test_host_specific_paths_are_excluded_unless_the_env_takes_them_back() {
		$ex = IXES_Mu::host_excludes( [ 'name' => 'x', 'excludes' => [] ] );
		$this->assertContains( 'mu-plugins/wp-toolkit.php', $ex );
		$this->assertContains( 'mu-plugins/wp-toolkit/', $ex );
		$this->assertContains( 'plugins/imunify-security/', $ex );
		$this->assertTrue( IXES_Transfer::excluded_path( 'mu-plugins/wp-toolkit/UI/x.php', $ex ) );
		// anchored: a theme file of the same name is not a host file
		$this->assertFalse( IXES_Transfer::excluded_path( 'themes/t/wp-toolkit.php', $ex ) );
		$back = IXES_Mu::host_excludes( [ 'name' => 'x', 'host_included' => [ 'mu-plugins/wp-toolkit.php', 'mu-plugins/wp-toolkit/' ] ] );
		$this->assertNotContains( 'mu-plugins/wp-toolkit.php', $back );
		$this->assertContains( 'plugins/imunify-security/', $back );
	}

	public function test_remove_and_add_exclude_move_host_paths_in_and_out() {
		$env = [ 'excludes' => [ 'ai1wm-backups/' ] ];
		$env = IXES_Mu::remove_excludes( $env, [ 'mu-plugins/wp-toolkit.php', 'ai1wm-backups/' ] );
		$this->assertSame( [], $env['excludes'] );
		$this->assertSame( [ 'mu-plugins/wp-toolkit.php' ], $env['host_included'] );
		$env = IXES_Mu::add_excludes( $env, [ 'mu-plugins/wp-toolkit.php', 'cache2/' ] );
		$this->assertSame( [ 'cache2/' ], $env['excludes'] );
		$this->assertSame( [], $env['host_included'] );
	}

	public function test_host_warning_only_for_host_paths_new_to_the_remote() {
		$w = IXES_Mu::host_warnings( [ 'mu-plugins/wp-toolkit.php', 'mu-plugins/kinsta-mu-plugins.php', 'plugins/p/p.php' ], [ 'mu-plugins/wp-toolkit.php' => null, 'mu-plugins/kinsta-mu-plugins.php' => 'abc' ], 'staging' );
		$this->assertCount( 1, $w );
		$this->assertStringContainsString( 'mu-plugins/wp-toolkit.php', $w[0] );
		$this->assertStringContainsString( 'Plesk', $w[0] );
		$this->assertStringContainsString( 'env add staging --add-exclude=mu-plugins/wp-toolkit.php', $w[0] );
	}

	public function test_forced_push_holds_back_boot_files_unless_only_names_mu_plugins() {
		$plan = [ 'files' => [ 'push' => [ 'uploads/a.jpg', 'mu-plugins/x.php', 'db.php' ], 'delete' => [ 'mu-plugins/y.php', 'uploads/b.jpg' ] ], 'remote_file_hashes' => [ 'mu-plugins/x.php' => null, 'mu-plugins/y.php' => 'h', 'uploads/b.jpg' => 'h' ], 'warnings' => [] ];
		$held = IXES_Mu::hold_boot( $plan, [ 'only' => [ 'files' ], 'paths' => [] ] );
		$this->assertSame( [ 'uploads/a.jpg' ], $held['files']['push'] );
		$this->assertSame( [ 'uploads/b.jpg' ], $held['files']['delete'] );
		$this->assertArrayNotHasKey( 'mu-plugins/x.php', $held['remote_file_hashes'] );
		$w = end( $held['warnings'] );
		$this->assertStringContainsString( '--only=mu-plugins', $w );
		// drop-ins sit outside mu-plugins/: the warning names them for --paths
		$this->assertStringContainsString( '--paths=db.php', $w );
		$this->assertSame( $plan, IXES_Mu::hold_boot( $plan, [ 'only' => [ 'files', 'mu-plugins' ], 'paths' => [] ] ) );
		// files the user named one by one are what they asked for
		$this->assertSame( $plan, IXES_Mu::hold_boot( $plan, [ 'only' => [ 'files' ], 'paths' => [ 'db.php' ] ] ) );
		$none = [ 'files' => [ 'push' => [ 'uploads/a.jpg' ], 'delete' => [] ], 'remote_file_hashes' => [], 'warnings' => [] ];
		$this->assertSame( $none, IXES_Mu::hold_boot( $none, [] ) );
	}

	public function test_rescue_key_round_trip() {
		$token = str_repeat( 'ab', 32 );
		$this->assertTrue( IXES_Mu::write_key( $this->tmp, $token, false ) );
		$this->assertFalse( IXES_Mu::write_key( $this->tmp, $token, false ), 'unchanged key is not rewritten' );
		$this->assertSame( [ 'allow_http' => false ], IXES_Mu::key_matches( $this->tmp, $token ) );
		$this->assertNull( IXES_Mu::key_matches( $this->tmp, str_repeat( 'cd', 32 ) ) );
		$this->assertStringNotContainsString( $token, file_get_contents( $this->tmp . '/' . IXES_Mu::KEY_FILE ) );
	}

	public function test_writing_a_key_drops_keys_left_in_other_storage_folders() {
		$token = str_repeat( 'ab', 32 );
		mkdir( $this->tmp . '/envsync-aaaaaaaaaaaaaaaa' ); mkdir( $this->tmp . '/envsync-bbbbbbbbbbbbbbbb' );
		IXES_Mu::write_key( $this->tmp . '/envsync-aaaaaaaaaaaaaaaa', $token, false );
		IXES_Mu::write_key( $this->tmp . '/envsync-bbbbbbbbbbbbbbbb', str_repeat( 'cd', 32 ), false );
		$this->assertFileDoesNotExist( $this->tmp . '/envsync-aaaaaaaaaaaaaaaa/' . IXES_Mu::KEY_FILE );
	}

	public function test_bare_auth_checks_key_https_clock_and_signature() {
		$token = str_repeat( 'ab', 32 );
		mkdir( $this->tmp . '/envsync-0123456789abcdef' );
		IXES_Mu::write_key( $this->tmp . '/envsync-0123456789abcdef', $token, false );
		$body = '{"action":"quarantine_mu"}'; $now = 1790000000;
		$sig = IXES_Auth::sign( $token, 'POST', '/envsync/v1/rescue', $now, $body );
		$ok = IXES_Mu::bare_auth( $this->tmp, $token, $now, $body, $sig, true, $now );
		$this->assertSame( $this->tmp . '/envsync-0123456789abcdef', $ok );
		$this->assertSame( 403, IXES_Mu::bare_auth( $this->tmp, $token, $now, $body, $sig, false, $now )->get_error_data()['status'] );
		$this->assertSame( 401, IXES_Mu::bare_auth( $this->tmp, str_repeat( 'cd', 32 ), $now, $body, $sig, true, $now )->get_error_data()['status'] );
		$this->assertSame( 401, IXES_Mu::bare_auth( $this->tmp, $token, $now, $body, 'bad', true, $now )->get_error_data()['status'] );
		$this->assertSame( 401, IXES_Mu::bare_auth( $this->tmp, $token, $now - 1000, $body, IXES_Auth::sign( $token, 'POST', '/envsync/v1/rescue', $now - 1000, $body ), true, $now )->get_error_data()['status'] );
	}

	public function test_quarantine_moves_what_the_job_brought_and_restores_the_snapshot() {
		$store = 'envsync-0123456789abcdef';
		$job = '20260930-120000-abcdef';
		$jd = "{$store}/jobs/{$job}";
		$this->put( "{$jd}/meta.json", json_encode( [ 'job' => $job, 'plan' => [ 'files' => [ 'push' => [ 'mu-plugins/new.php', 'mu-plugins/new/lib.php', 'mu-plugins/own.php', 'uploads/a.jpg' ], 'delete' => [ 'mu-plugins/gone.php' ] ] ], 'created_files' => [ 'mu-plugins/new.php', 'mu-plugins/new/lib.php' ] ] ) );
		$this->put( "{$jd}/files/mu-plugins/own.php", 'old own' );
		$this->put( "{$jd}/files/mu-plugins/gone.php", 'old gone' );
		$this->put( "{$jd}/stage/mu-plugins/late.php", 'staged' );
		$this->put( 'mu-plugins/new.php', 'fatal' );
		$this->put( 'mu-plugins/new/lib.php', 'lib' );
		$this->put( 'mu-plugins/own.php', 'new own' );
		$this->put( 'mu-plugins/untouched.php', 'keep' );
		$this->put( 'uploads/a.jpg', 'img' );

		$r = IXES_Mu::quarantine( $this->tmp, $this->tmp . '/' . $store, null );
		$this->assertSame( $job, $r['job'] );
		$this->assertSame( [ 'mu-plugins/new.php', 'mu-plugins/new/lib.php', 'mu-plugins/own.php' ], $r['quarantined'] );
		$this->assertSame( [ 'mu-plugins/own.php', 'mu-plugins/gone.php' ], $r['restored'] );
		$this->assertFileDoesNotExist( $this->tmp . '/mu-plugins/new.php' );
		$this->assertSame( 'fatal', file_get_contents( "{$this->tmp}/{$store}/quarantine/{$job}/mu-plugins/new.php" ) );
		$this->assertSame( 'old own', file_get_contents( $this->tmp . '/mu-plugins/own.php' ) );
		$this->assertSame( 'old gone', file_get_contents( $this->tmp . '/mu-plugins/gone.php' ) );
		$this->assertSame( 'keep', file_get_contents( $this->tmp . '/mu-plugins/untouched.php' ) );
		$this->assertSame( 'img', file_get_contents( $this->tmp . '/uploads/a.jpg' ) );
		$this->assertDirectoryDoesNotExist( "{$this->tmp}/{$jd}/stage" );
	}

	public function test_quarantine_refuses_unknown_job_and_path_tricks() {
		$store = $this->tmp . '/envsync-0123456789abcdef';
		mkdir( $store . '/jobs/j1', 0777, true );
		$this->assertSame( 404, IXES_Mu::quarantine( $this->tmp, $store, 'nope' )->get_error_data()['status'] );
		file_put_contents( $store . '/jobs/j1/meta.json', json_encode( [ 'plan' => [ 'files' => [ 'push' => [ 'mu-plugins/../../etc/x.php' ], 'delete' => [] ] ], 'created_files' => [] ] ) );
		$r = IXES_Mu::quarantine( $this->tmp, $store, 'j1' );
		$this->assertSame( [], $r['quarantined'] );
	}

	public function test_staged_files_commit_folder_first_loader_last() {
		$stage = $this->tmp . '/stage';
		$this->put( 'stage/mu-plugins/x.php', 'loader' );
		$this->put( 'stage/mu-plugins/x/a.php', 'a' );
		$this->put( 'mu-plugins/x.php', 'old' );
		$order = [];
		$this->put( 'live/mu-plugins/x/old.php', 'renamed away' );
		$this->put( 'live/mu-plugins/gone.php', 'loader' );
		$r = IXES_Mu::commit_staged( $stage, $this->tmp . '/live', function ( $rel ) use ( &$order ) { $order[] = $rel; }, [ 'mu-plugins/x/old.php', 'mu-plugins/gone.php', '../escape.php' ] );
		$this->assertTrue( $r );
		$this->assertSame( [ 'mu-plugins/x/a.php', 'mu-plugins/x.php' ], $order );
		// deletes wait for the commit too, so the old loader never runs without the file it requires
		$this->assertFileDoesNotExist( $this->tmp . '/live/mu-plugins/x/old.php' );
		$this->assertFileDoesNotExist( $this->tmp . '/live/mu-plugins/gone.php' );
		$this->assertSame( 'loader', file_get_contents( $this->tmp . '/live/mu-plugins/x.php' ) );
		$this->assertDirectoryDoesNotExist( $stage );
		$this->assertTrue( IXES_Mu::commit_staged( $this->tmp . '/none', $this->tmp . '/live' ) );
	}
}
