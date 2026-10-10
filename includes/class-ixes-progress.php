<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stage progress for push/pull. Modes:
 *   bar      a TTY: WP-CLI progress bar, ticked in KB when the stage has a byte total
 *   verbose  --verbose: one line per file/table, as before 0.5.1
 *   summary  piped (agents, CI): one line per stage when it ends
 * track() also keeps runs/<kind>-<env>-progress.json current, whatever the mode, for status and for agents.
 */
class IXES_Progress {
	private $mode; private $log; private $factory; private $clock;
	private $label = ''; private $total = null; private $items_total = 0;
	private $items = 0; private $bytes = 0; private $t0 = 0.0; private $bar = null; private $kb_ticked = 0;
	private $file = null; private $run = []; private $written = 0.0; private $notes = [];
	// seconds between two writes of the progress file; a stage change is always written
	const FILE_EVERY = 3;

	public function __construct( $mode, callable $log, ?callable $bar_factory = null, ?callable $clock = null ) {
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
		if ( $this->file ) {
			$this->run['phase'] = $label === 'Files' ? 'files' : ( $label === 'Database' ? 'db' : strtolower( trim( $label ) ) );
			if ( $this->run['phase'] === 'files' ) $this->run = [ 'files_done' => 0, 'files_total' => $this->items_total, 'bytes_done' => 0, 'bytes_total' => (int) $total_bytes ] + $this->run;
			if ( $this->run['phase'] === 'db' ) $this->run = [ 'tables_done' => 0, 'tables_total' => $this->items_total ] + $this->run;
			$this->write( true );
		}
		if ( $this->mode === 'bar' && $this->items_total > 0 ) {
			$count = $this->total !== null ? max( 1, (int) ceil( $this->total / 1024 ) ) : $this->items_total;
			$this->bar = call_user_func( $this->factory, str_pad( $label, 9 ), $count );
		}
	}

	public function bytes( $n ) {
		$this->bytes += (int) $n;
		if ( $this->file && $this->run['phase'] === 'files' ) { $this->run['bytes_done'] = $this->bytes; $this->write(); }
		if ( ! $this->bar || $this->total === null ) return;
		$kb = (int) floor( $this->bytes / 1024 );
		if ( $kb > $this->kb_ticked ) { $this->bar->tick( $kb - $this->kb_ticked, $this->message() ); $this->kb_ticked = $kb; }
	}

	public function item( $name ) {
		$this->items++;
		if ( $this->file && isset( [ 'files' => 1, 'db' => 1 ][ $this->run['phase'] ] ) ) { $this->run[ $this->run['phase'] === 'files' ? 'files_done' : 'tables_done' ] = $this->items; $this->write(); }
		if ( $this->mode === 'verbose' ) call_user_func( $this->log, "{$this->label} {$this->items}/{$this->items_total} {$name}" );
		if ( $this->bar && $this->total === null ) $this->bar->tick( 1, $this->message() );
	}

	/** A line that must stay visible (retries, skips); kept for the run file too. */
	public function note( $msg ) { $this->notes[] = (string) $msg; call_user_func( $this->log, $msg ); }

	/** Every note of this run, in order. */
	public function notes() { return $this->notes; }

	public function end() {
		if ( $this->label === '' ) return;
		if ( $this->bar ) { $this->bar->finish(); $this->bar = null; }
		if ( $this->mode === 'summary' && $this->items_total > 0 ) call_user_func( $this->log, $this->summary() );
		$this->label = '';
	}

	public static function file_path( $kind, $env ) { return ixes_storage_dir() . "/runs/{$kind}-{$env}-progress.json"; }

	/** Start keeping the progress file of this $kind (push, pull, diff) of run on $env; the run is in its plan phase until the first stage. */
	public function track( $kind, $env ) {
		$this->file = self::file_path( $kind, $env );
		$now = time();
		$this->run = [ 'kind' => $kind, 'env' => $env, 'job' => null, 'phase' => 'plan', 'pid' => getmypid(), 'started' => $now,
			'files_done' => 0, 'files_total' => 0, 'bytes_done' => 0, 'bytes_total' => 0, 'tables_done' => 0, 'tables_total' => 0, 'updated' => $now ];
		// WP_CLI::error() exits: the file must not outlive the run and look like one still going
		$path = $this->file;
		register_shutdown_function( function () use ( $path ) { IXES_Progress::forget( $path ); } );
		$this->write( true );
	}

	public function job( $id ) { if ( ! $this->file ) return; $this->run['job'] = $id; $this->write( true ); }

	/** The run is over: close the stage and remove the progress file. */
	public function finish() {
		$this->end();
		if ( $this->file ) { self::forget( $this->file ); $this->file = null; }
	}

	private function write( $force = false ) {
		$now = $this->now();
		if ( ! $force && $now - $this->written < self::FILE_EVERY ) return;
		$this->written = $now;
		$this->run['updated'] = time();
		wp_mkdir_p( dirname( $this->file ) );
		// written aside and renamed, so a reader never sees half a file
		$tmp = $this->file . '.' . getmypid();
		if ( file_put_contents( $tmp, json_encode( $this->run ) ) !== false ) rename( $tmp, $this->file );
	}

	/** Removes $path only when this process wrote it: a second run on the same env must keep its own. */
	public static function forget( $path ) {
		if ( ! is_file( $path ) ) return;
		$j = json_decode( (string) @file_get_contents( $path ), true );
		if ( ! is_array( $j ) || (int) ( $j['pid'] ?? 0 ) === getmypid() ) @unlink( $path );
	}

	/** The pull, push or diff running on $env now, from its progress file, or null. A file left by a killed run does not count. */
	public static function running( $env ) {
		foreach ( [ 'push', 'pull', 'diff' ] as $kind ) {
			$path = self::file_path( $kind, $env );
			if ( ! is_file( $path ) ) continue;
			$j = json_decode( (string) @file_get_contents( $path ), true );
			if ( is_array( $j ) && self::alive( (int) ( $j['pid'] ?? 0 ) ) ) return $j;
		}
		return null;
	}

	private static function alive( $pid ) {
		if ( $pid <= 0 ) return false;
		if ( is_dir( '/proc/self' ) ) return is_dir( "/proc/{$pid}" );
		if ( function_exists( 'posix_kill' ) ) return posix_kill( $pid, 0 );
		return true; // no way to tell: trust the file
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
