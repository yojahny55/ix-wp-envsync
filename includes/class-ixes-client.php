<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Client {
	const CHUNK_JSON_SIZE = 2097152;

	private $env; private $info = null; private $caps = null;

	public function __construct( array $env ) { $this->env = $env; }

	/** Overridden by tests. */
	protected function transport( $url, array $args ) { return wp_remote_request( $url, $args ); }
	protected function sleep_s( $s ) { sleep( (int) $s ); }

	/**
	 * $opts: raw_body (string, sent as octet-stream), accept ('json'|'binary'), headers (array), step (string, signed).
	 * Binary 2xx responses return [ 'body' => string, 'headers' => array ]; everything else returns decoded JSON or WP_Error.
	 */
	private function request( $method, $route, $body = null, array $opts = [] ) {
		$path = '/' . IXES_Rest::NS . $route;
		$ts   = time();
		$step = isset( $opts['step'] ) ? (string) $opts['step'] : '';
		if ( isset( $opts['raw_body'] ) ) { $raw = (string) $opts['raw_body']; $ctype = 'application/octet-stream'; }
		else { $raw = $body === null ? '' : wp_json_encode( $body ); $ctype = 'application/json'; }
		$headers = [
			'Authorization' => 'Bearer ' . $this->env['token'],
			'X-Envsync-Ts'  => $ts,
			'X-Envsync-Sig' => IXES_Auth::sign( $this->env['token'], $method, $path, $ts, $raw, $step ),
			'Content-Type'  => $ctype,
			'Accept'        => ( $opts['accept'] ?? 'json' ) === 'binary' ? 'application/octet-stream' : 'application/json',
		];
		if ( $step !== '' ) $headers['X-Envsync-Step'] = $step;
		if ( ! empty( $opts['headers'] ) ) $headers = array_merge( $headers, $opts['headers'] );
		$args = [ 'method' => $method, 'timeout' => 120, 'redirection' => 0, 'headers' => $headers ];
		if ( $raw !== '' || $body !== null ) $args['body'] = $raw;
		// ponytail: ?rest_route= works with any permalink structure; /wp-json/ 301s on plain permalinks and drops the Authorization header
		$res = $this->transport( $this->env['url'] . '/?rest_route=' . $path, $args );
		if ( is_wp_error( $res ) ) return $res;
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code >= 300 && $code < 400 ) return new WP_Error( 'remote_redirect', 'remote redirected to ' . wp_remote_retrieve_header( $res, 'location' ) . '; register the final URL with env add' );
		$body_s = wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			$json = json_decode( $body_s, true );
			$msg  = is_array( $json ) && isset( $json['message'] ) ? $json['message'] : $body_s;
			return new WP_Error( 'remote_' . $code, "remote {$code} on {$route}: {$msg}", [ 'status' => $code ] );
		}
		$is_bin = ( $opts['accept'] ?? 'json' ) === 'binary';
		if ( $is_bin ) {
			$h = [];
			foreach ( (array) wp_remote_retrieve_headers( $res ) as $k => $v ) $h[ strtolower( $k ) ] = is_array( $v ) ? end( $v ) : $v;
			return [ 'body' => $body_s, 'headers' => $h ];
		}
		$json = json_decode( $body_s, true );
		return is_array( $json ) ? $json : [];
	}

	public function get( $route )                    { return $this->request( 'GET', $route ); }
	public function post( $route, $body, $opts = [] ) { return $this->request( 'POST', $route, $body, $opts ); }

	public function info() {
		if ( $this->info === null ) $this->info = $this->get( '/info' );
		return $this->info;
	}

	/** Remote capability list from /info; [] for a 0.2 remote. */
	public function caps() {
		if ( $this->caps === null ) { $i = $this->info(); $this->caps = is_array( $i ) ? (array) ( $i['caps'] ?? [] ) : []; }
		return $this->caps;
	}
	public function set_caps( array $caps ) { $this->caps = $caps; }
	private function binary() { return in_array( 'binary', $this->caps(), true ); }

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

	/** @return int|null HTTP status carried by a WP_Error from request(), null for transport errors */
	private static function err_code( WP_Error $e ) {
		$d = $e->get_error_data();
		return is_array( $d ) && isset( $d['status'] ) ? (int) $d['status'] : null;
	}

	/**
	 * Pull one file chunk by chunk. $write( $offset, $data, $final, $sha256 ) returns true or WP_Error.
	 * @return true|WP_Error
	 */
	public function fetch_file( $rel, callable $write ) {
		$ch = new IXES_Chunker( $this->binary() ? 2097152 : self::CHUNK_JSON_SIZE );
		$offset = 0;
		while ( true ) {
			$t0  = microtime( true );
			$res = $this->post( '/file/get', [ 'path' => $rel, 'offset' => $offset, 'size' => $ch->size() ], [ 'accept' => $this->binary() ? 'binary' : 'json' ] );
			if ( is_wp_error( $res ) ) {
				if ( $ch->fail( self::err_code( $res ) ) ) { $this->sleep_s( $ch->backoff() ); continue; }
				return new WP_Error( 'transfer', "{$rel}: gave up at offset {$offset} after {$ch->attempts()} attempts: " . $res->get_error_message() );
			}
			if ( isset( $res['headers'] ) ) { // binary
				$data = (string) $res['body']; $total = (int) $res['headers']['x-envsync-total']; $sha = (string) $res['headers']['x-envsync-sha256'];
			} else {
				$data = base64_decode( (string) ( $res['data'] ?? '' ) ); $total = (int) ( $res['total'] ?? 0 ); $sha = (string) ( $res['sha256'] ?? '' );
			}
			$ch->ok( microtime( true ) - $t0 ); $ch->reset_attempts();
			$next  = $offset + strlen( $data );
			$final = $next >= $total || $data === '';
			$w = $write( $offset, $data, $final, $sha );
			if ( is_wp_error( $w ) ) return $w;
			if ( $final ) return true;
			$offset = $next;
		}
	}

	/**
	 * Push one file chunk by chunk through /job/step. $first_meta (expect, algo) is merged into the offset-0 step.
	 * @return array{ok:bool,refused:bool}|WP_Error
	 */
	public function send_file( $job, $rel, $abs, array $first_meta ) {
		$sha = hash_file( 'sha256', $abs ); $total = filesize( $abs );
		$ch  = new IXES_Chunker( $this->binary() ? 2097152 : self::CHUNK_JSON_SIZE );
		$fh  = fopen( $abs, 'rb' );
		if ( ! $fh ) return new WP_Error( 'io', "cannot read {$rel}" );
		$offset = 0;
		while ( true ) {
			fseek( $fh, $offset );
			$data  = (string) fread( $fh, $ch->size() );
			$final = $offset + strlen( $data ) >= $total || $data === '';
			$step  = [ 'job' => $job, 'kind' => 'file', 'path' => $rel, 'offset' => $offset, 'final' => $final, 'sha256' => $sha ];
			if ( $offset === 0 ) $step = array_merge( $step, $first_meta );
			$t0 = microtime( true );
			if ( $this->binary() ) $r = $this->post( '/job/step', null, [ 'raw_body' => $data, 'step' => wp_json_encode( $step ) ] );
			else                   $r = $this->post( '/job/step', $step + [ 'data' => base64_encode( $data ) ] );
			if ( is_wp_error( $r ) ) {
				if ( $ch->fail( self::err_code( $r ) ) ) { $this->sleep_s( $ch->backoff() ); continue; }
				fclose( $fh );
				return new WP_Error( 'transfer', "{$rel}: gave up at offset {$offset} after {$ch->attempts()} attempts: " . $r->get_error_message() );
			}
			$ch->ok( microtime( true ) - $t0 ); $ch->reset_attempts();
			if ( ! empty( $r['refused'] ) ) { fclose( $fh ); return [ 'ok' => false, 'refused' => true ]; }
			if ( $final ) { fclose( $fh ); return [ 'ok' => true, 'refused' => false ]; }
			$offset += strlen( $data );
		}
	}
}
