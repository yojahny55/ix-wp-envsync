<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Differ {

	public static function diff( array $base, array $local, array $remote ) {
		$out = [ 'push' => [], 'insert' => [], 'delete' => [], 'conflict' => [], 'kept' => [] ];
		$pks = array_unique( array_merge( array_keys( $base ), array_keys( $local ), array_keys( $remote ) ) );
		sort( $pks );
		foreach ( $pks as $pk ) {
			$b = isset( $base[ $pk ] )   ? $base[ $pk ]   : null;
			$l = isset( $local[ $pk ] )  ? $local[ $pk ]  : null;
			$r = isset( $remote[ $pk ] ) ? $remote[ $pk ] : null;

			if ( $l === $r ) continue;                       // already equal (incl. both absent)
			$local_changed  = ( $l !== $b );
			$remote_changed = ( $r !== $b );

			if ( ! $remote_changed ) {                        // only local moved
				if ( $l === null )       $out['delete'][] = $pk;
				elseif ( $b === null )   $out['insert'][] = $pk;
				else                     $out['push'][]   = $pk;
				continue;
			}
			// remote moved: prod wins
			$out['kept'][] = $pk;
			if ( $local_changed ) $out['conflict'][] = $pk;
		}
		return $out;
	}

	/** push --mirror: whatever only the remote has moves from kept to delete, so the remote ends up equal to local */
	public static function mirror( array $d, array $local, array $remote ) {
		$only_remote = array_keys( array_diff_key( $remote, $local ) );
		if ( ! $only_remote ) return $d;
		$d['kept']   = array_values( array_diff( $d['kept'], $only_remote ) );
		$d['delete'] = array_values( array_unique( array_merge( $d['delete'], $only_remote ) ) );
		return $d;
	}

	public static function diff_set( array $local_hashes, array $remote_hashes ) {
		$remote = array_flip( $remote_hashes );
		$insert = [];
		foreach ( array_unique( $local_hashes ) as $h ) {
			if ( ! isset( $remote[ $h ] ) ) $insert[] = $h;
		}
		return [ 'insert' => array_values( $insert ) ];
	}

	public static function merge_active_plugins( array $base, array $local, array $remote ) {
		$deactivated = array_diff( $base, $local );
		$activated   = array_diff( $local, $base );
		$merged = array_diff( $remote, $deactivated );
		foreach ( $activated as $p ) $merged[] = $p;
		return array_values( array_unique( $merged ) );
	}
}
