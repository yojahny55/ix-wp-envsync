<?php
use PHPUnit\Framework\TestCase;

// WordPress's plugin API, as far as the active_plugins step uses it
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return (int) ( $GLOBALS['ixes_test_user'] ?? 0 ); } }
if ( ! function_exists( 'wp_set_current_user' ) ) { function wp_set_current_user( $id ) { $GLOBALS['ixes_test_user'] = (int) $id; } }
if ( ! function_exists( 'get_users' ) ) { function get_users( $args = [] ) { return $GLOBALS['ixes_test_admins'] ?? []; } }
if ( ! function_exists( 'validate_plugin' ) ) {
	function validate_plugin( $plugin ) { return in_array( $plugin, $GLOBALS['ixes_test_plugin_files'] ?? [], true ) ? 0 : new WP_Error( 'plugin_not_found', 'Plugin file does not exist.' ); }
}
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) { function wp_using_ext_object_cache( $using = null ) { return false; } }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $key, $group = '' ) { return true; } }
if ( ! function_exists( 'activate_plugin' ) ) {
	// as core does it: the list is read before the plugin's hooks run and written after them, whatever they return
	function activate_plugin( $plugin, $redirect = '', $network = false, $silent = false ) {
		$dep = $GLOBALS['ixes_test_requires'][ $plugin ] ?? null;
		if ( $dep && ! in_array( $dep, (array) get_option( 'active_plugins', [] ), true ) ) {
			return new WP_Error( 'plugin_missing_dependencies', "{$plugin} requires {$dep}" );
		}
		if ( isset( $GLOBALS['ixes_test_activation_errors'][ $plugin ] ) ) return $GLOBALS['ixes_test_activation_errors'][ $plugin ];
		$current = (array) get_option( 'active_plugins', [] );
		$GLOBALS['ixes_test_activated'][] = [ $plugin, get_current_user_id() ];
		$hook = $GLOBALS['ixes_test_activation_hooks'][ $plugin ] ?? null;
		if ( $hook ) $hook();
		$current[] = $plugin; sort( $current );
		update_option( 'active_plugins', $current );
		return empty( $GLOBALS['ixes_test_activation_output'][ $plugin ] ) ? null : new WP_Error( 'unexpected_output', 'The plugin generated 12 characters of unexpected output' );
	}
}

/** The lock row, read straight from the table as IXES_Applier::raw_lock() does. */
class ActivateLockWpdb {
	public $options = 'wp_options';
	public function prepare( $q, ...$args ) { return [ $q, $args ]; }
	public function get_var( $q ) { $v = $GLOBALS['ixes_test_transients'][ substr( $q[1][0], strlen( '_transient_' ) ) ] ?? null; return $v === false ? null : $v; }
}

class ActivatePushedPluginsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-act-' . getmypid() . '-' . uniqid();
		mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
		$GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ] = IXES_Applier::lock_value( 'job-1', time() );
		$GLOBALS['ixes_test_options']['active_plugins'] = [ 'seo/seo.php', 'a/a.php' ];
		$GLOBALS['ixes_test_plugin_files'] = [ 'a/a.php', 'seo/seo.php', 'shop/shop.php', 'forms/forms.php' ];
		$GLOBALS['ixes_test_admins'] = [ 7 ];
		$GLOBALS['ixes_test_user'] = 0;
		$GLOBALS['ixes_test_activated'] = [];
		$GLOBALS['ixes_test_activation_hooks'] = [];
		$GLOBALS['ixes_test_requires'] = [];
		$GLOBALS['ixes_test_activation_errors'] = [];
		$GLOBALS['ixes_test_activation_output'] = [];
		$GLOBALS['wpdb'] = new ActivateLockWpdb();
	}
	protected function tearDown(): void {
		unset( $GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ], $GLOBALS['ixes_test_options']['active_plugins'], $GLOBALS['ixes_test_plugin_files'],
			$GLOBALS['ixes_test_admins'], $GLOBALS['ixes_test_user'], $GLOBALS['ixes_test_activated'], $GLOBALS['ixes_test_activation_hooks'], $GLOBALS['ixes_test_requires'],
			$GLOBALS['ixes_test_activation_errors'], $GLOBALS['ixes_test_activation_output'], $GLOBALS['wpdb'] );
	}

	private function step( array $want ) {
		return IXES_Applier::job_step( [ 'job' => 'job-1', 'kind' => 'option', 'name' => 'active_plugins', 'value' => $want ] );
	}

	public function test_a_plugin_the_push_turns_on_runs_its_activation_as_an_administrator() {
		$r = $this->step( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php' ] );
		$this->assertSame( [ 'ok' => true ], $r );
		$this->assertSame( [ [ 'shop/shop.php', 7 ] ], $GLOBALS['ixes_test_activated'], 'activated through WordPress, with an admin as the current user' );
		$this->assertSame( 0, get_current_user_id(), 'the request goes back to no user' );
		$this->assertSame( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php' ], get_option( 'active_plugins' ), 'the order the hub planned, not the sorted one' );
	}

	public function test_plugins_already_on_are_not_activated_again_and_removed_ones_go() {
		$this->assertSame( [ 'ok' => true ], $this->step( [ 'a/a.php' ] ) );
		$this->assertSame( [], $GLOBALS['ixes_test_activated'] );
		$this->assertSame( [ 'a/a.php' ], get_option( 'active_plugins' ) );
	}

	public function test_a_plugin_without_files_here_is_listed_as_before_without_activating() {
		$r = $this->step( [ 'seo/seo.php', 'a/a.php', 'gone/gone.php', 'shop/shop.php' ] );
		$this->assertSame( [ 'ok' => true ], $r );
		$this->assertSame( [ [ 'shop/shop.php', 7 ] ], $GLOBALS['ixes_test_activated'] );
		$this->assertSame( [ 'seo/seo.php', 'a/a.php', 'gone/gone.php', 'shop/shop.php' ], get_option( 'active_plugins' ) );
	}

	public function test_an_activation_that_throws_fails_the_step_with_the_plugin_named() {
		$GLOBALS['ixes_test_activation_hooks']['shop/shop.php'] = function () { throw new Exception( 'Access denied.' ); };
		$r = $this->step( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php' ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'activate_failed', $r->get_error_code() );
		$this->assertStringContainsString( 'shop/shop.php', $r->get_error_message() );
		$this->assertStringContainsString( 'Access denied.', $r->get_error_message() );
		$this->assertSame( 0, get_current_user_id(), 'the admin is dropped on the error path too' );
	}

	public function test_an_activation_error_fails_the_step_but_echoed_output_does_not() {
		$GLOBALS['ixes_test_activation_errors']['shop/shop.php'] = new WP_Error( 'plugin_wp_incompatible', 'needs a newer WordPress' );
		$r = $this->step( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php' ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'needs a newer WordPress', $r->get_error_message() );

		$this->setUp();
		$GLOBALS['ixes_test_activation_output']['shop/shop.php'] = true;
		$this->assertSame( [ 'ok' => true ], $this->step( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php' ] ) );
		$this->assertSame( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php' ], get_option( 'active_plugins' ) );
	}

	public function test_with_no_administrator_the_activation_still_runs() {
		$GLOBALS['ixes_test_admins'] = [];
		$this->assertSame( [ 'ok' => true ], $this->step( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php' ] ) );
		$this->assertSame( [ [ 'shop/shop.php', 0 ] ], $GLOBALS['ixes_test_activated'] );
	}

	public function test_an_extension_listed_before_the_plugin_it_requires_waits_for_it() {
		// sorted, woocommerce-x/ comes before woocommerce/: the extension is tried first and must wait a pass
		$GLOBALS['ixes_test_plugin_files'][] = 'shop-pay/shop-pay.php';
		$GLOBALS['ixes_test_requires']['shop-pay/shop-pay.php'] = 'shop/shop.php';
		$r = $this->step( [ 'seo/seo.php', 'a/a.php', 'shop-pay/shop-pay.php', 'shop/shop.php' ] );
		$this->assertSame( [ 'ok' => true ], $r );
		$this->assertSame( [ 'shop/shop.php', 'shop-pay/shop-pay.php' ], array_column( $GLOBALS['ixes_test_activated'], 0 ) );
		$this->assertSame( [ 'seo/seo.php', 'a/a.php', 'shop-pay/shop-pay.php', 'shop/shop.php' ], get_option( 'active_plugins' ) );
	}

	public function test_a_dependency_that_never_comes_fails_the_step() {
		$GLOBALS['ixes_test_plugin_files'][] = 'shop-pay/shop-pay.php';
		$GLOBALS['ixes_test_requires']['shop-pay/shop-pay.php'] = 'shop/shop.php';
		$r = $this->step( [ 'seo/seo.php', 'a/a.php', 'shop-pay/shop-pay.php' ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'shop-pay/shop-pay.php requires shop/shop.php', $r->get_error_message() );
	}

	public function test_once_the_hub_aborted_the_job_the_step_puts_back_the_list_and_stops() {
		// the hub timed out and ran /job/abort while the first install was still going; core then writes the list
		// it read before the hooks, plus the plugin, over the one the rollback restored
		$GLOBALS['ixes_test_activation_hooks']['shop/shop.php'] = function () {
			unset( $GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ] );
			update_option( 'active_plugins', [ 'seo/seo.php', 'a/a.php' ] );
		};
		$r = $this->step( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php', 'forms/forms.php' ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'nojob', $r->get_error_code() );
		$this->assertSame( [ 'shop/shop.php' ], array_column( $GLOBALS['ixes_test_activated'], 0 ), 'forms is not activated after the abort' );
		$this->assertSame( [ 'seo/seo.php', 'a/a.php' ], get_option( 'active_plugins' ), 'the list the job started from' );
	}

	public function test_the_step_checks_the_lock_row_not_this_requests_cache() {
		// another request cleared the lock in the table; this one's get_transient() still holds it
		$GLOBALS['wpdb'] = new class() extends ActivateLockWpdb { public function get_var( $q ) { return null; } };
		$r = $this->step( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php' ] );
		$this->assertSame( 'nojob', $r->get_error_code() );
		$this->assertSame( [], $GLOBALS['ixes_test_activated'] );
	}

	public function test_an_abort_during_the_last_activation_still_puts_back_the_list() {
		$GLOBALS['ixes_test_activation_hooks']['shop/shop.php'] = function () {
			unset( $GLOBALS['ixes_test_transients'][ IXES_Applier::LOCK ] );
			update_option( 'active_plugins', [ 'seo/seo.php', 'a/a.php' ] );
		};
		$this->assertSame( 'nojob', $this->step( [ 'seo/seo.php', 'a/a.php', 'shop/shop.php' ] )->get_error_code() );
		$this->assertSame( [ 'seo/seo.php', 'a/a.php' ], get_option( 'active_plugins' ) );
	}
}
