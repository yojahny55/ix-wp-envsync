<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_CLI {

	private function get_env( $name ) {
		$e = IXES_Env::get( $name );
		if ( ! $e ) WP_CLI::error( "unknown env '{$name}'. Run: wp envsync env list" );
		return $e;
	}
	private function client( $name ) { return new IXES_Client( $this->get_env( $name ) ); }
	private function fail_if_error( $v ) { if ( is_wp_error( $v ) ) WP_CLI::error( $v->get_error_message() ); return $v; }
	private function confirm( $assoc, $msg ) { if ( empty( $assoc['yes'] ) ) WP_CLI::confirm( $msg ); }
	private function logger() { return function ( $m ) { WP_CLI::log( $m ); }; }
	/** In a terminal without --yes, a failed push step asks what to do; otherwise (agents, --yes) it rolls back. */
	private function error_menu( $assoc ) {
		if ( ! empty( $assoc['yes'] ) || IXES_Progress::piped() || ! ( function_exists( 'stream_isatty' ) && stream_isatty( STDIN ) ) ) return null;
		return function ( WP_Error $e ) {
			WP_CLI::warning( $e->get_error_message() );
			if ( strpos( $e->get_error_message(), 'no available server' ) !== false ) {
				WP_CLI::log( '  The host proxy has no healthy container, so no request (not even rescue) reaches the site.' );
				WP_CLI::log( '  Point its health check at a static file such as /license.txt and restart the container, then choose [r] or [b].' );
			}
			WP_CLI::log( '  [r] retry this step' );
			WP_CLI::log( '  [b] roll back the push (through the rescue endpoint if the remote is down)' );
			WP_CLI::log( '  [p] switch the remote\'s plugins off, then retry' );
			WP_CLI::log( '  [l] leave it as is and quit (the lock stays; see wp envsync status)' );
			$k = \cli\choose( 'What now', 'rbpl', 'b' );
			return [ 'r' => 'retry', 'b' => 'rollback', 'p' => 'plugins_off', 'l' => 'leave' ][ $k ] ?? 'rollback';
		};
	}
	private function wants_json( $assoc ) { return ! empty( $assoc['json'] ) || ( $assoc['format'] ?? 'text' ) === 'json'; }
	private function show_report( array $report, $assoc ) {
		$path = IXES_Report::save( $report );
		if ( $this->wants_json( $assoc ) ) { WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); return $path; }
		WP_CLI::line( IXES_Report::render_text( $report ) );
		foreach ( $report['warnings'] as $w ) WP_CLI::warning( $w );
		return $path;
	}
	/** Run a push/pull and record its outcome in runs/<kind>-<env>-latest.json whatever happens. */
	private function run_recorded( $kind, $env, array $report, callable $run ) {
		$t0 = time();
		$base = [ 'started' => $t0, 'files' => $report['summary']['files'], 'bytes' => $report['summary']['bytes'], 'rows' => $report['summary']['rows'] ];
		$r = $run();
		$end = [ 'finished' => time(), 'seconds' => time() - $t0 ];
		if ( is_wp_error( $r ) ) {
			IXES_Report::save_run( $kind, $env, [ 'ok' => false, 'job' => null ] + $base + $end + [ 'stale' => [], 'error' => $r->get_error_message() ] );
			WP_CLI::error( $r->get_error_message() );
		}
		IXES_Report::save_run( $kind, $env, [ 'ok' => true, 'job' => $r['job'] ?? null ] + $base + $end + [ 'stale' => $r['stale'] ?? [], 'error' => null ] );
		return $r;
	}
	/** The admin Status panel caches its report; anything that changes state on this site must invalidate it. */
	private function forget_status() { delete_transient( 'ixes_status_report' ); }
	private function scope( $assoc ) {
		global $wpdb;
		try { return IXES_Scope::from_assoc( $assoc, $wpdb->prefix ); }
		catch ( InvalidArgumentException $e ) { WP_CLI::error( $e->getMessage() ); }
	}

	/**
	 * Manage environments.
	 * ## OPTIONS
	 *
	 * <action>
	 * : add|list|remove|ping|excludes
	 *
	 * [<name>]
	 * : Environment name.
	 *
	 * [<url>]
	 * : Remote site URL (for add).
	 *
	 * [--token=<token>]
	 * : Remote token (for add).
	 *
	 * [--label=<label>]
	 * : prod or staging.
	 *
	 * [--replace=<pairs>]
	 * : Comma-separated extra search:replace pairs.
	 *
	 * [--exclude=<paths>]
	 * : Comma-separated wp-content paths to skip. Replaces the whole list.
	 *
	 * [--add-exclude=<paths>]
	 * : Comma-separated paths to add to the existing exclude list.
	 *
	 * [--remove-exclude=<paths>]
	 * : Comma-separated paths to drop from the existing exclude list.
	 */
	public function env( $args, $assoc ) {
		$action = $args[0] ?? 'list';
		if ( $action === 'list' ) {
			$rows = [];
			foreach ( IXES_Env::all() as $e ) {
				$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $e['name'] . '.sqlite' );
				$rows[] = [ 'name' => $e['name'], 'label' => $e['label'], 'url' => $e['url'], 'baseline' => $bl->baseline_label() ];
			}
			WP_CLI\Utils\format_items( 'table', $rows, [ 'name', 'label', 'url', 'baseline' ] );
			return;
		}
		if ( $action === 'add' ) {
			if ( empty( $args[1] ) ) WP_CLI::error( 'usage: wp envsync env add <name> [<url>] [--token=...]' );
			// Updating an existing environment keeps everything you did not pass, so
			// rotating a token cannot silently wipe that environment's excludes.
			$existing = IXES_Env::get( $args[1] );
			$env      = is_array( $existing ) ? $existing : [ 'excludes' => [], 'extra_replace' => [], 'label' => 'prod' ];
			$env['name'] = $args[1];
			if ( ! empty( $args[2] ) )           $env['url']   = $args[2];
			if ( ! empty( $assoc['token'] ) )    $env['token'] = $assoc['token'];
			if ( ! empty( $assoc['label'] ) )    $env['label'] = $assoc['label'];
			if ( isset( $assoc['exclude'] ) )    $env['excludes'] = array_values( array_filter( explode( ',', $assoc['exclude'] ) ) );
			if ( isset( $assoc['add-exclude'] ) ) {
				$add = array_filter( explode( ',', $assoc['add-exclude'] ) );
				$env['excludes'] = array_values( array_unique( array_merge( (array) $env['excludes'], $add ) ) );
			}
			if ( isset( $assoc['remove-exclude'] ) ) {
				$drop = array_filter( explode( ',', $assoc['remove-exclude'] ) );
				$env['excludes'] = array_values( array_diff( (array) $env['excludes'], $drop ) );
			}
			if ( isset( $assoc['replace'] ) ) {
				$replace = [];
				foreach ( array_filter( explode( ',', $assoc['replace'] ) ) as $p ) { $x = explode( ':', $p, 2 ); if ( count( $x ) === 2 ) $replace[] = $x; }
				$env['extra_replace'] = $replace;
			}
			if ( empty( $env['url'] ) ) WP_CLI::error( 'a url is required the first time you add an environment' );
			try {
				IXES_Env::add( $env );
			} catch ( InvalidArgumentException $e ) { WP_CLI::error( $e->getMessage() ); }
			$this->forget_status();
			WP_CLI::success( $existing ? "env {$args[1]} updated" : "env {$args[1]} saved" );
			return;
		}
		if ( $action === 'excludes' ) {
			$env  = $this->get_env( $args[1] ?? '' );
			$rows = [];
			$own  = IXES_Transfer::own_dir();
			foreach ( array_filter( [ 'envsync-*/ (storage)', $own ? $own . ' (this plugin)' : '' ] ) as $p ) $rows[] = [ 'path' => $p, 'source' => 'always' ];
			foreach ( IXES_Env::default_excludes() as $p ) $rows[] = [ 'path' => $p, 'source' => 'default' ];
			foreach ( (array) $env['excludes'] as $p ) $rows[] = [ 'path' => $p, 'source' => 'this env' ];
			WP_CLI\Utils\format_items( 'table', $rows, [ 'path', 'source' ] );
			WP_CLI::log( sprintf( 'Add with --add-exclude=, drop one of the "this env" rows with --remove-exclude=. %d file(s) currently in scope.', count( IXES_Transfer::all_files( IXES_Pull::excludes( $env ) ) ) ) );
			return;
		}
		if ( $action === 'remove' ) { IXES_Env::remove( $args[1] ); $this->forget_status(); WP_CLI::success( 'removed' ); return; }
		if ( $action === 'ping' ) {
			$c = $this->client( $args[1] );
			$r = $this->fail_if_error( $c->get( '/ping' ) );
			$info = $this->fail_if_error( $c->info() );
			$via = ( $r['auth_via'] ?? 'authorization' ) === 'x-envsync-token' ? 'X-Envsync-Token (this host strips the Authorization header; that is fine)' : 'Authorization';
			WP_CLI::success( 'ok, remote time ' . date( 'c', $r['time'] ) . ", remote {$info['plugin']}, auth via {$via}" );
			if ( version_compare( (string) $info['plugin'], IXES_VERSION, '<' ) ) WP_CLI::warning( "remote runs {$info['plugin']}, hub runs " . IXES_VERSION . ": upload the release zip to {$this->get_env( $args[1] )['url']}" );
			elseif ( version_compare( (string) $info['plugin'], IXES_VERSION, '>' ) ) WP_CLI::log( "note: remote runs {$info['plugin']}, newer than this hub (" . IXES_VERSION . ')' );
			return;
		}
		WP_CLI::error( 'unknown action' );
	}

	/**
	 * Pull a full snapshot from <env> into this site (overwrite). Records baseline.
	 * ## OPTIONS
	 *
	 * <env>
	 * : Environment name.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * [--dry-run]
	 * : Show the plan and stop.
	 *
	 * [--flush-cache]
	 * : Discard the file hash cache and rehash everything.
	 *
	 * [--details]
	 * : Break the file counts down by directory.
	 *
	 * [--verbose]
	 * : One line per file and table instead of progress bars.
	 *
	 * [--format=<format>]
	 * : text (tables) or json (the manifest agents read; also saved under plans/).
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * [--fresh]
	 * : Discard an interrupted pull and start over.
	 *
	 * [--only=<parts>]
	 * : Comma list of db,files,uploads,themes,plugins,mu-plugins. Default: everything.
	 *
	 * [--tables=<tables>]
	 * : Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
	 *
	 * [--paths=<paths>]
	 * : Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.
	 */
	public function pull( $args, $assoc ) {
		if ( ! empty( $assoc['flush-cache'] ) ) IXES_Hashcache::flush();
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		if ( ! empty( $assoc['fresh'] ) ) IXES_Pull::discard( $env );
		$state = IXES_PullState::load( $env['name'] );
		if ( $state ) {
			if ( isset( $assoc['only'] ) || isset( $assoc['tables'] ) || isset( $assoc['paths'] ) ) WP_CLI::error( 'scope flags cannot change while resuming; use --fresh' );
			$plan = is_file( (string) $state->get( 'plan' ) ) ? json_decode( file_get_contents( $state->get( 'plan' ) ), true ) : null;
			$info = $this->fail_if_error( $c->info() );
			$why  = ! is_array( $plan ) ? 'saved plan file is missing' : $state->refusal(
				(string) ( $info['plugin'] ?? '' ), (array) $plan['excludes'], IXES_Pull::excludes( $env ),
				(array) ( $plan['extra_replace'] ?? [] ), (array) $env['extra_replace'],
				$state->get( 'table' ) ? IXES_Transfer::tmp_exists( $state->get( 'table' ) ) : true
			);
			if ( $why ) WP_CLI::error( "cannot resume: {$why}. Run again with --fresh to start over." );
			WP_CLI::log( $state->describe( count( $plan['files']['transfer'] ) ) );
			$sc = IXES_Scope::from_array( (array) ( $plan['scope'] ?? [] ), '' );
			if ( ! $sc->is_full() ) WP_CLI::log( '  scope: ' . $sc->label() );
			if ( ! empty( $assoc['dry-run'] ) ) return;
			$this->confirm( $assoc, 'Resume?' );
			$progress = IXES_Progress::for_cli( $assoc );
			$this->run_recorded( 'pull', $env['name'], IXES_Report::from_pull_plan( $plan ), function () use ( $env, $c, $plan, $state, $progress ) { $r = IXES_Pull::run( $env, $c, $plan, $this->logger(), $state, $progress ); $progress->end(); return $r; } );
			$this->forget_status();
			WP_CLI::success( "pulled {$env['name']}; baseline recorded" );
			return;
		}
		$plan = $this->fail_if_error( IXES_Pull::plan( $env, $c, $this->scope( $assoc ) ) );
		$report = IXES_Report::from_pull_plan( $plan );
		$manifest = $this->show_report( $report, $assoc );
		if ( $this->wants_json( $assoc ) && ! empty( $assoc['dry-run'] ) ) return;
		WP_CLI::log( 'REWRITE' );
		foreach ( $plan['pairs'] as $p ) WP_CLI::log( "  {$p[0]}  →  {$p[1]}" );
		WP_CLI::log( 'EXCLUDED  ' . implode( ', ', $plan['excludes'] ) );
		if ( ! empty( $assoc['details'] ) ) {
			foreach ( [ 'transfer', 'delete' ] as $k ) {
				$by = [];
				foreach ( $plan['files'][ $k ] as $rel ) {
					$dir = implode( '/', array_slice( explode( '/', $rel ), 0, 2 ) );
					$by[ $dir ] = ( isset( $by[ $dir ] ) ? $by[ $dir ] : 0 ) + 1;
				}
				arsort( $by );
				foreach ( $by as $dir => $count ) WP_CLI::log( sprintf( '  %-8s %6d  %s', $k, $count, $dir ) );
			}
		}
		WP_CLI::log( "manifest: {$manifest}" );
		if ( ! empty( $assoc['dry-run'] ) ) return;
		$this->confirm( $assoc, 'This OVERWRITES the local database and wp-content. Continue?' );
		$progress = IXES_Progress::for_cli( $assoc );
		$this->run_recorded( 'pull', $env['name'], $report, function () use ( $env, $c, $plan, $progress ) { $r = IXES_Pull::run( $env, $c, $plan, $this->logger(), null, $progress ); $progress->end(); return $r; } );
		$this->forget_status();
		WP_CLI::success( "pulled {$env['name']}; baseline recorded" );
	}

	/**
	 * Show what a push to <env> would change.
	 * ## OPTIONS
	 *
	 * <env>
	 * : Environment name.
	 *
	 * [--json]
	 * : Same as --format=json.
	 *
	 * [--format=<format>]
	 * : text (tables) or json (the manifest agents read; also saved under plans/).
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * [--details]
	 * : List every affected id and file.
	 *
	 * [--table=<table>]
	 * : Show a field-level diff for one table.
	 *
	 * [--id=<pk>]
	 * : Primary key of the row to field-diff.
	 *
	 * [--flush-cache]
	 * : Discard the file hash cache and rehash everything.
	 *
	 * [--only=<parts>]
	 * : Comma list of db,files,uploads,themes,plugins,mu-plugins. Default: everything.
	 *
	 * [--tables=<tables>]
	 * : Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
	 *
	 * [--paths=<paths>]
	 * : Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.
	 */
	public function diff( $args, $assoc ) {
		if ( ! empty( $assoc['flush-cache'] ) ) IXES_Hashcache::flush();
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		$plan = $this->fail_if_error( IXES_Planner::build( $env, $c, $this->scope( $assoc ) ) );
		$path = IXES_Planner::save( $plan );
		if ( ! empty( $assoc['table'] ) && ! empty( $assoc['id'] ) ) { $this->field_diff( $c, $assoc['table'], $assoc['id'], $plan ); return; }
		$manifest = $this->show_report( IXES_Report::from_push_plan( $plan, $this->fail_if_error( $c->info() ), 'diff' ), $assoc );
		if ( $this->wants_json( $assoc ) ) return;
		if ( ! empty( $assoc['details'] ) ) {
			foreach ( $plan['tables'] as $name => $t ) foreach ( [ 'push', 'insert', 'delete', 'conflict' ] as $k ) if ( $t[ $k ] ) WP_CLI::log( "  {$name} {$k}: " . implode( ', ', $t[ $k ] ) );
			foreach ( [ 'push', 'delete', 'conflict' ] as $k ) foreach ( $plan['files'][ $k ] as $rel ) WP_CLI::log( "  file {$k}: {$rel}" );
		}
		WP_CLI::log( "plan saved: {$path}" );
		WP_CLI::log( "manifest: {$manifest}" );
	}

	private function field_diff( IXES_Client $c, $table, $id, array $plan ) {
		global $wpdb;
		$pk = IXES_Transfer::pk_of( $table );
		$local  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` = %s", $id ), ARRAY_A );
		$remote = null;
		$d = $c->post( '/dump', [ 'table' => $table, 'from' => $id - 1, 'limit' => 1 ] );
		if ( ! is_wp_error( $d ) && $d['rows'] && (string) $d['rows'][0][ $pk ] === (string) $id ) $remote = $d['rows'][0];
		$lp = IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath() ); $rp = $c->remote_pairs();
		foreach ( array_unique( array_merge( array_keys( (array) $local ), array_keys( (array) $remote ) ) ) as $col ) {
			$l = IXES_Hasher::normalize( $local[ $col ] ?? null, $lp ); $r = IXES_Hasher::normalize( $remote[ $col ] ?? null, $rp );
			if ( $l === $r ) continue;
			WP_CLI::line( WP_CLI::colorize( "%Y{$col}%n" ) );
			WP_CLI::line( WP_CLI::colorize( '%R- prod:  %n' ) . mb_strimwidth( (string) $r, 0, 300, '…' ) );
			WP_CLI::line( WP_CLI::colorize( '%G+ local: %n' ) . mb_strimwidth( (string) $l, 0, 300, '…' ) );
		}
	}

	/**
	 * Push local changes to <env>. Prod-changed rows always win.
	 * ## OPTIONS
	 *
	 * <env>
	 * : Environment name.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * [--dry-run]
	 * : Show the plan and stop.
	 *
	 * [--force]
	 * : Overwrite prod-changed rows when there is no baseline.
	 *
	 * [--plan=<file>]
	 * : Apply a previously saved plan file.
	 *
	 * [--verbose]
	 * : One line per file and table instead of progress bars.
	 *
	 * [--format=<format>]
	 * : text (tables) or json (the manifest agents read; also saved under plans/).
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * [--only=<parts>]
	 * : Comma list of db,files,uploads,themes,plugins,mu-plugins. Default: everything.
	 *
	 * [--tables=<tables>]
	 * : Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
	 *
	 * [--paths=<paths>]
	 * : Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.
	 */
	public function push( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		if ( ! empty( $assoc['plan'] ) && ( isset( $assoc['only'] ) || isset( $assoc['tables'] ) || isset( $assoc['paths'] ) ) ) WP_CLI::error( '--plan carries its own scope; drop --only/--tables/--paths' );
		$plan = $this->fail_if_error( IXES_Planner::build( $env, $c, $this->scope( $assoc ) ) );
		if ( ! empty( $assoc['plan'] ) ) {
			$saved = json_decode( file_get_contents( $assoc['plan'] ), true );
			if ( ! $saved ) WP_CLI::error( 'cannot read plan file' );
			foreach ( $saved['remote_hashes'] as $t => $m ) foreach ( $m as $pk => $h ) if ( ( $plan['remote_hashes'][ $t ][ $pk ] ?? null ) !== $h ) WP_CLI::error( "prod changed {$t}#{$pk} since that plan; run diff again" );
			$plan = $saved;
		}
		if ( $plan['two_way'] ) {
			if ( empty( $assoc['force'] ) ) { WP_CLI::line( IXES_Planner::render_text( $plan ) ); WP_CLI::error( 'no baseline for this env: pull first, or pass --force to overwrite the rows listed as prod-wins' ); }
			foreach ( $plan['tables'] as $n => &$t ) { $t['push'] = array_merge( $t['push'], $t['conflict'] ); $t['conflict'] = []; $t['kept'] = []; } unset( $t );
			$plan['files']['push'] = array_merge( $plan['files']['push'], $plan['files']['conflict'] ); $plan['files']['conflict'] = [];
		}
		$report = IXES_Report::from_push_plan( $plan, $this->fail_if_error( $c->info() ), 'push' );
		$manifest = $this->show_report( $report, $assoc );
		if ( $this->wants_json( $assoc ) && ! empty( $assoc['dry-run'] ) ) return;
		WP_CLI::log( "manifest: {$manifest}" );
		if ( IXES_Planner::is_empty( $plan ) ) { WP_CLI::success( 'nothing to push' ); return; }
		if ( ! empty( $assoc['dry-run'] ) ) return;
		$this->confirm( $assoc, "Apply this plan (scope: " . IXES_Scope::from_array( (array) ( $plan['scope'] ?? [] ), '' )->label() . ") to {$env['name']} ({$env['url']})?" );
		$progress = IXES_Progress::for_cli( $assoc );
		$r = $this->run_recorded( 'push', $env['name'], $report, function () use ( $env, $c, $plan, $progress, $assoc ) { $r = IXES_Applier::apply( $env, $c, $plan, $this->logger(), $progress, $this->error_menu( $assoc ) ); $progress->end(); return $r; } );
		if ( $r['stale'] ) WP_CLI::warning( 'skipped (changed on prod during push): ' . implode( ', ', $r['stale'] ) );
		update_option( 'ixes_last_jobs', array_slice( array_merge( [ [ 'env' => $env['name'], 'job' => $r['job'], 'at' => time(), 'stale' => $r['stale'] ] ], (array) get_option( 'ixes_last_jobs', [] ) ), 0, 5 ), false );
		$this->forget_status();
		WP_CLI::success( "pushed to {$env['name']} (job {$r['job']}). Pull again before the next round of changes." );
	}

	/**
	 * Restore the snapshot taken before a push job on <env>.
	 * ## OPTIONS
	 *
	 * <env>
	 * : Environment name.
	 *
	 * [--job=<id>]
	 * : Job id to restore (default: the last one).
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function rollback( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		$this->confirm( $assoc, "Rollback last push on {$env['name']}?" );
		$r = $this->fail_if_error( $c->post( '/rollback', [ 'job' => $assoc['job'] ?? null ] ) );
		WP_CLI::success( "restored {$r['restored']} rows/files from job {$r['job']}" );
	}

	/**
	 * Clear a push lock left behind by a hub that died mid-push. Rolls nothing back.
	 * ## OPTIONS
	 *
	 * <env>
	 * : Environment name.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function unlock( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		$info = $this->fail_if_error( $c->info() );
		$lock = $info['lock'] ?? null;
		if ( ! $lock ) WP_CLI::error( "no push is locked on {$env['name']}" );
		$age = $lock['started'] ? (int) floor( ( time() - $lock['started'] ) / 60 ) . ' min' : 'unknown age';
		WP_CLI::log( "Nothing is rolled back; 'wp envsync rollback {$env['name']}' still restores that job's snapshot." );
		$this->confirm( $assoc, "Clear the lock from job {$lock['job']} ({$age}) on {$env['name']}?" );
		$r = $this->fail_if_error( $c->post( '/job/unlock', [] ) );
		$this->forget_status();
		WP_CLI::success( "unlocked {$env['name']} (job {$r['job']})" );
	}

	/**
	 * Recover a remote a push left broken, without loading its plugins or theme.
	 * ## OPTIONS
	 *
	 * <env>
	 * : Environment name.
	 *
	 * [--plugins-off]
	 * : Deactivate every plugin on the remote except EnvSync.
	 *
	 * [--rollback]
	 * : Restore the snapshot of the locked push (or the last one), clear its lock and the maintenance file.
	 *
	 * [--job=<id>]
	 * : Job to roll back instead.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function rescue( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		$s = $c->rescue( 'status' );
		if ( is_wp_error( $s ) ) {
			WP_CLI::error( $s->get_error_message() . "\nThe rescue endpoint ({$c->rescue_url()}) did not answer. Either the remote runs a plugin older than 0.5.1, or the host blocks PHP files under wp-content/plugins. Use the host's file manager or terminal: rename the crashing plugin's folder under wp-content/plugins." );
		}
		WP_CLI::log( "{$env['name']}  {$env['url']}  (rescue mode: no plugins, no theme)" );
		WP_CLI::log( '  plugin ' . $s['plugin'] . ( $s['maintenance'] ? '  · maintenance file present' : '' ) );
		WP_CLI::log( '  lock   ' . ( $s['lock'] ? "job {$s['lock']['job']}" : 'none' ) . '   last job ' . ( $s['last_job'] ?: 'none' ) );
		WP_CLI::log( '  active ' . ( $s['active_plugins'] ? implode( ', ', $s['active_plugins'] ) : 'none' ) );
		if ( empty( $assoc['plugins-off'] ) && empty( $assoc['rollback'] ) ) {
			WP_CLI::log( "\nNext: wp envsync rescue {$env['name']} --rollback   (undo the push)   or   --plugins-off   (keep its changes, disable plugins)" );
			return;
		}
		if ( ! empty( $assoc['plugins-off'] ) ) {
			$this->confirm( $assoc, "Deactivate every plugin on {$env['name']} except EnvSync?" );
			$r = $this->fail_if_error( $c->rescue( 'plugins_off' ) );
			WP_CLI::success( 'deactivated: ' . ( $r['deactivated'] ? implode( ', ', $r['deactivated'] ) : 'nothing' ) . '. Reactivate them from wp-admin once the cause is fixed.' );
		}
		if ( ! empty( $assoc['rollback'] ) ) {
			$job = $assoc['job'] ?? ( $s['lock']['job'] ?? $s['last_job'] );
			if ( ! $job ) WP_CLI::error( 'no push job to roll back' );
			$this->confirm( $assoc, "Roll back job {$job} on {$env['name']}?" );
			$r = $this->fail_if_error( $c->rescue( 'rollback', [ 'job' => $job ] ) );
			WP_CLI::success( "restored {$r['restored']} rows/files from job {$r['job']}; lock and maintenance cleared" );
		}
		$this->forget_status();
	}

	/**
	 * Show this site's role, each environment's state, and the one recommended next command.
	 * ## OPTIONS
	 *
	 * [<env>]
	 * : Only this environment.
	 *
	 * [--format=<format>]
	 * : Machine-readable report (what agents should read). WP-CLI rewrites --json to --format=json itself.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 */
	public function status( $args, $assoc ) {
		if ( isset( $args[0] ) ) $this->get_env( $args[0] );
		$r = IXES_Status::build( $args[0] ?? null );
		if ( ( $assoc['format'] ?? 'text' ) === 'json' ) { WP_CLI::line( wp_json_encode( $r, JSON_PRETTY_PRINT ) ); return; }
		WP_CLI::line( IXES_Status::render_text( $r ) );
	}

	/**
	 * Show or rotate this site's remote token.
	 * ## OPTIONS
	 *
	 * [--rotate]
	 * : Issue a new token.
	 */
	public function token( $args, $assoc ) {
		if ( ! empty( $assoc['rotate'] ) || ! get_option( 'ixes_token_hash' ) ) { $t = IXES_Auth::install_token(); $this->forget_status(); WP_CLI::line( $t ); return; }
		$t = get_transient( 'ixes_token_show' );
		if ( $t ) WP_CLI::line( $t ); else WP_CLI::error( 'token already shown; use --rotate to issue a new one' );
	}
}
