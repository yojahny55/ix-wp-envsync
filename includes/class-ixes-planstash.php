<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The remote row hashes of every table a plan finished, kept on disk so a plan that fails (network outage, crash,
 * Ctrl-C) resumes from the table it stopped at instead of the first one (#96).
 *
 * One folder per environment and scope key: a different scope, algo, byte mode, remote version, prefix or set of
 * environment settings never reads another one's tables. A saved table is used only while it is younger than
 * MAX_MIN and the remote still counts the same number of rows in it. Only the plan phase reads it: the applier asks
 * the remote again for every row it touches, so a row changed since is refused there, as with a reused plan.
 */
class IXES_Planstash {

	// as long as a dry run's plan may be reused (IXES_Planner::REUSE_MAX_MIN), and for the same reason
	const MAX_MIN = 60;

	private $dir;
	private $max_secs;

	/** $key: everything the remote's row hashes depend on besides the table itself. */
	public function __construct( $env_name, array $key, $max_secs = self::MAX_MIN * 60 ) {
		$this->dir      = self::env_dir( $env_name ) . '/' . md5( serialize( $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- hashed, never unserialized
		$this->max_secs = (int) $max_secs;
	}

	private static function env_dir( $env_name ) {
		return ixes_storage_dir() . '/plan-resume/' . preg_replace( '/[^A-Za-z0-9_.-]/', '_', (string) $env_name );
	}

	private function path( $table ) { return $this->dir . '/' . md5( (string) $table ) . '.json'; }

	/**
	 * The saved hashes of $table, or null when there are none, they expired, or the remote's row count
	 * ($rows, from /info; null when the remote does not send it) differs from the one they were taken at.
	 * @return array{rows:array,byte_keys:bool}|null
	 */
	public function get( $table, $rows, $now = null ) {
		$f = $this->path( $table );
		if ( ! is_file( $f ) ) return null;
		$raw = @file_get_contents( $f );
		$j = $raw === false ? null : json_decode( $raw, true );
		$now = $now === null ? time() : (int) $now;
		if ( ! is_array( $j ) || ( $j['table'] ?? null ) !== (string) $table || ! isset( $j['at'], $j['hashes'] ) || ! is_array( $j['hashes'] )
			|| $now - (int) $j['at'] > $this->max_secs || $now < (int) $j['at']
			|| ( $rows !== null && ( $j['count'] ?? null ) !== (int) $rows ) ) {
			@unlink( $f );
			return null;
		}
		return [ 'rows' => $j['hashes'], 'byte_keys' => ! empty( $j['byte_keys'] ) ];
	}

	/** Saves the finished $table. A write that fails only costs the resume, never the plan. */
	public function put( $table, $rows, array $hashes, $byte_keys = false, $now = null ) {
		if ( ! is_dir( $this->dir ) && ! @mkdir( $this->dir, 0700, true ) && ! is_dir( $this->dir ) ) return false;
		$json = json_encode( [ 'table' => (string) $table, 'at' => $now === null ? time() : (int) $now, 'count' => $rows === null ? null : (int) $rows, 'byte_keys' => (bool) $byte_keys, 'hashes' => $hashes ] );
		if ( $json === false ) return false;
		$f = $this->path( $table );
		$tmp = $f . '.' . getmypid() . '.tmp';
		if ( @file_put_contents( $tmp, $json ) === false ) { @unlink( $tmp ); return false; }
		return @rename( $tmp, $f );
	}

	/** Drops everything saved for $env_name, in every scope: after a plan that finished, and on --flush-cache. */
	public static function forget( $env_name ) {
		$root = self::env_dir( $env_name );
		if ( ! is_dir( $root ) ) return;
		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $d ) {
			foreach ( (array) glob( $d . '/*' ) as $f ) @unlink( $f );
			@rmdir( $d );
		}
		@rmdir( $root );
	}
}
