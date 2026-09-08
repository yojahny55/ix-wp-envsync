<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Rest {
	const NS = 'envsync/v1';

	public static function register() {
		$r = function ( $route, $method, $cb ) {
			register_rest_route( self::NS, $route, [ 'methods' => $method, 'callback' => $cb, 'permission_callback' => [ __CLASS__, 'auth' ] ] );
		};
		$r( '/ping',       'GET',  function () { return [ 'ok' => true, 'time' => time() ]; } );
		$r( '/info',       'GET',  function () { return IXES_Transfer::info(); } );
		$r( '/hash/rows',  'POST', [ __CLASS__, 'hash_rows' ] );
		$r( '/hash/files', 'POST', [ __CLASS__, 'hash_files' ] );
		$r( '/dump',       'POST', [ __CLASS__, 'dump' ] );
		$r( '/file/get',   'POST', [ __CLASS__, 'file_get' ] );
		foreach ( [ 'start', 'step', 'finish', 'abort' ] as $op ) {
			$r( '/job/' . $op, 'POST', function ( $req ) use ( $op ) { return self::applier( 'job_' . $op, $req->get_json_params() ); } );
		}
		$r( '/rollback', 'POST', function ( $req ) { return self::applier( 'rollback', $req->get_json_params() ); } );
	}

	public static function auth( WP_REST_Request $req ) {
		if ( ! IXES_Auth::https_ok( home_url() ) && ! is_ssl() ) return new WP_Error( 'https', 'https required', [ 'status' => 403 ] );
		$hdr = $req->get_header( 'authorization' );
		if ( ! $hdr || stripos( $hdr, 'Bearer ' ) !== 0 ) return new WP_Error( 'auth', 'missing token', [ 'status' => 401 ] );
		$token = trim( substr( $hdr, 7 ) );
		$ok = IXES_Auth::verify(
			(string) get_option( 'ixes_token_hash' ), $token, $req->get_method(),
			$req->get_route(), (int) $req->get_header( 'x-envsync-ts' ),
			(string) $req->get_body(), (string) $req->get_header( 'x-envsync-sig' )
		);
		return $ok ? true : new WP_Error( 'auth', 'bad signature', [ 'status' => 401 ] );
	}

	private static function pairs( array $p ) {
		return IXES_Hasher::placeholders( IXES_Env::local_url(), IXES_Env::local_abspath() );
	}

	public static function hash_rows( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		return IXES_Transfer::hash_rows( sanitize_text_field( $p['table'] ), $p['from'] ?? null, (int) ( $p['limit'] ?? 5000 ), self::pairs( $p ), sanitize_key( $p['algo'] ?? 'sha1' ) );
	}
	public static function hash_files( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		return IXES_Transfer::file_manifest( $p['cursor'] ?? null, (int) ( $p['limit'] ?? 2000 ), array_merge( IXES_Env::default_excludes(), (array) ( $p['excludes'] ?? [] ) ), sanitize_key( $p['algo'] ?? 'sha1' ) );
	}
	public static function dump( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		return IXES_Transfer::dump( sanitize_text_field( $p['table'] ), $p['from'] ?? null, (int) ( $p['limit'] ?? 5000 ) );
	}
	public static function file_get( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		return IXES_Transfer::file_chunk( $p['path'] ?? '', (int) ( $p['offset'] ?? 0 ), (int) ( $p['size'] ?? 2097152 ) );
	}
	private static function applier( $method, $params ) {
		if ( ! class_exists( 'IXES_Applier' ) ) return new WP_Error( 'unavailable', 'applier missing', [ 'status' => 501 ] );
		return call_user_func( [ 'IXES_Applier', $method ], is_array( $params ) ? $params : [] );
	}
}
