<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Planner {

	public static function build( array $env, IXES_Client $c, IXES_Scope $scope = null ) {
		global $wpdb;
		if ( $scope === null ) $scope = IXES_Scope::from_array( [], $wpdb->prefix );
		$info = $c->info();
		if ( is_wp_error( $info ) ) return $info;
		$algo = IXES_Hasher::algo( $info['algos'] );
		$bl   = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $env['name'] . '.sqlite' );
		$two_way = ! $bl->exists();
		if ( ! $two_way && $bl->meta( 'algo' ) !== $algo ) return new WP_Error( 'algo', 'baseline hash algo differs; pull again' );

		list( $extra_prod, $extra_local ) = IXES_Env::extras( $env );
		$local_pairs = IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath(), $extra_local );
		$ex = IXES_Pull::excludes( $env );
		$plan = [ 'env' => $env['name'], 'created' => time(), 'baseline_at' => $two_way ? null : $bl->meta( 'created_at' ), 'algo' => $algo, 'two_way' => $two_way, 'tables' => [], 'files' => [], 'active_plugins' => null, 'remote_hashes' => [], 'conflict_detail' => [], 'scope' => $scope->to_array() ];

		foreach ( $info['tables'] as $t ) {
			if ( ! $scope->table_in( $t['name'] ) ) continue;
			$name = $t['name'];
			if ( ! IXES_Transfer::valid_table( $name ) ) continue;
			// the remote names the pk column; only trust it if it is a real local column (it goes into SQL in apply())
			$pk = $t['pk'] === null ? null : IXES_Transfer::safe_pk( $name, $t['pk'] );
			if ( $t['pk'] !== null && ! $pk ) return new WP_Error( 'bad_pk', "remote reports unknown pk column '{$t['pk']}' for {$name}" );
			$remote = [];
			$r = $c->paged( '/hash/rows', [ 'table' => $name, 'algo' => $algo, 'extra' => $extra_prod, 'limit' => 5000 ], function ( $res ) use ( &$remote, $pk ) { if ( $pk ) $remote += $res['rows']; else $remote = array_merge( $remote, $res['rows'] ); } );
			if ( is_wp_error( $r ) ) return $r;
			$local = []; $next = null;
			do {
				$res = IXES_Transfer::hash_rows( $name, $next, 5000, $local_pairs, $algo );
				if ( $pk ) $local += $res['rows']; else $local = array_merge( $local, $res['rows'] );
				$next = $res['next'];
			} while ( $next !== null );

			if ( $pk ) {
				$d = IXES_Differ::diff( $two_way ? [] : $bl->rows( $name ), $local, $remote );
				$d['pk'] = $pk; $d['set_insert'] = [];
				$plan['remote_hashes'][ $name ] = array_intersect_key( $remote, array_flip( array_merge( $d['push'], $d['delete'] ) ) );
				// inserts must exist nowhere on prod: a null expectation means "no row", and stale() flags one that appeared
				foreach ( $d['insert'] as $id ) $plan['remote_hashes'][ $name ][ $id ] = null;
				if ( $name === $wpdb->posts && $d['conflict'] ) {
					foreach ( $d['conflict'] as $id ) $plan['conflict_detail'][ $name ][ $id ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $id ) );
				}
			} else {
				$d = [ 'pk' => null, 'push' => [], 'insert' => [], 'delete' => [], 'conflict' => [], 'kept' => [], 'set_insert' => IXES_Differ::diff_set( $local, $remote )['insert'] ];
			}
			if ( $name === $wpdb->options ) {
				$base_ap = $two_way ? [] : self::option_from_baseline_or_local( 'active_plugins', $bl );
				$remote_ap = (array) ( $info['active_plugins'] ?? [] );
				$merged = IXES_Differ::merge_active_plugins( $base_ap, (array) get_option( 'active_plugins', [] ), $remote_ap );
				if ( array_values( $merged ) !== array_values( $remote_ap ) ) $plan['active_plugins'] = $merged;
			}
			if ( $d['push'] || $d['insert'] || $d['delete'] || $d['conflict'] || $d['kept'] || $d['set_insert'] ) $plan['tables'][ $name ] = $d;
		}

		$plan['files'] = [ 'push' => [], 'delete' => [], 'conflict' => [], 'kept' => [] ];
		$plan['remote_file_hashes'] = [];
		if ( $scope->files_wanted() ) {
			$remote_files = [];
			$r = $c->paged( '/hash/files', [ 'excludes' => $ex, 'algo' => $algo, 'limit' => 2000 ], function ( $res ) use ( &$remote_files ) { $remote_files += $res['files']; }, 'cursor' );
			if ( is_wp_error( $r ) ) return $r;
			$remote_files = self::in_scope( IXES_Pull::drop_excluded( $remote_files, $ex ), $scope );
			$local_files  = self::in_scope( IXES_Transfer::local_manifest( $ex, $algo ), $scope );
			$base_files   = $two_way ? [] : self::in_scope( $bl->files(), $scope );
			$fd = IXES_Differ::diff( $base_files, $local_files, $remote_files );
			$plan['files'] = [ 'push' => array_merge( $fd['push'], $fd['insert'] ), 'delete' => $fd['delete'], 'conflict' => $fd['conflict'], 'kept' => $fd['kept'] ];
			foreach ( array_merge( $plan['files']['push'], $plan['files']['delete'] ) as $rel ) $plan['remote_file_hashes'][ $rel ] = $remote_files[ $rel ] ?? null;
		}
		return $plan;
	}

	private static function in_scope( array $manifest, IXES_Scope $scope ) {
		foreach ( array_keys( $manifest ) as $rel ) if ( ! $scope->path_in( $rel ) ) unset( $manifest[ $rel ] );
		return $manifest;
	}

	// ponytail: active_plugins baseline is read from the local option at pull time (identical to prod then). Good enough until someone edits plugins between pull and first diff.
	private static function option_from_baseline_or_local( $name, IXES_Baseline $bl ) {
		$v = $bl->meta( 'opt_' . $name );
		return $v ? (array) json_decode( $v, true ) : (array) get_option( $name, [] );
	}

	public static function is_empty( array $plan ) {
		foreach ( $plan['tables'] as $t ) if ( $t['push'] || $t['insert'] || $t['delete'] || $t['set_insert'] ) return false;
		return empty( $plan['files']['push'] ) && empty( $plan['files']['delete'] ) && $plan['active_plugins'] === null;
	}

	public static function render_text( array $plan ) {
		$o = [];
		$o[] = sprintf( '%s  ←  local          baseline: %s%s', $plan['env'], $plan['baseline_at'] ? date( 'Y-m-d H:i', $plan['baseline_at'] ) : 'NONE (2-way)', $plan['two_way'] ? '   !! everything different would OVERWRITE prod' : '' );
		if ( ! empty( $plan['scope'] ) ) {
			$sc = IXES_Scope::from_array( (array) $plan['scope'], '' );
			if ( ! $sc->is_full() ) $o[] = '  scope: ' . $sc->label();
		}
		$o[] = 'DB';
		foreach ( $plan['tables'] as $name => $t ) {
			$o[] = sprintf( '  %-32s push %-5d insert %-5d delete %-5d prod-wins %-5d kept-prod %d', $name, count( $t['push'] ) + count( $t['set_insert'] ), count( $t['insert'] ), count( $t['delete'] ), count( $t['conflict'] ), count( $t['kept'] ) );
		}
		if ( $plan['active_plugins'] !== null ) $o[] = '  active_plugins  → ' . implode( ', ', $plan['active_plugins'] );
		$o[] = 'FILES';
		foreach ( [ 'push', 'delete', 'conflict', 'kept' ] as $k ) {
			$by = [];
			foreach ( $plan['files'][ $k ] as $rel ) { $dir = implode( '/', array_slice( explode( '/', $rel ), 0, 2 ) ) . '/'; $by[ $dir ] = ( $by[ $dir ] ?? 0 ) + 1; }
			foreach ( $by as $dir => $n ) $o[] = sprintf( '  %-40s %s %d', $dir, $k === 'kept' ? 'kept-prod' : ( $k === 'conflict' ? 'prod-wins' : $k ), $n );
		}
		$conf = [];
		foreach ( $plan['tables'] as $name => $t ) foreach ( $t['conflict'] as $pk ) $conf[] = sprintf( '  %-20s #%s  %s', $name, $pk, $plan['conflict_detail'][ $name ][ $pk ] ?? '' );
		foreach ( $plan['files']['conflict'] as $rel ) $conf[] = '  file                 ' . $rel;
		if ( $conf ) { $o[] = 'CONFLICTS (prod wins)'; $o = array_merge( $o, $conf ); }
		if ( self::is_empty( $plan ) ) $o[] = 'Nothing to push.';
		return implode( "\n", $o ) . "\n";
	}

	public static function render_json( array $plan ) {
		$p = $plan; unset( $p['remote_hashes'], $p['remote_file_hashes'] );
		return wp_json_encode( $p, JSON_PRETTY_PRINT );
	}

	public static function save( array $plan, $kind = 'diff' ) {
		$dir = ixes_storage_dir() . '/plans'; wp_mkdir_p( $dir );
		$stamp = date( 'Ymd-His', $plan['created'] ?? time() );
		$path  = $dir . '/plan-' . ( $kind === 'pull' ? 'pull-' : '' ) . $plan['env'] . '-' . $stamp . '.json';
		file_put_contents( $path, json_encode( $plan ) );
		return $path;
	}
}
