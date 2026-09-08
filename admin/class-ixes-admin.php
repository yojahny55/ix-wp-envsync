<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Admin {
	public static function register() {
		add_management_page( 'EnvSync', 'EnvSync', 'manage_options', 'ix-envsync', [ __CLASS__, 'page' ] );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( isset( $_POST['ixes_rotate'] ) && check_admin_referer( 'ixes_rotate' ) ) IXES_Auth::install_token();
		$token = get_transient( 'ixes_token_show' );
		echo '<div class="wrap"><h1>EnvSync</h1>';

		echo '<h2>This site\'s token</h2>';
		if ( $token ) echo '<p>Copy it now, it is shown once:</p><code style="font-size:14px;user-select:all">' . esc_html( $token ) . '</code>';
		else echo '<p>Token already issued. Rotate to get a new one (the old one stops working).</p>';
		echo '<form method="post">'; wp_nonce_field( 'ixes_rotate' ); submit_button( 'Rotate token', 'secondary', 'ixes_rotate', false ); echo '</form>';

		$envs = IXES_Env::all();
		if ( $envs ) {
			echo '<h2>Environments</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Label</th><th>URL</th><th>Baseline</th></tr></thead><tbody>';
			foreach ( $envs as $e ) {
				$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $e['name'] . '.sqlite' );
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $e['name'] ), esc_html( $e['label'] ), esc_html( $e['url'] ), $bl->exists() ? esc_html( date( 'Y-m-d H:i', $bl->meta( 'created_at' ) ) ) : '—' );
			}
			echo '</tbody></table>';
		}

		$jobs = (array) get_option( 'ixes_last_jobs', [] );
		if ( $jobs ) {
			echo '<h2>Last pushes</h2><ul>';
			foreach ( $jobs as $j ) printf( '<li>%s → <b>%s</b> job %s%s</li>', esc_html( date( 'Y-m-d H:i', $j['at'] ) ), esc_html( $j['env'] ), esc_html( $j['job'] ), $j['stale'] ? ' <em>(skipped: ' . esc_html( implode( ', ', $j['stale'] ) ) . ')</em>' : '' );
			echo '</ul>';
		}

		$plans = glob( ixes_storage_dir() . '/plans/plan-*.json' );
		if ( $plans ) {
			sort( $plans ); $plan = json_decode( file_get_contents( end( $plans ) ), true );
			echo '<h2>Last plan (' . esc_html( basename( end( $plans ) ) ) . ')</h2><pre style="background:#fff;padding:12px;overflow:auto">' . esc_html( IXES_Planner::render_text( $plan ) ) . '</pre>';
		}

		$rjobs = glob( ixes_storage_dir() . '/jobs/*/meta.json' );
		if ( $rjobs ) {
			sort( $rjobs ); $m = json_decode( file_get_contents( end( $rjobs ) ), true );
			printf( '<h2>Last received push</h2><p>Job %s at %s, %d tables touched. Rollback with <code>wp envsync rollback &lt;env&gt;</code> from the hub.</p>', esc_html( $m['job'] ), esc_html( date( 'Y-m-d H:i', $m['started'] ) ), count( $m['plan']['tables'] ) );
		}
		echo '</div>';
	}
}
