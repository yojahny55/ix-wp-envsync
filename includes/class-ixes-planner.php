<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Planner {

	/**
	 * $mirror (push --mirror, first deploy only): rows, files and tables only the remote has, within the scope, are deleted there.
	 * $drop (push --drop-tables): tables only the remote has that are dropped there even though the baseline does not know them.
	 */
	public static function build( array $env, IXES_Client $c, IXES_Scope $scope = null, $mirror = false, array $drop = [] ) {
		global $wpdb;
		if ( $scope === null ) $scope = IXES_Scope::from_array( [], $wpdb->prefix );
		$info = $c->info();
		if ( is_wp_error( $info ) ) return $info;
		$refused = IXES_Pull::prefix_refusal( $info, $wpdb->prefix );
		if ( $refused ) return $refused;
		$algo = IXES_Hasher::algo( $info['algos'] );
		$bl   = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $env['name'] . '.sqlite' );
		$two_way = ! $bl->exists();
		if ( ! $two_way && $bl->meta( 'algo' ) !== $algo ) return new WP_Error( 'algo', 'baseline hash algo differs; pull again' );
		// a baseline recorded in the environment's default scope says nothing about what lies outside it
		$bs = $two_way ? '' : (string) $bl->meta( 'baseline_scope' );
		if ( $bs !== '' && ! IXES_Scope::from_array( [ 'only' => explode( ',', $bs ) ], $wpdb->prefix )->covers( $scope ) ) return new WP_Error( 'baseline_scope', "the {$env['name']} baseline covers only {$bs}: keep the scope inside it, or pull with --only=all first" );
		// with a baseline, what only the remote has is the remote's own work, and the remote wins
		if ( $mirror && ! $two_way ) return new WP_Error( 'mirror_baseline', "--mirror is only for a first deploy: {$env['name']} has a baseline, so what only it has is its own work and stays" );

		list( $extra_prod, $extra_local ) = IXES_Env::extras( $env );
		$local_pairs = IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath(), $extra_local );
		$ex = IXES_Pull::excludes( $env );
		$plan = [ 'env' => $env['name'], 'created' => time(), 'baseline_at' => $two_way ? null : $bl->meta( 'created_at' ), 'algo' => $algo, 'two_way' => $two_way, 'tables' => [], 'files' => [], 'active_plugins' => null, 'remote_hashes' => [], 'conflict_detail' => [], 'scope' => $scope->to_array(), 'new_tables' => [], 'mirror' => (bool) $mirror, 'drop_tables' => [], 'kept_tables' => [] ];
		$mirror_warn = [];

		// tables only this site has (a plugin's own tables on a first deploy): the push creates them, then fills them
		$candidates = $info['tables'];
		$remote_names = array_column( $info['tables'], 'name' );
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $name ) {
			if ( strpos( $name, $wpdb->prefix . 'ixes_' ) === 0 || in_array( $name, $remote_names, true ) || ! $scope->table_in( $name ) ) continue;
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$name}`", ARRAY_N );
			if ( ! $create ) continue;
			$plan['new_tables'][ $name ] = $create[1];
			$candidates[] = [ 'name' => $name, 'pk' => IXES_Transfer::pk_of( $name ), 'new' => true ];
		}

		$drop_warn = self::plan_drops( $plan, $env, $c, $info, $scope, $bl, $mirror, $drop, $algo, $extra_prod );
		if ( is_wp_error( $drop_warn ) ) return $drop_warn;

		$in_scope = [];
		// empty and small remote tables up front: an empty one costs no request, small ones share requests
		$cheap = [];
		foreach ( $candidates as $t ) {
			if ( empty( $t['new'] ) && $scope->table_in( $t['name'] ) && IXES_Transfer::valid_table( $t['name'] ) ) $cheap[] = $t;
		}
		$pre = self::remote_hashes( $c, $cheap, (array) ( $info['caps'] ?? [] ), $algo, $extra_prod );
		if ( is_wp_error( $pre ) ) return $pre;
		foreach ( $candidates as $t ) {
			if ( ! $scope->table_in( $t['name'] ) ) continue;
			$in_scope[] = $t['name']; // every in-scope table, not just ones that ended up with diffs, so family_warnings() sees the real --tables list
			$name = $t['name'];
			if ( ! IXES_Transfer::valid_table( $name ) ) continue;
			// the remote names the pk column; only trust it if it is a real local column (it goes into SQL in apply())
			$pk = $t['pk'] === null ? null : IXES_Transfer::safe_pk( $name, $t['pk'] );
			if ( $t['pk'] !== null && ! $pk ) return new WP_Error( 'bad_pk', "remote reports unknown pk column '{$t['pk']}' for {$name}" );
			$remote = [];
			if ( isset( $pre[ $name ] ) ) $remote = $pre[ $name ];
			elseif ( empty( $t['new'] ) ) {
				$r = $c->paged( '/hash/rows', [ 'table' => $name, 'algo' => $algo, 'extra' => $extra_prod, 'limit' => 5000 ], function ( $res ) use ( &$remote, $pk ) { if ( $pk ) $remote += $res['rows']; else $remote = array_merge( $remote, $res['rows'] ); } );
				if ( is_wp_error( $r ) ) return $r;
			}
			$local = []; $next = null;
			do {
				$res = IXES_Transfer::hash_rows( $name, $next, 5000, $local_pairs, $algo );
				if ( $pk ) $local += $res['rows']; else $local = array_merge( $local, $res['rows'] );
				$next = $res['next'];
			} while ( $next !== null );

			if ( $pk ) {
				$d = IXES_Differ::diff( $two_way ? [] : $bl->rows( $name ), $local, $remote );
				if ( $mirror ) $d = IXES_Differ::mirror( $d, $local, $remote );
				$d['pk'] = $pk; $d['set_insert'] = [];
				$plan['remote_hashes'][ $name ] = array_intersect_key( $remote, array_flip( array_merge( $d['push'], $d['delete'] ) ) );
				// inserts must exist nowhere on prod: a null expectation means "no row", and stale() flags one that appeared
				foreach ( $d['insert'] as $id ) $plan['remote_hashes'][ $name ][ $id ] = null;
				if ( $name === $wpdb->posts && $d['conflict'] ) {
					foreach ( $d['conflict'] as $id ) $plan['conflict_detail'][ $name ][ $id ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $id ) );
				}
			} else {
				$d = [ 'pk' => null, 'push' => [], 'insert' => [], 'delete' => [], 'conflict' => [], 'kept' => [], 'set_insert' => IXES_Differ::diff_set( $local, $remote )['insert'] ];
				// no primary key: --mirror deletes by row hash, which needs a remote that knows the delete_set step
				if ( $mirror ) {
					$gone = array_values( array_unique( array_diff( $remote, $local ) ) );
					if ( $gone && in_array( 'delete_set', (array) ( $info['caps'] ?? [] ), true ) ) $d['set_delete'] = $gone;
					elseif ( $gone ) $mirror_warn[] = "{$name} has no primary key and {$env['name']} runs a plugin older than 0.6.2: --mirror keeps the " . count( $gone ) . ' row(s) only it has';
				}
			}
			if ( $name === $wpdb->options ) {
				$base_ap = $two_way ? [] : self::option_from_baseline_or_local( 'active_plugins', $bl );
				$remote_ap = (array) ( $info['active_plugins'] ?? [] );
				$merged = IXES_Differ::merge_active_plugins( $base_ap, (array) get_option( 'active_plugins', [] ), $remote_ap );
				if ( array_values( $merged ) !== array_values( $remote_ap ) ) $plan['active_plugins'] = $merged;
			}
			if ( $d['push'] || $d['insert'] || $d['delete'] || $d['conflict'] || $d['kept'] || $d['set_insert'] || ! empty( $d['set_delete'] ) ) $plan['tables'][ $name ] = $d;
		}
		$plan['warnings'] = array_merge( $scope->family_warnings( $in_scope ), $mirror_warn, $drop_warn );

		$plan['files'] = [ 'push' => [], 'delete' => [], 'conflict' => [], 'kept' => [] ];
		$plan['remote_file_hashes'] = [];
		if ( $scope->files_wanted() ) {
			$remote_files = [];
			$r = $c->paged( '/hash/files', [ 'excludes' => $ex, 'algo' => $algo, 'limit' => 2000, 'roots' => $scope->roots() ], function ( $res ) use ( &$remote_files ) { $remote_files += $res['files']; }, 'cursor' );
			if ( is_wp_error( $r ) ) return $r;
			$remote_files = self::in_scope( IXES_Pull::drop_excluded( $remote_files, $ex ), $scope );
			$local_files  = self::in_scope( IXES_Transfer::local_manifest( $ex, $algo, $scope->roots() ), $scope );
			$base_files   = $two_way ? [] : self::in_scope( $bl->files(), $scope );
			$fd = IXES_Differ::diff( $base_files, $local_files, $remote_files );
			if ( $mirror ) $fd = IXES_Differ::mirror( $fd, $local_files, $remote_files );
			$plan['files'] = [ 'push' => array_merge( $fd['push'], $fd['insert'] ), 'delete' => $fd['delete'], 'conflict' => $fd['conflict'], 'kept' => $fd['kept'] ];
			foreach ( array_merge( $plan['files']['push'], $plan['files']['delete'] ) as $rel ) $plan['remote_file_hashes'][ $rel ] = $remote_files[ $rel ] ?? null;
		}
		return $plan;
	}

	/**
	 * Tables only the remote has, in scope: which ones the push drops there ($plan['drop_tables'], with the rows
	 * the remote must still hold for the drop to go ahead) and which stay as the remote's own ($plan['kept_tables']).
	 * @return string[]|WP_Error warnings
	 */
	private static function plan_drops( array &$plan, array $env, IXES_Client $c, array $info, IXES_Scope $scope, IXES_Baseline $bl, $mirror, array $drop, $algo, array $extra ) {
		global $wpdb;
		$local = array_flip( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) );
		$only = []; $foreign = [];
		$remote_names = array_column( $info['tables'], 'name' );
		foreach ( $info['tables'] as $t ) {
			$n = (string) $t['name'];
			if ( isset( $local[ $n ] ) || strpos( $n, $wpdb->prefix ) !== 0 || strpos( $n, $wpdb->prefix . 'ixes_' ) === 0 || ! preg_match( '/^[A-Za-z0-9_]+$/', $n ) || ! $scope->table_in( $n ) ) continue;
			// wp_old_posts, wp_2_options: another install sharing the database, never this sync's to drop
			if ( IXES_Droptable::other_install( $n, $wpdb->prefix, $remote_names ) !== null ) { $foreign[] = $n; continue; }
			$only[ $n ] = $t;
		}
		foreach ( $drop as $n ) {
			if ( ! isset( $only[ $n ] ) ) return new WP_Error( 'drop_tables', "--drop-tables: {$n} is not a table only {$env['name']} has, inside the scope" );
		}
		$two_way = ! $bl->exists();
		$sel = IXES_Differ::table_drops( $two_way ? [] : $bl->tables(), array_keys( $only ), $mirror, $drop );
		$plan['kept_tables'] = array_merge( $sel['kept'], $foreign );
		if ( ! $sel['drop'] ) return [];
		if ( ! in_array( 'drop_table', (array) ( $info['caps'] ?? [] ), true ) ) {
			return [ 'dropping ' . count( $sel['drop'] ) . " table(s) needs plugin 0.7.0 or newer on {$env['name']}; they stay: " . implode( ', ', array_keys( $sel['drop'] ) ) ];
		}
		$hashes = self::remote_hashes( $c, array_values( array_intersect_key( $only, $sel['drop'] ) ), (array) ( $info['caps'] ?? [] ), $algo, $extra );
		if ( is_wp_error( $hashes ) ) return $hashes;
		$warn = [];
		foreach ( $sel['drop'] as $n => $why ) {
			$pk = $only[ $n ]['pk'] ?? null;
			if ( ! isset( $hashes[ $n ] ) ) {
				$h = [];
				$r = $c->paged( '/hash/rows', [ 'table' => $n, 'algo' => $algo, 'extra' => $extra, 'limit' => self::BATCH_ROWS ], function ( $res ) use ( &$h, $pk ) { if ( $pk ) $h += $res['rows']; else $h = array_merge( $h, $res['rows'] ); } );
				if ( is_wp_error( $r ) ) return $r;
				$hashes[ $n ] = $h;
			}
			// this side dropped it since the baseline, but the remote wrote to it since: the remote's work wins
			if ( $why === 'baseline' ) {
				$base = $bl->rows( $n );
				$now  = $hashes[ $n ];
				if ( ! $pk ) { $base = array_values( array_unique( array_values( $base ) ) ); $now = array_values( array_unique( $now ) ); }
				if ( ! IXES_Droptable::same_rows( $now, $base, (bool) $pk ) ) { $warn[] = "{$n}: dropped here, but {$env['name']} changed it since the baseline, so it stays there"; continue; }
			}
			$plan['drop_tables'][ $n ] = [ 'why' => $why, 'pk' => $pk, 'rows' => count( $hashes[ $n ] ), 'digest' => IXES_Droptable::digest( $hashes[ $n ], (bool) $pk ) ];
		}
		return $warn;
	}

	const BATCH_ROWS   = 5000;
	const BATCH_TABLES = 50;

	/**
	 * Remote row hashes, keyed by table name, for the tables in $tables (the remote's info rows: name, pk, rows)
	 * that it counted empty, or small enough to batch when it can ('hash_batch'). Larger tables are left out
	 * and fetched one by one, so their hashes are never all held at once.
	 * @return array|WP_Error
	 */
	public static function remote_hashes( IXES_Client $c, array $tables, array $caps, $algo, array $extra ) {
		$out = []; $queue = []; $more = []; $has_pk = [];
		$batch = in_array( 'hash_batch', $caps, true );
		foreach ( $tables as $t ) {
			if ( ! isset( $t['rows'] ) ) continue;
			$n = (string) $t['name'];
			$has_pk[ $n ] = ! empty( $t['pk'] );
			if ( (int) $t['rows'] === 0 ) $out[ $n ] = [];
			elseif ( $batch && (int) $t['rows'] <= self::BATCH_ROWS ) $queue[] = $n;
		}
		while ( $queue ) {
			$ask = array_slice( $queue, 0, self::BATCH_TABLES );
			$res = $c->post( '/hash/tables', [ 'tables' => $ask, 'algo' => $algo, 'extra' => $extra, 'limit' => self::BATCH_ROWS ] );
			if ( is_wp_error( $res ) ) return $res;
			$got = array_intersect_key( (array) ( $res['tables'] ?? [] ), array_flip( $ask ) );
			// the remote always answers at least the first table it was asked for; anything else would loop forever
			if ( ! $got ) return new WP_Error( 'hash_batch', 'the remote answered a table batch without any of its tables' );
			foreach ( $got as $n => $r ) {
				$out[ $n ] = (array) ( $r['rows'] ?? [] );
				if ( isset( $r['next'] ) && $r['next'] !== null ) $more[ $n ] = $r['next'];
			}
			$queue = array_values( array_diff( $queue, array_keys( $got ) ) );
		}
		// a table the batch cut short continues page by page from where it stopped
		foreach ( $more as $n => $from ) {
			$pk = $has_pk[ $n ];
			$r = $c->paged( '/hash/rows', [ 'table' => $n, 'algo' => $algo, 'extra' => $extra, 'limit' => self::BATCH_ROWS, 'from' => $from ], function ( $res ) use ( &$out, $n, $pk ) { if ( $pk ) $out[ $n ] += $res['rows']; else $out[ $n ] = array_merge( $out[ $n ], $res['rows'] ); } );
			if ( is_wp_error( $r ) ) return $r;
		}
		return $out;
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
		foreach ( $plan['tables'] as $t ) if ( $t['push'] || $t['insert'] || $t['delete'] || $t['set_insert'] || ! empty( $t['set_delete'] ) ) return false;
		return empty( $plan['files']['push'] ) && empty( $plan['files']['delete'] ) && $plan['active_plugins'] === null && empty( $plan['drop_tables'] );
	}

	public static function render_text( array $plan ) {
		$o = [];
		$o[] = sprintf( '%s  ←  local          baseline: %s%s', $plan['env'], $plan['baseline_at'] ? wp_date( 'Y-m-d H:i T', $plan['baseline_at'] ) : 'NONE (2-way)', $plan['two_way'] ? "   !! everything different would OVERWRITE {$plan['env']}" : '' );
		if ( ! empty( $plan['scope'] ) ) {
			$sc = IXES_Scope::from_array( (array) $plan['scope'], '' );
			if ( ! $sc->is_full() ) $o[] = '  scope: ' . $sc->label();
		}
		$o[] = 'DB';
		foreach ( $plan['tables'] as $name => $t ) {
			$o[] = sprintf( '  %-32s push %-5d insert %-5d delete %-5d remote-wins %-5d kept-remote %d', $name, count( $t['push'] ) + count( $t['set_insert'] ), count( $t['insert'] ), count( $t['delete'] ) + count( $t['set_delete'] ?? [] ), count( $t['conflict'] ), count( $t['kept'] ) );
		}
		if ( $plan['active_plugins'] !== null ) $o[] = '  active_plugins  → ' . implode( ', ', $plan['active_plugins'] );
		foreach ( (array) ( $plan['drop_tables'] ?? [] ) as $name => $d ) $o[] = sprintf( '  %-32s DROP TABLE (%d rows, %s)', $name, $d['rows'], $d['why'] );
		$o[] = 'FILES';
		foreach ( [ 'push', 'delete', 'conflict', 'kept' ] as $k ) {
			$by = [];
			foreach ( $plan['files'][ $k ] as $rel ) { $dir = implode( '/', array_slice( explode( '/', $rel ), 0, 2 ) ) . '/'; $by[ $dir ] = ( $by[ $dir ] ?? 0 ) + 1; }
			foreach ( $by as $dir => $n ) $o[] = sprintf( '  %-40s %s %d', $dir, $k === 'kept' ? 'kept-remote' : ( $k === 'conflict' ? 'remote-wins' : $k ), $n );
		}
		$conf = [];
		foreach ( $plan['tables'] as $name => $t ) foreach ( $t['conflict'] as $pk ) $conf[] = sprintf( '  %-20s #%s  %s', $name, $pk, $plan['conflict_detail'][ $name ][ $pk ] ?? '' );
		foreach ( $plan['files']['conflict'] as $rel ) $conf[] = '  file                 ' . $rel;
		if ( $conf ) { $o[] = "CONFLICTS ({$plan['env']} wins)"; $o = array_merge( $o, $conf ); }
		if ( self::is_empty( $plan ) ) $o[] = 'Nothing to push.';
		return implode( "\n", $o ) . "\n";
	}

	public static function save( array $plan, $kind = 'diff' ) {
		$dir = ixes_storage_dir() . '/plans'; wp_mkdir_p( $dir );
		$stamp = date( 'Ymd-His', $plan['created'] ?? time() );
		$path  = $dir . '/plan-' . ( $kind === 'pull' ? 'pull-' : '' ) . $plan['env'] . '-' . $stamp . '.json';
		file_put_contents( $path, json_encode( $plan ) );
		return $path;
	}
}
