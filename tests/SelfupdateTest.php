<?php
use PHPUnit\Framework\TestCase;

/** Answers by route: $routes['/info'] etc. are callables ( $args ) => response; 'probe' gets page requests, 'rescue' rescue.php. */
class SelfupdateRouter {
	public $routes = []; public $log = [];
	public function __invoke( $url, array $args ) {
		if ( strpos( $url, 'ixes_smoke=' ) !== false ) $key = 'probe';
		elseif ( strpos( $url, 'rescue.php' ) !== false ) $key = 'rescue';
		else { parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q ); $key = substr( (string) $q['rest_route'], strlen( '/envsync/v1' ) ); }
		$this->log[] = $key;
		if ( ! isset( $this->routes[ $key ] ) ) throw new RuntimeException( "unrouted {$key}" );
		return ( $this->routes[ $key ] )( $args, $url );
	}
	public static function json( $code, $data ) { return [ 'response' => [ 'code' => $code ], 'body' => json_encode( $data ), 'headers' => [ 'content-type' => 'application/json' ] ]; }
}
class SelfupdateClient extends IXES_Client {
	public $router;
	public function __construct( array $env, SelfupdateRouter $router ) { parent::__construct( $env ); $this->router = $router; }
	protected function transport( $url, array $args ) { return ( $this->router )( $url, $args ); }
	protected function sleep_s( $s ) {}
}

class SelfupdateTest extends TestCase {
	private $tmp;
	private $env = [ 'name' => 'prod', 'url' => 'https://p.test', 'token' => 'tok' ];

	protected function setUp(): void {
		if ( ! class_exists( 'ZipArchive' ) ) $this->markTestSkipped( 'no zip extension' );
		$this->tmp = sys_get_temp_dir() . '/ixes-su-' . getmypid() . '-' . mt_rand();
		mkdir( $this->tmp, 0777, true );
		$GLOBALS['ixes_test_storage'] = $this->tmp . '/storage';
		$GLOBALS['ixes_test_options'] = [];
		IXES_Selfupdate::$folder = null; IXES_Selfupdate::$installer = null;
	}
	protected function tearDown(): void {
		if ( $this->tmp && is_dir( $this->tmp ) ) IXES_Selfupdate::rrmdir( $this->tmp );
		unset( $GLOBALS['ixes_test_storage'] );
		IXES_Selfupdate::$folder = null; IXES_Selfupdate::$installer = null;
	}

	private static function main( $version, $name = 'IX WP EnvSync', $const = null ) {
		return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n */\ndefine( 'IXES_VERSION', '" . ( $const ?? $version ) . "' );\n";
	}
	/** @param array $files name => content; $links names stored as unix symlinks */
	private function zip( array $files, array $links = [] ) {
		$p = $this->tmp . '/z' . mt_rand() . '.zip';
		$z = new ZipArchive(); $z->open( $p, ZipArchive::CREATE );
		foreach ( $files as $n => $c ) $z->addFromString( $n, $c );
		foreach ( $links as $n ) { $z->addFromString( $n, '/etc/passwd' ); $z->setExternalAttributesName( $n, ZipArchive::OPSYS_UNIX, 0120777 << 16 ); }
		$z->close();
		return $p;
	}
	private function good( $version = '0.9.4', $top = 'ix-wp-envsync' ) {
		return $this->zip( [ "{$top}/ix-wp-envsync.php" => self::main( $version ), "{$top}/includes/a.php" => "<?php\n\$a = 1;\n", "{$top}/README.md" => 'x' ] );
	}

	// ---------- zip validation ----------

