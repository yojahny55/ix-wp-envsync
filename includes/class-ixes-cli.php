<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_CLI {

	private function get_env( $name ) {
		$e = IXES_Env::get( $name );
		if ( ! $e ) WP_CLI::error( "unknown env '{$name}'. Run: wp envsync env list" );
		return $e;
	}
	/** $timeout (from --timeout on pull/diff/push) overrides the env's own --timeout for this run only; it is never saved. */
	private function client( $name, $timeout = null ) {
		$env = $this->get_env( $name );
		if ( $timeout !== null ) $env['timeout'] = $timeout;
		return new IXES_Client( $env );
	}
	private function timeout_override( $assoc ) {
		if ( ! isset( $assoc['timeout'] ) ) return null;
		if ( ! is_numeric( $assoc['timeout'] ) || (int) $assoc['timeout'] < 1 ) WP_CLI::error( '--timeout must be a positive number of seconds' );
		return (int) $assoc['timeout'];
	}
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
	private function run_recorded( $kind, $env, array $report, callable $run, ?IXES_Progress $progress = null ) {
		$t0 = time();
		$base = [ 'started' => $t0, 'files' => $report['summary']['files'], 'bytes' => $report['summary']['bytes'], 'rows' => $report['summary']['rows'] ];
		$r = $run();
		$end = [ 'finished' => time(), 'seconds' => time() - $t0, 'notes' => $progress ? $progress->notes() : [] ];
		if ( is_wp_error( $r ) ) {
			IXES_Report::save_run( $kind, $env, [ 'ok' => false, 'job' => null, 'phase' => 'job' ] + $base + $end + [ 'stale' => [], 'error' => $r->get_error_message() ] );
			WP_CLI::error( $r->get_error_message() );
		}
		IXES_Report::save_run( $kind, $env, [ 'ok' => true, 'job' => $r['job'] ?? null, 'phase' => 'done' ] + $base + $end + [ 'stale' => $r['stale'] ?? [], 'dropped' => $r['dropped'] ?? [], 'kept_tables' => $r['kept_tables'] ?? [], 'backups' => $r['backups'] ?? [], 'error' => null ] );
		return $r;
	}
	/**
	 * A push or pull that fails before its job opens still records that in runs/<kind>-<env>-latest.json, so the
	 * file never shows an older run's outcome. A dry run changes nothing and leaves the file alone.
	 * @return callable( WP_Error|mixed ) that stops with the error, or hands back any other value
	 */
	private function plan_guard( $kind, $env, array $assoc ) {
		$t0 = time(); $record = empty( $assoc['dry-run'] );
		return function ( $v ) use ( $kind, $env, $t0, $record ) {
			if ( ! is_wp_error( $v ) ) return $v;
			$msg = $v->get_error_message();
			if ( $record ) IXES_Report::save_failed_run( $kind, $env, 'plan', $t0, $msg );
			WP_CLI::error( $msg );
		};
	}
	/** The admin Status panel caches its report; anything that changes state on this site must invalidate it. */
	private function parallel( $assoc ) {
		$n = $assoc['parallel'] ?? 4;
		if ( ! is_numeric( $n ) || (int) $n < 1 || (int) $n > 16 ) WP_CLI::error( '--parallel takes a number from 1 to 16' );
		return (int) $n;
	}
	/** Progress for a pull/diff/push on $env, also written to runs/<kind>-<env>-progress.json from the planning on. */
	private function tracked( $kind, $env, $assoc ) {
		$p = IXES_Progress::for_cli( $assoc );
		$p->track( $kind, $env );
		return $p;
	}
	private function forget_status() { delete_transient( 'ixes_status_report' ); }
	private function scope( $assoc, array $env ) {
		global $wpdb;
		$d = IXES_Scope::with_default( $assoc, $env );
		if ( $d['note'] !== null && ! $this->wants_json( $assoc ) ) WP_CLI::log( $d['note'] );
		try { return IXES_Scope::from_assoc( $d['assoc'], $wpdb->prefix ); }
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
	 * [--only=<parts>]
	 * : Default scope for this environment's pull, diff and push, e.g. db,uploads when code travels by git (for add). An empty value or "all" removes it.
	 *
	 * [--tables=<tables>]
	 * : Default table list for this environment's pull, diff and push when no scope flag is given (for add). An empty value removes it.
	 *
	 * [--exclude-tables=<tables>]
	 * : Tables or globs this environment always leaves out (for add). @logs covers common log tables. Replaces the whole list.
	 *
	 * [--add-exclude-tables=<tables>]
	 * : Tables to add to the existing table exclude list.
	 *
	 * [--remove-exclude-tables=<tables>]
	 * : Tables to drop from the existing table exclude list.
	 *
	 * [--exclude-options=<globs>]
	 * : Option name globs whose wp_options rows this environment never pulls, pushes or deletes, e.g. a host-only plugin's settings (for add). Replaces the whole list.
	 *
	 * [--add-exclude-options=<globs>]
	 * : Option globs to add to the existing list.
	 *
	 * [--remove-exclude-options=<globs>]
	 * : Option globs to drop from the existing list.
	 *
	 * [--timeout=<seconds>]
	 * : HTTP timeout for every request to this environment (for add). Default: 120. --timeout on pull/diff/push overrides it for that run. Pass an empty value to remove it.
	 *
	 * [--basic-auth=<credentials>]
	 * : HTTP Basic Auth credentials as user:pass, for a remote behind a password-protected proxy (for add). Pass an empty value to remove them.
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
				$scope = IXES_Scope::with_default( [], $e )['assoc'];
				$rows[] = [ 'name' => $e['name'], 'label' => $e['label'], 'url' => $e['url'], 'scope' => IXES_Scope::from_assoc( $scope, '' )->label(), 'timeout' => ( $e['timeout'] ?? '' ) !== '' ? $e['timeout'] . 's' : '120s (default)', 'baseline' => $bl->baseline_label() ];
			}
			WP_CLI\Utils\format_items( 'table', $rows, [ 'name', 'label', 'url', 'scope', 'timeout', 'baseline' ] );
			return;
		}
		if ( $action === 'add' ) {
			if ( empty( $args[1] ) ) WP_CLI::error( 'usage: wp envsync env add <name> [<url>] [--token=...] [--basic-auth=user:pass]' );
			// Updating an existing environment keeps everything you did not pass, so
			// rotating a token cannot silently wipe that environment's excludes.
			$existing = IXES_Env::get( $args[1] );
			$env      = is_array( $existing ) ? $existing : [ 'excludes' => [], 'extra_replace' => [], 'label' => 'prod' ];
			$env['name'] = $args[1];
			if ( ! empty( $args[2] ) )           $env['url']   = $args[2];
			if ( ! empty( $assoc['token'] ) )    $env['token'] = $assoc['token'];
			if ( ! empty( $assoc['label'] ) )    $env['label'] = $assoc['label'];
			if ( isset( $assoc['only'] ) ) {
				try { $only = IXES_Scope::default_only( $assoc['only'] === true ? '' : $assoc['only'] ); }
				catch ( InvalidArgumentException $e ) { WP_CLI::error( $e->getMessage() ); }
				if ( $only === '' ) unset( $env['default_only'] ); else $env['default_only'] = $only;
			}
			try {
				if ( isset( $assoc['tables'] ) ) {
					$tables = IXES_Scope::default_tables( $assoc['tables'] === true ? '' : $assoc['tables'] );
					if ( $tables === '' ) unset( $env['default_tables'] ); else $env['default_tables'] = $tables;
				}
				if ( isset( $assoc['exclude-tables'] ) ) $env['exclude_tables'] = IXES_Scope::table_excludes( $assoc['exclude-tables'] === true ? '' : $assoc['exclude-tables'] );
				if ( isset( $assoc['add-exclude-tables'] ) ) $env['exclude_tables'] = array_values( array_unique( array_merge( (array) ( $env['exclude_tables'] ?? [] ), IXES_Scope::table_excludes( $assoc['add-exclude-tables'] ) ) ) );
				if ( isset( $assoc['remove-exclude-tables'] ) ) $env['exclude_tables'] = array_values( array_diff( (array) ( $env['exclude_tables'] ?? [] ), IXES_Scope::table_excludes( $assoc['remove-exclude-tables'] ) ) );
				if ( empty( $env['exclude_tables'] ) ) unset( $env['exclude_tables'] );
				if ( isset( $assoc['exclude-options'] ) ) $env['exclude_options'] = IXES_Env::option_globs( $assoc['exclude-options'] === true ? '' : $assoc['exclude-options'] );
				if ( isset( $assoc['add-exclude-options'] ) ) $env['exclude_options'] = array_values( array_unique( array_merge( (array) ( $env['exclude_options'] ?? [] ), IXES_Env::option_globs( $assoc['add-exclude-options'] ) ) ) );
				if ( isset( $assoc['remove-exclude-options'] ) ) $env['exclude_options'] = array_values( array_diff( (array) ( $env['exclude_options'] ?? [] ), IXES_Env::option_globs( $assoc['remove-exclude-options'] ) ) );
				if ( empty( $env['exclude_options'] ) ) unset( $env['exclude_options'] );
			} catch ( InvalidArgumentException $e ) { WP_CLI::error( $e->getMessage() ); }
			if ( isset( $assoc['timeout'] ) ) {
				if ( $assoc['timeout'] === '' || $assoc['timeout'] === true ) unset( $env['timeout'] );
				else $env['timeout'] = $assoc['timeout']; // IXES_Env::add() validates it
			}
			if ( isset( $assoc['basic-auth'] ) ) {
				if ( $assoc['basic-auth'] === '' || $assoc['basic-auth'] === true ) unset( $env['basic_auth'] );
				else $env['basic_auth'] = (string) $assoc['basic-auth'];
			}
			if ( isset( $assoc['exclude'] ) )    $env['excludes'] = array_values( array_filter( explode( ',', $assoc['exclude'] ) ) );
			if ( isset( $assoc['add-exclude'] ) )    $env = IXES_Mu::add_excludes( $env, array_filter( explode( ',', $assoc['add-exclude'] ) ) );
			if ( isset( $assoc['remove-exclude'] ) ) $env = IXES_Mu::remove_excludes( $env, array_filter( explode( ',', $assoc['remove-exclude'] ) ) );
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
			foreach ( IXES_Mu::host_excludes( $env ) as $p ) $rows[] = [ 'path' => $p, 'source' => 'host-specific (' . IXES_Mu::HOST_SPECIFIC[ $p ] . ')' ];
			foreach ( (array) $env['excludes'] as $p ) $rows[] = [ 'path' => $p, 'source' => 'this env' ];
			foreach ( (array) ( $env['exclude_options'] ?? [] ) as $o ) $rows[] = [ 'path' => 'option ' . $o, 'source' => 'this env' ];
			foreach ( (array) ( $env['exclude_tables'] ?? [] ) as $t ) $rows[] = [ 'path' => 'table ' . $t . ( isset( IXES_Scope::PRESETS[ $t ] ) ? ' (' . implode( ', ', IXES_Scope::PRESETS[ $t ] ) . ')' : '' ), 'source' => 'this env' ];
			WP_CLI\Utils\format_items( 'table', $rows, [ 'path', 'source' ] );
			foreach ( (array) ( $env['host_included'] ?? [] ) as $p ) WP_CLI::log( "synced although host-specific: {$p} (--add-exclude={$p} excludes it again)" );
			WP_CLI::log( sprintf( 'Add with --add-exclude=, --add-exclude-tables= or --add-exclude-options=; drop a "this env" or "host-specific" row with the matching --remove-exclude*=. %d file(s) currently in scope.', count( IXES_Transfer::all_files( IXES_Pull::excludes( $env ) ) ) ) );
			return;
		}
		if ( $action === 'remove' ) { IXES_Env::remove( $args[1] ); $this->forget_status(); WP_CLI::success( 'removed' ); return; }
		if ( $action === 'ping' ) {
			$c = $this->client( $args[1] );
			$r = $this->fail_if_error( $c->get( '/ping' ) );
			$info = $this->fail_if_error( $c->info() );
			$via = ( $r['auth_via'] ?? 'authorization' ) === 'x-envsync-token' ? 'X-Envsync-Token (this host strips the Authorization header; that is fine)' : 'Authorization';
			WP_CLI::success( 'ok, remote time ' . wp_date( 'Y-m-d H:i:s T', (int) $r['time'] ) . ", remote {$info['plugin']}, auth via {$via}" );
			if ( version_compare( (string) $info['plugin'], IXES_VERSION, '<' ) ) WP_CLI::warning( "remote runs {$info['plugin']}, hub runs " . IXES_VERSION . ': ' . ( in_array( 'self_update', (array) ( $info['caps'] ?? [] ), true ) ? "run wp envsync self-update {$args[1]}" : "upload the release zip to {$this->get_env( $args[1] )['url']}" ) );
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
	 * : Comma list of db,files,uploads,themes,plugins,mu-plugins, or all. Default: the environment's --only from env add, else everything.
	 *
	 * [--tables=<tables>]
	 * : Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
	 *
	 * [--paths=<paths>]
	 * : Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma list of table names or globs to leave out, added to the environment's own. @logs covers common log tables.
	 *
	 * [--backup-dir=<dir>]
	 * : Where to write the .sql copy of every table the pull drops here. Default: ENVSYNC_BACKUP_DIR, else the plugin's storage folder.
	 *
	 * [--no-seed]
	 * : Never try downloads.wordpress.org for plugin/theme files that would otherwise come from a slow remote. Default: ENVSYNC_NO_SEED, else seeding is on.
	 *
	 * [--timeout=<seconds>]
	 * : HTTP timeout for this pull, overriding the environment's own --timeout (env add).
	 *
	 * [--parallel=<n>]
	 * : File requests in flight at once, 1 to 16. Batching and parallel requests need 0.8.0 on the remote; an older one gets one file per request.
	 * ---
	 * default: 4
	 * ---
	 */
	public function pull( $args, $assoc ) {
		if ( ! empty( $assoc['flush-cache'] ) ) IXES_Hashcache::flush();
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0], $this->timeout_override( $assoc ) ); $par = $this->parallel( $assoc );
		if ( ! empty( $assoc['fresh'] ) ) IXES_Pull::discard( $env );
		$progress = $this->tracked( 'pull', $env['name'], $assoc );
		$state = IXES_PullState::load( $env['name'] );
		$guard = $this->plan_guard( 'pull', $env['name'], $assoc );
		if ( $state ) {
			$plan = is_file( (string) $state->get( 'plan' ) ) ? json_decode( file_get_contents( $state->get( 'plan' ) ), true ) : null;
			global $wpdb;
			$scope_why = IXES_Scope::resume_refusal( $assoc, is_array( $plan ) ? (array) ( $plan['scope'] ?? [] ) : [], $wpdb->prefix );
			if ( $scope_why ) $guard( new WP_Error( 'resume', "cannot resume: {$scope_why}. Run it without scope flags to resume, or with --fresh to start over." ) );
			$info = $guard( $c->info() );
			$why  = ! is_array( $plan ) ? 'saved plan file is missing' : $state->refusal(
				(string) ( $info['plugin'] ?? '' ), (array) $plan['excludes'], IXES_Pull::excludes( $env ),
				(array) ( $plan['extra_replace'] ?? [] ), (array) $env['extra_replace'],
				$state->get( 'table' ) ? IXES_Transfer::tmp_exists( $state->get( 'table' ) ) : true
			);
			if ( $why ) $guard( new WP_Error( 'resume', "cannot resume: {$why}. Run again with --fresh to start over." ) );
			WP_CLI::log( $state->describe( count( $plan['files']['transfer'] ) ) );
			$note = IXES_Pull::transfer_note( $c, count( $plan['files']['transfer'] ) - $state->files_count(), $par );
			if ( $note !== '' ) WP_CLI::log( $note );
			$sc = IXES_Scope::from_array( (array) ( $plan['scope'] ?? [] ), '' );
			WP_CLI::log( '  scope: ' . $sc->label() . ' (from the interrupted pull; the default for ' . $env['name'] . ' does not apply)' );
			if ( ! empty( $assoc['dry-run'] ) ) return;
			$this->confirm( $assoc, 'Resume?' );
			$this->run_recorded( 'pull', $env['name'], IXES_Report::from_pull_plan( $plan ), function () use ( $env, $c, $plan, $state, $progress, $par ) { $r = IXES_Pull::run( $env, $c, $plan, $this->logger(), $state, $progress, $par ); $progress->end(); return $r; }, $progress );
			$progress->finish();
			$this->forget_status();
			WP_CLI::success( "pulled {$env['name']}; baseline recorded" );
			return;
		}
		// plan() only ever verifies wordpress.org candidates (never writes), so this is identical for a dry run
		$seed_opts = [ 'no_seed' => ! empty( $assoc['no-seed'] ) ];
		$plan = $guard( IXES_Pull::plan( $env, $c, $this->scope( $assoc, $env ), $seed_opts ) );
		$plan['backup_dir'] = (string) ( $assoc['backup-dir'] ?? '' );
		$report = IXES_Report::from_pull_plan( $plan );
		$manifest = $this->show_report( $report, $assoc );
		if ( $this->wants_json( $assoc ) && ! empty( $assoc['dry-run'] ) ) return;
		if ( $plan['seed'] ) WP_CLI::log( sprintf( 'seeded %d files (%s) from wordpress.org; %d files left to transfer', $plan['seed']['files'], IXES_Report::size( $plan['seed']['bytes'] ), $plan['seed']['left'] ) );
		WP_CLI::log( 'REWRITE' );
		foreach ( $plan['pairs'] as $p ) WP_CLI::log( "  {$p[0]}  →  {$p[1]}" );
		WP_CLI::log( 'EXCLUDED  ' . implode( ', ', $plan['excludes'] ) );
		$note = IXES_Pull::transfer_note( $c, count( $plan['files']['transfer'] ), $par );
		if ( $note !== '' ) WP_CLI::log( $note );
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
		$this->run_recorded( 'pull', $env['name'], $report, function () use ( $env, $c, $plan, $progress, $par ) { $r = IXES_Pull::run( $env, $c, $plan, $this->logger(), null, $progress, $par ); $progress->end(); return $r; }, $progress );
		$progress->finish();
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
	 * : One table, with or without its prefix, for --id or --list.
	 *
	 * [--id=<pk>]
	 * : With --table: say where that row is (here, on the remote, both or neither) and show its fields: the ones that differ, or all of them when one side has it.
	 *
	 * [--list=<column>]
	 * : With --table: the keys in one column of the diff, with a few fields that name each row in core tables. local-only, differs, remote-only, or push, insert, delete, remote-wins, kept-remote.
	 *
	 * [--flush-cache]
	 * : Discard the file hash cache and rehash everything.
	 *
	 * [--only=<parts>]
	 * : Comma list of db,files,uploads,themes,plugins,mu-plugins, or all. Default: the environment's --only from env add, else everything.
	 *
	 * [--tables=<tables>]
	 * : Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
	 *
	 * [--paths=<paths>]
	 * : Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma list of table names or globs to leave out, added to the environment's own. @logs covers common log tables.
	 *
	 * [--timeout=<seconds>]
	 * : HTTP timeout for this diff, overriding the environment's own --timeout (env add).
	 */
	public function diff( $args, $assoc ) {
		if ( ! empty( $assoc['flush-cache'] ) ) IXES_Hashcache::flush();
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0], $this->timeout_override( $assoc ) );
		$table = empty( $assoc['table'] ) ? '' : $this->table_arg( $assoc['table'] );
		if ( ( isset( $assoc['id'] ) || isset( $assoc['list'] ) ) && $table === '' ) WP_CLI::error( '--id and --list go with --table' );
		if ( isset( $assoc['id'] ) ) { $this->row_diff( $env, $c, $table, (string) $assoc['id'] ); return; }
		$progress = $this->tracked( 'diff', $env['name'], $assoc );
		$plan = $this->fail_if_error( IXES_Planner::build( $env, $c, $this->scope( $assoc, $env ) ) );
		$progress->finish();
		$path = IXES_Planner::save( $plan );
		IXES_Planner::save_latest( $plan );
		if ( isset( $assoc['list'] ) ) { $this->list_rows( $env, $c, $plan, $table, (string) $assoc['list'] ); return; }
		$manifest = $this->show_report( IXES_Report::from_push_plan( $plan, $this->fail_if_error( $c->info() ), 'diff' ), $assoc );
		if ( $this->wants_json( $assoc ) ) return;
		if ( ! empty( $assoc['details'] ) ) {
			$cols = $plan['two_way'] ? [ 'local-only', 'differs', 'remote-only' ] : [ 'push', 'insert', 'delete', 'remote-wins', 'kept-remote' ];
			foreach ( $plan['tables'] as $name => $t ) foreach ( $cols as $k ) { $ids = IXES_Rowinfo::ids( $t, $k )['ids']; if ( $ids ) WP_CLI::log( "  {$name} {$k}: " . implode( ', ', $ids ) ); }
			foreach ( [ 'push', 'delete', 'conflict' ] as $k ) foreach ( $plan['files'][ $k ] as $rel ) WP_CLI::log( "  file {$k}: {$rel}" );
		}
		WP_CLI::log( "plan saved: {$path}" );
		WP_CLI::log( "manifest: {$manifest}" );
		self::reuse_hint( $env['name'], $path );
	}

	private static function reuse_hint( $env, $path ) {
		WP_CLI::log( sprintf( 'push %s --yes within %d minutes reuses this plan while nothing changes here (or pass --plan=%s)', $env, IXES_Planner::REUSE_MAX_MIN, $path ) );
	}

	private function table_arg( $table ) {
		global $wpdb;
		$table = strpos( $table, $wpdb->prefix ) === 0 ? $table : $wpdb->prefix . $table;
		if ( ! IXES_Transfer::valid_table( $table ) ) WP_CLI::error( "--table: {$table} is not a table name" );
		return $table;
	}

	/** The row with key $id on the remote, null when it has none, or an error when it cannot be asked. */
	private function remote_row( IXES_Client $c, $table, $id ) {
		$info = $c->info();
		if ( is_wp_error( $info ) ) return $info;
		$pk = null; $has = false;
		foreach ( (array) $info['tables'] as $t ) if ( $t['name'] === $table ) { $pk = $t['pk']; $has = true; }
		if ( ! $has ) return null;
		if ( ! $pk ) return new WP_Error( 'no_pk', "{$table} has no primary key on the remote" );
		$from = IXES_Rowinfo::cursor_before( $id );
		if ( $from === null ) return new WP_Error( 'text_key', "{$table}: only a numeric key can be looked up on the remote" );
		$d = $c->post( '/dump', [ 'table' => $table, 'from' => $from, 'limit' => 1 ] + ( $c->cells() ? [ 'cells' => 1 ] : [] ) );
		if ( is_wp_error( $d ) ) return $d;
		$rows = IXES_Hasher::rows_in( (array) ( $d['rows'] ?? [] ) );
		if ( is_wp_error( $rows ) ) return $rows;
		return $rows && isset( $rows[0][ $pk ] ) && (string) $rows[0][ $pk ] === (string) $id ? $rows[0] : null;
	}

	private function local_rows( $table, array $ids, array $columns = [] ) {
		global $wpdb;
		$pk = IXES_Transfer::pk_of( $table );
		if ( ! $pk || ! $ids ) return [];
		$sel = $columns ? '`' . $pk . '`, `' . implode( '`, `', array_map( 'esc_sql', $columns ) ) . '`' : '*';
		$in = implode( ',', array_map( function ( $v ) { return "'" . esc_sql( $v ) . "'"; }, $ids ) );
		$out = [];
		foreach ( (array) $wpdb->get_results( "SELECT {$sel} FROM `{$table}` WHERE `{$pk}` IN ({$in})", ARRAY_A ) as $row ) $out[ (string) $row[ $pk ] ] = $row;
		return $out;
	}

	private function pairs_for( array $env, IXES_Client $c ) {
		$info = $this->fail_if_error( $c->info() );
		list( $prod, $local ) = IXES_Env::extras( $env );
		return [
			IXES_Planner::local_pairs( IXES_Env::local_url(), IXES_Env::local_abspath(), $local, (string) $info['url'], (string) ( $info['abspath'] ?? '' ), $prod ),
			IXES_Hasher::placeholders( $info['url'], $info['abspath'], $prod ),
		];
	}

	private function row_diff( array $env, IXES_Client $c, $table, $id ) {
		$local = $this->local_rows( $table, [ $id ] )[ $id ] ?? null;
		$remote = $this->fail_if_error( $this->remote_row( $c, $table, $id ) );
		list( $lp, $rp ) = $this->pairs_for( $env, $c );
		$cmp = IXES_Rowinfo::compare( $local, $remote, $lp, $rp );
		$where = [ 'neither' => 'on neither side', 'local-only' => 'only here', 'remote-only' => "only on {$env['name']}", 'same' => 'on both sides, the same', 'differs' => 'on both sides, different' ];
		WP_CLI::line( "{$table} #{$id}: " . $where[ $cmp['where'] ] );
		foreach ( $cmp['fields'] as list( $col, $r, $l ) ) {
			WP_CLI::line( WP_CLI::colorize( "%Y{$col}%n" ) );
			// raw bytes shown as hex: printed as is they garble the terminal
			if ( $r !== null ) WP_CLI::line( WP_CLI::colorize( '%R- remote: %n' ) . mb_strimwidth( IXES_Hasher::is_bytes( $r ) ? '0x' . bin2hex( $r ) : $r, 0, 300, '…' ) );
			if ( $l !== null ) WP_CLI::line( WP_CLI::colorize( '%G+ local:  %n' ) . mb_strimwidth( IXES_Hasher::is_bytes( $l ) ? '0x' . bin2hex( $l ) : $l, 0, 300, '…' ) );
		}
	}

	/** How many remote rows diff --list names one by one: each is its own request. */
	const LIST_REMOTE_LABELS = 50;

	private function list_rows( array $env, IXES_Client $c, array $plan, $table, $column ) {
		global $wpdb;
		$t = $plan['tables'][ $table ] ?? null;
		if ( ! $t ) { WP_CLI::log( "{$table}: nothing differs in this scope" ); return; }
		if ( ! $t['pk'] ) WP_CLI::error( "{$table} has no primary key: its rows have no ids to list" );
		$sel = IXES_Rowinfo::ids( $t, $column );
		if ( $sel === null ) WP_CLI::error( "--list: {$column} is not a column; use local-only, differs, remote-only, push, insert, delete, remote-wins or kept-remote" );
		$cols = IXES_Rowinfo::label_columns( $table, $wpdb->prefix );
		WP_CLI::log( "{$table} {$column}: " . count( $sel['ids'] ) . ( $sel['side'] === 'remote' ? " (rows on {$env['name']})" : '' ) );
		if ( ! $cols ) { if ( $sel['ids'] ) WP_CLI::log( '  ' . implode( ', ', $sel['ids'] ) ); return; }
		$named = $sel['ids']; $rest = [];
		if ( $sel['side'] === 'local' ) $rows = $this->local_rows( $table, $named, $cols );
		else {
			$rest = array_slice( $named, self::LIST_REMOTE_LABELS ); $named = array_slice( $named, 0, self::LIST_REMOTE_LABELS ); $rows = [];
			foreach ( $named as $id ) { $r = $this->remote_row( $c, $table, $id ); if ( is_array( $r ) ) $rows[ $id ] = $r; }
		}
		foreach ( $named as $id ) WP_CLI::log( sprintf( '  %-10s %s', "#{$id}", isset( $rows[ $id ] ) ? IXES_Rowinfo::label( $rows[ $id ], $cols ) : '' ) );
		if ( $rest ) WP_CLI::log( '  and ' . count( $rest ) . ' more: ' . implode( ', ', $rest ) );
	}

	/**
	 * Push local changes to <env>. Rows the remote changed always win.
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
	 * : Overwrite rows the remote changed, when there is no baseline (first deploy).
	 *
	 * [--mirror]
	 * : With --force on a first deploy: also delete, within the scope, the rows and files only the remote has. The pre-push snapshot keeps them for rollback.
	 *
	 * [--plan=<file>]
	 * : Apply a previously saved plan file (diff and push --dry-run print its path). It is applied without planning again when nothing changed on this side since.
	 *
	 * [--replan]
	 * : Plan again even when the last dry run or diff made the same plan less than an hour ago and nothing changed here since.
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
	 * : Comma list of db,files,uploads,themes,plugins,mu-plugins, or all. Default: the environment's --only from env add, else everything.
	 *
	 * [--tables=<tables>]
	 * : Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
	 *
	 * [--paths=<paths>]
	 * : Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma list of table names or globs to leave out, added to the environment's own. @logs covers common log tables.
	 *
	 * [--drop-tables=<tables>]
	 * : Comma list of tables only the remote has (with or without prefix) to drop there, although the baseline does not know them. Each one is checked, copied and kept for rollback first.
	 *
	 * [--backup-dir=<dir>]
	 * : Where the hub writes its .sql copy of every table the push drops. Default: ENVSYNC_BACKUP_DIR, else the plugin's storage folder.
	 *
	 * [--timeout=<seconds>]
	 * : HTTP timeout for this push, overriding the environment's own --timeout (env add).
	 *
	 * [--parallel=<n>]
	 * : File requests in flight at once, 1 to 16. Batching and parallel requests need 0.8.0 on the remote; an older one gets one file per request.
	 * ---
	 * default: 4
	 * ---
	 */
	public function push( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0], $this->timeout_override( $assoc ) ); $par = $this->parallel( $assoc );
		if ( ! empty( $assoc['plan'] ) && ( isset( $assoc['only'] ) || isset( $assoc['tables'] ) || isset( $assoc['paths'] ) ) ) WP_CLI::error( '--plan carries its own scope; drop --only/--tables/--paths' );
		$saved = null;
		if ( ! empty( $assoc['plan'] ) ) {
			$saved = json_decode( file_get_contents( $assoc['plan'] ), true );
			if ( ! $saved ) WP_CLI::error( 'cannot read plan file' );
		}
		global $wpdb;
		// replaying a saved plan re-checks it in the scope it was made with, not the environment's current default
		$scope = $saved ? IXES_Scope::from_array( (array) ( $saved['scope'] ?? [] ), $wpdb->prefix ) : $this->scope( $assoc, $env );
		$mirror = $saved ? ! empty( $saved['mirror'] ) : ! empty( $assoc['mirror'] );
		if ( $saved && ! empty( $assoc['mirror'] ) && empty( $saved['mirror'] ) ) WP_CLI::error( 'that plan was made without --mirror; run push --force --mirror without --plan' );
		if ( $mirror && empty( $assoc['force'] ) ) WP_CLI::error( '--mirror only goes with --force, on a first deploy' );
		$drop = $saved ? array_keys( array_filter( (array) ( $saved['drop_tables'] ?? [] ), function ( $d ) { return ( $d['why'] ?? '' ) === 'asked'; } ) ) : IXES_Droptable::table_list( (string) ( $assoc['drop-tables'] ?? '' ), $wpdb->prefix );
		$guard = $this->plan_guard( 'push', $env['name'], $assoc );
		$progress = $this->tracked( 'push', $env['name'], $assoc );
		$plan = null;
		if ( $saved && ! empty( $saved['local'] ) ) {
			// a plan that records its local fingerprint is applied without planning again; the applier re-checks every remote row and file it touches
			$want = $guard( IXES_Planner::want( $env, $c, $scope, $mirror, $drop ) );
			$why = IXES_Planner::reuse_refusal( $saved, $want, time(), PHP_INT_MAX ) ?? IXES_Planner::local_change( $saved, $env );
			if ( $why !== null ) $guard( new WP_Error( 'stale_plan', "cannot apply that plan: {$why}. Run diff again" ) );
			$plan = $saved;
		} elseif ( $saved ) {
			$plan = $guard( IXES_Planner::build( $env, $c, $scope, $mirror, $drop ) );
			foreach ( $saved['remote_hashes'] as $t => $m ) foreach ( $m as $pk => $h ) if ( ( $plan['remote_hashes'][ $t ][ $pk ] ?? null ) !== $h ) $guard( new WP_Error( 'stale_plan', "{$env['name']} changed {$t}#{$pk} since that plan; run diff again" ) );
			$plan = $saved;
		} elseif ( empty( $assoc['dry-run'] ) && empty( $assoc['replan'] ) && ( $latest = IXES_Planner::load_latest( $env['name'] ) ) ) {
			$want = $guard( IXES_Planner::want( $env, $c, $scope, $mirror, $drop ) );
			$why = IXES_Planner::reuse_refusal( $latest, $want, time() );
			$say = $this->wants_json( $assoc ) ? function () {} : $this->logger();
			if ( $why === null ) { $say( 'checking this site against the plan from the last dry run or diff' ); $why = IXES_Planner::local_change( $latest, $env ); }
			if ( $why === null ) { $plan = $latest; $say( 'reusing plan from ' . wp_date( 'H:i', (int) $latest['created'] ) . ' (last dry run or diff); nothing changed here since' ); }
			else $say( "planning again: {$why}" );
		}
		if ( $plan === null ) {
			$plan = $guard( IXES_Planner::build( $env, $c, $scope, $mirror, $drop ) );
			if ( ! empty( $assoc['dry-run'] ) ) { $saved_path = IXES_Planner::save( $plan, 'push' ); IXES_Planner::save_latest( $plan ); }
		}
		if ( $plan['two_way'] ) {
			if ( empty( $assoc['force'] ) ) { WP_CLI::line( IXES_Planner::render_text( $plan ) ); $guard( new WP_Error( 'no_baseline', "no baseline for {$env['name']}: pull first, or pass --force to overwrite the rows listed as remote-wins" ) ); }
			$plan = IXES_Planner::force( $plan );
			// no baseline says whose mu-plugins these are: they go only when --only names them
			$plan = IXES_Mu::hold_boot( $plan, (array) ( $plan['scope'] ?? [] ) );
		}
		$plan['backup_dir'] = (string) ( $assoc['backup-dir'] ?? '' );
		$report = IXES_Report::from_push_plan( $plan, $guard( $c->info() ), 'push' );
		$manifest = $this->show_report( $report, $assoc );
		if ( $this->wants_json( $assoc ) && ! empty( $assoc['dry-run'] ) ) return;
		WP_CLI::log( "manifest: {$manifest}" );
		$note = IXES_Pull::transfer_note( $c, count( $plan['files']['push'] ), $par, true );
		if ( $note !== '' ) WP_CLI::log( $note );
		if ( IXES_Planner::is_empty( $plan ) ) { WP_CLI::success( 'nothing to push' ); return; }
		if ( ! empty( $assoc['dry-run'] ) ) { if ( isset( $saved_path ) ) self::reuse_hint( $env['name'], $saved_path ); return; }
		$this->confirm( $assoc, "Apply this plan (scope: " . IXES_Scope::from_array( (array) ( $plan['scope'] ?? [] ), '' )->label() . ") to {$env['name']} ({$env['url']})?" );
		// used once: after this push its remote hashes are the old ones, and every row would come back stale
		IXES_Planner::forget_latest( $env['name'] );
		$r = $this->run_recorded( 'push', $env['name'], $report, function () use ( $env, $c, $plan, $progress, $assoc, $par ) { $r = IXES_Applier::apply( $env, $c, $plan, $this->logger(), $progress, $this->error_menu( $assoc ), $par ); $progress->end(); return $r; }, $progress );
		$progress->finish();
		IXES_Planner::mark_applied( $plan, $r['job'] );
		if ( $r['stale'] ) WP_CLI::warning( "skipped (changed on {$env['name']} during push): " . implode( ', ', $r['stale'] ) );
		foreach ( (array) ( $r['kept_tables'] ?? [] ) as $t => $why ) WP_CLI::warning( "kept table {$t} on {$env['name']}: {$why}" );
		$line = IXES_Elementor::line( $r['elementor'] ?? null );
		if ( $line ) WP_CLI::log( $line );
		if ( ! empty( $r['dropped'] ) ) WP_CLI::log( 'dropped on ' . $env['name'] . ': ' . implode( ', ', $r['dropped'] ) . "\nlocal copies:\n  " . implode( "\n  ", (array) $r['backups'] ) );
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
		foreach ( (array) ( $r['errors'] ?? [] ) as $e ) WP_CLI::warning( $e );
		$line = IXES_Elementor::line( $r['elementor'] ?? null );
		if ( $line ) WP_CLI::log( $line );
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
	 * : Job to roll back or quarantine instead.
	 *
	 * [--quarantine-mu]
	 * : Move the mu-plugins and drop-ins the push (--job, else the last one) brought into the storage folder's quarantine/, and put back the versions its snapshot kept. Runs without booting WordPress, so it works when a mu-plugin or drop-in fatals every request.
	 *
	 * [--restore-self]
	 * : Put back the EnvSync folder the last self-update replaced.
	 *
	 * [--from=<version>]
	 * : With --restore-self: the version to put back. Default: the version the remote's backup holds.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function rescue( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		// before 'status': that action boots WordPress, and a mu-plugin or drop-in may be exactly what fatals
		if ( ! empty( $assoc['quarantine-mu'] ) ) {
			$job = isset( $assoc['job'] ) ? (string) $assoc['job'] : '';
			$this->confirm( $assoc, 'Move the mu-plugins and drop-ins ' . ( $job !== '' ? "job {$job}" : 'the last push' ) . " brought to {$env['name']} into quarantine?" );
			$r = $c->rescue( 'quarantine_mu', $job !== '' ? [ 'job' => $job ] : [] );
			if ( is_wp_error( $r ) ) WP_CLI::error( $r->get_error_message() . "\n--quarantine-mu needs EnvSync 0.9.7 on {$env['name']}, and a push made with it (the push leaves the key it checks). Otherwise use the host's file manager: move the new files out of wp-content/mu-plugins." );
			foreach ( (array) $r['errors'] as $e ) WP_CLI::warning( $e );
			WP_CLI::log( 'quarantined: ' . ( $r['quarantined'] ? implode( ', ', $r['quarantined'] ) : 'nothing' ) );
			WP_CLI::log( 'restored from the snapshot: ' . ( $r['restored'] ? implode( ', ', $r['restored'] ) : 'nothing' ) );
			WP_CLI::success( "job {$r['job']}: the files sit in the storage folder under quarantine/{$r['job']}/. Next: wp envsync rescue {$env['name']} --rollback to undo the rest of the push, or wp envsync unlock {$env['name']} to keep it" );
			$this->forget_status();
			return;
		}
		if ( ! empty( $assoc['restore-self'] ) ) {
			$from = (string) ( $assoc['from'] ?? '' );
			if ( $from === '' ) {
				$b = $this->fail_if_error( $c->rescue( 'self_backup' ) );
				$from = (string) ( $b['version'] ?? '' );
				if ( $from === '' ) WP_CLI::error( "{$env['name']} keeps no self-update backup (a successful self-update drops it)" );
			}
			$this->confirm( $assoc, "Put EnvSync {$from} back on {$env['name']}, from the backup its last self-update kept?" );
			$r = $this->fail_if_error( $c->rescue( 'restore_self', [ 'from' => $from ] ) );
			$this->forget_status();
			WP_CLI::success( "EnvSync {$r['restored']} is back on {$env['name']}" );
			return;
		}
		$s = $c->rescue( 'status' );
		if ( is_wp_error( $s ) ) {
			WP_CLI::error( $s->get_error_message() . "\nThe rescue endpoint ({$c->rescue_url()}) did not answer. Either the remote runs a plugin older than 0.5.1, or the host blocks PHP files under wp-content/plugins. Use the host's file manager or terminal: rename the crashing plugin's folder under wp-content/plugins.\nIf it answered with a critical error, a mu-plugin or drop-in crashes, and those load even here: wp envsync rescue {$env['name']} --quarantine-mu" );
		}
		WP_CLI::log( "{$env['name']}  {$env['url']}  (rescue mode: no plugins, no theme)" );
		WP_CLI::log( '  plugin ' . $s['plugin'] . ( $s['maintenance'] ? '  · maintenance file present' : '' ) );
		WP_CLI::log( '  lock   ' . ( $s['lock'] ? "job {$s['lock']['job']}" : 'none' ) . '   last job ' . ( $s['last_job'] ?: 'none' ) );
		WP_CLI::log( '  active ' . ( $s['active_plugins'] ? implode( ', ', $s['active_plugins'] ) : 'none' ) );
		if ( ! empty( $s['self_backup'] ) ) WP_CLI::log( "  self-update backup of EnvSync {$s['self_backup']} kept (--restore-self puts it back)" );
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
	 * Install this hub's EnvSync (or a release zip) on <env>, then check the site; an unhealthy site gets its old version back.
	 * ## OPTIONS
	 *
	 * <env>
	 * : Environment name.
	 *
	 * [--zip=<file>]
	 * : Release zip to install. Default: a zip built from this hub's own plugin folder.
	 *
	 * [--force]
	 * : Install even when the zip is not newer than what the remote runs.
	 *
	 * [--dry-run]
	 * : Show the plan and stop.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * [--timeout=<seconds>]
	 * : HTTP timeout for this run, overriding the environment's own --timeout (env add).
	 *
	 * @subcommand self-update
	 */
	public function self_update( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $t = $this->timeout_override( $assoc );
		$c = $this->client( $args[0], $t );
		$zip = isset( $assoc['zip'] ) ? (string) $assoc['zip'] : null;
		if ( $zip !== null && ! is_file( $zip ) ) WP_CLI::error( "no such file: {$zip}" );
		$plan = $this->fail_if_error( IXES_Selfupdate::plan( $c, $zip, ! empty( $assoc['force'] ) ) );
		WP_CLI::log( "{$env['name']}  {$env['url']}" );
		WP_CLI::log( "  EnvSync {$plan['from']} → {$plan['to']}" . ( version_compare( $plan['to'], $plan['from'], '>' ) ? '' : '  (--force: not newer)' ) );
		WP_CLI::log( '  zip     ' . ( $plan['built'] ? "built from this hub's plugin folder" : $zip ) . ', ' . IXES_Report::size( $plan['size'] ) . ", top folder {$plan['top']}/" );
		WP_CLI::log( "  sha256  {$plan['sha256']}" );
		WP_CLI::log( '  The remote keeps a copy of its current EnvSync folder. If /info or the site fails afterwards, the hub puts it back through the rescue endpoint.' );
		if ( ! empty( $assoc['dry-run'] ) ) return;
		$this->confirm( $assoc, "Install EnvSync {$plan['to']} on {$env['name']}?" );
		$r = IXES_Selfupdate::apply( $env, $c, $plan, $this->logger(), function () use ( $args, $t ) { return $this->client( $args[0], $t ); } );
		$this->forget_status();
		$this->fail_if_error( $r );
		if ( $r['note'] !== '' ) WP_CLI::warning( "the new version runs and the site answers, but: {$r['note']}" );
		WP_CLI::success( "{$env['name']} runs EnvSync {$r['to']} (was {$r['from']}); /info and the site answer" );
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
	 * List every plugin on this site and on <env> side by side: versions, active state, plugins on one side only,
	 * folders with no readable plugin header (orphans) and active_plugins entries whose folder is gone. Read-only.
	 * ## OPTIONS
	 *
	 * <env>
	 * : Environment name.
	 *
	 * [--timeout=<seconds>]
	 * : Per-request timeout for this run only.
	 *
	 * [--format=<format>]
	 * : Machine-readable list. WP-CLI rewrites --json to --format=json itself.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 */
	public function plugins( $args, $assoc ) {
		$info = $this->fail_if_error( $this->client( $args[0], $this->timeout_override( $assoc ) )->info() );
		if ( ! isset( $info['inventory'] ) ) WP_CLI::error( "{$args[0]} runs an EnvSync too old to report its plugins. Run: wp envsync self-update {$args[0]}" );
		$rows = IXES_Report::plugin_inventory( IXES_Transfer::inventory(), (array) $info['inventory'], (array) get_option( 'active_plugins', [] ), (array) ( $info['active_plugins'] ?? [] ) );
		if ( $this->wants_json( $assoc ) ) { WP_CLI::line( wp_json_encode( [ 'env' => $args[0], 'plugins' => $rows ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); return; }
		WP_CLI::line( IXES_Report::render_inventory( $rows, $args[0] ) );
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
