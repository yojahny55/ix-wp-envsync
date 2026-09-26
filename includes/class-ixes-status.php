<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * "Where am I and what do I do next." Pure apart from the injectable readers; one /info call per env.
 * The rule order below is the contract: the first rule that matches decides `next`.
 */
class IXES_Status {
	const LOCK_STALE_MIN = 10;   // minutes before a remote lock is presumed dead
	const BASELINE_OLD_DAYS = 7;
	// ponytail: a fresh install has 3-4 wp_posts rows (Hello world, Sample page, Privacy policy, an auto-draft); any real site has far more. Check users too if this ever misfires.
	const FRESH_MAX_POSTS = 5;
	// rule order (the contract above): unconfigured, remote_only, crashing (500), unreachable, old_remote, stale_lock, interrupted_pull, no_baseline, old_baseline, ready
	// admin page renders this synchronously on a cache miss, so a dead remote must fail fast and stay under wp-admin's max_execution_time
	const INFO_TIMEOUT = 10;

	private static function defaults() {
		return [
			'now'           => time(),
			'hub_version'   => IXES_VERSION,
			'token_issued'  => (bool) get_option( 'ixes_token_hash' ),
			'token_pending' => (bool) get_transient( 'ixes_token_show' ),
			'envs'          => IXES_Env::all(),
			'baseline'      => function ( $name ) {
				$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $name . '.sqlite' );
				return [ 'created_at' => $bl->meta( 'created_at' ), 'partial_at' => $bl->meta( 'partial_at' ), 'partial_scope' => $bl->meta( 'partial_scope' ), 'scope' => $bl->meta( 'baseline_scope' ) ?: null ];
			},
			'pull_state'    => function ( $name ) {
				$s = IXES_PullState::load( $name );
				if ( ! $s ) return null;
				$plan = is_file( (string) $s->get( 'plan' ) ) ? json_decode( file_get_contents( $s->get( 'plan' ) ), true ) : null;
				return [ 'started' => $s->get( 'started' ), 'table' => $s->get( 'table' ), 'cursor' => $s->get( 'cursor' ), 'files_done' => $s->get( 'files_done' ), 'files_total' => is_array( $plan ) ? count( $plan['files']['transfer'] ?? [] ) : null ];
			},
		];
	}

	public static function build( $env_name = null, callable $info_for = null, array $ctx = null ) {
		$ctx = ( $ctx ?: [] ) + self::defaults();
		if ( $info_for === null ) $info_for = function ( array $env ) { return ( new IXES_Client( $env ) )->info( IXES_Status::INFO_TIMEOUT ); };
		$envs = $ctx['envs'];
		if ( $env_name !== null ) $envs = isset( $envs[ $env_name ] ) ? [ $env_name => $envs[ $env_name ] ] : [];
		$is_hub = ! empty( $ctx['envs'] );
		$role = $is_hub && $ctx['token_issued'] ? 'both' : ( $is_hub ? 'hub' : ( $ctx['token_issued'] ? 'remote' : 'unconfigured' ) );
		$report = [ 'role' => $role, 'hub_version' => $ctx['hub_version'], 'token' => [ 'issued' => $ctx['token_issued'], 'shown_pending' => $ctx['token_pending'] ], 'envs' => [], 'next' => null ];

		if ( $role === 'unconfigured' ) {
			$report['next'] = [ 'command' => 'wp envsync token  (remote)  |  wp envsync env add <name> <url> --token=…  (hub)', 'why' => 'this site is not set up yet', 'env' => null ];
			return $report;
		}
		if ( $role === 'remote' ) {
			$report['next'] = [ 'command' => $ctx['token_pending'] ? 'wp envsync token' : '', 'why' => 'this site is a remote; run pull/diff/push from the hub', 'env' => null ];
			return $report;
		}

		$decided = null;
		foreach ( $envs as $name => $env ) {
			$e = self::env_facts( $env, $info_for, $ctx );
			$report['envs'][ $name ] = $e;
			$n = self::next_for( $name, $env, $e, $ctx );
			if ( $decided === null && $n['why'] !== 'ready' ) $decided = $n;
			if ( $decided === null && $name === array_key_last( $envs ) ) $decided = $n;
		}
		$report['next'] = $decided ?: [ 'command' => '', 'why' => 'no such environment', 'env' => $env_name ];
		return $report;
	}

	private static function env_facts( array $env, callable $info_for, array $ctx ) {
		$now = $ctx['now'];
		$e = [ 'url' => $env['url'], 'label' => $env['label'] ?? '', 'reachable' => false, 'error' => null, 'remote_version' => null, 'version_ok' => null, 'auth_via' => null,
			'baseline' => null, 'interrupted_pull' => null, 'remote_lock' => null, 'remote_posts' => null, 'prefix_map' => null, 'excludes_count' => count( (array) ( $env['excludes'] ?? [] ) ) ];
		$info = $info_for( $env );
		if ( is_wp_error( $info ) ) { $e['error'] = $info->get_error_message(); }
		else {
			$e['reachable'] = true;
			$e['remote_version'] = (string) ( $info['plugin'] ?? '' );
			$e['version_ok'] = $e['remote_version'] === '' ? null : version_compare( $e['remote_version'], $ctx['hub_version'], '>=' );
			$e['auth_via'] = $info['auth_via'] ?? null;
			// a remote with another prefix reports its tables in the hub's names (IXES_Client::info())
			$names_prefix = $info['hub_prefix'] ?? ( $info['prefix'] ?? '' );
			foreach ( (array) ( $info['tables'] ?? [] ) as $t ) if ( $t['name'] === $names_prefix . 'posts' ) $e['remote_posts'] = (int) $t['rows'];
			if ( ! empty( $info['hub_prefix'] ) ) $e['prefix_map'] = "{$info['prefix']} → {$info['hub_prefix']}";
			if ( ! empty( $info['lock']['job'] ) ) {
				$st = $info['lock']['started'] ?? null;
				$e['remote_lock'] = [ 'job' => $info['lock']['job'], 'started' => $st, 'age_minutes' => $st ? (int) floor( ( $now - $st ) / 60 ) : null ];
			}
		}
		$b = $ctx['baseline']( $env['name'] );
		$e['baseline'] = $b + [ 'age_days' => $b['created_at'] ? (int) floor( ( $now - $b['created_at'] ) / 86400 ) : null ];
		$e['interrupted_pull'] = $ctx['pull_state']( $env['name'] );
		return $e;
	}

	private static function next_for( $name, array $env, array $e, array $ctx ) {
		$cmd = function ( $c, $why ) use ( $name ) { return [ 'command' => $c, 'why' => $why, 'env' => $name ]; };
		if ( ! $e['reachable'] && strpos( (string) $e['error'], 'remote 500' ) === 0 ) return $cmd( "wp envsync rescue {$name}", "{$env['url']} crashes on every request (a plugin or a half-finished push); rescue works without loading plugins" );
		if ( ! $e['reachable'] && strpos( (string) $e['error'], 'HTTP Basic Auth' ) !== false ) return $cmd( "wp envsync env add {$name} --basic-auth=<user:pass>", "{$env['url']} is password-protected by its web server: {$e['error']}" );
		if ( ! $e['reachable'] ) return $cmd( "wp envsync env add {$name} --token=<new token>", "cannot reach {$env['url']}: {$e['error']}" );
		if ( $e['version_ok'] === false ) return $cmd( "upload the release zip to {$env['url']}", "remote runs {$e['remote_version']}, hub runs {$ctx['hub_version']}" );
		$lock = $e['remote_lock'];
		if ( $lock && ( $lock['age_minutes'] === null || $lock['age_minutes'] >= self::LOCK_STALE_MIN ) ) return $cmd( "wp envsync unlock {$name}", 'a push started ' . ( $lock['age_minutes'] === null ? 'some time' : $lock['age_minutes'] . ' minutes' ) . ' ago never finished' );
		if ( $e['interrupted_pull'] ) return $cmd( "wp envsync pull {$name}", 'an interrupted pull can be resumed (or start over with --fresh)' );
		if ( empty( $e['baseline']['created_at'] ) && $e['remote_posts'] !== null && $e['remote_posts'] <= self::FRESH_MAX_POSTS ) return $cmd( "wp envsync push {$name} --force --dry-run", "no baseline and {$env['url']} looks like a fresh install ({$e['remote_posts']} posts): first deploy? pulling would overwrite this site with it" );
		if ( empty( $e['baseline']['created_at'] ) ) return $cmd( "wp envsync pull {$name}", 'no baseline: pull before any push' );
		if ( $e['baseline']['age_days'] >= self::BASELINE_OLD_DAYS ) return $cmd( "wp envsync diff {$name}", "baseline is {$e['baseline']['age_days']} days old; consider pulling first" );
		return $cmd( "wp envsync diff {$name}", 'ready' );
	}

	public static function render_text( array $r ) {
		$o = [];
		if ( $r['role'] === 'unconfigured' ) {
			$o[] = 'This site is not set up for EnvSync yet.';
			$o[] = '  If this is a remote (production/staging): run  wp envsync token  and copy the token to your hub.';
			$o[] = '  If this is the hub (your local site):     run  wp envsync env add prod https://client.com --token=…';
			return implode( "\n", $o ) . "\n";
		}
		if ( $r['role'] === 'remote' ) {
			$o[] = 'This site is a remote (token issued). Run pull, diff and push from the hub.';
			if ( $r['token']['shown_pending'] ) $o[] = '  The token has not been read yet: wp envsync token';
			return implode( "\n", $o ) . "\n";
		}
		$d = function ( $t ) { return $t ? wp_date( 'Y-m-d H:i T', (int) $t ) : '-'; };
		foreach ( $r['envs'] as $name => $e ) {
			$o[] = "{$name}  {$e['url']}  ({$e['label']})";
			if ( ! $e['reachable'] ) { $o[] = "  unreachable: {$e['error']}"; }
			else {
				$via = $e['auth_via'] === 'x-envsync-token' ? 'X-Envsync-Token' : 'Authorization';
				$o[] = "  remote {$e['remote_version']}  hub {$r['hub_version']}  auth via {$via}" . ( $e['version_ok'] === false ? '  (remote is older)' : '' ) . ( ! empty( $e['prefix_map'] ) ? "  prefix {$e['prefix_map']}" : '' );
			}
			$b = $e['baseline'];
			$line = '  baseline ' . ( $b['created_at'] ? $d( $b['created_at'] ) . " ({$b['age_days']} days" . ( ! empty( $b['scope'] ) ? ", {$b['scope']}" : '' ) . ')' : 'none' );
			if ( $b['partial_at'] ) $line .= ' · partial ' . $d( $b['partial_at'] ) . " ({$b['partial_scope']})";
			$o[] = $line;
			if ( $p = $e['interrupted_pull'] ) $o[] = '  interrupted pull: started ' . $d( $p['started'] ) . ( $p['table'] ? ", stopped in {$p['table']}" : ', tables done' ) . ", {$p['files_done']}/" . ( $p['files_total'] ?? '?' ) . ' files';
			if ( $l = $e['remote_lock'] ) $o[] = "  lock: job {$l['job']}, " . ( $l['age_minutes'] === null ? 'unknown age' : "{$l['age_minutes']} min old" );
			$o[] = '';
		}
		$o[] = "Next: {$r['next']['command']}   ({$r['next']['why']})";
		return implode( "\n", $o ) . "\n";
	}
}
