<?php
use PHPUnit\Framework\TestCase;

class HasherTest extends TestCase {
	private $pairs;
	protected function setUp(): void {
		$this->pairs = IXES_Hasher::placeholders( 'https://client.com', '/home/client/public_html' );
	}
	public function test_plain_string_replaced() {
		$this->assertSame( 'see {{URL}}/about', IXES_Hasher::normalize( 'see https://client.com/about', $this->pairs ) );
	}
	public function test_serialized_string_reserialized_with_correct_length() {
		$in  = serialize( [ 'url' => 'https://client.com/x' ] );
		$out = IXES_Hasher::normalize( $in, $this->pairs );
		$this->assertSame( [ 'url' => '{{URL}}/x' ], unserialize( $out ) );
	}
	public function test_json_escaped_url_replaced() {
		$in = '{"u":"https:\/\/client.com\/y"}';
		$this->assertSame( '{"u":"{{URL}}\/y"}', IXES_Hasher::normalize( $in, $this->pairs ) );
	}
	public function test_abspath_replaced() {
		$this->assertSame( '{{ABSPATH}}/wp-content/x', IXES_Hasher::normalize( '/home/client/public_html/wp-content/x', $this->pairs ) );
	}
	public function test_same_row_different_url_hashes_equal() {
		$prod  = [ 'ID' => 1, 'guid' => 'https://client.com/?p=1', 'post_content' => 'a' ];
		$local = [ 'guid' => 'http://client.local/?p=1', 'post_content' => 'a', 'ID' => 1 ];
		$pp = IXES_Hasher::placeholders( 'https://client.com', '/a' );
		$lp = IXES_Hasher::placeholders( 'http://client.local', '/b' );
		$this->assertSame( IXES_Hasher::hash_row( $prod, $pp, 'sha1' ), IXES_Hasher::hash_row( $local, $lp, 'sha1' ) );
	}
	public function test_extra_replace_pair_hashes_equal_on_both_sides() {
		$prod  = [ 'ID' => 1, 'v' => 'key=pk_live_abc and https:\/\/client.com\/x' ];
		$local = [ 'ID' => 1, 'v' => 'key=pk_test_xyz and https:\/\/client.local\/x' ];
		$pp = IXES_Hasher::placeholders( 'https://client.com', '/a', [ 'pk_live_abc' ] );
		$lp = IXES_Hasher::placeholders( 'https://client.local', '/b', [ 'pk_test_xyz' ] );
		$this->assertSame( IXES_Hasher::hash_row( $prod, $pp, 'sha1' ), IXES_Hasher::hash_row( $local, $lp, 'sha1' ) );
	}
	public function test_different_content_hashes_differ() {
		$pp = IXES_Hasher::placeholders( 'https://c.com', '/a' );
		$this->assertNotSame(
			IXES_Hasher::hash_row( [ 'ID' => 1, 'x' => 'a' ], $pp, 'sha1' ),
			IXES_Hasher::hash_row( [ 'ID' => 1, 'x' => 'b' ], $pp, 'sha1' )
		);
	}
	public function test_algo_negotiation_falls_back_to_sha1() {
		$this->assertSame( 'sha1', IXES_Hasher::algo( [ 'sha1', 'md5' ] ) );
		$this->assertContains( IXES_Hasher::algo( null ), [ 'xxh128', 'sha1' ] );
	}
	public function test_null_and_int_columns_survive() {
		$pp = IXES_Hasher::placeholders( 'https://c.com', '/a' );
		$this->assertIsString( IXES_Hasher::hash_row( [ 'ID' => 1, 'n' => null, 'f' => 1.5 ], $pp, 'sha1' ) );
	}
}