	public function test_a_release_shaped_zip_passes() {
		$m = IXES_Selfupdate::inspect( $this->good(), 'ix-wp-envsync' );
		$this->assertIsArray( $m );
		$this->assertSame( [ 'ix-wp-envsync', 'IX WP EnvSync', '0.9.4' ], [ $m['top'], $m['name'], $m['version'] ] );
	}
	public function test_path_traversal_and_absolute_paths_are_refused() {
		foreach ( [ 'ix-wp-envsync/../evil.php' => 'traversal', '/ix-wp-envsync/abs.php' => 'absolute', 'ix-wp-envsync\\..\\evil.php' => 'backslash' ] as $bad => $why ) {
			$r = IXES_Selfupdate::inspect( $this->zip( [ 'ix-wp-envsync/ix-wp-envsync.php' => self::main( '0.9.4' ), $bad => '<?php' ] ) );
			$this->assertInstanceOf( WP_Error::class, $r, $why );
			$this->assertSame( 422, $r->get_error_data()['status'] );
		}
	}
	public function test_wrong_or_several_top_folders_are_refused() {
		$r = IXES_Selfupdate::inspect( $this->good( '0.9.4', 'ix-wp-envsync-main' ), 'ix-wp-envsync' );
		$this->assertStringContainsString( 'top folder is ix-wp-envsync-main/', $r->get_error_message() );
		$r = IXES_Selfupdate::inspect( $this->zip( [ 'ix-wp-envsync/ix-wp-envsync.php' => self::main( '0.9.4' ), 'other/x.php' => '<?php' ] ) );
		$this->assertStringContainsString( 'more than one top folder', $r->get_error_message() );
		$r = IXES_Selfupdate::inspect( $this->zip( [ 'ix-wp-envsync.php' => self::main( '0.9.4' ) ] ) );
		$this->assertStringContainsString( 'outside a top folder', $r->get_error_message() );
	}
	public function test_missing_or_foreign_header_is_refused() {
		$r = IXES_Selfupdate::inspect( $this->zip( [ 'ix-wp-envsync/readme.txt' => 'x' ] ) );
		$this->assertStringContainsString( 'no ix-wp-envsync/ix-wp-envsync.php', $r->get_error_message() );
		$r = IXES_Selfupdate::inspect( $this->zip( [ 'ix-wp-envsync/ix-wp-envsync.php' => self::main( '0.9.4', 'Something Else' ) ] ) );
		$this->assertStringContainsString( 'Plugin Name is "Something Else"', $r->get_error_message() );
		$r = IXES_Selfupdate::inspect( $this->zip( [ 'ix-wp-envsync/ix-wp-envsync.php' => "<?php\n/**\n * Plugin Name: IX WP EnvSync\n */\n" ] ) );
		$this->assertStringContainsString( 'no valid Version', $r->get_error_message() );
		$r = IXES_Selfupdate::inspect( $this->zip( [ 'ix-wp-envsync/ix-wp-envsync.php' => self::main( '0.9.4', 'IX WP EnvSync', '0.9.3' ) ] ) );
		$this->assertStringContainsString( 'IXES_VERSION says 0.9.3', $r->get_error_message() );
	}
	public function test_symlink_entries_are_refused() {
		$r = IXES_Selfupdate::inspect( $this->zip( [ 'ix-wp-envsync/ix-wp-envsync.php' => self::main( '0.9.4' ) ], [ 'ix-wp-envsync/link' ] ) );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'symlink ix-wp-envsync/link', $r->get_error_message() );
	}
	public function test_a_php_syntax_error_is_refused_before_install() {
		$r = IXES_Selfupdate::inspect( $this->zip( [ 'ix-wp-envsync/ix-wp-envsync.php' => self::main( '0.9.4' ), 'ix-wp-envsync/includes/b.php' => "<?php\nfunction ( {\n" ] ) );
		$this->assertStringContainsString( 'syntax error in ix-wp-envsync/includes/b.php', $r->get_error_message() );
	}
	public function test_build_zip_leaves_out_dev_files_and_symlinks() {
		$src = $this->tmp . '/src';
		foreach ( [ 'includes', 'tests', '.git', 'skills/wp-envsync' ] as $d ) mkdir( "{$src}/{$d}", 0777, true );
		file_put_contents( "{$src}/ix-wp-envsync.php", self::main( '0.9.4' ) );
		file_put_contents( "{$src}/includes/a.php", '<?php' );
		file_put_contents( "{$src}/skills/wp-envsync/SKILL.md", 'x' );
		file_put_contents( "{$src}/tests/T.php", '<?php' );
		file_put_contents( "{$src}/.git/HEAD", 'x' );
		file_put_contents( "{$src}/composer.json", '{}' );
		symlink( $src . '/includes', "{$src}/vendor" );
		$out = IXES_Selfupdate::build_zip( $src, 'my-folder', $this->tmp . '/b.zip' );
		$this->assertSame( '0.9.4', IXES_Selfupdate::inspect( $out, 'my-folder' )['version'] );
		$z = new ZipArchive(); $z->open( $out ); $names = [];
		for ( $i = 0; $i < $z->numFiles; $i++ ) $names[] = $z->getNameIndex( $i );
		sort( $names );
		$this->assertSame( [ 'my-folder/', 'my-folder/includes/a.php', 'my-folder/ix-wp-envsync.php', 'my-folder/skills/wp-envsync/SKILL.md' ], $names );
	}

	// ---------- version gating ----------

	public function test_version_gating() {
		$this->assertNull( IXES_Selfupdate::version_refusal( '0.9.2', '0.9.4', false ) );
		$this->assertStringContainsString( 'already runs 0.9.4', IXES_Selfupdate::version_refusal( '0.9.4', '0.9.4', false ) );
		$this->assertNotNull( IXES_Selfupdate::version_refusal( '0.9.4', '0.9.2', false ) );
		$this->assertNull( IXES_Selfupdate::version_refusal( '0.9.4', '0.9.2', true ) );
	}
	public function test_plan_refuses_an_older_zip_and_remotes_that_cannot_or_may_not() {
		$router = new SelfupdateRouter();
		$caps = [ 'self_update' ];
		$router->routes['/info'] = function () use ( &$caps ) { return SelfupdateRouter::json( 200, [ 'plugin' => '0.9.4', 'caps' => $caps, 'self_dir' => 'ix-wp-envsync', 'url' => 'https://p.test' ] ); };
		$plan = function ( $force = false ) use ( $router ) { return IXES_Selfupdate::plan( new SelfupdateClient( $this->env, $router ), $this->good( '0.9.3' ), $force ); };
		$this->assertSame( 'not_newer', $plan()->get_error_code() );
		$this->assertSame( '0.9.3', $plan( true )['to'] );
		$caps = [ 'self_update_off' ];
		$this->assertSame( 'self_update_off', $plan()->get_error_code() );
		$caps = [ 'binary' ];
		$this->assertSame( 'old_remote', $plan()->get_error_code() );
	}

	// ---------- transport ----------

	/** The hub's chunks, verified with the remote's own signature check and fed to receive(), land byte for byte. */
	public function test_signed_upload_round_trip_plain_and_deflated() {
		$src = $this->tmp . '/big.zip';
		file_put_contents( $src, random_bytes( 5 * 1048576 + 123 ) );
		foreach ( [ [ 'binary' ], [ 'binary', 'packed' ] ] as $caps ) {
			$router = new SelfupdateRouter(); $seen = [];
			$router->routes['/self-update/chunk'] = function ( $a, $url ) use ( &$seen ) {
				parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
				$h = $a['headers'];
				$ok = IXES_Auth::verify( wp_hash( 'tok' ), $h['X-Envsync-Token'], 'POST', $q['rest_route'], $h['X-Envsync-Ts'], $a['body'], $h['X-Envsync-Sig'], null, $h['X-Envsync-Step'] );
				if ( ! $ok ) return SelfupdateRouter::json( 401, [ 'message' => 'bad signature' ] );
				$p = IXES_Rest::unpack_step( json_decode( $h['X-Envsync-Step'], true ), $a['body'] );
				$seen[] = $p['enc'] ?? 'identity';
				$r = IXES_Selfupdate::receive( $p );
				return is_wp_error( $r ) ? SelfupdateRouter::json( $r->get_error_data()['status'], [ 'message' => $r->get_error_message() ] ) : SelfupdateRouter::json( 200, $r );
			};
			$c = new SelfupdateClient( $this->env, $router ); $c->set_caps( $caps );
			$this->assertTrue( $c->send_package( 'abcdef123456', $src ) );
			$this->assertGreaterThan( 1, count( $seen ) );
			$this->assertSame( in_array( 'packed', $caps, true ) ? 'deflate' : 'identity', $seen[0] );
			$this->assertSame( hash_file( 'sha256', $src ), hash_file( 'sha256', IXES_Selfupdate::store() . '/incoming-abcdef123456.zip' ) );
		}
	}
	public function test_a_tampered_body_fails_the_signature_and_a_wrong_sha_is_dropped() {
		$c = new IXES_Client( $this->env );
		$ts = time(); $step = json_encode( [ 'id' => 'abcdef123456', 'offset' => 0, 'final' => true, 'sha256' => hash( 'sha256', 'good' ) ] );
		$sig = IXES_Auth::sign( 'tok', 'POST', '/envsync/v1/self-update/chunk', $ts, 'good', $step );
		$this->assertFalse( IXES_Auth::verify( wp_hash( 'tok' ), 'tok', 'POST', '/envsync/v1/self-update/chunk', $ts, 'evil', $sig, null, $step ) );
		$r = IXES_Selfupdate::receive( [ 'id' => 'abcdef123456', 'offset' => 0, 'final' => true, 'sha256' => hash( 'sha256', 'good' ), 'bin' => 'evil' ] );
		$this->assertSame( 'bad_sha', $r->get_error_code() );
		$this->assertFileDoesNotExist( IXES_Selfupdate::store() . '/incoming-abcdef123456.zip' );
		$this->assertSame( 409, IXES_Selfupdate::receive( [ 'id' => 'abcdef123456', 'offset' => 10, 'bin' => 'x' ] )->get_error_data()['status'] );
		$this->assertSame( 400, IXES_Selfupdate::receive( [ 'id' => '../../x', 'offset' => 0, 'bin' => 'x' ] )->get_error_data()['status'] );
	}

	// ---------- health check and rollback ----------

	private function apply_with( array $routes, array &$rescued ) {
		$router = new SelfupdateRouter();
		$router->routes = $routes + [
			'/self-update/chunk'   => function () { return SelfupdateRouter::json( 200, [ 'ok' => true ] ); },
			'/self-update/install' => function () { return SelfupdateRouter::json( 200, [ 'ok' => true ] ); },
			'rescue'               => function ( $a ) use ( &$rescued ) { $rescued[] = json_decode( $a['body'], true ); return SelfupdateRouter::json( 200, [ 'ok' => true, 'restored' => '0.9.2' ] ); },
		];
		$c = new SelfupdateClient( $this->env, $router ); $c->set_caps( [ 'binary', 'packed', 'self_update' ] );
		$zip = $this->good();
		$plan = [ 'from' => '0.9.2', 'to' => '0.9.4', 'zip' => $zip, 'size' => filesize( $zip ), 'sha256' => hash_file( 'sha256', $zip ), 'top' => 'ix-wp-envsync', 'url' => 'https://p.test', 'force' => false ];
		return IXES_Selfupdate::apply( $this->env, $c, $plan, function () {}, function () use ( $router ) { return new SelfupdateClient( $this->env, $router ); } );
	}

	public function test_a_fataling_info_after_install_restores_through_rescue() {
		$rescued = []; $restored = false;
		$r = $this->apply_with( [
			'probe' => function () { return SelfupdateRouter::json( 200, [] ); },
			'/info' => function () use ( &$rescued ) { return $rescued ? SelfupdateRouter::json( 200, [ 'plugin' => '0.9.2' ] ) : [ 'response' => [ 'code' => 500 ], 'body' => '<p>There has been a critical error on this website.</p>', 'headers' => [] ]; },
		], $rescued );
		$this->assertSame( 'health_failed', $r->get_error_code() );
		$this->assertSame( [ [ 'action' => 'restore_self', 'from' => '0.9.2' ] ], $rescued );
		$this->assertStringContainsString( '/info failed: remote 500', $r->get_error_message() );
		$this->assertStringContainsString( 'Restored 0.9.2 through the rescue endpoint; the site answers again on 0.9.2', $r->get_error_message() );
	}
	public function test_a_site_that_starts_to_500_restores_even_when_info_answers() {
		$rescued = []; $n = 0;
		$r = $this->apply_with( [
			// three probes before the install are fine, the three after it are not
			'probe' => function () use ( &$n, &$rescued ) { $n++; return SelfupdateRouter::json( $n > 3 && ! $rescued ? 500 : 200, [] ); },
			'/info' => function () use ( &$rescued ) { return SelfupdateRouter::json( 200, [ 'plugin' => $rescued ? '0.9.2' : '0.9.4' ] ); },
		], $rescued );
		$this->assertSame( 'health_failed', $r->get_error_code() );
		$this->assertCount( 1, $rescued );
		$this->assertStringContainsString( 'HTTP 500', $r->get_error_message() );
	}
	public function test_a_rescue_that_fails_too_says_what_to_do_by_hand() {
		$rescued = [];
		$r = $this->apply_with( [
			'probe'  => function () { return SelfupdateRouter::json( 200, [] ); },
			'/info'  => function () { return SelfupdateRouter::json( 500, [ 'message' => 'boom' ] ); },
			'rescue' => function () { return SelfupdateRouter::json( 500, [ 'message' => 'still boom' ] ); },
		], $rescued );
		$this->assertStringContainsString( 'failed too', $r->get_error_message() );
		$this->assertStringContainsString( 'wp envsync rescue prod --restore-self', $r->get_error_message() );
	}
	public function test_a_healthy_new_version_needs_no_rescue() {
		$rescued = [];
		$r = $this->apply_with( [
			'probe' => function () { return SelfupdateRouter::json( 200, [] ); },
			'/info' => function () { return SelfupdateRouter::json( 200, [ 'plugin' => '0.9.4' ] ); },
		], $rescued );
		$this->assertSame( [ 'from' => '0.9.2', 'to' => '0.9.4' ], [ 'from' => $r['from'], 'to' => $r['to'] ] );
		$this->assertSame( [], $rescued );
	}
	public function test_a_refused_install_touches_nothing_and_checks_nothing() {
		$rescued = [];
		$r = $this->apply_with( [
			'probe' => function () { return SelfupdateRouter::json( 200, [] ); },
			'/self-update/install' => function () { return SelfupdateRouter::json( 409, [ 'message' => 'the zip holds 0.9.4 and the remote already runs 0.9.4' ] ); },
		], $rescued );
		$this->assertSame( 'refused', $r->get_error_code() );
		$this->assertSame( [], $rescued );
	}

	// ---------- remote install and restore ----------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_install_keeps_a_backup_stays_active_and_restore_puts_it_back() {
		$plugins = $this->tmp . '/plugins'; $dir = "{$plugins}/ix-wp-envsync";
		mkdir( $dir, 0777, true );
		file_put_contents( "{$dir}/ix-wp-envsync.php", self::main( '0.4.0' ) );
		define( 'WP_PLUGIN_DIR', $plugins );
		IXES_Selfupdate::$folder = $dir;
		$GLOBALS['ixes_test_options']['active_plugins'] = [ 'ix-wp-envsync/ix-wp-envsync.php', 'other/other.php' ];
		IXES_Selfupdate::$installer = function ( $zip ) use ( $plugins ) {
			$GLOBALS['ixes_test_options']['active_plugins'] = [ 'other/other.php' ]; // something deactivated us on the way
			$z = new ZipArchive(); $z->open( $zip ); $z->extractTo( $plugins ); $z->close();
			return true;
		};
		$zip = $this->good( '0.5.0' );
		copy( $zip, IXES_Selfupdate::store() . '/incoming-abcdef123456.zip' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$r = IXES_Selfupdate::install( [ 'id' => 'abcdef123456', 'sha256' => hash_file( 'sha256', $zip ), 'hub' => 'https://hub.test', 'by' => 'dev' ] );
		$this->assertSame( [ 'ok' => true, 'from' => '0.4.0', 'to' => '0.5.0' ], array_intersect_key( $r, [ 'ok' => 1, 'from' => 1, 'to' => 1 ] ) );
		$this->assertStringContainsString( '0.5.0', file_get_contents( "{$dir}/ix-wp-envsync.php" ) );
		$this->assertStringContainsString( '0.4.0', file_get_contents( IXES_Selfupdate::store() . '/backup/ix-wp-envsync/ix-wp-envsync.php' ) );
		$this->assertSame( [ 'ix-wp-envsync/ix-wp-envsync.php', 'other/other.php' ], $GLOBALS['ixes_test_options']['active_plugins'] );
		$log = IXES_Selfupdate::log_tail();
		$this->assertSame( [ 'install', '0.4.0', '0.5.0', 'https://hub.test', 'dev', '203.0.113.9' ], [ $log[0]['event'], $log[0]['from'], $log[0]['to'], $log[0]['hub'], $log[0]['by'], $log[0]['ip'] ] );

		$this->assertSame( 'backup_mismatch', IXES_Selfupdate::restore( '0.3.0' )->get_error_code() );
		$this->assertSame( [ 'ok' => true, 'restored' => '0.4.0' ], IXES_Selfupdate::restore( '0.4.0' ) );
		$this->assertStringContainsString( '0.4.0', file_get_contents( "{$dir}/ix-wp-envsync.php" ) );
		$this->assertFileDoesNotExist( "{$dir}/README.md" );
		$this->assertDirectoryDoesNotExist( "{$plugins}/.ixes-restore" );
		$this->assertSame( 'restore', IXES_Selfupdate::log_tail()[1]['event'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_install_refuses_a_zip_that_is_not_newer() {
		$plugins = $this->tmp . '/plugins'; $dir = "{$plugins}/ix-wp-envsync";
		mkdir( $dir, 0777, true );
		define( 'WP_PLUGIN_DIR', $plugins );
		IXES_Selfupdate::$folder = $dir;
		IXES_Selfupdate::$installer = function () { throw new RuntimeException( 'must not install' ); };
		$zip = $this->good( '0.4.0' );
		copy( $zip, IXES_Selfupdate::store() . '/incoming-abcdef123456.zip' );
		$r = IXES_Selfupdate::install( [ 'id' => 'abcdef123456', 'sha256' => hash_file( 'sha256', $zip ) ] );
		$this->assertSame( 409, $r->get_error_data()['status'] );
		$this->assertStringContainsString( 'already runs 0.4.0', $r->get_error_message() );
	}

	public function test_install_waits_for_a_running_push() {
		$GLOBALS['ixes_test_transients']['ixes_lock'] = 'job-1|' . time();
		$zip = $this->good( '0.5.0' );
		copy( $zip, IXES_Selfupdate::store() . '/incoming-abcdef123456.zip' );
		IXES_Selfupdate::$installer = function () { throw new RuntimeException( 'must not install' ); };
		$r = IXES_Selfupdate::install( [ 'id' => 'abcdef123456', 'sha256' => hash_file( 'sha256', $zip ) ] );
		unset( $GLOBALS['ixes_test_transients']['ixes_lock'] );
		$this->assertSame( 'locked', $r->get_error_code() );
		$this->assertSame( 409, $r->get_error_data()['status'] );
	}

	// ---------- opt-out ----------

	public function test_option_turns_it_off() {
		$this->assertTrue( IXES_Selfupdate::allowed( null, false ) );
		$this->assertFalse( IXES_Selfupdate::allowed( true, false ) );
		$GLOBALS['ixes_test_options'][ IXES_Selfupdate::OPTION ] = 1;
		$this->assertSame( [ 'self_update_off' ], IXES_Selfupdate::caps() );
		$this->assertSame( 403, IXES_Selfupdate::receive( [ 'id' => 'abcdef123456', 'offset' => 0, 'bin' => 'x' ] )->get_error_data()['status'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_turns_it_off_whatever_the_option_says() {
		define( 'ENVSYNC_DISABLE_SELF_UPDATE', true );
		$this->assertFalse( IXES_Selfupdate::enabled() );
		$this->assertSame( [ 'self_update_off' ], IXES_Selfupdate::caps() );
		$r = IXES_Selfupdate::install( [ 'id' => 'abcdef123456', 'sha256' => str_repeat( '0', 64 ) ] );
		$this->assertSame( 403, $r->get_error_data()['status'] );
		$this->assertStringContainsString( 'ENVSYNC_DISABLE_SELF_UPDATE', $r->get_error_message() );
	}
}
