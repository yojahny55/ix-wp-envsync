<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Client {
	private $env; private $info = null;

	public function __construct( array $env ) { $this->env = $env; }

	private function request( $method, $route, $body = null ) {
		$path = '/' . IXES_Rest::NS . $route;
		$ts   = time();
		$raw  = $body === null ? '' : wp_json_encode( $body );
		$args = [
			'method'  => $method,
			'timeout' => 120,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->env['token'],
				'X-Envsync-Ts'  => $ts,
				'X-Envsync-Sig' => IXES_Auth::sign( $this->env['token'], $method, $path, $ts, $raw ),
				'Content-Type'  => 'application/json',
			],
		];
		if ( $body !== null ) $args['body'] = $raw;
		$res = wp_remote_request( $this->env['url'] . '/wp-json' . $path, $args );
		if ( is_wp_error( $res ) ) return $res;
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $json ) && isset( $json['message'] ) ? $json['message'] : wp_remote_retrieve_body( $res );
			return new WP_Error( 'remote_' . $code, "remote {$code} on {$route}: {$msg}" );
		}
		return is_array( $json ) ? $json : [];
	}

	public function get( $route )          { return $this->request( 'GET', $route ); }
	public function post( $route, $body )  { return $this->request( 'POST', $route, $body ); }

	public function info() {
		if ( $this->info === null ) $this->info = $this->get( '/info' );
		return $this->info;
	}

	public function remote_pairs() {
		$i = $this->info();
		return IXES_Hasher::placeholders( $i['url'], $i['abspath'] );
	}

	public function paged( $route, array $body, callable $each, $cursor_key = 'from' ) {
		$limit = isset( $body['limit'] ) ? (int) $body['limit'] : 5000;
		$max   = $limit;
		$next  = null;
		do {
			$body['limit'] = $limit;
			$body[ $cursor_key ] = $next;
			$t0  = microtime( true );
			$res = $this->post( $route, $body );
			if ( is_wp_error( $res ) ) return $res;
			$dt  = microtime( true ) - $t0;
			$each( $res );
			$next = isset( $res['next'] ) ? $res['next'] : null;
			if ( $dt > 10 ) $limit = max( 100, (int) ( $limit / 2 ) );
			elseif ( $dt < 2 ) $limit = min( $max, $limit * 2 );
		} while ( $next !== null );
	}
}
