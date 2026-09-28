<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Hasher {

	/**
	 * Remote capability (0.9.3): rows travel with their raw bytes. JSON cannot carry a string that is not valid UTF-8
	 * (a varbinary IP, a blob, latin1 text read raw): wp_json_encode() swaps the bad bytes for '?', so two IPs that
	 * differ only there collapse into one value and a composite primary key collides on import. Such a cell travels
	 * as {"b64": ...} instead, only when the hub asks for it ('cells' on /dump, /hash/*, job steps).
	 */
	const CAP = 'binary_cells';

	public static function algo( $remote_algos = null ) {
		$local = in_array( 'xxh128', hash_algos(), true );
		$remote = $remote_algos === null ? true : in_array( 'xxh128', $remote_algos, true );
		return ( $local && $remote ) ? 'xxh128' : 'sha1';
	}

	// $extra: this side's values of the env's extra_replace pairs, index-aligned with the other side's list
	public static function placeholders( $url, $abspath, array $extra = [] ) {
		$url = rtrim( $url, '/' );
		$abspath = rtrim( $abspath, '/' );
		$bare = preg_replace( '#^https?://#', '', $url );
		$pairs = [
			[ $url, '{{URL}}' ],
			[ str_replace( '/', '\/', $url ), '{{URL}}' ],
			[ '//' . $bare, '{{URL}}' ],
			[ $abspath, '{{ABSPATH}}' ],
			[ str_replace( '/', '\/', $abspath ), '{{ABSPATH}}' ],
		];
		foreach ( array_values( $extra ) as $i => $e ) {
			$e = (string) $e;
			if ( $e === '' ) continue;
			$ph = '{{X' . $i . '}}';
			$pairs[] = [ $e, $ph ];
			$esc = str_replace( '/', '\/', $e );
			if ( $esc !== $e ) $pairs[] = [ $esc, $ph ];
		}
		// longest first so scheme-full matches before bare host
		usort( $pairs, function ( $a, $b ) { return strlen( $b[0] ) - strlen( $a[0] ); } );
		return $pairs;
	}

	/**
	 * Whether this sync hashes byte cells as raw bytes: the remote can, and the baseline's hashes were taken that way
	 * (or there is none). A baseline from an older remote holds them as null; comparing the new hashes against it
	 * would show every such row as changed there, and a local edit to one as a conflict.
	 */
	public static function bytes_mode( array $caps, $baseline_exists, $baseline_bytes ) {
		return in_array( self::CAP, $caps, true ) && ( ! $baseline_exists || $baseline_bytes === 'yes' );
	}

	/** A cell JSON cannot carry: a string that is not valid UTF-8. */
	public static function is_bytes( $v ) {
		return is_string( $v ) && $v !== '' && ! preg_match( '//u', $v );
	}

	/** Byte cells as {"b64": ...}. A real cell is a string or null, never an object, so the wrapper cannot collide with one. */
	public static function cells_out( array $row ) {
		foreach ( $row as $k => $v ) if ( self::is_bytes( $v ) ) $row[ $k ] = [ 'b64' => base64_encode( $v ) ];
		return $row;
	}

	/** cells_out() undone; null when a cell is an array that is not exactly a well-formed wrapper. */
	public static function cells_in( array $row ) {
		foreach ( $row as $k => $v ) {
			if ( ! is_array( $v ) ) continue;
			$b = count( $v ) === 1 && isset( $v['b64'] ) && is_string( $v['b64'] ) ? base64_decode( $v['b64'], true ) : false;
			if ( $b === false ) return null;
			$row[ $k ] = $b;
		}
		return $row;
	}

	/** @return array|WP_Error a page of rows with every wrapper back to its bytes */
	public static function rows_in( array $rows ) {
		foreach ( $rows as $i => $r ) {
			$d = is_array( $r ) ? self::cells_in( $r ) : null;
			if ( $d === null ) return new WP_Error( 'bad_cells', 'a row carries a cell that is neither text nor encoded bytes' );
			$rows[ $i ] = $d;
		}
		return $rows;
	}

	public static function normalize( $value, array $pairs ) {
		// security: never instantiate application classes from DB strings (comments, form entries and
		// custom tables are visitor-controlled). Only stdClass is walked; any other class stays opaque
		// and serialize() re-emits it byte-for-byte, so its URLs are simply not rewritten.
		if ( $value instanceof __PHP_Incomplete_Class ) return $value;
		if ( is_string( $value ) ) {
			// raw bytes are never rewritten: a URL-shaped run inside an IP or a blob is not a URL
			if ( self::is_bytes( $value ) ) return $value;
			$un = is_serialized( $value ) ? @unserialize( trim( $value ), [ 'allowed_classes' => [ 'stdClass' ] ] ) : $value;
			if ( $un !== $value && ( is_array( $un ) || is_object( $un ) ) ) {
				return maybe_serialize( self::normalize( $un, $pairs ) );
			}
			foreach ( $pairs as $p ) {
				if ( $p[0] !== '' ) $value = str_replace( $p[0], $p[1], $value );
			}
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) $value[ $k ] = self::normalize( $v, $pairs );
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) $value->$k = self::normalize( $v, $pairs );
			return $value;
		}
		return $value;
	}

	/**
	 * $bytes (both sides 0.9.3+): a byte cell hashes as its base64, so a change inside it shows. Without it such a cell
	 * hashes as null, as before (JSON_PARTIAL_OUTPUT_ON_ERROR). A row of valid UTF-8 hashes the same either way.
	 */
	public static function hash_row( array $row, array $pairs, $algo, $bytes = false ) {
		foreach ( $row as $k => $v ) $row[ $k ] = $bytes && self::is_bytes( $v ) ? [ 'b64' => base64_encode( $v ) ] : self::normalize( $v, $pairs );
		ksort( $row );
		return hash( $algo, json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
	}

	public static function hash_file( $path, $algo ) {
		return hash_file( $algo, $path );
	}
}
