<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Admin {
	public static function register() {
		add_management_page( 'EnvSync', 'EnvSync', 'manage_options', 'ix-envsync', [ __CLASS__, 'page' ] );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( isset( $_POST['ixes_rotate'] ) && check_admin_referer( 'ixes_rotate' ) ) IXES_Auth::install_token();
		if ( isset( $_POST['ixes_refresh_dirs'] ) && check_admin_referer( 'ixes_excludes' ) ) self::forget_dir_sizes();
		if ( isset( $_POST['ixes_self_update'] ) && check_admin_referer( 'ixes_self_update' ) ) update_option( IXES_Selfupdate::OPTION, sanitize_key( wp_unslash( $_POST['ixes_self_update'] ) ) === 'off' ? 1 : 0, false );
		$jobs_notice = self::handle_jobs();
		list( $saved_excludes, $excludes_error ) = self::save_excludes();
		$token = get_transient( 'ixes_token_show' );
		echo '<div class="wrap"><h1>EnvSync</h1>';
		if ( $excludes_error ) echo '<div class="notice notice-error"><p>' . esc_html( $excludes_error ) . '</p></div>';
		if ( $jobs_notice ) printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $jobs_notice[0] ), esc_html( $jobs_notice[1] ) );
		self::status_section();

		echo '<h2>This site\'s token</h2>';
		if ( $token ) echo '<p>Copy it now, it is shown once:</p><code style="font-size:14px;user-select:all">' . esc_html( $token ) . '</code>';
		else echo '<p>Token already issued. Rotate to get a new one (the old one stops working).</p>';
		echo '<p><strong>Anyone holding this token can write to this site\'s database and to every file in wp-content, plugin PHP included — treat it like an admin password.</strong></p>';
		echo '<form method="post">'; wp_nonce_field( 'ixes_rotate' ); submit_button( 'Rotate token', 'secondary', 'ixes_rotate', false ); echo '</form>';
		self::self_update_section();

		$envs = IXES_Env::all();
		if ( $envs ) {
			echo '<h2>Environments</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Label</th><th>URL</th><th>Baseline</th></tr></thead><tbody>';
			foreach ( $envs as $e ) {
				$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $e['name'] . '.sqlite' );
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $e['name'] ), esc_html( $e['label'] ), esc_html( $e['url'] ), esc_html( $bl->baseline_label() ) );
			}
			echo '</tbody></table>';
		}

		self::excludes_section( $envs, $saved_excludes );

		$jobs = (array) get_option( 'ixes_last_jobs', [] );
		if ( $jobs ) {
			echo '<h2>Last pushes</h2><ul>';
			foreach ( $jobs as $j ) printf( '<li>%s → <b>%s</b> job %s%s</li>', esc_html( wp_date( 'Y-m-d H:i', $j['at'] ) ), esc_html( $j['env'] ), esc_html( $j['job'] ), $j['stale'] ? ' <em>(skipped: ' . esc_html( implode( ', ', $j['stale'] ) ) . ')</em>' : '' );
			echo '</ul>';
		}

		$plans = array_filter( (array) glob( ixes_storage_dir() . '/plans/plan-*.json' ), function ( $f ) { return strpos( basename( $f ), 'plan-pull-' ) !== 0; } );
		if ( $plans ) {
			sort( $plans ); $plan = json_decode( file_get_contents( end( $plans ) ), true );
			if ( is_array( $plan ) ) echo '<h2>Last plan (' . esc_html( basename( end( $plans ) ) ) . ')</h2><pre style="background:#fff;padding:12px;overflow:auto">' . esc_html( IXES_Planner::render_text( $plan ) ) . '</pre>';
		}

		$rjobs = glob( ixes_storage_dir() . '/jobs/*/meta.json' );
		if ( $rjobs ) {
			sort( $rjobs ); $m = json_decode( file_get_contents( end( $rjobs ) ), true );
			if ( is_array( $m ) ) printf( '<h2>Last received push</h2><p>Job %s at %s, %d tables touched. Rollback with <code>wp envsync rollback &lt;env&gt;</code> from the hub.</p>', esc_html( $m['job'] ?? '' ), esc_html( wp_date( 'Y-m-d H:i', $m['started'] ?? 0 ) ), count( (array) ( $m['plan']['tables'] ?? [] ) ) );
		}
		self::jobs_section();
		echo '</div>';
	}

	/** @return array{0:string,1:string}|null notice type and text after a delete from the push snapshots table */
	private static function handle_jobs() {
		if ( isset( $_POST['ixes_delete_job'] ) && check_admin_referer( 'ixes_jobs' ) ) {
			$job = sanitize_key( wp_unslash( $_POST['ixes_delete_job'] ) );
			$r = IXES_Applier::delete_job( $job );
			return is_wp_error( $r ) ? [ 'error', "Job {$job}: " . $r->get_error_message() ] : [ 'success', "Deleted the snapshot of job {$job}." ];
		}
		if ( isset( $_POST['ixes_prune_jobs'] ) && check_admin_referer( 'ixes_jobs' ) ) {
			$gone = IXES_Applier::prune_jobs( false, null, 1 );
			return [ 'success', $gone ? 'Deleted ' . count( $gone ) . ' snapshot(s); the last push stays.' : 'Nothing to delete.' ];
		}
		return null;
	}

	/** The rollback copies this site keeps of the pushes it received: size, state, and a delete per job. */
	private static function jobs_section() {
		$jobs = IXES_Applier::jobs_list();
		if ( ! $jobs ) return;
		$labels = [ 'running' => 'running', 'finished' => 'finished', 'rolled_back' => 'rolled back', 'unlocked' => 'unlocked half-way', 'unknown' => 'not recorded (older EnvSync, or the push died)' ];
		$keep = defined( 'IXES_KEEP_JOBS' ) ? (int) IXES_KEEP_JOBS : IXES_Applier::KEEP_JOBS;
		$ttl  = defined( 'IXES_JOB_TTL_DAYS' ) ? (int) IXES_JOB_TTL_DAYS : IXES_Applier::JOB_TTL_DAYS;
		echo '<h2>Push snapshots</h2>';
		printf( '<p>Before each push this site copies what the push overwrites or deletes, so <code>wp envsync rollback &lt;env&gt;</code> can restore it. The copies hold database rows (users included) and files. The last %d are kept, none older than %d days; deleting one here means that push can no longer be rolled back.</p>', (int) $keep, (int) $ttl );
		echo '<form method="post">'; wp_nonce_field( 'ixes_jobs' );
		echo '<table class="widefat striped"><thead><tr><th>Job</th><th>Started</th><th>State</th><th>Size</th><th></th></tr></thead><tbody>';
		$total = 0;
		foreach ( $jobs as $j ) {
			$total += $j['bytes'];
			$button = $j['state'] === 'running' ? '' : '<button type="submit" class="button button-small" name="ixes_delete_job" value="' . esc_attr( $j['job'] ) . '">Delete</button>';
			printf( '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $j['job'] ), esc_html( wp_date( 'Y-m-d H:i', $j['started'] ) ), esc_html( $labels[ $j['state'] ] ?? $j['state'] ), esc_html( size_format( $j['bytes'] ) ), $button ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $button is built from escaped parts above
		}
		printf( '</tbody><tfoot><tr><th colspan="3">Total</th><th>%s</th><th></th></tr></tfoot></table>', esc_html( size_format( $total ) ) );
		if ( count( $jobs ) > 1 ) { echo '<p>'; submit_button( 'Delete all but the last', 'secondary', 'ixes_prune_jobs', false ); echo '</p>'; }
		echo '</form>';
	}

	/** The self-update switch and its log. Installing code is more than the sync itself does, so a site can refuse it. */
	private static function self_update_section() {
		echo '<h2>Self-update</h2>';
		if ( IXES_Selfupdate::by_constant() ) {
			echo '<p>Off: <code>ENVSYNC_DISABLE_SELF_UPDATE</code> is set in <code>wp-config.php</code>. Upgrade EnvSync here through Plugins &rarr; Add New.</p>';
		} else {
			$on = IXES_Selfupdate::enabled();
			echo '<p>' . ( $on
				? 'On: a hub holding this site\'s token can install a newer EnvSync here with <code>wp envsync self-update &lt;env&gt;</code>. The current folder is kept, and put back when the site stops answering.'
				: 'Off: a hub cannot install EnvSync here; upgrade it through Plugins &rarr; Add New.' ) . ' To lock it off for good, add <code>define( \'ENVSYNC_DISABLE_SELF_UPDATE\', true );</code> to <code>wp-config.php</code>.</p>';
			echo '<form method="post">'; wp_nonce_field( 'ixes_self_update' );
			printf( '<input type="hidden" name="ixes_self_update" value="%s">', $on ? 'off' : 'on' );
			submit_button( $on ? 'Turn self-update off' : 'Turn self-update on', 'secondary', 'ixes_self_update_submit', false );
			echo '</form>';
		}
		$log = array_reverse( IXES_Selfupdate::log_tail( 5 ) );
		if ( ! $log ) return;
		echo '<ul>';
		foreach ( $log as $l ) {
			$what = [ 'install' => 'installed', 'install_failed' => 'install failed', 'restore' => 'restored' ][ $l['event'] ?? '' ] ?? (string) ( $l['event'] ?? '' );
			printf( '<li>%s &mdash; %s %s%s%s%s</li>', esc_html( wp_date( 'Y-m-d H:i', (int) ( $l['at'] ?? 0 ) ) ), esc_html( $what ),
				esc_html( isset( $l['from'] ) ? "{$l['from']} → {$l['to']}" : (string) ( $l['to'] ?? '' ) ),
				! empty( $l['hub'] ) || ! empty( $l['by'] ) ? esc_html( ' by ' . trim( ( $l['by'] ?? '' ) . ' ' . ( $l['hub'] ?? '' ) ) ) : '',
				! empty( $l['ip'] ) ? esc_html( " from {$l['ip']}" ) : '',
				! empty( $l['sha256'] ) ? ' <small><code>' . esc_html( substr( $l['sha256'], 0, 12 ) ) . '</code></small>' : ''
			);
		}
		echo '</ul>';
	}

	/** Same report as `wp envsync status`, cached 60 s so a dead remote cannot slow the page. */
	private static function status_section() {
		$r = get_transient( 'ixes_status_report' );
		if ( ! is_array( $r ) ) { $r = IXES_Status::build(); set_transient( 'ixes_status_report', $r, 60 ); }
		echo '<h2>Status</h2>';
		$roles = [ 'hub' => 'This site is the hub: you run pull, diff and push from here.', 'remote' => 'This site is a remote: commands run from your hub.', 'both' => 'This site is both a hub and a remote.', 'unconfigured' => 'This site is not set up yet.' ];
		echo '<p>' . esc_html( $roles[ $r['role'] ] ) . '</p>';
		if ( $r['envs'] ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Environment</th><th>Reachable</th><th>Version</th><th>Baseline</th><th>Interrupted pull</th><th>Lock</th></tr></thead><tbody>';
			foreach ( $r['envs'] as $name => $e ) {
				$b = $e['baseline'];
				printf( '<tr><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $name ), esc_html( $e['url'] ),
					$e['reachable'] ? 'yes' : '<span style="color:#b32d2e">no: ' . esc_html( (string) $e['error'] ) . '</span>',
					esc_html( $e['remote_version'] ?: '?' ) . ( $e['version_ok'] === false ? ' <em>(older than hub)</em>' : '' ),
					esc_html( $b['created_at'] ? wp_date( 'Y-m-d H:i', $b['created_at'] ) . " ({$b['age_days']} d)" : 'none' ) . ( $b['partial_at'] ? '<br><small>partial ' . esc_html( wp_date( 'Y-m-d H:i', $b['partial_at'] ) . " ({$b['partial_scope']})" ) . '</small>' : '' ),
					$e['interrupted_pull'] ? esc_html( "{$e['interrupted_pull']['files_done']}/" . ( $e['interrupted_pull']['files_total'] ?? '?' ) . ' files' ) : '—',
					$e['remote_lock'] ? esc_html( "job {$e['remote_lock']['job']}, " . ( $e['remote_lock']['age_minutes'] === null ? '?' : $e['remote_lock']['age_minutes'] ) . ' min' ) : '—'
				);
			}
			echo '</tbody></table>';
		}
		if ( ! empty( $r['next']['command'] ) ) echo '<div class="notice notice-info inline"><p><strong>Next:</strong> <code>' . esc_html( $r['next']['command'] ) . '</code> &mdash; ' . esc_html( $r['next']['why'] ) . '</p></div>';
	}

	const BIG  = 104857600; // 100 MB: shown as a neutral note, never a recommendation
	const JUNK = [ 'ai1wm-backups', 'updraft', 'updraftplus', 'backup', 'backups', 'cache', 'wpvivid', 'duplicator' ];
	// core content: never recommended or pre-ticked, whatever its name or size. Still manually tickable.
	const NEVER = [ 'uploads', 'themes', 'plugins', 'mu-plugins', 'languages' ];

	private static function recommended( $folder ) {
		$n = rtrim( $folder, '/' );
		if ( in_array( $n, self::NEVER, true ) ) return false;
		return in_array( $n, self::JUNK, true ) || strpos( $n, 'backup' ) !== false;
	}

	/** Handles the exclude form; returns [ saved env name, error message ]. */
	private static function save_excludes() {
		if ( ! isset( $_POST['ixes_excludes_env'] ) || isset( $_POST['ixes_refresh_dirs'] ) ) return [ '', '' ];
		check_admin_referer( 'ixes_excludes' );
		if ( ! current_user_can( 'manage_options' ) ) return [ '', '' ];
		$name = sanitize_key( wp_unslash( $_POST['ixes_excludes_env'] ) );
		$env  = IXES_Env::get( $name );
		if ( ! $env ) return [ '', '' ];

		$defaults = IXES_Env::default_excludes();
		$picked   = [];
		// is_string first: a crafted POST can nest arrays, which would fatal in sanitize_text_field
		foreach ( array_filter( (array) wp_unslash( $_POST['ixes_exclude'] ?? [] ), 'is_string' ) as $f ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each element is sanitized on the next line
			$f = trim( sanitize_text_field( $f ) );
			// only top-level "name/" forms come from this screen
			if ( preg_match( '#^[^/]+/$#', $f ) && ! in_array( $f, $defaults, true ) ) $picked[] = $f;
		}
		// keep whatever the CLI put there that this screen cannot express (single files, nested paths)
		$keep = [];
		foreach ( (array) ( $env['excludes'] ?? [] ) as $f ) {
			if ( is_string( $f ) && ! preg_match( '#^[^/]+/$#', $f ) ) $keep[] = $f;
		}
		$env['excludes'] = array_values( array_unique( array_merge( $keep, $picked ) ) );
		// once saved from this screen the operator's ticks are authoritative: stop pre-ticking
		// recommendations, or unticking one would silently come back on the next save
		$env['excludes_configured'] = true;
		try {
			IXES_Env::add( $env );
		} catch ( InvalidArgumentException $e ) {
			return [ '', $e->getMessage() ];
		}
		return [ $name, '' ];
	}

	private static function forget_dir_sizes() {
		delete_transient( 'ixes_dirs_local' );
		foreach ( IXES_Env::all() as $e ) delete_transient( 'ixes_dirs_' . $e['name'] );
	}

	/** wp-content sizes, cached 5 minutes; $env null means this site. */
	private static function dir_sizes( $env = null ) {
		$key = 'ixes_dirs_' . ( $env ? $env['name'] : 'local' );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) return $hit;
		$res = $env ? ( new IXES_Client( $env ) )->post( '/dirs', [] ) : IXES_Transfer::dir_sizes();
		if ( is_wp_error( $res ) ) return $res; // do not cache a failure
		set_transient( $key, $res, 300 );
		return $res;
	}

	private static function excludes_section( array $envs, $saved ) {
		if ( ! $envs ) return;
		$local    = self::dir_sizes();
		if ( is_wp_error( $local ) || ! isset( $local['dirs'] ) ) $local = [ 'dirs' => [] ];
		$defaults = IXES_Env::default_excludes();

		echo '<h2>Sync excludes</h2><p>Every top-level folder in <code>wp-content</code>, with what it costs on each side. Ticked folders are skipped by pull, diff and push. Sizes are cached for 5 minutes.</p>';
		echo '<p><em>Files are compared by modification time and size, so a file rewritten in place to the same size within the same second, or restored with its mtime preserved, is treated as unchanged and stops syncing. Run <code>wp envsync diff &lt;env&gt; --flush-cache</code> if a change is not being picked up.</em></p>';
		if ( $saved ) echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( 'Excludes saved for %s.', $saved ) ) . '</p></div>';

		foreach ( $envs as $e ) {
			$rows   = [];
			$err    = '';
			$remote = [];
			// one call per env, once per page render, cached 5 minutes; a slow or dead remote
			// must not break the page
			$res = self::dir_sizes( $e );
			if ( is_wp_error( $res ) ) {
				$err = $res->get_error_message();
			} else {
				foreach ( (array) ( $res['dirs'] ?? [] ) as $d ) $remote[ (string) $d['path'] ] = $d;
			}

			$cur       = (array) ( $e['excludes'] ?? [] );
			$configured = ! empty( $e['excludes_configured'] );
			foreach ( array_unique( array_merge( array_column( $local['dirs'], 'path' ), array_keys( $remote ) ) ) as $path ) {
				$l = null;
				foreach ( $local['dirs'] as $d ) if ( $d['path'] === $path ) { $l = $d; break; }
				$r     = isset( $remote[ $path ] ) ? $remote[ $path ] : null;
				$bytes = max( (int) ( $l['bytes'] ?? 0 ), (int) ( $r['bytes'] ?? 0 ) );
				$rows[] = [
					'path'    => $path,
					'local'   => $l,
					'remote'  => $r,
					'bytes'   => $bytes,
					'default' => in_array( $path, $defaults, true ),
					'on'      => in_array( $path, $cur, true ),
					'rec'     => self::recommended( $path ),
					'large'   => $bytes > self::BIG,
				];
			}
			usort( $rows, function ( $a, $b ) { return $b['bytes'] === $a['bytes'] ? 0 : ( $b['bytes'] < $a['bytes'] ? -1 : 1 ); } );

			printf( '<h3>%s <span style="font-weight:400">(%s)</span></h3>', esc_html( $e['name'] ), esc_html( $e['url'] ) );
			if ( $err ) echo '<div class="notice notice-warning inline"><p>' . esc_html( 'Remote sizes unavailable: ' . $err ) . '</p></div>';
			echo '<form method="post"><table class="widefat striped" style="max-width:900px"><thead><tr><th>Folder</th><th>Local</th><th>Remote</th><th>Files</th><th>Excluded</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				if ( $row['default'] ) $badge = '';
				elseif ( $row['rec'] ) $badge = ' <span class="dashicons dashicons-warning" style="color:#b32d2e"></span> <em>Recommended</em>';
				elseif ( $row['large'] ) $badge = ' <span style="color:#646970">large</span>'; // neutral note: your call
				else $badge = '';
				if ( $row['default'] ) {
					$box = '<em>always</em>';
				} elseif ( ! preg_match( '#^[^/]+/$#', $row['path'] ) ) {
					$box = '&mdash;'; // the loose-files aggregate is not a folder, so it cannot be excluded
				} else {
					$box = sprintf(
						'<input type="checkbox" name="ixes_exclude[]" value="%s"%s>',
						esc_attr( $row['path'] ),
						( $row['on'] || ( $row['rec'] && ! $configured ) ) ? ' checked' : ''
					);
				}
				printf(
					'<tr><td><code>%s</code>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $row['path'] ),
					$badge, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from literals only
					$row['local'] ? esc_html( size_format( $row['local']['bytes'] ) ) : '&mdash;',
					$row['remote'] ? esc_html( size_format( $row['remote']['bytes'] ) ) : ( $err ? '<em>?</em>' : '&mdash;' ),
					esc_html( (string) max( (int) ( $row['local']['files'] ?? 0 ), (int) ( $row['remote']['files'] ?? 0 ) ) ),
					$box // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literals plus esc_attr()
				);
			}
			echo '</tbody></table>';
			wp_nonce_field( 'ixes_excludes' );
			printf( '<input type="hidden" name="ixes_excludes_env" value="%s">', esc_attr( $e['name'] ) );
			submit_button( 'Save excludes', 'primary', 'ixes_save_excludes_' . $e['name'], false );
			echo ' ';
			submit_button( 'Refresh sizes', 'secondary', 'ixes_refresh_dirs', false );
			echo '</form>';
		}
	}
}
