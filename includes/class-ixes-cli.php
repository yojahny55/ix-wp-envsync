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
	 * <action>
	 * : add|list|remove|ping
	 * [<name>]
	 * [<url>]
	 * [--token=<token>]
	 * [--label=<label>]
	 * [--replace=<pairs>]
	 * [--exclude=<paths>]
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
			$replace = [];
			foreach ( array_filter( explode( ',', $assoc['replace'] ?? '' ) ) as $p ) { $x = explode( ':', $p, 2 ); if ( count( $x ) === 2 ) $replace[] = $x; }
			try {
				IXES_Env::add( [ 'name' => $args[1], 'url' => $args[2], 'token' => $assoc['token'] ?? '', 'label' => $assoc['label'] ?? 'prod', 'extra_replace' => $replace, 'excludes' => array_filter( explode( ',', $assoc['exclude'] ?? '' ) ) ] );
			} catch ( InvalidArgumentException $e ) { WP_CLI::error( $e->getMessage() ); }
			WP_CLI::success( "env {$args[1]} saved" );
			return;
		}
		if ( $action === 'remove' ) { IXES_Env::remove( $args[1] ); WP_CLI::success( 'removed' ); return; }
		if ( $action === 'ping' ) { $r = $this->fail_if_error( $this->client( $args[1] )->get( '/ping' ) ); WP_CLI::success( 'ok, remote time ' . date( 'c', $r['time'] ) ); return; }
		WP_CLI::error( 'unknown action' );
	}

	/**
	 * Pull a full snapshot from <env> into this site (overwrite). Records baseline.
	 * ## OPTIONS
	 * <env>
	 * [--yes]
	 * [--dry-run]
	 */
	public function pull( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		$plan = $this->fail_if_error( IXES_Pull::plan( $env, $c ) );
		$rows = array_sum( array_column( $plan['tables'], 'rows' ) );
		WP_CLI::log( sprintf( "PULL %s → local\n  tables: %d (%d rows)\n  files: %d to transfer, %d to delete\n  rewrite:", $env['name'], count( $plan['tables'] ), $rows, count( $plan['files']['transfer'] ), count( $plan['files']['delete'] ) ) );
		foreach ( $plan['pairs'] as $p ) WP_CLI::log( "    {$p[0]}  →  {$p[1]}" );
		WP_CLI::log( '  excludes: ' . implode( ', ', $plan['excludes'] ) );
		if ( ! empty( $assoc['dry-run'] ) ) return;
		$this->confirm( $assoc, 'This OVERWRITES the local database and wp-content. Continue?' );
		$this->fail_if_error( IXES_Pull::run( $env, $c, $plan, $this->logger() ) );
		WP_CLI::success( "pulled {$env['name']}; baseline recorded" );
	}

	/**
	 * Show what a push to <env> would change.
	 * ## OPTIONS
	 * <env>
	 * [--json]
	 * [--verbose]
	 * [--table=<table>]
	 * [--id=<pk>]
	 */
	public function diff( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		$plan = $this->fail_if_error( IXES_Planner::build( $env, $c ) );
		$path = IXES_Planner::save( $plan );
		if ( ! empty( $assoc['table'] ) && ! empty( $assoc['id'] ) ) { $this->field_diff( $c, $assoc['table'], $assoc['id'], $plan ); return; }
		if ( ! empty( $assoc['json'] ) ) { WP_CLI::line( IXES_Planner::render_json( $plan ) ); return; }
		WP_CLI::line( IXES_Planner::render_text( $plan ) );
		if ( ! empty( $assoc['verbose'] ) ) {
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
	 * <env>
	 * [--yes]
	 * [--dry-run]
	 * [--force]
	 * [--plan=<file>]
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
	 * <env>
	 * [--job=<id>]
	 * [--yes]
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
	 * [--rotate]
	 */
	public function token( $args, $assoc ) {
		if ( ! empty( $assoc['rotate'] ) || ! get_option( 'ixes_token_hash' ) ) { WP_CLI::line( IXES_Auth::install_token() ); return; }
		$t = get_transient( 'ixes_token_show' );
		if ( $t ) WP_CLI::line( $t ); else WP_CLI::error( 'token already shown; use --rotate to issue a new one' );
	}
}
