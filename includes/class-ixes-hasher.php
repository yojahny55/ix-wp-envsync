<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Hasher {

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

	public static function normalize( $value, array $pairs ) {
		if ( is_string( $value ) ) {
			$un = maybe_unserialize( $value );
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

	public static function hash_row( array $row, array $pairs, $algo ) {
		foreach ( $row as $k => $v ) $row[ $k ] = self::normalize( $v, $pairs );
		ksort( $row );
		return hash( $algo, json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
	}

	public static function hash_file( $path, $algo ) {
		return hash_file( $algo, $path );
	}
}
