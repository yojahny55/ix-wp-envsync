<?php
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase {
	protected function setUp(): void { $GLOBALS['ixes_test_options'] = []; }

	private function base( array $over = [] ) {
		return array_merge( [ 'name' => 'prod', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ], $over );
	}

	public function test_add_stores_a_valid_timeout() {
		IXES_Env::add( $this->base( [ 'timeout' => '300' ] ) );
		$this->assertSame( 300, IXES_Env::get( 'prod' )['timeout'] );
	}

	public function test_add_rejects_a_zero_or_negative_timeout() {
		$this->expectException( InvalidArgumentException::class );
		IXES_Env::add( $this->base( [ 'timeout' => 0 ] ) );
	}

	public function test_add_rejects_a_non_numeric_timeout() {
		$this->expectException( InvalidArgumentException::class );
		IXES_Env::add( $this->base( [ 'timeout' => 'soon' ] ) );
	}

	public function test_add_without_timeout_leaves_it_unset() {
		IXES_Env::add( $this->base() );
		$this->assertArrayNotHasKey( 'timeout', IXES_Env::get( 'prod' ) );
	}

	public function test_re_adding_without_timeout_keeps_the_stored_one() {
		IXES_Env::add( $this->base( [ 'timeout' => 300 ] ) );
		// mimics the CLI's update path: start from the existing env, only overwrite what was passed
		$existing = IXES_Env::get( 'prod' );
		IXES_Env::add( array_merge( $existing, [ 'label' => 'staging' ] ) );
		$this->assertSame( 300, IXES_Env::get( 'prod' )['timeout'] );
		$this->assertSame( 'staging', IXES_Env::get( 'prod' )['label'] );
	}

	public function test_option_globs_exclude_matching_rows_for_this_request_only() {
		IXES_Env::set_option_globs( [ 'imunify_*', 'hostsec_key' ] );
		$this->assertTrue( IXES_Env::option_excluded( 'imunify_settings' ) );
		$this->assertTrue( IXES_Env::option_excluded( 'hostsec_key' ) );
		$this->assertFalse( IXES_Env::option_excluded( 'blogname' ) );
		$this->assertTrue( IXES_Env::option_excluded( 'siteurl' ), 'the built-in list still applies' );
		IXES_Env::set_option_globs( [] );
		$this->assertFalse( IXES_Env::option_excluded( 'imunify_settings' ) );
	}
	public function test_option_globs_header_round_trip() {
		$this->assertSame( [ 'a_*', 'b' ], IXES_Env::option_globs( ' a_*, b,,' ) );
		$this->assertSame( [], IXES_Env::option_globs( '' ) );
	}
	public function test_option_excludes_need_a_remote_that_knows_them() {
		$env = [ 'name' => 'prod', 'exclude_options' => [ 'imunify_*' ] ];
		$r = IXES_Env::option_globs_refusal( $env, [ 'plugin' => '0.9.5', 'caps' => [ 'binary' ] ] );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'self-update prod', $r->get_error_message() );
		$this->assertNull( IXES_Env::option_globs_refusal( $env, [ 'caps' => [ 'exclude_options' ] ] ) );
		$this->assertNull( IXES_Env::option_globs_refusal( [ 'name' => 'prod' ], [ 'caps' => [] ] ) );
	}
}
