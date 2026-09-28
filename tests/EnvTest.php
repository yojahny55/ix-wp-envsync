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
}
