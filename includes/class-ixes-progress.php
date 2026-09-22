<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stage progress for push/pull. Modes:
 *   bar      a TTY: WP-CLI progress bar, ticked in KB when the stage has a byte total
 *   verbose  --verbose: one line per file/table, as before 0.5.1
 *   summary  piped (agents, CI): one line per stage when it ends
 */
class IXES_Progress {
	private $mode; private $log; private $factory; private $clock;
	private $label = ''; private $total = null; private $items_total = 0;
	private $items = 0; private $bytes = 0; private $t0 = 0.0; private $bar = null; private $kb_ticked = 0;

	public function __construct( $mode, callable $log, callable $bar_factory = null, callable $clock = null ) {
		$this->mode = $mode; $this->log = $log;
		$this->factory = $bar_factory ?: function ( $label, $count ) { return \WP_CLI\Utils\make_progress_bar( $label, $count ); };
		$this->clock = $clock ?: 'microtime';
	}

	public static function for_cli( array $assoc ) {
		$log = function ( $m ) { WP_CLI::log( $m ); };
		if ( ! empty( $assoc['verbose'] ) ) return new self( 'verbose', $log );
		return new self( self::piped() ? 'summary' : 'bar', $log );
	}

	// WP-CLI's isPiped() needs ext-posix and reports "terminal" without it; stream_isatty() is core PHP 7.2+
	public static function piped() {
		$env = getenv( 'SHELL_PIPE' );
		if ( $env !== false ) return filter_var( $env, FILTER_VALIDATE_BOOLEAN );
		return function_exists( 'stream_isatty' ) ? ! stream_isatty( STDOUT ) : \WP_CLI\Utils\isPiped();
	}

	private function now() { return (float) call_user_func( $this->clock, true ); }

	public function stage( $label, $total_bytes, $items_total ) {
		$this->end();
		$this->label = $label; $this->total = $total_bytes; $this->items_total = (int) $items_total;
		$this->items = 0; $this->bytes = 0; $this->kb_ticked = 0; $this->t0 = $this->now();
		if ( $this->mode === 'bar' && $this->items_total > 0 ) {
			$count = $this->total !== null ? max( 1, (int) ceil( $this->total / 1024 ) ) : $this->items_total;
			$this->bar = call_user_func( $this->factory, str_pad( $label, 9 ), $count );
		}
	}

	public function bytes( $n ) {
		$this->bytes += (int) $n;
		if ( ! $this->bar || $this->total === null ) return;
		$kb = (int) floor( $this->bytes / 1024 );
		if ( $kb > $this->kb_ticked ) { $this->bar->tick( $kb - $this->kb_ticked, $this->message() ); $this->kb_ticked = $kb; }
	}

	public function item( $name ) {
		$this->items++;
		if ( $this->mode === 'verbose' ) call_user_func( $this->log, "{$this->label} {$this->items}/{$this->items_total} {$name}" );
		if ( $this->bar && $this->total === null ) $this->bar->tick( 1, $this->message() );
	}

	/** A line that must stay visible (retries, skips). */
	public function note( $msg ) { call_user_func( $this->log, $msg ); }

	public function end() {
		if ( $this->label === '' ) return;
		if ( $this->bar ) { $this->bar->finish(); $this->bar = null; }
		if ( $this->mode === 'summary' && $this->items_total > 0 ) call_user_func( $this->log, $this->summary() );
		$this->label = '';
	}

	public function summary() {
		$secs = max( 0.001, $this->now() - $this->t0 );
		$s = strtolower( trim( $this->label ) ) . ': ' . $this->items . ( $this->total !== null ? ' (' . IXES_Report::size( $this->bytes ) . ')' : '' );
		$s .= ' in ' . self::duration( $secs );
		if ( $this->total !== null ) $s .= ', ' . IXES_Report::size( (int) ( $this->bytes / $secs ) ) . '/s';
		return $s;
	}

	private function message() {
		$secs = max( 0.001, $this->now() - $this->t0 );
		if ( $this->total === null ) return str_pad( $this->label, 9 ) . "{$this->items}/{$this->items_total}";
		return str_pad( $this->label, 9 ) . IXES_Report::size( $this->bytes ) . ' / ' . IXES_Report::size( $this->total ) . '  ' . IXES_Report::size( (int) ( $this->bytes / $secs ) ) . '/s';
	}

	public static function duration( $secs ) {
		$secs = (int) round( $secs );
		return $secs >= 60 ? intdiv( $secs, 60 ) . 'm' . str_pad( (string) ( $secs % 60 ), 2, '0', STR_PAD_LEFT ) . 's' : $secs . 's';
	}

}
