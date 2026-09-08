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
		if ( ! in_array( $env['label'], [ 'prod', 'staging', 'local' ], true ) ) throw new InvalidArgumentException( 'label must be prod|staging|local' );
		$env['url'] = untrailingslashit( $env['url'] );
		$all = self::all();
		$all[ $env['name'] ] = $env;
		update_option( self::OPTION, $all, false );
	}

	public static function remove( $name ) {
		$all = self::all();
		unset( $all[ $name ] );
		update_option( self::OPTION, $all, false );
	}

	public static function default_excludes() {
		return [ 'cache/', 'wp-config.php', '.htaccess', '.env', 'debug.log', 'object-cache.php', 'advanced-cache.php', 'envsync/', 'envsync-', 'upgrade/', 'uploads/wc-logs/' ];
	}

	public static function excluded_options() {
		return [ 'siteurl', 'home', 'cron', 'recently_activated' ];
	}

	public static function option_excluded( $name ) {
		if ( in_array( $name, self::excluded_options(), true ) ) return true;
		foreach ( [ 'ixes_', '_transient_', '_site_transient_' ] as $p ) {
			if ( strpos( $name, $p ) === 0 ) return true;
		}
		return false;
	}

	public static function local_url() { return untrailingslashit( home_url() ); }
	public static function local_abspath() { return untrailingslashit( ABSPATH ); }
}
