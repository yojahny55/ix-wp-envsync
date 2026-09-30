<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ElementorStub.php';

if ( ! function_exists( 'did_action' ) ) { function did_action( $a ) { return $GLOBALS['ixes_test_actions'][ $a ] ?? 0; } }
if ( ! function_exists( 'delete_post_meta_by_key' ) ) { function delete_post_meta_by_key( $k ) { $GLOBALS['ixes_test_deleted_meta'][] = $k; return true; } }
if ( ! function_exists( 'wp_cache_flush' ) ) { function wp_cache_flush() { return true; } }
if ( ! function_exists( 'flush_rewrite_rules' ) ) { function flush_rewrite_rules() {} }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['ixes_test_transients'][ $k ] ); return true; } }

class ElementorTest extends TestCase {
	private $files;

	protected function setUp(): void {
		$this->files = new \Elementor\Fake_Files_Manager();
		\Elementor\Plugin::$instance = (object) [ 'files_manager' => $this->files ];
		$GLOBALS['ixes_test_actions'] = [ 'elementor/loaded' => 1 ];
		$GLOBALS['ixes_test_deleted_meta'] = [];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = null;
		$GLOBALS['ixes_test_actions'] = [];
	}

	public function test_does_nothing_when_elementor_is_not_loaded() {
		$GLOBALS['ixes_test_actions'] = [];
		$this->assertNull( IXES_Elementor::clear_cache() );
		$this->assertSame( 0, $this->files->cleared );
		$this->assertSame( [], $GLOBALS['ixes_test_deleted_meta'] );
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
