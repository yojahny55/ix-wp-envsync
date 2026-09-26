<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Applier {

	const LOCK = 'ixes_lock';
	const ORDER = [ 'users', 'usermeta', 'terms', 'term_taxonomy', 'posts', 'postmeta', 'term_relationships', 'termmeta', 'comments', 'commentmeta' ];
	const UNLOCK_MIN_AGE = 120; // seconds: a lock younger than this is a live push

	// Lock transient value is "job|started". 0.3 wrote the bare job id; parse_lock() accepts both.
	public static function lock_value( $job, $started ) { return $job . '|' . (int) $started; }
	public static function parse_lock( $value ) {
		if ( ! is_string( $value ) || $value === '' ) return [ 'job' => '', 'started' => null ];
		$parts = explode( '|', $value, 2 );
		return [ 'job' => $parts[0], 'started' => isset( $parts[1] ) ? (int) $parts[1] : null ];
	}
	public static function current_job() { return self::parse_lock( get_transient( self::LOCK ) )['job']; }
	/** @return array{job:string,started:int|null}|null */
	public static function lock_info() {
		$l = self::parse_lock( get_transient( self::LOCK ) );
		return $l['job'] === '' ? null : $l;
	}
	/** Whatever the hub sent, meta.json gets arrays where the admin page and rollback expect arrays. */
	public static function plan_meta_shape( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];
		$files = is_array( $raw['files'] ?? null ) ? $raw['files'] : [];
		return [
			'tables' => is_array( $raw['tables'] ?? null ) ? $raw['tables'] : [],
			'files'  => [ 'push' => (array) ( $files['push'] ?? [] ), 'delete' => (array) ( $files['delete'] ?? [] ) ],
		] + $raw;
	}

	private static function jobs_dir() { $d = ixes_storage_dir() . '/jobs'; wp_mkdir_p( $d ); return $d; }
	private static function job_dir( $job ) { $job = preg_replace( '/[^a-z0-9-]/', '', $job ); return $job ? self::jobs_dir() . '/' . $job : null; }
	private static function maintenance( $on ) {
		$f = ABSPATH . '.maintenance';
		if ( $on ) file_put_contents( $f, self::maintenance_body( time() ) );
		elseif ( file_exists( $f ) ) unlink( $f );
	}
	// core requires .maintenance before plugins load, so the exemptions have to live inside the file itself
	// (an $upgrading in the past means "not in maintenance"). Exempt: our own signed REST calls, and requests
	// from loopback -- a Docker/Coolify health check curls the site from inside the container, and a 503 there
	// gets the container marked unhealthy and pulled from the proxy mid-push, which kills the push itself.
	// ponytail: loopback also covers a same-host reverse proxy that forwards over 127.0.0.1; its visitors then
	// see the site during a push instead of the maintenance page. Harmless, and wp-config can set REMOTE_ADDR.
	public static function maintenance_body( $now ) {
		return "<?php\n\$upgrading = " . (int) $now . ";\n"
			. "\$ixes_uri = isset( \$_SERVER['REQUEST_URI'] ) ? \$_SERVER['REQUEST_URI'] : '';\n"
			. "if ( isset( \$_SERVER['HTTP_X_ENVSYNC_SIG'] ) && preg_match( '#^[^?]*(\\\\?rest_route=|/wp-json)/" . IXES_Rest::NS . "/#', \$ixes_uri ) ) \$upgrading = 1;\n"
			. "if ( isset( \$_SERVER['REMOTE_ADDR'] ) && in_array( \$_SERVER['REMOTE_ADDR'], array( '127.0.0.1', '::1' ), true ) ) \$upgrading = 1;\n";
	}
	private static function remote_pairs( array $extra = [] ) { return IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath(), $extra ); }

	// ---------- remote side ----------

	public static function job_start( array $p ) {
		global $wpdb;
		$p['plan_meta'] = self::plan_meta_shape( $p['plan_meta'] ?? null );
		if ( self::current_job() !== '' ) return new WP_Error( 'locked', 'another job running', [ 'status' => 423 ] );
		$job = date( 'Ymd-His' ) . '-' . substr( md5( uniqid() ), 0, 6 );
		set_transient( self::LOCK, self::lock_value( $job, time() ), HOUR_IN_SECONDS );
		$dir = self::job_dir( $job );
		wp_mkdir_p( $dir . '/files' );
		$meta = [ 'job' => $job, 'started' => time(), 'plan' => $p['plan_meta'], 'inserted' => [], 'created_files' => [] ];

		foreach ( (array) ( $p['plan_meta']['tables'] ?? [] ) as $table => $t ) {
			if ( ! IXES_Transfer::valid_table( $table ) ) continue;
			$ids = array_merge( (array) ( $t['touch'] ?? [] ), (array) ( $t['delete'] ?? [] ) );
			if ( ! $ids || empty( $t['pk'] ) ) continue;
			$pk = IXES_Transfer::safe_pk( $table, $t['pk'] );
			if ( ! $pk ) continue;
			$in = implode( ',', array_map( function ( $v ) { return "'" . esc_sql( $v ) . "'"; }, $ids ) );
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE `{$pk}` IN ({$in})", ARRAY_A );
			$found = array_map( function ( $r ) use ( $pk ) { return $r[ $pk ]; }, $rows );
			// touch ids that don't exist yet were inserted by this push and must be deleted, not restored, on rollback
			$meta['inserted'][ $table ] = [ 'pk' => $pk, 'ids' => array_values( array_diff( (array) ( $t['touch'] ?? [] ), $found ) ) ];
			file_put_contents( $dir . '/rows-' . $table . '.json', json_encode( [ 'pk' => $pk, 'rows' => $rows ] ) );
		}

		$files = array_unique( array_merge( (array) ( $p['plan_meta']['files']['push'] ?? [] ), (array) ( $p['plan_meta']['files']['delete'] ?? [] ) ) );
		foreach ( $files as $rel ) {
			$rel = IXES_Transfer::safe_rel( $rel );
			if ( ! $rel ) continue;
			$src = WP_CONTENT_DIR . '/' . $rel;
			if ( ! is_file( $src ) ) { $meta['created_files'][] = $rel; continue; }
			$dest = $dir . '/files/' . $rel;
			wp_mkdir_p( dirname( $dest ) );
			copy( $src, $dest );
		}

		file_put_contents( $dir . '/meta.json', json_encode( $meta ) );
		self::maintenance( true );
		return [ 'job' => $job ];
	}

	// $expect maps id => hash the hub saw on prod, or null for "no row here yet" (an insert)
	private static function stale( $table, $pk, array $expect, $algo, array $extra = [] ) {
		global $wpdb;
		$stale = [];
		$pairs = self::remote_pairs( $extra );
		$map = IXES_Prefix::current();
		foreach ( $expect as $id => $h ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` = %s", $id ), ARRAY_A );
			// the hub hashed this row in its own prefix; an orphan is invisible to it, so it is "no row"
			if ( $row && $map ) $row = $map->row_out( $map->bare( $table ), $row );
			// an excluded option (transient, cron, siteurl...) is invisible to the planner, so it is "no row" here too
			if ( $row && self::excluded_option_row( $table, $row ) ) $row = null;
			$cur = $row ? IXES_Hasher::hash_row( $row, $pairs, $algo ) : null;
			if ( $cur !== $h ) $stale[] = $id;
		}
		return $stale;
	}

	private static function excluded_option_row( $table, array $row ) {
		global $wpdb;
		return $table === $wpdb->options && isset( $row['option_name'] ) && IXES_Env::option_excluded( $row['option_name'] );
	}

	/**
	 * Option ids are per-site counters: a fresh remote hands the ids a hub row uses to its own transients.
	 * When the incoming id belongs to an excluded option there, drop the id and let option_name (UNIQUE) place the row.
	 * @return bool true when the row was re-keyed by name
	 */
	public static function rekey_option( array &$row, $holder_name ) {
		if ( $holder_name === null || $holder_name === ( $row['option_name'] ?? null ) || ! IXES_Env::option_excluded( $holder_name ) ) return false;
		unset( $row['option_id'] );
		return true;
	}

	// read-modify-write meta.json once per step (not per row) to record no-PK rows this step inserted, so rollback can delete them
	private static function record_set_inserted( $job, $table, array $rows ) {
		$dir = self::job_dir( $job );
		if ( ! $dir || ! is_file( $dir . '/meta.json' ) ) return;
		$meta = json_decode( file_get_contents( $dir . '/meta.json' ), true );
		if ( ! is_array( $meta ) ) $meta = [ 'set_inserted' => [] ];
		$meta['set_inserted'][ $table ] = array_merge( (array) ( $meta['set_inserted'][ $table ] ?? [] ), $rows );
		file_put_contents( $dir . '/meta.json', json_encode( $meta ) );
	}

	private static function record_meta( $job, $key, $value ) {
		$dir = self::job_dir( $job );
		if ( ! $dir || ! is_file( $dir . '/meta.json' ) ) return;
		$meta = json_decode( file_get_contents( $dir . '/meta.json' ), true );
		if ( ! is_array( $meta ) ) return;
		$meta[ $key ][] = $value;
		file_put_contents( $dir . '/meta.json', json_encode( $meta ) );
	}

	/**
	 * A push may create a table the remote lacks (a plugin's own tables on a first deploy).
	 * Only one plain CREATE TABLE for exactly that name, under this site's prefix, for a table that does not exist yet.
	 * @return string|null why it is refused
	 */
	public static function create_table_refusal( $table, $sql, $prefix, $exists ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || strpos( $table, $prefix ) !== 0 ) return 'table name refused';
		if ( strpos( $table, $prefix . 'ixes_' ) === 0 ) return 'table name refused';
		if ( $exists ) return 'table already exists';
		if ( ! preg_match( '/^CREATE TABLE `' . preg_quote( $table, '/' ) . '` \(/', $sql ) ) return 'not a CREATE TABLE for that table';
		if ( strpos( $sql, ';' ) !== false ) return 'one statement only';
		if ( preg_match( '/\b(SELECT|DATA\s+DIRECTORY|INDEX\s+DIRECTORY|UNION)\b/i', $sql ) ) return 'unsupported table option';
		return null;
	}

	// same read-modify-write as record_set_inserted: keep the value the option had before this job touched it
	private static function record_option_before( $job, $name ) {
		$dir = self::job_dir( $job );
		if ( ! $dir || ! is_file( $dir . '/meta.json' ) ) return;
		$meta = json_decode( file_get_contents( $dir . '/meta.json' ), true );
		if ( ! is_array( $meta ) ) return;
		if ( isset( $meta['options_before'] ) && array_key_exists( $name, (array) $meta['options_before'] ) ) return;
		$meta['options_before'][ $name ] = get_option( $name, null );
		file_put_contents( $dir . '/meta.json', json_encode( $meta ) );
	}

	// true when the local file is exactly what the hub expected: $expect null means "must not exist here"
	private static function file_matches( $rel, $expect, $algo ) {
		$rel = IXES_Transfer::safe_rel( $rel );
		if ( ! $rel ) return false;
		$abs = WP_CONTENT_DIR . '/' . $rel;
		if ( ! is_file( $abs ) ) return $expect === null || $expect === false;
		if ( $expect === null || ! in_array( (string) $algo, hash_algos(), true ) ) return false;
		return hash_file( (string) $algo, $abs ) === $expect;
	}

	public static function job_step( array $p ) {
		global $wpdb;
		if ( self::current_job() !== (string) ( $p['job'] ?? '' ) ) return new WP_Error( 'nojob', 'job not active', [ 'status' => 409 ] );
		$kind = $p['kind'] ?? '';

		if ( $kind === 'rows' || $kind === 'delete_rows' ) {
			$table = sanitize_text_field( $p['table'] );
			if ( ! IXES_Transfer::valid_table( $table ) ) return new WP_Error( 'bad_table', 'unknown table', [ 'status' => 400 ] );
			$pk = ( $p['pk'] ?? null ) === null || $p['pk'] === '' ? null : IXES_Transfer::safe_pk( $table, $p['pk'] );
			if ( ( $p['pk'] ?? null ) !== null && $p['pk'] !== '' && ! $pk ) return new WP_Error( 'bad_pk', 'unknown primary key column', [ 'status' => 400 ] );
			$algo = $p['algo'] ?? 'sha1';
			$extra = array_map( 'strval', array_values( (array) ( $p['extra'] ?? [] ) ) );
			$stale = $pk ? self::stale( $table, $pk, (array) ( $p['expect'] ?? [] ), $algo, $extra ) : [];
			$skip = array_flip( $stale );
			$refused = array_values( (array) ( $p['prefix_refused'] ?? [] ) );
			$is_options = ( $table === $wpdb->options );

			if ( $kind === 'rows' ) {
				$inserted_rows = [];
				foreach ( (array) $p['rows'] as $row ) {
					if ( $pk && isset( $skip[ $row[ $pk ] ] ) ) continue;
					if ( $is_options && isset( $row['option_name'] ) && IXES_Env::option_excluded( $row['option_name'] ) ) { $refused[] = $row['option_name']; continue; }
					foreach ( $row as $k => $v ) if ( $v !== null ) $row[ $k ] = IXES_Hasher::normalize( $v, (array) $p['pairs'] );
					if ( ! $pk ) {
						if ( $wpdb->insert( $table, $row ) ) $inserted_rows[] = $row;
						continue;
					}
					if ( $is_options && isset( $row['option_id'] ) ) {
						$holder = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM `{$table}` WHERE option_id = %d", $row['option_id'] ) );
						if ( self::rekey_option( $row, $holder ) ) {
							$had = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM `{$table}` WHERE option_name = %s", $row['option_name'] ) );
							if ( ! $had ) self::record_meta( $p['job'], 'inserted_option_names', $row['option_name'] );
						}
					}
					$wpdb->replace( $table, $row );
				}
				if ( $inserted_rows ) self::record_set_inserted( $p['job'], $table, $inserted_rows );
			} else {
				$ids = array_values( array_diff( (array) $p['ids'], $stale ) );
				if ( $is_options && $ids ) {
					$in = implode( ',', array_map( function ( $v ) { return "'" . esc_sql( $v ) . "'"; }, $ids ) );
					$named = $wpdb->get_results( "SELECT `{$pk}` AS ixes_pk, option_name FROM `{$table}` WHERE `{$pk}` IN ({$in})", ARRAY_A );
					$bad = [];
					foreach ( $named as $row ) if ( IXES_Env::option_excluded( $row['option_name'] ) ) { $refused[] = $row['option_name']; $bad[] = $row['ixes_pk']; }
					if ( $bad ) $ids = array_values( array_diff( $ids, $bad ) );
				}
				if ( $ids ) {
					$in = implode( ',', array_map( function ( $v ) { return "'" . esc_sql( $v ) . "'"; }, $ids ) );
					$wpdb->query( "DELETE FROM `{$table}` WHERE `{$pk}` IN ({$in})" );
				}
			}
			return [ 'stale' => $stale, 'refused' => $refused ];
		}

		if ( $kind === 'file' ) {
			$rel = (string) ( $p['path'] ?? '' );
			// prod wins: refuse the whole file if its current content is not what the plan saw (null expect = must not exist)
			if ( (int) ( $p['offset'] ?? 0 ) === 0 && array_key_exists( 'expect', $p ) && ! self::file_matches( $rel, $p['expect'], $p['algo'] ?? 'sha1' ) ) {
				return [ 'ok' => false, 'refused' => [ $rel ] ];
			}
			$bytes = array_key_exists( 'bin', $p ) ? (string) $p['bin'] : base64_decode( (string) ( $p['data'] ?? '' ) );
			$r = IXES_Transfer::write_file_chunk( $rel, (int) ( $p['offset'] ?? 0 ), $bytes, ! empty( $p['final'] ), (string) ( $p['sha256'] ?? '' ) );
			if ( is_wp_error( $r ) ) return $r;
			return [ 'ok' => true ];
		}

		if ( $kind === 'files' ) {
			$items = IXES_Batch::decode( (string) ( $p['bin'] ?? '' ) );
			if ( is_wp_error( $items ) ) return $items;
			$refused = [];
			foreach ( $items as $it ) {
				list( $m, $bytes ) = $it;
				$rel = (string) $m['path'];
				// a retried batch finds files it already wrote: identical content is done, not a conflict
				if ( self::file_matches( $rel, (string) ( $m['sha256'] ?? '' ), 'sha256' ) ) continue;
				if ( array_key_exists( 'expect', $m ) && ! self::file_matches( $rel, $m['expect'], $m['algo'] ?? 'sha1' ) ) { $refused[] = $rel; continue; }
				$r = IXES_Transfer::write_file_chunk( $rel, 0, $bytes, true, (string) ( $m['sha256'] ?? '' ) );
				if ( is_wp_error( $r ) ) return $r;
			}
			return [ 'ok' => true, 'refused' => $refused ];
		}

		if ( $kind === 'create_table' ) {
			$table = (string) ( $p['table'] ?? '' ); $sql = (string) ( $p['sql'] ?? '' );
			$why = self::create_table_refusal( $table, $sql, $wpdb->prefix, IXES_Transfer::valid_table( $table ) );
			if ( $why ) return new WP_Error( 'bad_create', $why, [ 'status' => 400 ] );
			self::record_meta( $p['job'], 'created_tables', $table );
			if ( $wpdb->query( $sql ) === false ) return new WP_Error( 'create_failed', "cannot create {$table}: {$wpdb->last_error}", [ 'status' => 500 ] );
			return [ 'ok' => true ];
		}

		if ( $kind === 'delete_set' ) {
			// push --mirror on a table without a primary key: delete the rows whose hash the hub listed, keep them for rollback
			$table = sanitize_text_field( $p['table'] ?? '' );
			if ( ! IXES_Transfer::valid_table( $table ) || $table === $wpdb->options ) return new WP_Error( 'bad_table', 'unknown table', [ 'status' => 400 ] );
			$want  = array_flip( array_map( 'strval', (array) ( $p['hashes'] ?? [] ) ) );
			$pairs = self::remote_pairs( array_map( 'strval', array_values( (array) ( $p['extra'] ?? [] ) ) ) );
			$algo  = $p['algo'] ?? 'sha1';
			$map   = IXES_Prefix::current();
			$gone  = []; $off = 0;
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", 5000, $off ), ARRAY_A );
				foreach ( $rows as $row ) {
					// hash the row as the hub saw it: in the hub's prefix
					$seen = $map ? $map->row_out( $map->bare( $table ), $row ) : $row;
					if ( $seen !== null && isset( $want[ IXES_Hasher::hash_row( $seen, $pairs, $algo ) ] ) ) $gone[] = $row;
				}
				$off += 5000;
			} while ( count( $rows ) === 5000 );
			if ( ! $gone ) return [ 'ok' => true, 'deleted' => 0 ];
			// rollback reads this file; if the rows cannot be kept (bad encoding, disk), delete nothing
			$dir  = self::job_dir( $p['job'] );
			$file = $dir ? $dir . '/setdel-' . $table . '.json' : null;
			$keep = function ( array $rows ) use ( $file ) { $j = json_encode( $rows ); return $j !== false && $file && file_put_contents( $file, $j ) !== false; };
			if ( ! $keep( $gone ) ) return new WP_Error( 'snapshot_failed', "cannot keep the {$table} rows for rollback; nothing deleted", [ 'status' => 500 ] );
			$done = [];
			foreach ( $gone as $row ) {
				// exact bytes and one row per match: the table's collation would also match rows that differ in case or trailing spaces
				$where = []; $vals = [];
				foreach ( $row as $col => $v ) {
					if ( $v === null ) { $where[] = "`{$col}` IS NULL"; continue; }
					$where[] = "BINARY `{$col}` = %s"; $vals[] = $v;
				}
				$sql = "DELETE FROM `{$table}` WHERE " . implode( ' AND ', $where ) . ' LIMIT 1';
				if ( $wpdb->query( $vals ? $wpdb->prepare( $sql, $vals ) : $sql ) ) $done[] = $row;
			}
			$keep( $done );
			return [ 'ok' => true, 'deleted' => count( $done ) ];
		}

		if ( $kind === 'drop_check' ) {
			// before the hub copies and drops anything: what would stop each drop, and the definition to copy
			$out = [];
			$tables = array_map( function ( $t ) { return sanitize_text_field( (string) $t ); }, array_values( (array) ( $p['tables'] ?? [] ) ) );
			$blocked = IXES_Droptable::blockers( array_values( array_filter( $tables, [ 'IXES_Transfer', 'valid_table' ] ) ) );
			foreach ( $tables as $table ) {
				if ( ! IXES_Transfer::valid_table( $table ) ) { $out[] = [ 'table' => $table, 'blocked' => [ 'unknown table' ], 'create' => null ]; continue; }
				$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
				$out[] = [ 'table' => $table, 'blocked' => $blocked[ $table ] ?? [], 'create' => $create ? $create[1] : null ];
			}
			return [ 'results' => $out ];
		}

		if ( $kind === 'drop_table' ) {
			// the data checks run again here, not trusted from drop_check: the hub spent time copying in between.
			// The code scan does not: code arrives by deploy, not in the seconds between the two steps
			$table = sanitize_text_field( (string) ( $p['table'] ?? '' ) );
			if ( ! IXES_Transfer::valid_table( $table ) ) return [ 'ok' => false, 'refused' => 'unknown table' ];
			$blocked = IXES_Droptable::blockers( [ $table ], false )[ $table ] ?? [];
			if ( $blocked ) return [ 'ok' => false, 'refused' => implode( '; ', $blocked ) ];
			$pk  = IXES_Transfer::pk_of( $table );
			$now = IXES_Droptable::hashes( $table, self::remote_pairs( array_map( 'strval', array_values( (array) ( $p['extra'] ?? [] ) ) ) ), $p['algo'] ?? 'sha1' );
			if ( is_wp_error( $now ) ) return $now;
			if ( IXES_Droptable::digest( $now, (bool) $pk ) !== (string) ( $p['digest'] ?? '' ) ) return [ 'ok' => false, 'refused' => 'changed since the plan' ];
			$dir  = self::job_dir( (string) $p['job'] );
			$snap = $dir ? IXES_Droptable::snapshot_to( $table, $dir . '/drop-' . $table . '.jsonl' ) : new WP_Error( 'nojob', 'no job folder' );
			if ( is_wp_error( $snap ) ) return [ 'ok' => false, 'refused' => 'no copy for rollback: ' . $snap->get_error_message() ];
			self::record_meta( $p['job'], 'dropped_tables', $table );
			if ( $wpdb->query( "DROP TABLE `{$table}`" ) === false ) return [ 'ok' => false, 'refused' => "DROP failed: {$wpdb->last_error}" ];
			return [ 'ok' => true, 'rows' => $snap['rows'] ];
		}

		if ( $kind === 'delete_files' ) {
			$expect  = (array) ( $p['expect'] ?? [] );
			$algo    = $p['algo'] ?? 'sha1';
			$refused = [];
			foreach ( (array) $p['paths'] as $rel ) {
				if ( $expect && ! self::file_matches( $rel, $expect[ $rel ] ?? null, $algo ) ) { $refused[] = $rel; continue; }
				IXES_Transfer::delete_file( $rel );
			}
			return [ 'ok' => true, 'refused' => $refused ];
		}

		if ( $kind === 'option' ) {
			if ( IXES_Env::option_excluded( $p['name'] ) ) return new WP_Error( 'refused', 'option excluded' );
			self::record_option_before( $p['job'] ?? '', $p['name'] );
			update_option( $p['name'], $p['value'] );
			return [ 'ok' => true ];
		}

		return new WP_Error( 'bad_kind', 'unknown step' );
	}

	private static function raw_lock() {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . self::LOCK ) );
		return self::parse_lock( $v === null ? false : $v );
	}

	private static function last_job() {
		$jobs = glob( self::jobs_dir() . '/*', GLOB_ONLYDIR ); sort( $jobs );
		return $jobs ? basename( end( $jobs ) ) : null;
	}

	public static function rescue_status() {
		$l = self::raw_lock();
		return [ 'ok' => true, 'plugin' => IXES_VERSION, 'active_plugins' => array_values( (array) get_option( 'active_plugins', [] ) ),
			'lock' => $l['job'] === '' ? null : $l, 'maintenance' => file_exists( ABSPATH . '.maintenance' ), 'last_job' => self::last_job() ];
	}

	/** Keep only EnvSync active; the previous list is kept in ixes_rescue_plugins_before. */
	public static function rescue_plugins_off() {
		// folder/file, not plugin_basename(): plugins are not loaded here, so a symlinked plugin is not registered and would resolve wrongly
		$keep = basename( dirname( IXES_FILE ) ) . '/' . basename( IXES_FILE );
		$before = array_values( (array) get_option( 'active_plugins', [] ) );
		if ( $before !== [ $keep ] ) update_option( 'ixes_rescue_plugins_before', $before, false );
		update_option( 'active_plugins', [ $keep ] );
		return [ 'ok' => true, 'deactivated' => array_values( array_diff( $before, [ $keep ] ) ) ];
	}

	/** Roll back $job (default: the locked job, else the last one), then drop the lock and the maintenance file. */
	public static function rescue_rollback( $job = null ) {
		global $wpdb;
		$l = self::raw_lock();
		$job = $job ?: ( $l['job'] !== '' ? $l['job'] : self::last_job() );
		if ( ! $job ) return new WP_Error( 'nojob', 'no push job to roll back', [ 'status' => 404 ] );
		$r = self::rollback( [ 'job' => $job ] );
		self::maintenance( false );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", '_transient_' . self::LOCK, '_transient_timeout_' . self::LOCK ) );
		return $r;
	}

	/**
	 * Run one remote call; on failure ask $on_error what to do: retry | plugins_off (then retry) | rollback | leave.
	 * No $on_error (agents, --yes) means rollback. $choice receives the final answer.
	 */
	public static function attempt( callable $op, callable $on_error = null, callable $plugins_off = null, &$choice = null ) {
		while ( true ) {
			$r = $op();
			if ( ! is_wp_error( $r ) ) return $r;
			$choice = $on_error ? $on_error( $r ) : 'rollback';
			if ( $choice === 'retry' ) continue;
			if ( $choice === 'plugins_off' && $plugins_off ) { $plugins_off(); continue; }
			return $r;
		}
	}

	public static function job_finish( array $p ) {
		if ( self::current_job() !== (string) ( $p['job'] ?? '' ) ) return new WP_Error( 'nojob', 'job not active', [ 'status' => 409 ] );
		wp_cache_flush(); flush_rewrite_rules();
		self::maintenance( false );
		delete_transient( self::LOCK );
		$jobs = glob( self::jobs_dir() . '/*', GLOB_ONLYDIR ); sort( $jobs );
		foreach ( array_slice( $jobs, 0, max( 0, count( $jobs ) - 3 ) ) as $old ) self::rrmdir( $old );
		return [ 'ok' => true ];
	}

	public static function job_abort( array $p ) {
		$r = self::rollback( $p );
		self::maintenance( false );
		delete_transient( self::LOCK );
		return $r;
	}

	public static function job_unlock( array $p ) {
		$l = self::lock_info();
		if ( ! $l ) return new WP_Error( 'nolock', 'no push is locked', [ 'status' => 404 ] );
		$age = $l['started'] ? time() - $l['started'] : null;
		if ( $age !== null && $age < self::UNLOCK_MIN_AGE ) return new WP_Error( 'too_recent', "lock is only {$age}s old; a push may still be running", [ 'status' => 409 ] );
		self::maintenance( false );
		delete_transient( self::LOCK );
		return [ 'ok' => true, 'job' => $l['job'], 'age_minutes' => $age === null ? null : (int) floor( $age / 60 ) ];
	}

	public static function rollback( array $p ) {
		global $wpdb;
		$job = $p['job'] ?? null;
		if ( ! $job ) {
			$jobs = glob( self::jobs_dir() . '/*', GLOB_ONLYDIR ); sort( $jobs );
			$job = $jobs ? basename( end( $jobs ) ) : null;
		}
		$dir = $job ? self::job_dir( $job ) : null;
		if ( ! $dir || ! is_file( $dir . '/meta.json' ) ) return new WP_Error( 'nojob', 'no such job', [ 'status' => 404 ] );
		$meta = json_decode( file_get_contents( $dir . '/meta.json' ), true );
		if ( ! is_array( $meta ) ) return new WP_Error( 'bad_meta', 'job meta unreadable', [ 'status' => 500 ] );
		$n = 0;

		// tables the push dropped come back first, so the row restores below find them
		$errors = [];
		foreach ( array_unique( (array) ( $meta['dropped_tables'] ?? [] ) ) as $table ) {
			$r = IXES_Droptable::restore_file( $dir . '/drop-' . preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table ) . '.jsonl' );
			if ( is_wp_error( $r ) ) $errors[] = $r->get_error_message(); else $n++;
		}

		foreach ( glob( $dir . '/rows-*.json' ) as $f ) {
			$table = substr( basename( $f ), strlen( 'rows-' ), -5 ); // strip 'rows-' prefix and '.json' suffix
			if ( ! IXES_Transfer::valid_table( $table ) ) continue;
			$snap = json_decode( file_get_contents( $f ), true );
			if ( ! is_array( $snap ) ) continue;
			foreach ( (array) ( $snap['rows'] ?? [] ) as $row ) { $wpdb->replace( $table, $row ); $n++; }
		}
		foreach ( (array) ( $meta['inserted'] ?? [] ) as $table => $i ) {
			if ( ! IXES_Transfer::valid_table( $table ) || empty( $i['ids'] ) ) continue;
			$pk = IXES_Transfer::safe_pk( $table, $i['pk'] ?? '' );
			if ( ! $pk ) continue;
			$wpdb->query( "DELETE FROM `{$table}` WHERE `{$pk}` IN (" . implode( ',', array_map( function ( $v ) { return "'" . esc_sql( $v ) . "'"; }, $i['ids'] ) ) . ')' );
		}
		foreach ( (array) ( $meta['options_before'] ?? [] ) as $name => $val ) { update_option( $name, $val ); $n++; }
		foreach ( (array) ( $meta['set_inserted'] ?? [] ) as $table => $rows ) {
			if ( ! IXES_Transfer::valid_table( $table ) ) continue;
			foreach ( (array) $rows as $row ) { $wpdb->delete( $table, $row ); $n++; }
		}
		foreach ( glob( $dir . '/setdel-*.json' ) ?: [] as $f ) {
			$table = substr( basename( $f, '.json' ), 7 );
			if ( ! IXES_Transfer::valid_table( $table ) ) continue;
			foreach ( (array) json_decode( (string) file_get_contents( $f ), true ) as $row ) if ( is_array( $row ) ) { $wpdb->insert( $table, $row ); $n++; }
		}

		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir . '/files', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() ) {
				$rel = substr( $f->getPathname(), strlen( $dir . '/files/' ) );
				wp_mkdir_p( dirname( WP_CONTENT_DIR . '/' . $rel ) );
				copy( $f->getPathname(), WP_CONTENT_DIR . '/' . $rel );
				$n++;
			}
		}
		foreach ( (array) ( $meta['created_files'] ?? [] ) as $rel ) IXES_Transfer::delete_file( $rel );
		foreach ( (array) ( $meta['inserted_option_names'] ?? [] ) as $name ) { $wpdb->delete( $wpdb->options, [ 'option_name' => (string) $name ] ); $n++; }
		foreach ( (array) ( $meta['created_tables'] ?? [] ) as $table ) {
			if ( IXES_Transfer::valid_table( $table ) && ! self::create_table_refusal( $table, "CREATE TABLE `{$table}` (", $wpdb->prefix, false ) ) { $wpdb->query( "DROP TABLE `{$table}`" ); $n++; }
		}
		wp_cache_flush();
		return [ 'restored' => $n, 'job' => $job, 'errors' => $errors ];
	}

	private static function rrmdir( $d ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $d );
	}

	// ---------- hub side ----------

	/**
	 * Streams a remote table into a .sql file on the hub, page by page: $name in the hub's names for /dump,
	 * $remote_name and $create as the remote reported them, so the file reloads on the remote as it was.
	 * @return string|WP_Error the file
	 */
	private static function copy_remote_table( IXES_Client $c, $name, $remote_name, $create, $file ) {
		if ( ! wp_mkdir_p( dirname( $file ) ) || ! ( $h = @fopen( $file, 'wb' ) ) ) return new WP_Error( 'backup_failed', "cannot write {$file}" );
		$ok = true;
		$put = function ( $s ) use ( $h, &$ok ) { if ( $ok && fwrite( $h, $s ) !== strlen( $s ) ) $ok = false; };
		$put( IXES_Droptable::sql_head( $remote_name, $create ) );
		$r = $c->paged( '/dump', [ 'table' => $name, 'limit' => 5000 ], function ( $res ) use ( $put, $remote_name ) { if ( $res['rows'] ) $put( IXES_Droptable::sql_insert( $remote_name, (array) $res['rows'] ) ); } );
		if ( ! fclose( $h ) ) $ok = false;
		if ( is_wp_error( $r ) || ! $ok ) { @unlink( $file ); return is_wp_error( $r ) ? $r : new WP_Error( 'backup_failed', "cannot write {$file}" ); }
		return $file;
	}

	public static function apply( array $env, IXES_Client $c, array $plan, callable $log, IXES_Progress $progress = null, callable $on_error = null ) {
		global $wpdb;
		$progress = $progress ?: new IXES_Progress( 'verbose', $log );
		$info = $c->info();
		if ( is_wp_error( $info ) ) return $info;

		$pairs = [
			[ IXES_Env::local_url(), untrailingslashit( $info['url'] ) ],
			[ str_replace( '/', '\/', IXES_Env::local_url() ), str_replace( '/', '\/', untrailingslashit( $info['url'] ) ) ],
			[ IXES_Env::local_abspath(), untrailingslashit( $info['abspath'] ) ],
		];
		foreach ( (array) $env['extra_replace'] as $x ) $pairs[] = [ $x[1], $x[0] ];
		// last: scheme-full urls are already rewritten by now, so this only catches //host references
		$bare = function ( $u ) { return preg_replace( '#^https?://#', '', untrailingslashit( $u ) ); };
		$pairs[] = [ '//' . $bare( IXES_Env::local_url() ), '//' . $bare( $info['url'] ) ];
		list( $extra_prod, $extra_local ) = IXES_Env::extras( $env );
		$local_pairs = IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath(), $extra_local );

		$touch = [];
		foreach ( $plan['tables'] as $name => $t ) {
			$touch[ $name ] = [ 'pk' => $t['pk'], 'touch' => array_merge( (array) $t['push'], (array) $t['insert'] ), 'delete' => (array) $t['delete'] ];
		}
		$plan_meta = [ 'env' => $env['name'], 'created' => $plan['created'], 'tables' => $touch, 'files' => [ 'push' => $plan['files']['push'], 'delete' => $plan['files']['delete'] ] ];

		$caps = $c->caps();
		if ( ! empty( $plan['new_tables'] ) && ! in_array( 'create_table', $caps, true ) ) {
			return new WP_Error( 'old_remote', 'this push creates ' . count( $plan['new_tables'] ) . ' table(s) the remote lacks (' . implode( ', ', array_keys( $plan['new_tables'] ) ) . "); upload plugin 0.5.1 or newer to {$env['url']} first" );
		}
		$drops = (array) ( $plan['drop_tables'] ?? [] );
		if ( $drops && ! in_array( 'drop_table', $caps, true ) ) {
			return new WP_Error( 'old_remote', 'this push drops ' . count( $drops ) . " table(s); upload plugin 0.7.0 or newer to {$env['url']} first" );
		}
		// how the site answers before anything changes: a drop is blamed only for what it breaks
		$smoke_urls = $drops ? IXES_Droptable::smoke_urls( $info['url'], (string) time() ) : [];
		$smoke_before = $drops ? IXES_Droptable::smoke( $smoke_urls, [ $c, 'probe' ] ) : [];
		$start = $c->post( '/job/start', [ 'plan_meta' => $plan_meta ] );
		if ( is_wp_error( $start ) ) return $start;
		$job = $start['job'];

		$stale = [];
		$choice = null;
		$plugins_off = function () use ( $c, $progress ) {
			$x = $c->rescue( 'plugins_off' );
			$progress->note( is_wp_error( $x ) ? 'could not switch plugins off: ' . $x->get_error_message() : 'switched off on the remote: ' . implode( ', ', (array) $x['deactivated'] ) );
		};
		$call = function ( callable $op ) use ( $on_error, $plugins_off, &$choice ) { return self::attempt( $op, $on_error, $plugins_off, $choice ); };
		$fail = function ( WP_Error $err ) use ( $c, $job, $env, $progress, &$choice ) {
			$progress->end();
			$msg = $err->get_error_message();
			if ( $choice === 'leave' ) return new WP_Error( $err->get_error_code(), "{$msg}\nLeft as is: job {$job} still holds the lock on {$env['name']}. Next: wp envsync status {$env['name']}" );
			$a = $c->post( '/job/abort', [ 'job' => $job ] );
			if ( ! is_wp_error( $a ) ) return new WP_Error( $err->get_error_code(), "{$msg}\nRolled back job {$job}." );
			$x = $c->rescue( 'rollback', [ 'job' => $job ] );
			if ( ! is_wp_error( $x ) ) return new WP_Error( $err->get_error_code(), "{$msg}\nThe remote could not roll back normally (" . $a->get_error_message() . "); rolled back job {$job} through the rescue endpoint." );
			return new WP_Error( $err->get_error_code(), "{$msg}\nRollback failed (" . $a->get_error_message() . ') and so did the rescue endpoint (' . $x->get_error_message() . "). Next: wp envsync rescue {$env['name']}" );
		};

		// files first
		$file_hashes = (array) ( $plan['remote_file_hashes'] ?? [] );
		$present = array_values( array_filter( $plan['files']['push'], function ( $rel ) { return is_file( WP_CONTENT_DIR . '/' . $rel ); } ) );
		$progress->stage( 'Files', array_sum( array_map( function ( $rel ) { return (int) filesize( WP_CONTENT_DIR . '/' . $rel ); }, $present ) ), count( $present ) );
		$on_bytes = function ( $b ) use ( $progress ) { $progress->bytes( $b ); };
		$meta_for = function ( $rel ) use ( $file_hashes, $plan ) { return array_key_exists( $rel, $file_hashes ) ? [ 'expect' => $file_hashes[ $rel ], 'algo' => $plan['algo'] ] : []; };
		$sizes = [];
		foreach ( $present as $rel ) $sizes[ $rel ] = (int) filesize( WP_CONTENT_DIR . '/' . $rel );
		$packed = in_array( 'batch', $caps, true ) ? IXES_Batch::pack( $sizes ) : [ 'batches' => [], 'large' => $present ];
		foreach ( $packed['batches'] as $batch ) {
			$items = [];
			foreach ( $batch as $rel ) {
				$data = (string) file_get_contents( WP_CONTENT_DIR . '/' . $rel );
				$items[] = [ [ 'path' => $rel, 'sha256' => hash( 'sha256', $data ) ] + $meta_for( $rel ), $data ];
			}
			$r = $call( function () use ( $c, $job, $items ) { return $c->send_batch( $job, $items ); } );
			if ( is_wp_error( $r ) ) return $fail( $r );
			$refused = array_flip( (array) ( $r['refused'] ?? [] ) );
			foreach ( $batch as $rel ) {
				$progress->bytes( $sizes[ $rel ] );
				if ( isset( $refused[ $rel ] ) ) { $stale[] = "file: {$rel}"; continue; }
				$progress->item( $rel );
			}
		}
		foreach ( $packed['large'] as $rel ) {
			$r = $call( function () use ( $c, $job, $rel, $meta_for, $on_bytes ) { return $c->send_file( $job, $rel, WP_CONTENT_DIR . '/' . $rel, $meta_for( $rel ), $on_bytes ); } );
			if ( is_wp_error( $r ) ) return $fail( $r );
			if ( $r['refused'] ) { $stale[] = "file: {$rel}"; continue; }
			$progress->item( $rel );
		}
		$progress->end();
		if ( $plan['files']['delete'] ) {
			$expect = array_intersect_key( $file_hashes, array_flip( $plan['files']['delete'] ) );
			$step = [ 'job' => $job, 'kind' => 'delete_files', 'paths' => $plan['files']['delete'], 'expect' => $expect, 'algo' => $plan['algo'] ];
			$r = $call( function () use ( $c, $step ) { return $c->step( $step ); } );
			if ( is_wp_error( $r ) ) return $fail( $r );
			foreach ( (array) ( $r['refused'] ?? [] ) as $ref ) $stale[] = "file: {$ref}";
		}

		// tables in FK-safe order, unknown tables after, options last
		$ordered = [];
		foreach ( self::ORDER as $k ) if ( isset( $plan['tables'][ $wpdb->prefix . $k ] ) ) $ordered[] = $wpdb->prefix . $k;
		foreach ( array_keys( $plan['tables'] ) as $n ) if ( ! in_array( $n, $ordered, true ) && $n !== $wpdb->options ) $ordered[] = $n;
		if ( isset( $plan['tables'][ $wpdb->options ] ) ) $ordered[] = $wpdb->options;

		$progress->stage( 'Database', null, count( $ordered ) + ( $plan['active_plugins'] !== null ? 1 : 0 ) );
		foreach ( (array) ( $plan['new_tables'] ?? [] ) as $name => $sql ) {
			$step = [ 'job' => $job, 'kind' => 'create_table', 'table' => $name, 'sql' => $sql ];
			$r = $call( function () use ( $c, $step ) { return $c->step( $step ); } );
			if ( is_wp_error( $r ) ) return $fail( $r );
		}
		// plugins switch on last, after their tables and rows exist: never through the options rows
		$ap_id = isset( $plan['tables'][ $wpdb->options ] ) ? (string) $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name = 'active_plugins'" ) : '';
		foreach ( $ordered as $name ) {
			$t = $plan['tables'][ $name ]; $pk = $t['pk'];
			$ids = array_merge( (array) $t['push'], (array) $t['insert'] );
			if ( $name === $wpdb->options && $ap_id !== '' ) $ids = array_values( array_filter( $ids, function ( $id ) use ( $ap_id ) { return (string) $id !== $ap_id; } ) );
			if ( $ids ) {
				foreach ( array_chunk( $ids, 500 ) as $chunk ) {
					$expect = array_intersect_key( $plan['remote_hashes'][ $name ] ?? [], array_flip( $chunk ) );
					$in = implode( ',', array_map( function ( $v ) { return "'" . esc_sql( $v ) . "'"; }, $chunk ) );
					$rows = $wpdb->get_results( "SELECT * FROM `{$name}` WHERE `{$pk}` IN ({$in})", ARRAY_A );
					$step = [ 'job' => $job, 'kind' => 'rows', 'table' => $name, 'pk' => $pk, 'rows' => $rows, 'expect' => $expect, 'extra' => $extra_prod, 'pairs' => $pairs, 'algo' => $plan['algo'] ];
					$r = $call( function () use ( $c, $step ) { return $c->step( $step ); } );
					if ( is_wp_error( $r ) ) return $fail( $r );
					foreach ( $r['stale'] as $id ) $stale[] = "{$name}#{$id}";
					foreach ( (array) ( $r['refused'] ?? [] ) as $ref ) $stale[] = "{$name}: refused {$ref}";
				}
			}
			// before set_insert, so a pushed row can never be what gets deleted
			if ( ! empty( $t['set_delete'] ) ) {
				$step = [ 'job' => $job, 'kind' => 'delete_set', 'table' => $name, 'hashes' => $t['set_delete'], 'extra' => $extra_prod, 'algo' => $plan['algo'] ];
				$r = $call( function () use ( $c, $step ) { return $c->step( $step ); } );
				if ( is_wp_error( $r ) ) return $fail( $r );
			}
			if ( $t['set_insert'] ) {
				// no-pk table: send full rows whose hash is in set_insert
				$rows = []; $next = null;
				do { $d = IXES_Transfer::dump( $name, $next, 5000 ); foreach ( $d['rows'] as $row ) if ( in_array( IXES_Hasher::hash_row( $row, $local_pairs, $plan['algo'] ), $t['set_insert'], true ) ) $rows[] = $row; $next = $d['next']; } while ( $next !== null );
				foreach ( array_chunk( $rows, 500 ) as $chunk ) {
					$step = [ 'job' => $job, 'kind' => 'rows', 'table' => $name, 'pk' => null, 'rows' => $chunk, 'expect' => [], 'extra' => $extra_prod, 'pairs' => $pairs, 'algo' => $plan['algo'] ];
					$r = $call( function () use ( $c, $step ) { return $c->step( $step ); } );
					if ( is_wp_error( $r ) ) return $fail( $r );
					foreach ( (array) ( $r['refused'] ?? [] ) as $ref ) $stale[] = "{$name}: refused {$ref}";
				}
			}
			if ( $t['delete'] ) {
				$expect = array_intersect_key( $plan['remote_hashes'][ $name ] ?? [], array_flip( $t['delete'] ) );
				$step = [ 'job' => $job, 'kind' => 'delete_rows', 'table' => $name, 'pk' => $pk, 'ids' => $t['delete'], 'expect' => $expect, 'extra' => $extra_prod, 'algo' => $plan['algo'] ];
				$r = $call( function () use ( $c, $step ) { return $c->step( $step ); } );
				if ( is_wp_error( $r ) ) return $fail( $r );
				foreach ( $r['stale'] as $id ) $stale[] = "{$name}#{$id} (delete)";
				foreach ( (array) ( $r['refused'] ?? [] ) as $ref ) $stale[] = "{$name}: refused {$ref}";
			}
			$progress->item( $name );
		}

		$dropped = []; $backups = []; $kept_tables = [];
		if ( $drops ) {
			$names = array_keys( $drops );
			$step = [ 'job' => $job, 'kind' => 'drop_check', 'tables' => $names ];
			$r = $call( function () use ( $c, $step ) { return $c->step( $step ); } );
			if ( is_wp_error( $r ) ) return $fail( $r );
			$checks = array_values( (array) ( $r['results'] ?? [] ) );
			$dir = IXES_Droptable::backup_dir( (string) ( $plan['backup_dir'] ?? '' ) );
			foreach ( $names as $i => $name ) {
				$chk = $checks[ $i ] ?? null;
				if ( ! $chk || ! empty( $chk['blocked'] ) || empty( $chk['create'] ) ) { $kept_tables[ $name ] = $chk ? implode( '; ', $chk['blocked'] ?: [ 'no table definition' ] ) : 'no answer'; continue; }
				// the hub's own copy exists before the remote drops anything; without it the table stays
				$file = self::copy_remote_table( $c, $name, (string) $chk['table'], (string) $chk['create'], IXES_Droptable::backup_file( $dir, $env['name'] . '-' . $job, (string) $chk['table'] ) );
				if ( is_wp_error( $file ) ) { $kept_tables[ $name ] = 'no local copy: ' . $file->get_error_message(); continue; }
				$backups[ $name ] = $file;
				$step = [ 'job' => $job, 'kind' => 'drop_table', 'table' => $name, 'digest' => $drops[ $name ]['digest'], 'extra' => $extra_prod, 'algo' => $plan['algo'] ];
				$r = $call( function () use ( $c, $step ) { return $c->step( $step ); } );
				if ( is_wp_error( $r ) ) return $fail( $r );
				if ( empty( $r['ok'] ) ) { $kept_tables[ $name ] = (string) ( $r['refused'] ?? 'refused' ); continue; }
				$dropped[] = $name;
				$progress->item( "drop {$name}" );
			}
		}

		if ( $plan['active_plugins'] !== null ) {
			$step = [ 'job' => $job, 'kind' => 'option', 'name' => 'active_plugins', 'value' => $plan['active_plugins'] ];
			$r = $call( function () use ( $c, $step ) { return $c->step( $step ); } );
			if ( is_wp_error( $r ) ) return $fail( $r );
			$progress->item( 'active_plugins' );
		}
		$progress->end();

		$r = $call( function () use ( $c, $job ) { return $c->post( '/job/finish', [ 'job' => $job ] ); } );
		if ( is_wp_error( $r ) ) return $fail( $r );

		if ( $dropped ) {
			// fresh urls: a page cache must not answer this round with the healthy pages of the first one
			$after = IXES_Droptable::smoke( IXES_Droptable::smoke_urls( $info['url'], wp_generate_password( 12, false ) ), [ $c, 'probe' ] );
			$bad = IXES_Droptable::regressions( $smoke_before, $after );
			if ( $bad ) {
				$why = implode( ', ', array_map( function ( $u, $w ) { return "{$u} ({$w})"; }, array_keys( $bad ), $bad ) );
				$x = $c->post( '/rollback', [ 'job' => $job ] );
				if ( is_wp_error( $x ) ) $tail = 'Rollback failed (' . $x->get_error_message() . "). Next: wp envsync rollback {$env['name']} --job={$job}";
				elseif ( ! empty( $x['errors'] ) ) $tail = "Rolled back job {$job}, but not every table came back: " . implode( '; ', (array) $x['errors'] ) . '. Load the local copies below.';
				else $tail = "Rolled back job {$job}: the dropped tables are back.";
				return new WP_Error( 'smoke_failed', "{$env['name']} broke after dropping " . implode( ', ', $dropped ) . ": {$why}\n{$tail}\nLocal copies: " . implode( ', ', $backups ) );
			}
		}

		return [ 'job' => $job, 'stale' => $stale, 'dropped' => $dropped, 'kept_tables' => $kept_tables, 'backups' => $backups ];
	}
}
