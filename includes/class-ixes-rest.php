<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Rest {
	const NS = 'envsync/v1';

	private static $auth_via = null;

	public static function auth_via() { return self::$auth_via; }

	public static function register() {
		$r = function ( $route, $method, $cb ) {
			register_rest_route( self::NS, $route, [ 'methods' => $method, 'callback' => $cb, 'permission_callback' => [ __CLASS__, 'auth' ] ] );
		};
		$r( '/ping',       'GET',  function () { return [ 'ok' => true, 'time' => time(), 'auth_via' => self::auth_via() ]; } );
		$r( '/info',       'GET',  function () { return IXES_Transfer::info(); } );
		$r( '/hash/rows',  'POST', [ __CLASS__, 'hash_rows' ] );
		$r( '/hash/tables', 'POST', [ __CLASS__, 'hash_tables' ] );
		$r( '/hash/files', 'POST', [ __CLASS__, 'hash_files' ] );
		$r( '/dump',       'POST', [ __CLASS__, 'dump' ] );
		$r( '/file/get',   'POST', [ __CLASS__, 'file_get' ] );
		$r( '/dirs',       'POST', function () { return IXES_Transfer::dir_sizes(); } );
		foreach ( [ 'start', 'step', 'finish', 'abort', 'unlock' ] as $op ) {
			$r( '/job/' . $op, 'POST', function ( $req ) use ( $op ) { return self::applier( 'job_' . $op, self::step_params( $req ) ); } );
		}
		$r( '/rollback', 'POST', function ( $req ) { return self::applier( 'rollback', $req->get_json_params() ); } );
		add_filter( 'rest_pre_serve_request', [ __CLASS__, 'serve_binary' ], 10, 4 );
	}

	public static function auth( WP_REST_Request $req ) {
		if ( ! is_ssl() && ! ( defined( 'ENVSYNC_ALLOW_HTTP' ) && ENVSYNC_ALLOW_HTTP ) ) return new WP_Error( 'https', 'https required', [ 'status' => 403 ] );
		$authorization = $req->get_header( 'authorization' );
		// Test-only: simulates a host that strips the Authorization header, forcing the X-Envsync-Token fallback.
		if ( defined( 'ENVSYNC_TEST_DROP_AUTHORIZATION' ) && ENVSYNC_TEST_DROP_AUTHORIZATION ) $authorization = '';
		list( $token, $via ) = IXES_Auth::token_from_headers( $authorization, $req->get_header( 'x-envsync-token' ) );
		if ( $token === '' ) return new WP_Error( 'auth', 'missing token', [ 'status' => 401 ] );
		self::$auth_via = $via;
		$prefix = (string) $req->get_header( 'x-envsync-prefix' );
		if ( $prefix !== '' && ! IXES_Prefix::valid( $prefix ) ) return new WP_Error( 'prefix', 'bad prefix', [ 'status' => 400 ] );
		$ok = IXES_Auth::verify(
			(string) get_option( 'ixes_token_hash' ), $token, $req->get_method(),
			$req->get_route(), (int) $req->get_header( 'x-envsync-ts' ),
			(string) $req->get_body(), (string) $req->get_header( 'x-envsync-sig' ),
			null, (string) $req->get_header( 'x-envsync-step' ), $prefix
		);
		if ( ! $ok ) return new WP_Error( 'auth', 'bad signature', [ 'status' => 401 ] );
		global $wpdb;
		// the hub speaks in its own table names; everything below translates through this for the rest of the request
		IXES_Prefix::set_current( $prefix !== '' && $prefix !== $wpdb->prefix ? new IXES_Prefix( $wpdb->prefix, $prefix ) : null );
		return true;
	}

	private static function wants_binary( WP_REST_Request $req ) {
		return stripos( (string) $req->get_header( 'accept' ), 'application/octet-stream' ) !== false;
	}

	/** A table name from the hub, in this site's names ('' when it does not carry the hub prefix: unknown table). */
	private static function table( $name ) {
		$name = sanitize_text_field( (string) $name );
		$map  = IXES_Prefix::current();
		return $map ? (string) $map->table_in( $name ) : $name;
	}

	private static function pairs( array $p ) {
		// 'extra' carries this env's prod-side extra_replace values, index-aligned with the hub's local ones
		return IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath(), array_map( 'strval', array_values( (array) ( $p['extra'] ?? [] ) ) ) );
	}

	public static function hash_rows( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		return IXES_Transfer::hash_rows( self::table( $p['table'] ?? '' ), $p['from'] ?? null, (int) ( $p['limit'] ?? 5000 ), self::pairs( $p ), sanitize_key( $p['algo'] ?? 'sha1' ) );
	}
	/**
	 * The first page of several tables' row hashes in one request, keyed by the hub's table names.
	 * Stops once 'limit' rows are out; a table cut short carries its 'next' cursor for /hash/rows, and one never reached is left out.
	 */
	public static function hash_tables( WP_REST_Request $req ) {
		$p      = $req->get_json_params();
		$budget = max( 1, min( 20000, (int) ( $p['limit'] ?? 5000 ) ) );
		$algo   = sanitize_key( $p['algo'] ?? 'sha1' );
		$pairs  = self::pairs( $p );
		$out    = [];
		foreach ( array_slice( array_values( (array) ( $p['tables'] ?? [] ) ), 0, 100 ) as $name ) {
			if ( $budget <= 0 ) break;
			$name = sanitize_text_field( (string) $name );
			$r = IXES_Transfer::hash_rows( self::table( $name ), null, $budget, $pairs, $algo );
			if ( is_wp_error( $r ) ) return $r;
			$out[ $name ] = $r;
			$budget -= max( 1, count( $r['rows'] ) );
		}
		return [ 'tables' => (object) $out ];
	}
	public static function hash_files( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		$roots = array_values( array_filter( array_map( 'strval', (array) ( $p['roots'] ?? [] ) ) ) );
		return IXES_Transfer::file_manifest( $p['cursor'] ?? null, (int) ( $p['limit'] ?? 2000 ), array_merge( IXES_Env::default_excludes(), (array) ( $p['excludes'] ?? [] ) ), sanitize_key( $p['algo'] ?? 'sha1' ), ! empty( $p['sizes'] ), $roots );
	}
	public static function dump( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		return IXES_Transfer::dump( self::table( $p['table'] ?? '' ), $p['from'] ?? null, (int) ( $p['limit'] ?? 5000 ) );
	}
	public static function file_get( WP_REST_Request $req ) {
		$p   = $req->get_json_params();
		$bin = self::wants_binary( $req );
		$r   = IXES_Transfer::file_chunk( $p['path'] ?? '', (int) ( $p['offset'] ?? 0 ), (int) ( $p['size'] ?? 2097152 ), $bin );
		if ( is_wp_error( $r ) || ! $bin ) return $r;
		// Raw bytes must bypass the JSON encoder, but still go through the normal REST response
		// pipeline (rest_post_dispatch, CORS/security plugins, etc.) instead of exiting early.
		// rest_pre_serve_request is the hook WordPress provides for emitting a body itself; see
		// serve_binary(). WP_REST_Server::serve_request() sends $result->get_headers() (including
		// ours below) via PHP's header() before that filter runs, and header() replaces the
		// earlier default 'Content-Type: application/json' with the last value set for that name.
		$res = new WP_REST_Response( null, 200 );
		$res->header( 'Content-Type', 'application/octet-stream' );
		$res->header( 'Content-Length', (string) $r['size'] );
		$res->header( 'X-Envsync-Total', (string) $r['total'] );
		$res->header( 'X-Envsync-Size', (string) $r['size'] );
		$res->header( 'X-Envsync-Sha256', $r['sha256'] );
		$res->header( 'Cache-Control', 'no-cache' );
		$res->set_data( $r['bin'] );
		$res->ixes_binary = true; // marker read by serve_binary()
		return $res;
	}

	/** Emit a binary file_get response as raw bytes instead of JSON; headers were already sent by the server. */
	public static function serve_binary( $served, $result, $request, $server ) {
		if ( $served || ! ( $result instanceof WP_REST_Response ) || empty( $result->ixes_binary ) ) return $served;
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file body, not HTML
		return true;
	}

	/** Step params: JSON body, or X-Envsync-Step header plus raw body when the hub sends octet-stream. */
	private static function step_params( WP_REST_Request $req ) {
		if ( stripos( (string) $req->get_header( 'content-type' ), 'application/octet-stream' ) === 0 ) {
			$p = json_decode( (string) $req->get_header( 'x-envsync-step' ), true );
			if ( ! is_array( $p ) ) return [];
			return self::unpack_step( $p, (string) $req->get_body() );
		}
		$p = $req->get_json_params();
		return is_array( $p ) ? $p : [];
	}

	const PACKED_MAX = 67108864; // inflated size cap for one packed step

	/**
	 * Header params + raw body -> step params. kind 'packed' carries a whole JSON step deflated: host firewalls
	 * (Hostinger's, ModSecurity CRS) score serialized PHP objects in plain bodies and block a batch of option or
	 * Action Scheduler rows as "object injection"; compressed bytes are not pattern-matched. The body is signed like any other.
	 */
	public static function unpack_step( array $p, $body ) {
		if ( ( $p['kind'] ?? '' ) !== 'packed' ) { $p['bin'] = $body; return $p; }
		$json = function_exists( 'gzinflate' ) ? @gzinflate( $body, self::PACKED_MAX ) : false;
		$inner = $json === false ? null : json_decode( $json, true );
		if ( ! is_array( $inner ) || ( $inner['kind'] ?? '' ) === 'packed' ) return [];
		return $inner;
	}

	private static function applier( $method, $params ) {
		if ( ! class_exists( 'IXES_Applier' ) ) return new WP_Error( 'unavailable', 'applier missing', [ 'status' => 501 ] );
		$params = is_array( $params ) ? $params : [];
		$map = IXES_Prefix::current();
		if ( $map && $method === 'job_start' ) $params = $map->start_in( $params );
		if ( $map && $method === 'job_step' ) $params = $map->step_in( $params );
		return call_user_func( [ 'IXES_Applier', $method ], $params );
	}
}
