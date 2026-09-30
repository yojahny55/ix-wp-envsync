<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Env {
	const OPTION = 'ixes_envs';

	public static function all() {
		$v = get_option( self::OPTION, [] );
		return is_array( $v ) ? $v : [];
	}

	public static function get( $name ) {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	public static function add( array $env ) {
		$env = array_merge( [ 'label' => 'prod', 'extra_replace' => [], 'excludes' => [] ], $env );
		if ( ! preg_match( '/^[a-z0-9_-]+$/', $env['name'] ) ) throw new InvalidArgumentException( 'bad name' );
		if ( ! IXES_Auth::https_ok( $env['url'] ) ) throw new InvalidArgumentException( 'url must be https (or define ENVSYNC_ALLOW_HTTP)' );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $env['token'] ) ) throw new InvalidArgumentException( 'token must be 64 hex chars' );
		if ( isset( $env['basic_auth'] ) && strpos( (string) $env['basic_auth'], ':' ) === false ) throw new InvalidArgumentException( 'basic-auth must be user:pass' );
		if ( ! in_array( $env['label'], [ 'prod', 'staging', 'local' ], true ) ) throw new InvalidArgumentException( 'label must be prod|staging|local' );
		if ( isset( $env['timeout'] ) ) {
			if ( ! is_numeric( $env['timeout'] ) || (int) $env['timeout'] < 1 ) throw new InvalidArgumentException( 'timeout must be a positive number of seconds' );
			$env['timeout'] = (int) $env['timeout'];
		}
		$env['url'] = untrailingslashit( $env['url'] );
		$all = self::all();
		$all[ $env['name'] ] = $env;
		update_option( self::OPTION, $all, false );
	}

	/** Update one stored field without re-validating the whole env (used for values the remote reports). */
	public static function set_field( $name, $key, $value ) {
		$all = self::all();
		if ( ! isset( $all[ $name ] ) ) return;
		$all[ $name ][ $key ] = $value;
		update_option( self::OPTION, $all, false );
	}

	public static function remove( $name ) {
		$all = self::all();
		unset( $all[ $name ] );
		update_option( self::OPTION, $all, false );
	}

	public static function default_excludes() {
		// '.git/' and 'node_modules/' are dev artifacts: syncing a repo into a public
		// web directory leaks source and history, and node_modules wrecks the manifest.
		return [ 'cache/', 'wp-config.php', '.htaccess', '.env', 'debug.log', 'object-cache.php', 'advanced-cache.php', 'envsync/', 'envsync-', 'upgrade/', 'uploads/wc-logs/', '.git/', 'node_modules/' ];
	}

	// extra_replace is a list of [prod_value, local_value]; returns the two index-aligned lists
	public static function extras( array $env ) {
		$prod = []; $local = [];
		foreach ( (array) ( $env['extra_replace'] ?? [] ) as $p ) {
			if ( ! isset( $p[0], $p[1] ) ) continue;
			$prod[] = (string) $p[0]; $local[] = (string) $p[1];
		}
		return [ $prod, $local ];
	}

	public static function excluded_options() {
		return [ 'siteurl', 'home', 'cron', 'recently_activated' ];
	}

	/** @var string[] the environment's exclude_options globs, for this request: the hub sets them from the env, the remote from the hub's header */
	private static $option_globs = [];
	public static function set_option_globs( array $globs ) { self::$option_globs = array_values( $globs ); }

	/** Parse a comma list of option name globs (env add --exclude-options, the X-Envsync-Exclude-Options header). */
	public static function option_globs( $value ) {
		return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' ) ) );
	}

	/** A remote older than 0.9.9 would hash, send and overwrite the rows this environment excludes by name. */
	public static function option_globs_refusal( array $env, array $info ) {
		if ( empty( $env['exclude_options'] ) || in_array( 'exclude_options', (array) ( $info['caps'] ?? [] ), true ) ) return null;
		$from = (string) ( $info['plugin'] ?? '?' );
		return new WP_Error( 'old_remote', "{$env['name']} runs {$from}, which does not know --exclude-options: run wp envsync self-update {$env['name']} first" );
	}

	public static function option_excluded( $name ) {
		if ( in_array( $name, self::excluded_options(), true ) ) return true;
		foreach ( self::$option_globs as $g ) if ( fnmatch( $g, (string) $name ) ) return true;
		foreach ( [ 'ixes_', '_transient_', '_site_transient_' ] as $p ) {
			if ( strpos( $name, $p ) === 0 ) return true;
		}
		return false;
	}

	public static function local_url() { return untrailingslashit( home_url() ); }
	public static function local_abspath() { return untrailingslashit( ABSPATH ); }
}
