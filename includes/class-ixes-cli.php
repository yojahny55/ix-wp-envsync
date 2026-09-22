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
				$rows[] = [ 'name' => $e['name'], 'label' => $e['label'], 'url' => $e['url'], 'baseline' => $bl->exists() ? date( 'Y-m-d H:i', $bl->meta( 'created_at' ) ) : '-' ];
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
		if ( $action === 'remove' ) { IXES_Env::remove( $args[1] ); WP_CLI::success( 'removed' ); return; }
		if ( $action === 'ping' ) { $r = $this->fail_if_error( $this->client( $args[1] )->get( '/ping' ) ); WP_CLI::success( 'ok, remote time ' . date( 'c', $r['time'] ) ); return; }
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
	 * [--fresh]
	 * : Discard an interrupted pull and start over.
	 */
	public function pull( $args, $assoc ) {
		if ( ! empty( $assoc['flush-cache'] ) ) IXES_Hashcache::flush();
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		if ( ! empty( $assoc['fresh'] ) ) IXES_Pull::discard( $env );
		$state = IXES_PullState::load( $env['name'] );
		if ( $state ) {
			$plan = is_file( (string) $state->get( 'plan' ) ) ? json_decode( file_get_contents( $state->get( 'plan' ) ), true ) : null;
			$info = $this->fail_if_error( $c->info() );
			$why  = ! is_array( $plan ) ? 'saved plan file is missing' : $state->refusal(
				(string) ( $info['plugin'] ?? '' ), (array) $plan['excludes'], IXES_Pull::excludes( $env ),
				(array) ( $plan['extra_replace'] ?? [] ), (array) $env['extra_replace'],
				$state->get( 'table' ) ? IXES_Transfer::tmp_exists( $state->get( 'table' ) ) : true
			);
			if ( $why ) WP_CLI::error( "cannot resume: {$why}. Run again with --fresh to start over." );
			WP_CLI::log( $state->describe( count( $plan['files']['transfer'] ) ) );
			if ( ! empty( $assoc['dry-run'] ) ) return;
			$this->confirm( $assoc, 'Resume?' );
			$this->fail_if_error( IXES_Pull::run( $env, $c, $plan, $this->logger(), $state ) );
			WP_CLI::success( "pulled {$env['name']}; baseline recorded" );
			return;
		}
		$plan = $this->fail_if_error( IXES_Pull::plan( $env, $c ) );
		// ... existing plan printing (rows, files, rewrite, excludes, --details) unchanged ...
		$rows = array_sum( array_column( $plan['tables'], 'rows' ) );
		WP_CLI::log( sprintf( "PULL %s → local\n  tables: %d (%d rows)\n  files: %d to transfer, %d to delete\n  rewrite:", $env['name'], count( $plan['tables'] ), $rows, count( $plan['files']['transfer'] ), count( $plan['files']['delete'] ) ) );
		foreach ( $plan['pairs'] as $p ) WP_CLI::log( "    {$p[0]}  →  {$p[1]}" );
		WP_CLI::log( '  excludes: ' . implode( ', ', $plan['excludes'] ) );
		if ( ! empty( $assoc['details'] ) || ! empty( $assoc['verbose'] ) ) {
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
		if ( ! empty( $assoc['dry-run'] ) ) return;
		$this->confirm( $assoc, 'This OVERWRITES the local database and wp-content. Continue?' );
		$this->fail_if_error( IXES_Pull::run( $env, $c, $plan, $this->logger() ) );
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
	 * : Output the plan as JSON.
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
	 */
	public function diff( $args, $assoc ) {
		if ( ! empty( $assoc['flush-cache'] ) ) IXES_Hashcache::flush();
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		$plan = $this->fail_if_error( IXES_Planner::build( $env, $c ) );
		$path = IXES_Planner::save( $plan );
		if ( ! empty( $assoc['table'] ) && ! empty( $assoc['id'] ) ) { $this->field_diff( $c, $assoc['table'], $assoc['id'], $plan ); return; }
		if ( ! empty( $assoc['json'] ) ) { WP_CLI::line( IXES_Planner::render_json( $plan ) ); return; }
		WP_CLI::line( IXES_Planner::render_text( $plan ) );
		if ( ! empty( $assoc['details'] ) || ! empty( $assoc['verbose'] ) ) {
			foreach ( $plan['tables'] as $name => $t ) foreach ( [ 'push', 'insert', 'delete', 'conflict' ] as $k ) if ( $t[ $k ] ) WP_CLI::log( "  {$name} {$k}: " . implode( ', ', $t[ $k ] ) );
			foreach ( [ 'push', 'delete', 'conflict' ] as $k ) foreach ( $plan['files'][ $k ] as $rel ) WP_CLI::log( "  file {$k}: {$rel}" );
		}
		WP_CLI::log( "plan saved: {$path}" );
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
	 */
	public function push( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		$plan = $this->fail_if_error( IXES_Planner::build( $env, $c ) );
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
		WP_CLI::line( IXES_Planner::render_text( $plan ) );
		if ( IXES_Planner::is_empty( $plan ) ) { WP_CLI::success( 'nothing to push' ); return; }
		if ( ! empty( $assoc['dry-run'] ) ) return;
		$this->confirm( $assoc, "Apply this plan to {$env['name']} ({$env['url']})?" );
		$r = $this->fail_if_error( IXES_Applier::apply( $env, $c, $plan, $this->logger() ) );
		if ( $r['stale'] ) WP_CLI::warning( 'skipped (changed on prod during push): ' . implode( ', ', $r['stale'] ) );
		update_option( 'ixes_last_jobs', array_slice( array_merge( [ [ 'env' => $env['name'], 'job' => $r['job'], 'at' => time(), 'stale' => $r['stale'] ] ], (array) get_option( 'ixes_last_jobs', [] ) ), 0, 5 ), false );
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
	 * Show or rotate this site's remote token.
	 * ## OPTIONS
	 *
	 * [--rotate]
	 * : Issue a new token.
	 */
	public function token( $args, $assoc ) {
		if ( ! empty( $assoc['rotate'] ) || ! get_option( 'ixes_token_hash' ) ) { WP_CLI::line( IXES_Auth::install_token() ); return; }
		$t = get_transient( 'ixes_token_show' );
		if ( $t ) WP_CLI::line( $t ); else WP_CLI::error( 'token already shown; use --rotate to issue a new one' );
	}
}
