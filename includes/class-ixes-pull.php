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

	/** Different prefixes need a remote that translates them (0.6.0+); IXES_Client::info() marks it with hub_prefix. */
	public static function prefix_refusal( array $info, $local ) {
		$remote = (string) ( $info['prefix'] ?? '' );
		if ( $remote === (string) $local || ! empty( $info['hub_prefix'] ) ) return null;
		return new WP_Error( 'prefix_mismatch', "remote prefix '{$remote}' differs from local '{$local}'; upload 0.6.0 or newer to the remote to sync across prefixes" );
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

	public static function plan( array $env, IXES_Client $c, IXES_Scope $scope = null ) {
		global $wpdb;
		if ( $scope === null ) $scope = IXES_Scope::from_array( [], $wpdb->prefix );
		$info = $c->info();
		if ( is_wp_error( $info ) ) return $info;
		$refused = self::prefix_refusal( $info, $wpdb->prefix );
		if ( $refused ) return $refused;
		$algo = IXES_Hasher::algo( $info['algos'] );
		$ex   = self::excludes( $env );
		$remote = []; $local = []; $transfer = []; $delete = []; $sizes = [];
		if ( $scope->files_wanted() ) {
			$r = $c->paged( '/hash/files', [ 'excludes' => $ex, 'algo' => $algo, 'limit' => 2000, 'sizes' => true, 'roots' => $scope->roots() ], function ( $res ) use ( &$remote, &$sizes ) { $remote += $res['files']; if ( isset( $res['sizes'] ) && $sizes !== null ) $sizes += $res['sizes']; else $sizes = null; }, 'cursor' );
			if ( is_wp_error( $r ) ) return $r;
			$remote = self::drop_excluded( $remote, $ex );
			foreach ( array_keys( $remote ) as $rel ) if ( ! $scope->path_in( $rel ) ) unset( $remote[ $rel ] );
			$local = IXES_Transfer::local_manifest( $ex, $algo, $scope->roots() );
			foreach ( array_keys( $local ) as $rel ) if ( ! $scope->path_in( $rel ) ) unset( $local[ $rel ] );
			$transfer = array_keys( array_diff_assoc( $remote, $local ) ); sort( $transfer, SORT_STRING );
			$delete   = array_keys( array_diff_key( $local, $remote ) );
		}
		$tables_in_scope = array_values( array_filter( $info['tables'], function ( $t ) use ( $scope ) { return $scope->table_in( $t['name'] ); } ) );
		list( $drop_local, $drop_warn ) = self::plan_drops( $env, $info, $scope, $algo );
		return [
			'created' => time(),
			'env' => $env['name'], 'algo' => $algo, 'info' => $info,
			'tables' => $tables_in_scope,
			'files' => [ 'transfer' => $transfer, 'delete' => $delete, 'remote' => $remote ],
			// null: a pre-0.5.1 remote that does not report sizes
			'sizes' => $sizes === null ? null : array_intersect_key( $sizes, array_flip( $transfer ) ),
			'pairs' => self::pairs( $env, $info ), 'excludes' => $ex, 'extra_replace' => (array) $env['extra_replace'],
			'scope' => $scope->to_array(),
			'warnings' => array_merge( $scope->family_warnings( array_column( $tables_in_scope, 'name' ) ), $drop_warn ),
			'drop_local' => $drop_local,
		];
	}

	/**
	 * Tables the remote dropped since the baseline that this side still has, unchanged: the pull drops them here too.
	 * A table this side wrote to since the baseline stays (with a warning), as does any table the baseline does not know.
	 * @return array [ drop_local: table => [why, pk, rows, expect], warnings ]
	 */
	public static function plan_drops( array $env, array $info, IXES_Scope $scope, $algo ) {
		global $wpdb;
		$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $env['name'] . '.sqlite' );
		if ( ! $bl->exists() || ! $scope->db_wanted() || $bl->meta( 'algo' ) !== $algo ) return [ [], [] ];
		$remote = array_flip( array_column( $info['tables'], 'name' ) );
		list( , $extra_local ) = IXES_Env::extras( $env );
		$pairs = IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath(), $extra_local );
		$drop = []; $warn = [];
		foreach ( $bl->tables() as $n ) {
			if ( isset( $remote[ $n ] ) || strpos( $n, $wpdb->prefix . 'ixes_' ) === 0 || ! $scope->table_in( $n ) || ! IXES_Transfer::valid_table( $n ) ) continue;
			$pk  = IXES_Transfer::pk_of( $n );
			$now = IXES_Droptable::hashes( $n, $pairs, $algo );
			if ( is_wp_error( $now ) ) { $warn[] = "{$n}: " . $now->get_error_message(); continue; }
			$base = $bl->rows( $n ); $cmp = $now;
			if ( ! $pk ) { $base = array_values( array_unique( array_values( $base ) ) ); $cmp = array_values( array_unique( $now ) ); }
			if ( ! IXES_Droptable::same_rows( $cmp, $base, (bool) $pk ) ) { $warn[] = "{$n}: {$env['name']} dropped it, but it changed here since the baseline, so it stays"; continue; }
			$drop[ $n ] = [ 'why' => 'baseline', 'pk' => $pk, 'rows' => count( $now ), 'digest' => IXES_Droptable::digest( $now, (bool) $pk ) ];
		}
		return [ $drop, $warn ];
	}

	/**
	 * Drops the plan's drop_local tables here: each one checked again, copied to a .sql file and kept in memory,
	 * then dropped; if the site stops answering afterwards, every table dropped here comes back.
	 * @return array [ dropped, kept (table => why), backups (table => file), restored (url => why, when rolled back) ]
	 */
	public static function drop_tables( array $env, array $plan, IXES_Progress $progress ) {
		$drops = array_filter( (array) ( $plan['drop_local'] ?? [] ), function ( $n ) { return IXES_Transfer::valid_table( $n ); }, ARRAY_FILTER_USE_KEY );
		$out = [ 'dropped' => [], 'kept' => [], 'backups' => [], 'restored' => [] ];
		if ( ! $drops ) return $out;
		global $wpdb;
		list( , $extra_local ) = IXES_Env::extras( $env );
		$pairs = IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath(), $extra_local );
		$get = function ( $u ) { return wp_remote_get( $u, [ 'timeout' => 30, 'sslverify' => false, 'redirection' => 3, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] ); };
		$before = IXES_Droptable::smoke( IXES_Droptable::smoke_urls( IXES_Env::local_url(), wp_generate_password( 12, false ) ), $get );
		$blocked = IXES_Droptable::blockers( array_keys( $drops ) );
		$dir = IXES_Droptable::backup_dir( (string) ( $plan['backup_dir'] ?? '' ) );
		$label = 'pull-' . $env['name'] . '-' . date( 'Ymd-His' );
		$snaps = [];
		foreach ( $drops as $n => $d ) {
			if ( ! empty( $blocked[ $n ] ) ) { $out['kept'][ $n ] = implode( '; ', $blocked[ $n ] ); continue; }
			$now = IXES_Droptable::hashes( $n, $pairs, $plan['algo'] );
			if ( is_wp_error( $now ) || IXES_Droptable::digest( $now, (bool) $d['pk'] ) !== (string) $d['digest'] ) { $out['kept'][ $n ] = 'changed since the plan'; continue; }
			// the .sql is the person's copy; the .jsonl is what brings the table back if the site breaks
			$jsonl = ixes_storage_dir() . '/drops/' . $label . '-' . $n . '.jsonl';
			$snap = IXES_Droptable::snapshot_to( $n, $jsonl, IXES_Droptable::backup_file( $dir, $label, $n ) );
			if ( is_wp_error( $snap ) ) { $out['kept'][ $n ] = 'no copy: ' . $snap->get_error_message(); continue; }
			$out['backups'][ $n ] = IXES_Droptable::backup_file( $dir, $label, $n );
			if ( $wpdb->query( "DROP TABLE `{$n}`" ) === false ) { $out['kept'][ $n ] = "DROP failed: {$wpdb->last_error}"; continue; }
			$snaps[ $n ] = $jsonl;
			$out['dropped'][] = $n;
			$progress->item( "drop {$n}" );
		}
		if ( $snaps ) {
			$bad = IXES_Droptable::regressions( $before, IXES_Droptable::smoke( IXES_Droptable::smoke_urls( IXES_Env::local_url(), wp_generate_password( 12, false ) ), $get ) );
			if ( $bad ) {
				foreach ( $snaps as $n => $jsonl ) {
					$r = IXES_Droptable::restore_file( $jsonl );
					if ( is_wp_error( $r ) ) $out['kept'][ $n ] = 'NOT restored: ' . $r->get_error_message();
				}
				$out['restored'] = $bad; $out['dropped'] = [];
			}
		}
		return $out;
	}

	/** Drop everything an interrupted pull left behind. */
	public static function discard( array $env ) {
		$s = IXES_PullState::load( $env['name'] );
		if ( ! $s ) return;
		$plan = is_file( (string) $s->get( 'plan' ) ) ? json_decode( file_get_contents( $s->get( 'plan' ) ), true ) : null;
		if ( is_array( $plan ) ) IXES_Transfer::drop_tmp_tables( array_column( $plan['tables'], 'name' ) );
		if ( is_file( (string) $s->get( 'plan' ) ) ) unlink( $s->get( 'plan' ) );
		$s->clear();
	}

	/**
	 * @param IXES_PullState|null $state  null = fresh pull; an instance = resume from it (plan must be the saved one)
	 * @param IXES_Progress|null  $progress  null = one line per table/file through $log
	 */
	public static function run( array $env, IXES_Client $c, array $plan, callable $log, $state = null, IXES_Progress $progress = null ) {
		global $wpdb;
		$progress = $progress ?: new IXES_Progress( 'verbose', $log );
		$pairs = $plan['pairs'];
		$scope = IXES_Scope::from_array( (array) ( $plan['scope'] ?? [] ), $wpdb->prefix );
		$partial = ! $scope->is_full();
		$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $env['name'] . '.sqlite' );
		if ( $state === null ) {
			// a pull in the environment's default scope is that environment's whole sync, so it records a baseline for that
			// scope, unless a full baseline exists: then it only refreshes part of it, as any narrower pull does
			$full = $bl->exists() && (string) $bl->meta( 'baseline_scope' ) === '';
			$as_baseline = ! $partial || ( $scope->is_env_default( $env ) && ! $full );
			if ( $as_baseline ) $bl->reset();
			// decided once: a resumed pull finishes the way it started, whatever the environment says by then
			$bl->meta( 'pull_as_baseline', $as_baseline ? 'yes' : 'no' );
			$path  = IXES_Planner::save( $plan, 'pull' );
			$state = IXES_PullState::start( $env['name'], $path, (string) ( $plan['info']['plugin'] ?? '' ), (array) ( $plan['scope'] ?? [] ) );
		}
		$bl->meta( 'algo', $plan['algo'] ); $bl->meta( 'source_url', $plan['info']['url'] );
		list( $extra_prod ) = IXES_Env::extras( $env );
		$hash_pairs = IXES_Hasher::placeholders( $plan['info']['url'], $plan['info']['abspath'], $extra_prod );
		$done    = (array) $state->get( 'tables_done' );
		$resume  = $state->get( 'table' );

		$progress->stage( 'Database', null, count( array_diff( array_column( $plan['tables'], 'name' ), $done ) ) );
		foreach ( $plan['tables'] as $t ) {
			$name = $t['name'];
			if ( in_array( $name, $done, true ) ) continue;
			$from = null;
			// a null cursor for the resume table means the last page was written but table_done()
			// never got to save (killed in between): the tmp table already holds every row, and for
			// a no-PK table (plain INSERT, no REPLACE) re-running from "start" would duplicate them all.
			// Fall through to the fresh-table branch below so import_begin()/delete_table() restart it clean.
			if ( $resume === $name && $state->get( 'cursor' ) !== null ) { $from = $state->get( 'cursor' ); $progress->note( "table {$name} (resuming at {$from})" ); }
			else {
				$b = IXES_Transfer::import_begin( $name );
				if ( is_wp_error( $b ) ) { $progress->note( 'skip: ' . $b->get_error_message() ); continue; }
				$bl->delete_table( $name );
			}
			$pk = $t['pk'];
			$row_err = null;
			$r = $c->paged( '/dump', [ 'table' => $name, 'limit' => 5000, 'from' => $from ], function ( $res ) use ( $name, $pairs, $hash_pairs, $bl, $pk, $plan, $state, &$row_err ) {
				if ( $row_err ) return;
				$ins = IXES_Transfer::import_rows( $name, $res['rows'], $pairs, (bool) $pk );
				if ( is_wp_error( $ins ) ) { $row_err = $ins; return; }
				$map = [];
				foreach ( $res['rows'] as $row ) {
					$h = IXES_Hasher::hash_row( $row, $hash_pairs, $plan['algo'] );
					if ( $pk ) $map[ $row[ $pk ] ] = $h; else $map[ $h ] = $h;
				}
				$bl->write_rows( $name, $map );
				$state->cursor( $name, $res['next'] ?? null ); // this page is now safe to skip on rerun
			} );
			if ( is_wp_error( $r ) ) return $r;
			if ( $row_err ) return $row_err;
			$state->table_done( $name );
			$bl->add_table( $name );
			$done[] = $name;
			$progress->item( "{$name} ({$t['rows']} rows)" );
		}
		$progress->end();
		if ( ! $done && $scope->db_wanted() ) return new WP_Error( 'nothing_imported', 'no tables were imported' );
		// a missing tmp table after a finished table phase can only mean the RENAME already ran (crash before committed() was written)
		if ( $done && ! $state->get( 'committed' ) && ! IXES_Transfer::tmp_exists( $done[0] ) ) $state->committed();
		$options_in = in_array( $wpdb->options, $done, true );
		if ( $done && ! $state->get( 'committed' ) ) {
			IXES_Transfer::preserve_local_options( $done );
			$commit = IXES_Transfer::import_commit( $done );
			if ( is_wp_error( $commit ) ) return $commit;
			$state->committed();
			// imported rows are live now; the bootstrapped object cache may still hold stale copies of any of them
			wp_cache_flush();
			if ( $options_in ) $bl->meta( 'opt_active_plugins', json_encode( get_option( 'active_plugins', [] ) ) );
		}

		// tables the remote dropped since the baseline go here too, once the imported tables are live
		$dr = self::drop_tables( $env, $plan, $progress );
		foreach ( $dr['dropped'] as $n ) $bl->forget_table( $n );
		foreach ( $dr['kept'] as $n => $why ) $progress->note( "warning: kept table {$n}: {$why}" );
		if ( $dr['dropped'] ) $progress->note( 'dropped here: ' . implode( ', ', $dr['dropped'] ) . '; copies: ' . implode( ', ', $dr['backups'] ) );
		if ( $dr['restored'] ) $progress->note( 'warning: the site broke after dropping tables (' . implode( ', ', array_map( function ( $u, $w ) { return "{$u}: {$w}"; }, array_keys( $dr['restored'] ), $dr['restored'] ) ) . '); they were restored. Copies: ' . implode( ', ', $dr['backups'] ) );

		$skipped = [];
		$start = (int) $state->get( 'files_done' );
		$left  = array_slice( $plan['files']['transfer'], $start );
		$sizes = $plan['sizes'] ?? null;
		$progress->stage( 'Files', is_array( $sizes ) ? array_sum( array_intersect_key( $sizes, array_flip( $left ) ) ) : null, count( $left ) );
		$on_bytes = function ( $b ) use ( $progress ) { $progress->bytes( $b ); };
		foreach ( $plan['files']['transfer'] as $i => $rel ) {
			if ( $i < $start ) continue;
			$r = $c->fetch_file( $rel, function ( $offset, $data, $final, $sha ) use ( $rel ) {
				return IXES_Transfer::write_file_chunk( $rel, $offset, $data, $final, $sha );
			}, $on_bytes );
			// A path this side refuses is a policy difference between the two plugin
			// versions, not a transfer failure: skip it rather than abort the pull.
			if ( is_wp_error( $r ) && $r->get_error_code() === 'bad_path' ) { $skipped[] = $rel; $state->files_done( $i + 1 ); continue; }
			if ( is_wp_error( $r ) ) return $r;
			$state->files_done( $i + 1 );
			$progress->item( $rel );
		}
		$progress->end();
		if ( $skipped ) $progress->note( 'skipped ' . count( $skipped ) . ' excluded path(s) offered by the remote, e.g. ' . $skipped[0] );
		$undeleted = 0;
		foreach ( $plan['files']['delete'] as $rel ) if ( ! IXES_Transfer::delete_file( $rel ) ) $undeleted++;
		if ( $undeleted ) $progress->note( "warning: {$undeleted} stale file(s) could not be deleted (check ownership under wp-content)" );
		if ( $partial ) { foreach ( $plan['files']['delete'] as $rel ) $bl->delete_file( $rel ); }
		$bl->write_files( $plan['files']['remote'] );
		$state->clear();

		if ( $options_in ) IXES_Transfer::after_import( IXES_Env::local_url(), IXES_Env::local_abspath() );
		IXES_Transfer::offset_auto_increment( $done );
		$as_baseline = $bl->meta( 'pull_as_baseline' ) !== null ? $bl->meta( 'pull_as_baseline' ) === 'yes' : ! $partial;
		if ( $as_baseline ) { $bl->meta( 'baseline_scope', $partial ? implode( ',', $scope->to_array()['only'] ) : '' ); $bl->commit(); }
		else { $bl->meta( 'partial_at', time() ); $bl->meta( 'partial_scope', $scope->label() ); }
		return true;
	}
}
