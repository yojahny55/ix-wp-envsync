<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resume file for an interrupted pull: <storage>/pull-<env>.json.
 * Written after every unit of work that is safe to skip on rerun (a page inserted AND its baseline written,
 * a whole file landed). Pure apart from file I/O in the storage dir.
 */
class IXES_PullState {
	private $d;

	private function __construct( array $d ) { $this->d = $d; }

	public static function path( $env_name ) { return ixes_storage_dir() . '/pull-' . $env_name . '.json'; }

	public static function load( $env_name ) {
		$f = self::path( $env_name );
		if ( ! is_file( $f ) ) return null;
		$d = json_decode( (string) file_get_contents( $f ), true );
		return is_array( $d ) && isset( $d['env'] ) ? new self( $d ) : null;
	}

	public static function start( $env_name, $plan_path, $remote_plugin, array $scope ) {
		$s = new self( [
			'env' => $env_name, 'plan' => $plan_path, 'started' => time(), 'remote_plugin' => (string) $remote_plugin,
			'scope' => $scope, 'tables_done' => [], 'table' => null, 'cursor' => null, 'files_done' => 0,
			'committed' => false,
		] );
		$s->save();
		return $s;
	}

	// Written to a sibling .tmp file then renamed into place: a kill mid-write must never leave
	// an unparseable state file, since load() treating that as "no state" would silently orphan
	// the tmp tables this state was tracking.
	private function save() {
		$f = self::path( $this->d['env'] );
		$tmp = $f . '.tmp';
		file_put_contents( $tmp, json_encode( $this->d ) );
		rename( $tmp, $f );
	}

	public function get( $k ) { return $this->d[ $k ] ?? null; }

	public function cursor( $table, $next ) { $this->d['table'] = $table; $this->d['cursor'] = $next; $this->save(); }
	public function table_done( $table ) {
		if ( ! in_array( $table, $this->d['tables_done'], true ) ) $this->d['tables_done'][] = $table;
		$this->d['table'] = null; $this->d['cursor'] = null; $this->save();
	}
	public function files_done( $n ) { $this->d['files_done'] = (int) $n; $this->save(); }

	/**
	 * Parallel batches land out of order. files_done stays the contiguous prefix of the sorted transfer list
	 * (what 0.7 and older read); files beyond it that already landed are kept as merged [from, to) ranges.
	 * Only a file written and verified may be marked: a resume skips exactly these.
	 */
	public function files_mark( array $indices ) {
		$r = (array) ( $this->d['files_ranges'] ?? [] );
		foreach ( $indices as $i ) $r[] = [ (int) $i, (int) $i + 1 ];
		usort( $r, function ( $a, $b ) { return $a[0] - $b[0]; } );
		$done = (int) $this->d['files_done']; $out = [];
		foreach ( $r as $x ) {
			if ( $x[1] <= $done ) continue;
			if ( $x[0] <= $done ) { $done = $x[1]; continue; }
			$n = count( $out );
			if ( $n && $x[0] <= $out[ $n - 1 ][1] ) $out[ $n - 1 ][1] = max( $out[ $n - 1 ][1], $x[1] );
			else $out[] = $x;
		}
		// a range may now touch the grown prefix
		while ( $out && $out[0][0] <= $done ) { $done = max( $done, $out[0][1] ); array_shift( $out ); }
		$this->d['files_done'] = $done; $this->d['files_ranges'] = $out;
		$this->save();
	}

	public function file_done( $i ) {
		if ( $i < (int) $this->d['files_done'] ) return true;
		foreach ( (array) ( $this->d['files_ranges'] ?? [] ) as $x ) if ( $i >= $x[0] && $i < $x[1] ) return true;
		return false;
	}

	/** Files landed so far, prefix and ranges together. */
	public function files_count() {
		$n = (int) $this->d['files_done'];
		foreach ( (array) ( $this->d['files_ranges'] ?? [] ) as $x ) $n += $x[1] - $x[0];
		return $n;
	}
	/** Marks the tmp-table commit (RENAME TABLE) as done, so a rerun does not attempt it again. */
	public function committed() { $this->d['committed'] = true; $this->save(); }
	public function clear() { $f = self::path( $this->d['env'] ); if ( is_file( $f ) ) unlink( $f ); }

	public function describe( $files_total ) {
		$where = $this->d['table']
			? "stopped in {$this->d['table']}" . ( $this->d['cursor'] !== null ? " at row {$this->d['cursor']}" : '' )
			: 'finished tables';
		return sprintf( 'An interrupted pull of %s from %s %s, %d/%d files done.', $this->d['env'], wp_date( 'Y-m-d H:i T', (int) $this->d['started'] ), $where, $this->files_count(), (int) $files_total );
	}

	/** '' when the pull can be resumed, otherwise a one-line reason. */
	public function refusal( $remote_plugin, array $plan_excludes, array $env_excludes, array $plan_extra, array $env_extra, $tmp_exists ) {
		if ( (string) $remote_plugin !== $this->d['remote_plugin'] ) return "remote plugin version changed ({$this->d['remote_plugin']} → {$remote_plugin})";
		if ( array_values( $plan_excludes ) !== array_values( $env_excludes ) ) return 'env excludes changed since the pull started';
		if ( array_values( $plan_extra ) !== array_values( $env_extra ) ) return 'env replace pairs changed since the pull started';
		if ( $this->d['table'] && ! $tmp_exists ) return "tmp table for {$this->d['table']} is gone";
		return '';
	}
}
