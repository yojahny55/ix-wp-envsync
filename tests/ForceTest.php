<?php
use PHPUnit\Framework\TestCase;

/** push --force on a first deploy: what differs is overwritten, what only the remote has stays and is said (#46). */
class ForceTest extends TestCase {
	private function plan( $mirror = false ) {
		return [
			'env' => 'staging', 'two_way' => true, 'mirror' => $mirror, 'warnings' => [ 'earlier' ],
			'tables' => [
				'wp_posts' => [ 'pk' => 'ID', 'push' => [], 'insert' => [ 3 ], 'delete' => [], 'conflict' => [ 9 ], 'kept' => [ 9, 10, 11 ], 'same' => 5, 'set_insert' => [] ],
				'wp_postmeta' => [ 'pk' => 'meta_id', 'push' => [], 'insert' => [], 'delete' => [], 'conflict' => [], 'kept' => range( 1, 1200 ), 'same' => 0, 'set_insert' => [] ],
			],
			'files' => [ 'push' => [ 'a' ], 'delete' => [], 'conflict' => [ 'b' ], 'kept' => [ 'b', 'uploads/c.jpg' ] ],
		];
	}

	public function test_differing_rows_are_pushed_and_remote_only_rows_stay_kept() {
		$p = IXES_Planner::force( $this->plan() );
		$this->assertSame( [ 9 ], $p['tables']['wp_posts']['push'] );
		$this->assertSame( [], $p['tables']['wp_posts']['conflict'] );
		$this->assertSame( [ 10, 11 ], $p['tables']['wp_posts']['kept'] );
		$this->assertSame( [ 'a', 'b' ], $p['files']['push'] );
		$this->assertSame( [ 'uploads/c.jpg' ], $p['files']['kept'] );
	}

	public function test_it_says_what_the_remote_keeps() {
		$w = IXES_Planner::force( $this->plan() )['warnings'];
		$this->assertSame( 'earlier', $w[0] );
		$this->assertSame( 'staging keeps what only it has: 2 wp_posts rows, 1,200 wp_postmeta rows, 1 file (push --force --mirror deletes them)', $w[1] );
	}

	public function test_nothing_to_say_when_the_remote_has_nothing_of_its_own() {
		$p = $this->plan();
		$p['tables']['wp_posts']['kept'] = [ 9 ]; unset( $p['tables']['wp_postmeta'] ); $p['files']['kept'] = [ 'b' ];
		$this->assertSame( [ 'earlier' ], IXES_Planner::force( $p )['warnings'] );
	}
}
