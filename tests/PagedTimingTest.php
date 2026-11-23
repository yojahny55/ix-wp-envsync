<?php
use PHPUnit\Framework\TestCase;

/** Each scripted page takes a given number of seconds on a fake clock: [ seconds, response ]. */
class TimedClient extends IXES_Client {
	public $script = [];
	public $limits = [];
	private $clock = 1000.0;
	protected function transport( $url, array $args ) {
		if ( ! $this->script ) throw new RuntimeException( 'unscripted call' );
		$this->limits[] = json_decode( $args['body'], true )['limit'];
		[ $s, $res ] = array_shift( $this->script );
		$this->clock += $s;
		return $res;
	}
	protected function now() { return $this->clock; }
	protected function sleep_s( $s ) {}
}

class PagedTimingTest extends TestCase {
	private function run_pages( array $seconds, $limit = 5000 ) {
		$c = new TimedClient( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$n = count( $seconds );
		foreach ( array_values( $seconds ) as $i => $s ) {
			$c->script[] = [ $s, [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'rows' => [], 'next' => $i + 1 < $n ? $i + 1 : null ] ), 'headers' => [] ] ];
		}
		$this->assertNull( $c->paged( '/hash/rows', [ 'table' => 'wp_postmeta', 'limit' => $limit ], function () {} ) );
		return $c->limits;
	}

	public function test_a_fixed_cost_per_request_does_not_shrink_the_page_to_the_floor() {
		// every page costs 12-15 s whatever its size: one halving shows it, then the page goes back and stays
		$this->assertSame( [ 5000, 2500, 5000, 5000, 5000, 5000 ], $this->run_pages( [ 15.4, 12.9, 15.0, 14.8, 15.2, 15.1 ] ) );
	}

	public function test_a_page_whose_time_follows_its_size_still_shrinks() {
		$this->assertSame( [ 5000, 2500, 1250, 1250, 1250 ], $this->run_pages( [ 20, 11, 6, 6, 6 ] ) );
	}

	public function test_a_fast_page_still_doubles_back_to_the_requested_size() {
		$this->assertSame( [ 5000, 2500, 5000 ], $this->run_pages( [ 12, 1, 1 ] ) );
	}

	public function test_a_page_already_at_the_floor_is_not_mistaken_for_a_fixed_cost() {
		$this->assertSame( [ 100, 100, 100 ], $this->run_pages( [ 12, 12, 12 ], 100 ) );
	}
}
