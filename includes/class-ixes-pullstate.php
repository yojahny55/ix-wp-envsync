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
		] );
		$s->save();
		return $s;
	}

	private function save() { file_put_contents( self::path( $this->d['env'] ), json_encode( $this->d ) ); }

	public function get( $k ) { return $this->d[ $k ] ?? null; }

	public function cursor( $table, $next ) { $this->d['table'] = $table; $this->d['cursor'] = $next; $this->save(); }
	public function table_done( $table ) {
		if ( ! in_array( $table, $this->d['tables_done'], true ) ) $this->d['tables_done'][] = $table;
		$this->d['table'] = null; $this->d['cursor'] = null; $this->save();
	}
	public function files_done( $n ) { $this->d['files_done'] = (int) $n; $this->save(); }
	public function clear() { $f = self::path( $this->d['env'] ); if ( is_file( $f ) ) unlink( $f ); }

	public function describe( $files_total ) {
		$where = $this->d['table']
			? "stopped in {$this->d['table']}" . ( $this->d['cursor'] !== null ? " at row {$this->d['cursor']}" : '' )
			: 'finished tables';
		return sprintf( 'An interrupted pull of %s from %s %s, %d/%d files done.', $this->d['env'], date( 'Y-m-d H:i', (int) $this->d['started'] ), $where, (int) $this->d['files_done'], (int) $files_total );
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
