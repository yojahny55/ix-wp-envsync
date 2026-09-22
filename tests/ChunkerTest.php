<?php
use PHPUnit\Framework\TestCase;

class ChunkerTest extends TestCase {
	public function test_defaults() {
		$c = new IXES_Chunker();
		$this->assertSame( 2097152, $c->size() );
	}
	public function test_grows_after_two_fast_chunks_up_to_max() {
		$c = new IXES_Chunker();
		$c->ok( 0.5 ); $this->assertSame( 2097152, $c->size(), 'one fast chunk is not enough' );
		$c->ok( 0.5 ); $this->assertSame( 4194304, $c->size() );
		$c->ok( 0.5 ); $c->ok( 0.5 ); $this->assertSame( 4194304, $c->size(), 'capped at max' );
	}
	public function test_slow_chunk_resets_the_fast_streak() {
		$c = new IXES_Chunker();
		$c->ok( 0.5 ); $c->ok( 3 ); $c->ok( 0.5 );
		$this->assertSame( 2097152, $c->size() );
	}
	public function test_retryable_failure_halves_down_to_min() {
		$c = new IXES_Chunker();
		$this->assertTrue( $c->fail( 413 ) ); $this->assertSame( 1048576, $c->size() );
		$this->assertTrue( $c->fail( null ) ); $this->assertSame( 524288, $c->size() );
		$this->assertTrue( $c->fail( 502 ) ); $this->assertSame( 262144, $c->size() );
		$this->assertTrue( $c->fail( 504 ) ); $this->assertSame( 262144, $c->size(), 'floor' );
		$this->assertFalse( $c->fail( 503 ), 'fifth failure spends the budget' );
	}
	public function test_non_retryable_code_keeps_size_but_counts_attempt() {
		$c = new IXES_Chunker();
		$this->assertTrue( $c->fail( 500 ) );
		$this->assertSame( 2097152, $c->size() );
		$this->assertSame( 1, $c->attempts() );
	}
	public function test_backoff_sequence() {
		$c = new IXES_Chunker();
		$seen = [];
		for ( $i = 0; $i < 5; $i++ ) { $c->fail( 502 ); $seen[] = $c->backoff(); }
		$this->assertSame( [ 1, 2, 4, 8, 8 ], $seen );
	}
	public function test_success_resets_attempts() {
		$c = new IXES_Chunker();
		$c->fail( 502 ); $c->reset_attempts();
		$this->assertSame( 0, $c->attempts() );
	}
	public function test_retryable_static() {
		$this->assertTrue( IXES_Chunker::retryable( null ) );
		$this->assertTrue( IXES_Chunker::retryable( 413 ) );
		$this->assertFalse( IXES_Chunker::retryable( 401 ) );
	}
}
