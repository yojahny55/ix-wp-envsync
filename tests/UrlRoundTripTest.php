<?php
use PHPUnit\Framework\TestCase;

/**
 * A pushed row must hash the same on the remote, after the push rewrites it, as it hashes here.
 * Otherwise the next diff lists it again, forever (#41).
 */
class UrlRoundTripTest extends TestCase {
	const L_URL = 'https://site.local';
	const L_ABS = '/srv/http/site';
	const R_URL = 'https://staging.example.com';
	const R_ABS = '/home/u/public_html';

	private function assertRoundTrip( $value, array $extra = [], $r_url = self::R_URL ) {
		list( $prod, $local ) = IXES_Env::extras( [ 'extra_replace' => $extra ] );
		$here  = IXES_Hasher::normalize( $value, IXES_Planner::local_pairs( self::L_URL, self::L_ABS, $local, $r_url, self::R_ABS, $prod ) );
		$wrote = IXES_Hasher::normalize( $value, IXES_Applier::write_pairs( self::L_URL, self::L_ABS, $r_url, self::R_ABS, $extra ) );
		$there = IXES_Hasher::normalize( $wrote, IXES_Hasher::placeholders( $r_url, self::R_ABS, $prod ) );
		$this->assertSame( $here, $there, "wrote: {$wrote}" );
	}

	public function test_plain_url() { $this->assertRoundTrip( '<a href="https://site.local/about/">x</a>' ); }
	public function test_json_escaped_url() { $this->assertRoundTrip( '{"u":"https:\/\/site.local\/a"}' ); }
	public function test_other_scheme() { $this->assertRoundTrip( 'see http://site.local/x' ); }
	public function test_protocol_relative() { $this->assertRoundTrip( '<img src="//site.local/i.png">' ); }
	public function test_abspath() { $this->assertRoundTrip( '/srv/http/site/wp-content/uploads/a.jpg' ); }
	public function test_json_escaped_abspath() { $this->assertRoundTrip( '{"p":"\/srv\/http\/site\/wp-content"}' ); }
	public function test_serialized() { $this->assertRoundTrip( serialize( [ 'u' => 'https://site.local/a', 'p' => '/srv/http/site/x' ] ) ); }
	public function test_remote_url_typed_in_local_content() { $this->assertRoundTrip( 'link to https://staging.example.com/page' ); }
	public function test_remote_url_json_escaped_in_local_content() { $this->assertRoundTrip( '"https:\/\/staging.example.com\/page"' ); }
	public function test_remote_in_a_subfolder() { $this->assertRoundTrip( 'https://site.local/a and //site.local/b', [], 'https://example.com/staging' ); }

	public function test_extra_pair() {
		$this->assertRoundTrip( 'img https://cdn.site.local/a.png', [ [ 'https://cdn.example.com', 'https://cdn.site.local' ] ] );
	}
	public function test_extra_pair_json_escaped() {
		$this->assertRoundTrip( '"https:\/\/cdn.site.local\/a.png"', [ [ 'https://cdn.example.com', 'https://cdn.site.local' ] ] );
	}
	public function test_extra_pair_under_the_local_url() {
		// the longer local value must win over the site URL it starts with, on both sides
		$this->assertRoundTrip( 'img https://site.local/cdn/a.png', [ [ 'https://cdn.example.com', 'https://site.local/cdn' ] ] );
	}

	public function test_remote_url_that_contains_the_local_one() {
		$this->assertRoundTrip( 'https://site.local/a and //site.local/b', [], 'https://site.local.example.com' );
		$w = IXES_Hasher::normalize( 'https://site.local/a //site.local/b', IXES_Applier::write_pairs( self::L_URL, self::L_ABS, 'https://site.local.example.com', self::R_ABS, [] ) );
		$this->assertSame( 'https://site.local.example.com/a //site.local.example.com/b', $w, 'each reference is rewritten once' );
	}

	public function test_written_values() {
		$p = IXES_Applier::write_pairs( self::L_URL . '/', self::L_ABS, self::R_URL . '/', self::R_ABS, [ [ 'https://cdn.example.com', 'https://site.local/cdn' ] ] );
		$this->assertSame(
			'https://staging.example.com/a http://staging.example.com/b https:\/\/staging.example.com\/c /home/u/public_html/d https://cdn.example.com/e',
			IXES_Hasher::normalize( 'https://site.local/a http://site.local/b https:\/\/site.local\/c /srv/http/site/d https://site.local/cdn/e', $p )
		);
	}
	public function test_remote_url_that_contains_the_local_one_typed_in_local_content() {
		$this->assertRoundTrip( 'link https://site.local.example.com/page and "https:\/\/site.local.example.com\/p"', [], 'https://site.local.example.com' );
	}
}
