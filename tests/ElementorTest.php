<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ElementorStub.php';

if ( ! function_exists( 'did_action' ) ) { function did_action( $a ) { return $GLOBALS['ixes_test_actions'][ $a ] ?? 0; } }
if ( ! function_exists( 'delete_post_meta_by_key' ) ) { function delete_post_meta_by_key( $k ) { $GLOBALS['ixes_test_deleted_meta'][] = $k; return true; } }
if ( ! function_exists( 'wp_cache_flush' ) ) { function wp_cache_flush() { return true; } }
if ( ! function_exists( 'flush_rewrite_rules' ) ) { function flush_rewrite_rules() {} }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['ixes_test_options'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_upload_dir' ) ) { function wp_upload_dir( $time = null, $create = true ) { return [ 'basedir' => $GLOBALS['ixes_test_uploads'] ?? '' ]; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['ixes_test_transients'][ $k ] ); return true; } }

class ElementorTest extends TestCase {
	private $files;

	protected function setUp(): void {
		$this->files = new \Elementor\Fake_Files_Manager();
		\Elementor\Plugin::$instance = (object) [ 'files_manager' => $this->files ];
		$GLOBALS['ixes_test_actions'] = [ 'elementor/loaded' => 1 ];
		$GLOBALS['ixes_test_deleted_meta'] = [];
		$GLOBALS['ixes_test_uploads'] = sys_get_temp_dir() . '/ixes-el-' . getmypid() . '-' . uniqid();
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = null;
		$GLOBALS['ixes_test_actions'] = [];
		unset( $GLOBALS['ixes_test_options']['active_plugins'], $GLOBALS['ixes_test_options']['_elementor_global_css'] );
		$css = $GLOBALS['ixes_test_uploads'] . '/elementor/css';
		foreach ( glob( $css . '/*' ) ?: [] as $f ) unlink( $f );
		@rmdir( $css ); @rmdir( dirname( $css ) ); @rmdir( $GLOBALS['ixes_test_uploads'] );
	}

	public function test_does_nothing_when_elementor_is_not_on_the_site() {
		$GLOBALS['ixes_test_actions'] = [];
		$this->assertNull( IXES_Elementor::clear_cache() );
		$this->assertSame( 0, $this->files->cleared );
		$this->assertSame( [], $GLOBALS['ixes_test_deleted_meta'] );
	}

	// a pull into a fresh install: Elementor arrived with the pull, but this process booted without it
	public function test_clears_without_elementor_loaded_when_the_pull_made_it_active() {
		$GLOBALS['ixes_test_actions'] = [];
		$GLOBALS['ixes_test_options']['active_plugins'] = [ 'elementor/elementor.php' ];
		$GLOBALS['ixes_test_options']['_elementor_global_css'] = 'x';
		$css = $GLOBALS['ixes_test_uploads'] . '/elementor/css';
		mkdir( $css, 0777, true );
		file_put_contents( $css . '/post-12.css', 'body{background:url("https://remote.test/a.jpg")}' );
		$this->assertSame( 'cleared', IXES_Elementor::clear_cache() );
		$this->assertSame( [], glob( $css . '/*.css' ) );
		$this->assertSame( [ '_elementor_css', '_elementor_element_cache' ], $GLOBALS['ixes_test_deleted_meta'] );
		$this->assertArrayNotHasKey( '_elementor_global_css', $GLOBALS['ixes_test_options'] );
		$this->assertSame( 0, $this->files->cleared );
	}

	public function test_clears_leftover_css_of_an_inactive_elementor() {
		$GLOBALS['ixes_test_actions'] = [];
		$css = $GLOBALS['ixes_test_uploads'] . '/elementor/css';
		mkdir( $css, 0777, true );
		file_put_contents( $css . '/global.css', '' );
		$this->assertSame( 'cleared', IXES_Elementor::clear_cache() );
		$this->assertSame( [], glob( $css . '/*.css' ) );
	}

	public function test_clears_files_and_element_cache_when_elementor_is_loaded() {
		$this->assertSame( 'cleared', IXES_Elementor::clear_cache() );
		$this->assertSame( 1, $this->files->cleared );
		$this->assertSame( [ '_elementor_element_cache' ], $GLOBALS['ixes_test_deleted_meta'] );
	}

	public function test_a_throwing_clear_is_reported_not_raised() {
		$this->files->throw = new RuntimeException( 'uploads/elementor/css not writable' );
		$this->assertSame( 'failed: uploads/elementor/css not writable', IXES_Elementor::clear_cache() );
	}

	public function test_line_for_each_status() {
		$this->assertSame( 'elementor: cache cleared', IXES_Elementor::line( 'cleared' ) );
		$this->assertSame( 'warning: elementor: cache not cleared (x); clear it in Elementor > Tools', IXES_Elementor::line( 'failed: x' ) );
		$this->assertNull( IXES_Elementor::line( null ) );
	}

	public function test_job_finish_clears_the_cache_and_says_so() {
		$GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ] = IXES_Applier::lock_value( 'job-a', time() );
		$r = IXES_Applier::job_finish( [ 'job' => 'job-a' ] );
		$this->assertSame( 'cleared', $r['elementor'] );
		$this->assertSame( 1, $this->files->cleared );
	}

	public function test_rollback_clears_the_cache_and_says_so() {
		$dir = ixes_storage_dir() . '/jobs/job-b';
		mkdir( $dir . '/files', 0777, true );
		file_put_contents( $dir . '/meta.json', '{}' );
		$r = IXES_Applier::rollback( [ 'job' => 'job-b' ] );
		$this->assertSame( 'cleared', $r['elementor'] );
		$this->assertSame( 1, $this->files->cleared );
	}
}
