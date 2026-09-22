<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adaptive chunk size and retry budget for one file transfer, in either direction.
 * Pure: no I/O. The caller sleeps backoff() seconds itself.
 */
class IXES_Chunker {
	const FAST = 2.0; // seconds; two consecutive chunks under this double the size

	private $size; private $min; private $max; private $retries;
	private $fast_streak = 0; private $attempts = 0;

	public function __construct( $start = 2097152, $min = 262144, $max = 4194304, $retries = 5 ) {
		$this->size = (int) $start; $this->min = (int) $min; $this->max = (int) $max; $this->retries = (int) $retries;
	}

	public static function retryable( $code ) {
		return $code === null || in_array( (int) $code, [ 408, 413, 502, 503, 504 ], true );
	}

	public function size() { return $this->size; }
	public function attempts() { return $this->attempts; }
	public function reset_attempts() { $this->attempts = 0; }

	public function ok( $seconds ) {
		if ( $seconds < self::FAST ) {
			$this->fast_streak++;
			if ( $this->fast_streak >= 2 ) { $this->size = min( $this->max, $this->size * 2 ); $this->fast_streak = 0; }
		} else {
			$this->fast_streak = 0;
		}
	}

	/** @return bool true = retry the same offset, false = budget spent */
	public function fail( $code ) {
		$this->attempts++;
		$this->fast_streak = 0;
		if ( self::retryable( $code ) ) $this->size = max( $this->min, (int) ( $this->size / 2 ) );
		return $this->attempts < $this->retries;
	}

	public function backoff() {
		return min( 8, 1 << max( 0, $this->attempts - 1 ) );
	}
}
