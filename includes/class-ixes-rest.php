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
		$r( '/schema',     'POST', [ __CLASS__, 'schema' ] );
		$r( '/dump',       'POST', [ __CLASS__, 'dump' ] );
		$r( '/file/get',   'POST', [ __CLASS__, 'file_get' ] );
		$r( '/file/batch', 'POST', [ __CLASS__, 'file_batch' ] );
		$r( '/dirs',       'POST', function () { return IXES_Transfer::dir_sizes(); } );
		foreach ( [ 'start', 'step', 'finish', 'abort', 'unlock' ] as $op ) {
			$r( '/job/' . $op, 'POST', function ( $req ) use ( $op ) { return self::applier( 'job_' . $op, self::step_params( $req ) ); } );
		}
		$r( '/rollback', 'POST', function ( $req ) { return self::applier( 'rollback', $req->get_json_params() ); } );
		// installing code is a power of its own: both refuse with 403 when the site turned self-update off
		$r( '/self-update/chunk',   'POST', function ( $req ) { return IXES_Selfupdate::receive( self::step_params( $req ) ); } );
		$r( '/self-update/install', 'POST', function ( $req ) { $p = $req->get_json_params(); return IXES_Selfupdate::install( is_array( $p ) ? $p : [] ); } );
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

	/**
	 * A page answer's cursor: a binary key goes out wrapped for every hub, whether it asked for byte cells or not.
	 * Raw, wp_json_encode() turns its bytes into '?', the hub sends that back, and `k > '?..'` answers the same page
	 * forever. Any hub just echoes the cursor back as 'from', where cursor_in() unwraps it.
	 */
	public static function cursor_out( $r ) {
		if ( is_array( $r ) && IXES_Hasher::is_bytes( $r['next'] ?? null ) ) $r['next'] = [ 'b64' => base64_encode( $r['next'] ) ];
		return $r;
	}

	/** The hub's 'from' cursor, unwrapped. */
	public static function cursor_in( array $p ) {
		$f = $p['from'] ?? null;
		if ( ! is_array( $f ) ) return $f;
		$d = IXES_Hasher::cells_in( [ $f ] );
		return $d === null ? new WP_Error( 'bad_cursor', 'unreadable cursor', [ 'status' => 400 ] ) : $d[0];
	}

	public static function hash_rows( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		$from = self::cursor_in( $p );
		if ( is_wp_error( $from ) ) return $from;
		return self::cursor_out( IXES_Transfer::hash_rows( self::table( $p['table'] ?? '' ), $from, (int) ( $p['limit'] ?? 5000 ), self::pairs( $p ), sanitize_key( $p['algo'] ?? 'sha1' ), ! empty( $p['cells'] ) ) );
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
			$r = IXES_Transfer::hash_rows( self::table( $name ), null, $budget, $pairs, $algo, ! empty( $p['cells'] ) );
			if ( is_wp_error( $r ) ) return $r;
			$out[ $name ] = self::cursor_out( $r );
			$budget -= max( 1, count( $r['rows'] ) );
		}
		return [ 'tables' => (object) $out ];
	}
	public static function hash_files( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		$roots = array_values( array_filter( array_map( 'strval', (array) ( $p['roots'] ?? [] ) ) ) );
		return IXES_Transfer::file_manifest( $p['cursor'] ?? null, (int) ( $p['limit'] ?? 2000 ), array_merge( IXES_Env::default_excludes(), (array) ( $p['excludes'] ?? [] ) ), sanitize_key( $p['algo'] ?? 'sha1' ), ! empty( $p['sizes'] ), $roots );
	}
	/** CREATE TABLE text for tables a pull needs: ones this side has that the hub lacks, or whose columns it lacks. Keyed by the hub's own names. */
	public static function schema( WP_REST_Request $req ) {
		$p   = $req->get_json_params();
		$map = IXES_Prefix::current();
		$out = [];
		foreach ( array_slice( array_values( (array) ( $p['tables'] ?? [] ) ), 0, 200 ) as $name ) {
			$name  = sanitize_text_field( (string) $name );
			$local = self::table( $name );
			if ( $local === '' || ! IXES_Transfer::valid_table( $local ) ) continue;
			$sql = IXES_Transfer::create_table_sql( $local );
			if ( $sql === null ) continue;
			$out[ $name ] = $map ? $map->sql_out( $sql ) : $sql;
		}
		return [ 'tables' => (object) $out ];
	}
	public static function dump( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		$from = self::cursor_in( $p );
		if ( is_wp_error( $from ) ) return $from;
		return self::cursor_out( IXES_Transfer::dump( self::table( $p['table'] ?? '' ), $from, (int) ( $p['limit'] ?? 5000 ), (int) ( $p['bytes'] ?? 0 ), ! empty( $p['cells'] ) ) );
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
		return self::binary_response( $r['bin'], [ 'X-Envsync-Total' => (string) $r['total'], 'X-Envsync-Size' => (string) $r['size'], 'X-Envsync-Sha256' => $r['sha256'] ] );
	}

	private static function binary_response( $bytes, array $headers ) {
		$res = new WP_REST_Response( null, 200 );
		$res->header( 'Content-Type', 'application/octet-stream' );
		$res->header( 'Content-Length', (string) strlen( $bytes ) );
		foreach ( $headers as $k => $v ) $res->header( $k, $v );
		$res->header( 'Cache-Control', 'no-cache' );
		$res->set_data( $bytes );
		$res->ixes_binary = true; // marker read by serve_binary()
		return $res;
	}

	/** Many small files in one answer (0.8.0): a pull pays the remote's WordPress boot once per batch, not once per file. */
	public static function file_batch( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		$paths = array_values( array_filter( (array) ( $p['paths'] ?? [] ), 'is_string' ) );
		if ( ! $paths || count( $paths ) > IXES_Batch::MAX_ITEMS ) return new WP_Error( 'bad_batch', 'between 1 and ' . IXES_Batch::MAX_ITEMS . ' paths per batch', [ 'status' => 400 ] );
		$raw = IXES_Transfer::file_batch( $paths );
		$z   = ! empty( $p['deflate'] ) && function_exists( 'gzdeflate' );
		return self::binary_response( $z ? gzdeflate( $raw, 6 ) : $raw, [ 'X-Envsync-Enc' => $z ? 'deflate' : 'identity', 'X-Envsync-Items' => (string) count( $paths ) ] );
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
