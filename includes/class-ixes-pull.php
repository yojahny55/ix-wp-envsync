<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Pull {

	public static function pairs( array $env, array $info ) {
		$pairs = [
			[ untrailingslashit( $info['url'] ), IXES_Env::local_url() ],
			[ str_replace( '/', '\/', untrailingslashit( $info['url'] ) ), str_replace( '/', '\/', IXES_Env::local_url() ) ],
			[ untrailingslashit( $info['abspath'] ), IXES_Env::local_abspath() ],
		];
		foreach ( (array) $env['extra_replace'] as $p ) $pairs[] = [ $p[0], $p[1] ];
		// last: scheme-full urls are already rewritten by now, so this only catches //host references
		$pairs[] = [ '//' . self::bare( $info['url'] ), '//' . self::bare( IXES_Env::local_url() ) ];
		return $pairs;
	}

	private static function bare( $url ) {
		return preg_replace( '#^https?://#', '', untrailingslashit( $url ) );
	}

	public static function excludes( array $env ) {
		return array_merge( IXES_Env::default_excludes(), (array) $env['excludes'] );
	}

	/**
	 * Drop paths this side refuses to touch from a manifest the other side sent.
	 * The two sides can run different plugin versions with different exclude rules,
	 * and a path we would refuse to write must never reach the transfer list.
	 *
	 * @param array $manifest path => hash
	 */
	public static function drop_excluded( array $manifest, array $excludes ) {
		foreach ( array_keys( $manifest ) as $rel ) {
			if ( IXES_Transfer::excluded_path( $rel, $excludes ) ) unset( $manifest[ $rel ] );
		}
		return $manifest;
	}

	public static function plan( array $env, IXES_Client $c ) {
		global $wpdb;
		$info = $c->info();
		if ( is_wp_error( $info ) ) return $info;
		if ( $info['prefix'] !== $wpdb->prefix ) return new WP_Error( 'prefix_mismatch', "remote prefix '{$info['prefix']}' differs from local '{$wpdb->prefix}'; v0.1 requires identical prefixes" );
		$algo = IXES_Hasher::algo( $info['algos'] );
		$ex   = self::excludes( $env );
		$remote = [];
		$r = $c->paged( '/hash/files', [ 'excludes' => $ex, 'algo' => $algo, 'limit' => 2000 ], function ( $res ) use ( &$remote ) { $remote += $res['files']; }, 'cursor' );
		if ( is_wp_error( $r ) ) return $r;
		$remote = self::drop_excluded( $remote, $ex );
		$local = IXES_Transfer::local_manifest( $ex, $algo );
		$transfer = array_keys( array_diff_assoc( $remote, $local ) );
		$delete   = array_keys( array_diff_key( $local, $remote ) );
		return [
			'created' => time(),
			'env' => $env['name'], 'algo' => $algo, 'info' => $info,
			'tables' => $info['tables'],
			'files' => [ 'transfer' => $transfer, 'delete' => $delete, 'remote' => $remote ],
			'pairs' => self::pairs( $env, $info ), 'excludes' => $ex,
		];
	}

	public static function run( array $env, IXES_Client $c, array $plan, callable $log ) {
		// ponytail: pull is not resumable mid-table in v0.1; add ixes_job_{env} cursor persistence when needed
		global $wpdb;
		$pairs = $plan['pairs'];
		$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $env['name'] . '.sqlite' );
		$bl->reset();
		$bl->meta( 'algo', $plan['algo'] ); $bl->meta( 'source_url', $plan['info']['url'] );
		list( $extra_prod ) = IXES_Env::extras( $env );
		$hash_pairs = IXES_Hasher::placeholders( $plan['info']['url'], $plan['info']['abspath'], $extra_prod );
		$done = [];

		foreach ( $plan['tables'] as $t ) {
			$name = $t['name'];
			$log( "table {$name} ({$t['rows']} rows)" );
			$b = IXES_Transfer::import_begin( $name );
			if ( is_wp_error( $b ) ) { $log( 'skip: ' . $b->get_error_message() ); continue; }
			$pk = $t['pk'];
			$row_err = null;
			$r = $c->paged( '/dump', [ 'table' => $name, 'limit' => 5000 ], function ( $res ) use ( $name, $pairs, $hash_pairs, $bl, $pk, $plan, &$row_err ) {
				if ( $row_err ) return; // already failed this table; stop touching the db further
				$ins = IXES_Transfer::import_rows( $name, $res['rows'], $pairs );
				if ( is_wp_error( $ins ) ) { $row_err = $ins; return; }
				$map = [];
				foreach ( $res['rows'] as $row ) {
					$h = IXES_Hasher::hash_row( $row, $hash_pairs, $plan['algo'] );
					if ( $pk ) $map[ $row[ $pk ] ] = $h; else $map[ $h ] = $h;
				}
				$bl->write_rows( $name, $map );
			} );
			if ( is_wp_error( $r ) ) { IXES_Transfer::drop_tmp_tables( array_merge( $done, [ $name ] ) ); return $r; }
			if ( $row_err ) { IXES_Transfer::drop_tmp_tables( array_merge( $done, [ $name ] ) ); return $row_err; }
			$done[] = $name;
		}
		if ( ! $done ) return new WP_Error( 'nothing_imported', 'no tables were imported' );
		IXES_Transfer::preserve_local_options( $done );
		$commit = IXES_Transfer::import_commit( $done );
		if ( is_wp_error( $commit ) ) { IXES_Transfer::drop_tmp_tables( $done ); return $commit; }
		wp_cache_flush(); // the imported options table is live now; the bootstrapped alloptions cache is not
		$bl->meta( 'opt_active_plugins', json_encode( get_option( 'active_plugins', [] ) ) );

		$n = count( $plan['files']['transfer'] );
		$skipped = [];
		foreach ( $plan['files']['transfer'] as $i => $rel ) {
			$log( "file " . ( $i + 1 ) . "/{$n} {$rel}" );
			$r = $c->fetch_file( $rel, function ( $offset, $data, $final, $sha ) use ( $rel ) {
				return IXES_Transfer::write_file_chunk( $rel, $offset, $data, $final, $sha );
			} );
			// A path this side refuses is a policy difference between the two plugin
			// versions, not a transfer failure: skip it rather than abort the pull.
			if ( is_wp_error( $r ) && $r->get_error_code() === 'bad_path' ) { $skipped[] = $rel; continue; }
			if ( is_wp_error( $r ) ) return $r;
		}
		if ( $skipped ) $log( 'skipped ' . count( $skipped ) . ' excluded path(s) offered by the remote, e.g. ' . $skipped[0] );
		$undeleted = 0;
		foreach ( $plan['files']['delete'] as $rel ) if ( ! IXES_Transfer::delete_file( $rel ) ) $undeleted++;
		if ( $undeleted ) $log( "warning: {$undeleted} stale file(s) could not be deleted (check ownership under wp-content)" );
		$bl->write_files( $plan['files']['remote'] );

		IXES_Transfer::after_import( IXES_Env::local_url(), IXES_Env::local_abspath() );
		IXES_Transfer::offset_auto_increment();
		$bl->commit();
		$log( 'done' );
		return true;
	}
}
