<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Applier {

	const LOCK = 'ixes_lock';
	const ORDER = [ 'users', 'usermeta', 'terms', 'term_taxonomy', 'posts', 'postmeta', 'term_relationships', 'termmeta', 'comments', 'commentmeta' ];

	private static function jobs_dir() { $d = ixes_storage_dir() . '/jobs'; wp_mkdir_p( $d ); return $d; }
	private static function job_dir( $job ) { $job = preg_replace( '/[^a-z0-9-]/', '', $job ); return $job ? self::jobs_dir() . '/' . $job : null; }
	private static function maintenance( $on ) {
		$f = ABSPATH . '.maintenance';
		// core requires .maintenance before plugins load, so the exemption for our own REST calls
		// has to live inside the file itself (an $upgrading in the past means "not in maintenance")
		$body = "<?php\n\$upgrading = " . time() . ";\n"
			. "\$ixes_uri = isset( \$_SERVER['REQUEST_URI'] ) ? \$_SERVER['REQUEST_URI'] : '';\n"
			. "if ( isset( \$_SERVER['HTTP_X_ENVSYNC_SIG'] ) && preg_match( '#^[^?]*(\\\\?rest_route=|/wp-json)/" . IXES_Rest::NS . "/#', \$ixes_uri ) ) \$upgrading = 1;\n";
		if ( $on ) file_put_contents( $f, $body );
		elseif ( file_exists( $f ) ) unlink( $f );
	}
	private static function remote_pairs( array $extra = [] ) { return IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath(), $extra ); }

	// ---------- remote side ----------

	public static function job_start( array $p ) {
		global $wpdb;
		if ( get_transient( self::LOCK ) ) return new WP_Error( 'locked', 'another job running', [ 'status' => 423 ] );
		$job = date( 'Ymd-His' ) . '-' . substr( md5( uniqid() ), 0, 6 );
		set_transient( self::LOCK, $job, HOUR_IN_SECONDS );
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
		foreach ( $expect as $id => $h ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` = %s", $id ), ARRAY_A );
			$cur = $row ? IXES_Hasher::hash_row( $row, $pairs, $algo ) : null;
			if ( $cur !== $h ) $stale[] = $id;
		}
		return $stale;
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
		if ( get_transient( self::LOCK ) !== ( $p['job'] ?? '' ) ) return new WP_Error( 'nojob', 'job not active', [ 'status' => 409 ] );
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
			$refused = [];
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
			$r = IXES_Transfer::write_file_chunk( $rel, (int) ( $p['offset'] ?? 0 ), base64_decode( (string) ( $p['data'] ?? '' ) ), ! empty( $p['final'] ), (string) ( $p['sha256'] ?? '' ) );
			if ( is_wp_error( $r ) ) return $r;
			return [ 'ok' => true ];
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

	public static function job_finish( array $p ) {
		if ( get_transient( self::LOCK ) !== ( $p['job'] ?? '' ) ) return new WP_Error( 'nojob', 'job not active', [ 'status' => 409 ] );
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
		wp_cache_flush();
		return [ 'restored' => $n, 'job' => $job ];
	}

	private static function rrmdir( $d ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $d );
	}

	// ---------- hub side ----------

	public static function apply( array $env, IXES_Client $c, array $plan, callable $log ) {
		global $wpdb;
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

		$start = $c->post( '/job/start', [ 'plan_meta' => $plan_meta ] );
		if ( is_wp_error( $start ) ) return $start;
		$job = $start['job'];

		$stale = [];
		$fail = function ( $err ) use ( $c, $job ) { $c->post( '/job/abort', [ 'job' => $job ] ); return $err; };

		// files first
		$file_hashes = (array) ( $plan['remote_file_hashes'] ?? [] );
		foreach ( $plan['files']['push'] as $rel ) {
			$abs = WP_CONTENT_DIR . '/' . $rel;
			if ( ! is_file( $abs ) ) continue;
			$sha = hash_file( 'sha256', $abs ); $total = filesize( $abs ); $offset = 0;
			$fh = fopen( $abs, 'rb' );
			$refused = false;
			do {
				$data = fread( $fh, 2097152 ); $offset += strlen( $data ); $final = $offset >= $total || $data === '';
				$step = [ 'job' => $job, 'kind' => 'file', 'path' => $rel, 'offset' => $offset - strlen( $data ), 'data' => base64_encode( $data ), 'final' => $final, 'sha256' => $sha ];
				if ( $step['offset'] === 0 && array_key_exists( $rel, $file_hashes ) ) { $step['expect'] = $file_hashes[ $rel ]; $step['algo'] = $plan['algo']; }
				$r = $c->post( '/job/step', $step );
				if ( is_wp_error( $r ) ) { fclose( $fh ); return $fail( $r ); }
				if ( ! empty( $r['refused'] ) ) { $stale[] = "file: {$rel}"; $refused = true; break; }
			} while ( ! $final );
			fclose( $fh );
			if ( ! $refused ) $log( "file {$rel}" );
		}
		if ( $plan['files']['delete'] ) {
			$expect = array_intersect_key( $file_hashes, array_flip( $plan['files']['delete'] ) );
			$r = $c->post( '/job/step', [ 'job' => $job, 'kind' => 'delete_files', 'paths' => $plan['files']['delete'], 'expect' => $expect, 'algo' => $plan['algo'] ] );
			if ( is_wp_error( $r ) ) return $fail( $r );
			foreach ( (array) ( $r['refused'] ?? [] ) as $ref ) $stale[] = "file: {$ref}";
		}

		// tables in FK-safe order, unknown tables after, options last
		$ordered = [];
		foreach ( self::ORDER as $k ) if ( isset( $plan['tables'][ $wpdb->prefix . $k ] ) ) $ordered[] = $wpdb->prefix . $k;
		foreach ( array_keys( $plan['tables'] ) as $n ) if ( ! in_array( $n, $ordered, true ) && $n !== $wpdb->options ) $ordered[] = $n;
		if ( isset( $plan['tables'][ $wpdb->options ] ) ) $ordered[] = $wpdb->options;

		foreach ( $ordered as $name ) {
			$t = $plan['tables'][ $name ]; $pk = $t['pk'];
			$ids = array_merge( (array) $t['push'], (array) $t['insert'] );
			if ( $ids ) {
				foreach ( array_chunk( $ids, 500 ) as $chunk ) {
					$expect = array_intersect_key( $plan['remote_hashes'][ $name ] ?? [], array_flip( $chunk ) );
					$in = implode( ',', array_map( function ( $v ) { return "'" . esc_sql( $v ) . "'"; }, $chunk ) );
					$rows = $wpdb->get_results( "SELECT * FROM `{$name}` WHERE `{$pk}` IN ({$in})", ARRAY_A );
					$r = $c->post( '/job/step', [ 'job' => $job, 'kind' => 'rows', 'table' => $name, 'pk' => $pk, 'rows' => $rows, 'expect' => $expect, 'extra' => $extra_prod, 'pairs' => $pairs, 'algo' => $plan['algo'] ] );
					if ( is_wp_error( $r ) ) return $fail( $r );
					foreach ( $r['stale'] as $id ) $stale[] = "{$name}#{$id}";
					foreach ( (array) ( $r['refused'] ?? [] ) as $ref ) $stale[] = "{$name}: refused {$ref}";
				}
				$log( "{$name} rows " . count( $ids ) );
			}
			if ( $t['set_insert'] ) {
				// no-pk table: send full rows whose hash is in set_insert
				$rows = []; $next = null;
				do { $d = IXES_Transfer::dump( $name, $next, 5000 ); foreach ( $d['rows'] as $row ) if ( in_array( IXES_Hasher::hash_row( $row, $local_pairs, $plan['algo'] ), $t['set_insert'], true ) ) $rows[] = $row; $next = $d['next']; } while ( $next !== null );
				foreach ( array_chunk( $rows, 500 ) as $chunk ) {
					$r = $c->post( '/job/step', [ 'job' => $job, 'kind' => 'rows', 'table' => $name, 'pk' => null, 'rows' => $chunk, 'expect' => [], 'extra' => $extra_prod, 'pairs' => $pairs, 'algo' => $plan['algo'] ] );
					if ( is_wp_error( $r ) ) return $fail( $r );
					foreach ( (array) ( $r['refused'] ?? [] ) as $ref ) $stale[] = "{$name}: refused {$ref}";
				}
				$log( "{$name} set_insert " . count( $rows ) );
			}
			if ( $t['delete'] ) {
				$expect = array_intersect_key( $plan['remote_hashes'][ $name ] ?? [], array_flip( $t['delete'] ) );
				$r = $c->post( '/job/step', [ 'job' => $job, 'kind' => 'delete_rows', 'table' => $name, 'pk' => $pk, 'ids' => $t['delete'], 'expect' => $expect, 'extra' => $extra_prod, 'algo' => $plan['algo'] ] );
				if ( is_wp_error( $r ) ) return $fail( $r );
				foreach ( $r['stale'] as $id ) $stale[] = "{$name}#{$id} (delete)";
				foreach ( (array) ( $r['refused'] ?? [] ) as $ref ) $stale[] = "{$name}: refused {$ref}";
			}
		}

		if ( $plan['active_plugins'] !== null ) {
			$r = $c->post( '/job/step', [ 'job' => $job, 'kind' => 'option', 'name' => 'active_plugins', 'value' => $plan['active_plugins'] ] );
			if ( is_wp_error( $r ) ) return $fail( $r );
			$log( 'active_plugins' );
		}

		$r = $c->post( '/job/finish', [ 'job' => $job ] );
		if ( is_wp_error( $r ) ) return $fail( $r );

		return [ 'job' => $job, 'stale' => $stale ];
	}
}
