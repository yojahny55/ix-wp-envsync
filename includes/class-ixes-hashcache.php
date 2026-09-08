<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * mtime+size keyed file hash cache, one JSON file in the storage dir.
 * Advisory only: an entry is trusted only when BOTH mtime and size match and
 * the requested algo is present, so it can never make two different files
 * hash the same, nor return a hash computed with another algo.
 */
class IXES_Hashcache {

	const PRUNE_OVER  = 20000;
	const PRUNE_EVERY = 3600;
	const PRUNED_AT   = '__pruned_at'; // bookkeeping key inside the cache file, never a rel path

	private static $data   = null;
	private static $loaded = null; // path of the file currently in $data
	private static $dirty  = false;

	private static function file() {
		return ixes_storage_dir() . '/hashcache.json';
	}

	private static function load() {
		$f = self::file();
		if ( self::$loaded === $f ) return;
		$raw = is_file( $f ) ? @file_get_contents( $f ) : false;
		$j   = ( $raw === false || $raw === '' ) ? null : json_decode( $raw, true );
		// a corrupt or truncated cache is simply an empty one, never fatal
		self::$data   = is_array( $j ) ? $j : [];
		self::$loaded = $f;
		self::$dirty  = false;
	}

	/** @return string|false hash, or false if the file cannot be read */
	public static function hash( $abs, $rel, $algo ) {
		self::load();
		$st = @stat( $abs );
		if ( $st === false ) return false;
		// A file actually named like the prune stamp would clobber it: never cache that one.
		if ( $rel === self::PRUNED_AT ) return @hash_file( $algo, $abs );
		$m = (int) $st['mtime'];
		$s = (int) $st['size'];

		$e     = isset( self::$data[ $rel ] ) && is_array( self::$data[ $rel ] ) ? self::$data[ $rel ] : null;
		$fresh = $e !== null && (int) ( $e['m'] ?? -1 ) === $m && (int) ( $e['s'] ?? -1 ) === $s;
		if ( $fresh && isset( $e['h'][ $algo ] ) && is_string( $e['h'][ $algo ] ) ) return $e['h'][ $algo ];

		$h = @hash_file( $algo, $abs );
		if ( $h === false ) return false;

		$hashes           = ( $fresh && isset( $e['h'] ) && is_array( $e['h'] ) ) ? $e['h'] : [];
		$hashes[ $algo ]  = $h;
		self::$data[ $rel ] = [ 'm' => $m, 's' => $s, 'h' => $hashes ];
		self::$dirty        = true;
		return $h;
	}

	public static function save() {
		if ( ! self::$dirty || ! is_array( self::$data ) ) return;
		self::prune();
		$f = self::file();
		// unique temp name: file_manifest and file_chunk requests can overlap, and a shared
		// "<file>.tmp" lets two writers interleave into one corrupt file
		$tmp = @tempnam( dirname( $f ), 'ixes' );
		if ( $tmp && @file_put_contents( $tmp, json_encode( self::$data ) ) !== false ) {
			@chmod( $tmp, 0644 );
			@rename( $tmp, $f );
		} elseif ( $tmp ) {
			@unlink( $tmp );
		}
		self::$dirty = false;
	}

	// ponytail: prune at most hourly and only past PRUNE_OVER entries; file_chunk saves once per
	// 2 MB chunk, so an unconditional stat() per entry would be the new O(n^2)
	private static function prune() {
		if ( count( self::$data ) <= self::PRUNE_OVER || ! defined( 'WP_CONTENT_DIR' ) ) return;
		$last = isset( self::$data[ self::PRUNED_AT ] ) ? (int) self::$data[ self::PRUNED_AT ] : 0;
		if ( time() - $last < self::PRUNE_EVERY ) return;
		foreach ( self::$data as $rel => $unused ) {
			if ( $rel === self::PRUNED_AT ) continue;
			if ( ! file_exists( WP_CONTENT_DIR . '/' . $rel ) ) unset( self::$data[ $rel ] );
		}
		self::$data[ self::PRUNED_AT ] = time();
	}

	public static function flush() {
		$f = self::file();
		if ( is_file( $f ) ) @unlink( $f );
		if ( is_file( $f . '.tmp' ) ) @unlink( $f . '.tmp' );
		self::$data   = null;
		self::$loaded = null;
		self::$dirty  = false;
	}
}
