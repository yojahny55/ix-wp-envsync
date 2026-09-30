<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Client {
	const CHUNK_JSON_SIZE = 2097152;
	const DEFAULT_TIMEOUT = 120;
	const BATCH_TIMEOUT = 300; // floor for one batch request: a 4 MB batch of media that does not compress, on a slow link

	private $env; private $info = null; private $caps = null;
	private $prefix_header = '';
	/** This site's table prefix; null reads $wpdb. Set by tests. */
	public $hub_prefix = null;

	public function __construct( array $env ) {
		$this->env = $env;
		// one environment per run: its option excludes hold on this side too (hashing, preserve_local_options)
		IXES_Env::set_option_globs( (array) ( $env['exclude_options'] ?? [] ) );
	}

	/** Overridden by tests. */
	protected function transport( $url, array $args ) { return wp_remote_request( $url, $args ); }
	protected function sleep_s( $s ) { sleep( (int) $s ); }

	/** This env's own --timeout (env add), or 0 when it never set one. */
	private function raw_timeout() { return max( 0, (int) ( $this->env['timeout'] ?? 0 ) ); }

	/** raw_timeout(), else the built-in default. A caller's own $opts['timeout'] always wins over this. */
	public function effective_timeout() {
		$t = $this->raw_timeout();
		return $t > 0 ? $t : self::DEFAULT_TIMEOUT;
	}

	/**
	 * $opts: raw_body (string, sent as octet-stream), accept ('json'|'binary'), headers (array), step (string, signed).
	 * Binary 2xx responses return [ 'body' => string, 'headers' => array ]; everything else returns decoded JSON or WP_Error.
	 */
	private function request( $method, $route, $body = null, array $opts = [] ) {
		list( $url, $args ) = $this->prepare( $method, $route, $body, $opts );
		return $this->parse( $this->transport( $url, $args ), $route, $opts );
	}

	/** @return array [ url, wp_remote_request args ], signed on its own ts and body, so concurrent requests all verify */
	private function prepare( $method, $route, $body, array $opts ) {
		$path = '/' . IXES_Rest::NS . $route;
		$ts   = time();
		$step = isset( $opts['step'] ) ? (string) $opts['step'] : '';
		// rescue.php (a custom 'url') verifies without a prefix
		$prefix = isset( $opts['url'] ) ? '' : $this->prefix_header;
		// /info and /self-update go out bare, so a remote too old for the excludes still answers, option_globs_refusal()
		// can say why and self-update can fix it; every other request carries them signed, and that remote refuses it
		$bare   = isset( $opts['url'] ) || $route === '/info' || strpos( $route, '/self-update/' ) === 0;
		$excl   = $bare || empty( $this->env['exclude_options'] ) ? '' : implode( ',', (array) $this->env['exclude_options'] );
		if ( isset( $opts['raw_body'] ) ) { $raw = (string) $opts['raw_body']; $ctype = 'application/octet-stream'; }
		else { $raw = $body === null ? '' : wp_json_encode( $body ); $ctype = 'application/json'; }
		$headers = [
			// A remote behind HTTP Basic Auth needs Authorization for the proxy; the token then travels only in X-Envsync-Token
			'Authorization' => ! empty( $this->env['basic_auth'] ) ? 'Basic ' . base64_encode( $this->env['basic_auth'] ) : 'Bearer ' . $this->env['token'],
			'X-Envsync-Token' => $this->env['token'],
			'X-Envsync-Ts'  => $ts,
			'X-Envsync-Sig' => IXES_Auth::sign( $this->env['token'], $method, $path, $ts, $raw, $step, $prefix, $excl ),
			'Content-Type'  => $ctype,
			'Accept'        => ( $opts['accept'] ?? 'json' ) === 'binary' ? 'application/octet-stream' : 'application/json',
		];
		if ( $step !== '' ) $headers['X-Envsync-Step'] = $step;
		if ( $prefix !== '' ) $headers['X-Envsync-Prefix'] = $prefix;
		// signed: stripped, the remote would overwrite host-only options; widened, it would skip rows the hub expects
		if ( $excl !== '' ) $headers['X-Envsync-Exclude-Options'] = $excl;
		if ( ! empty( $opts['headers'] ) ) $headers = array_merge( $headers, $opts['headers'] );
		$args = [ 'method' => $method, 'timeout' => (int) ( $opts['timeout'] ?? $this->effective_timeout() ), 'redirection' => 0, 'headers' => $headers ];
		if ( $raw !== '' || $body !== null ) $args['body'] = $raw;
		// ponytail: ?rest_route= works with any permalink structure; /wp-json/ 301s on plain permalinks and drops the Authorization header
		return [ $opts['url'] ?? $this->env['url'] . '/?rest_route=' . $path, $args ];
	}

	private function parse( $res, $route, array $opts ) {
		if ( is_wp_error( $res ) ) return $res;
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code >= 300 && $code < 400 ) return new WP_Error( 'remote_redirect', 'remote redirected to ' . wp_remote_retrieve_header( $res, 'location' ) . '; register the final URL with env add' );
		$body_s = wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			$json = json_decode( $body_s, true );
			// an HTML error page (WordPress's critical-error screen, a proxy page) becomes one readable line
			$msg  = is_array( $json ) && isset( $json['message'] ) ? $json['message'] : mb_strimwidth( trim( preg_replace( '/\s+/', ' ', strip_tags( $body_s ) ) ), 0, 200, '…' );
			if ( $code === 401 && ! is_array( $json ) && stripos( (string) wp_remote_retrieve_header( $res, 'www-authenticate' ), 'basic' ) === 0 ) {
				$msg = empty( $this->env['basic_auth'] ) ? 'the site is behind HTTP Basic Auth; register its credentials with --basic-auth=<user:pass>' : 'HTTP Basic Auth rejected the --basic-auth credentials';
			}
			return new WP_Error( 'remote_' . $code, "remote {$code} on {$route}: {$msg}", [ 'status' => $code ] );
		}
		// Request intent decides what we asked for; the response content-type confirms what we actually got.
		// A binary request answered with a non-octet-stream content-type (e.g. a JSON error body on a 2xx) falls through to the JSON parse below instead of being handed to the caller as file bytes.
		$ctype_res = (string) wp_remote_retrieve_header( $res, 'content-type' );
		$is_bin    = ( $opts['accept'] ?? 'json' ) === 'binary' && ( $ctype_res === '' || stripos( $ctype_res, 'application/octet-stream' ) === 0 );
		if ( $is_bin ) {
			$h = [];
			// WordPress returns a CaseInsensitiveDictionary object; an (array) cast yields its mangled private property, not the headers
			$raw_h = wp_remote_retrieve_headers( $res );
			if ( is_object( $raw_h ) && method_exists( $raw_h, 'getAll' ) ) $raw_h = $raw_h->getAll();
			foreach ( (array) $raw_h as $k => $v ) $h[ strtolower( $k ) ] = is_array( $v ) ? end( $v ) : $v;
			return [ 'body' => $body_s, 'headers' => $h ];
		}
		$json = json_decode( $body_s, true );
		return is_array( $json ) ? $json : [];
	}

	/**
	 * Several requests at once. $wire: key => [ url, args ] from prepare(); returns key => response array or WP_Error.
	 * Overridden by tests. Requests ships with every supported WordPress: namespaced from 6.2, the old global class before.
	 */
	protected function transport_multi( array $wire ) {
		$cls = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : null );
		$out = [];
		// a proxy, blocked external hosts or a filter on the HTTP API only take effect through wp_remote_request: keep that path
		$proxy = class_exists( 'WP_HTTP_Proxy' ) ? new WP_HTTP_Proxy() : null;
		$wp_http_only = ( $proxy && $proxy->is_enabled() ) || ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL )
			|| ( function_exists( 'has_filter' ) && ( has_filter( 'pre_http_request' ) || has_filter( 'http_request_args' ) ) );
		if ( ! $cls || ! class_exists( 'WP_HTTP_Requests_Response' ) || $wp_http_only ) {
			foreach ( $wire as $k => $w ) $out[ $k ] = $this->transport( $w[0], $w[1] );
			return $out;
		}
		$reqs = [];
		foreach ( $wire as $k => $w ) {
			list( $url, $a ) = $w;
			$reqs[ $k ] = [ 'url' => $url, 'type' => $a['method'], 'headers' => $a['headers'], 'data' => $a['body'] ?? '', 'options' => [
				'timeout' => $a['timeout'], 'connect_timeout' => min( 10, $a['timeout'] ), 'follow_redirects' => false,
				'useragent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				// wp_remote_request's own default: WordPress's CA bundle, unless a filter turned verification off
				'verify' => apply_filters( 'https_ssl_verify', true, $url ) ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : false,
			] ];
		}
		foreach ( $cls::request_multiple( $reqs ) as $k => $r ) {
			$out[ $k ] = $r instanceof Exception ? new WP_Error( 'http_request_failed', $r->getMessage() ) : ( new WP_HTTP_Requests_Response( $r ) )->to_array();
		}
		return $out;
	}

	/**
	 * $reqs: key => [ method, route, body, opts ]. Up to $parallel go out together; 1 sends them one by one, as before 0.8.0.
	 * @return array key => what request() returns
	 */
	public function many( array $reqs, $parallel ) {
		$out = [];
		if ( (int) $parallel <= 1 || count( $reqs ) < 2 ) {
			foreach ( $reqs as $k => $r ) $out[ $k ] = $this->request( $r[0], $r[1], $r[2], $r[3] ?? [] );
			return $out;
		}
		foreach ( array_chunk( $reqs, max( 1, (int) $parallel ), true ) as $set ) {
			$wire = [];
			foreach ( $set as $k => $r ) $wire[ $k ] = $this->prepare( $r[0], $r[1], $r[2], $r[3] ?? [] );
			$res = $this->transport_multi( $wire );
			foreach ( $set as $k => $r ) $out[ $k ] = $this->parse( $res[ $k ] ?? new WP_Error( 'http_request_failed', 'no response' ), $r[1], $r[3] ?? [] );
		}
		return $out;
	}

	public function get( $route )                    { return $this->request( 'GET', $route ); }

	/** A plain page request to the site, as a visitor (through Basic Auth when the env has it): for smoke tests. */
	public function probe( $url ) {
		$h = [ 'Cache-Control' => 'no-cache' ];
		if ( ! empty( $this->env['basic_auth'] ) ) $h['Authorization'] = 'Basic ' . base64_encode( $this->env['basic_auth'] );
		return $this->transport( $url, [ 'method' => 'GET', 'timeout' => 30, 'redirection' => 3, 'headers' => $h ] );
	}

	/** A JSON job step; deflated into an octet-stream body when the remote can unpack it (see IXES_Rest::unpack_step). */
	public function step( array $body ) {
		if ( ! function_exists( 'gzdeflate' ) || ! in_array( 'packed', $this->caps(), true ) ) return $this->post( '/job/step', $body );
		return $this->post( '/job/step', null, [ 'raw_body' => gzdeflate( wp_json_encode( $body ), 6 ), 'step' => wp_json_encode( [ 'job' => $body['job'] ?? '', 'kind' => 'packed' ] ) ] );
	}
	public function post( $route, $body, $opts = [] ) { return $this->request( 'POST', $route, $body, $opts ); }

	public function info( $timeout = null ) {
		if ( $this->info === null ) {
			// /info is a light call: 30s covers it even on a slow host, but a deliberately larger --timeout still wins
			$t = $timeout !== null ? (int) $timeout : max( 30, $this->raw_timeout() );
			$this->info = $this->map_prefix( $this->request( 'GET', '/info', null, [ 'timeout' => $t ] ) );
			// remember where rescue.php lives while the remote still answers: it is needed exactly when it no longer does
			$u = is_array( $this->info ) ? (string) ( $this->info['rescue_url'] ?? '' ) : '';
			if ( $u !== '' && $u !== ( $this->env['rescue_url'] ?? '' ) && isset( $this->env['name'] ) && function_exists( 'update_option' ) ) {
				$this->env['rescue_url'] = $u;
				IXES_Env::set_field( $this->env['name'], 'rescue_url', $u );
			}
		}
		return $this->info;
	}

	/**
	 * A remote with another table prefix that can translate: its table names become ours, and every later request
	 * tells it our prefix. Without the cap the names stay as they are and IXES_Pull::prefix_refusal() stops the sync.
	 */
	private function map_prefix( $info ) {
		$hub = $this->hub_prefix !== null ? (string) $this->hub_prefix : ( isset( $GLOBALS['wpdb']->prefix ) ? (string) $GLOBALS['wpdb']->prefix : '' );
		if ( ! is_array( $info ) || $hub === '' || ! isset( $info['prefix'] ) || $info['prefix'] === $hub ) return $info;
		if ( ! in_array( 'prefix_map', (array) ( $info['caps'] ?? [] ), true ) ) return $info;
		$map = new IXES_Prefix( $hub, (string) $info['prefix'] );
		$tables = [];
		foreach ( (array) ( $info['tables'] ?? [] ) as $t ) {
			$n = $map->table_in( (string) ( $t['name'] ?? '' ) );
			if ( $n !== null ) { $t['name'] = $n; $tables[] = $t; }
		}
		$info['tables'] = $tables;
		$info['hub_prefix'] = $hub;
		$this->prefix_header = $hub;
		return $info;
	}

	public function rescue_url() {
		$u = (string) ( $this->env['rescue_url'] ?? '' );
		return $u !== '' ? $u : $this->env['url'] . '/wp-content/plugins/ix-wp-envsync/rescue.php';
	}

	/** Talk to rescue.php, which answers even when a plugin fatals on every normal request. */
	public function rescue( $action, array $extra = [] ) {
		return $this->request( 'POST', '/rescue', [ 'action' => $action ] + $extra, [ 'url' => $this->rescue_url(), 'timeout' => max( 60, $this->raw_timeout() ) ] );
	}

	/** Remote capability list from /info; [] for a 0.2 remote. */
	public function caps() {
		if ( $this->caps === null ) { $i = $this->info(); $this->caps = is_array( $i ) ? (array) ( $i['caps'] ?? [] ) : []; }
		return $this->caps;
	}
	public function set_caps( array $caps ) { $this->caps = $caps; }
	/** The remote carries byte cells losslessly (0.9.3): the hub then asks for them with 'cells' and hashes them as raw bytes. */
	public function cells() { return in_array( IXES_Hasher::CAP, $this->caps(), true ); }
	private function binary() { return in_array( 'binary', $this->caps(), true ); }
	/** The remote serves /file/batch and takes deflated push batches (0.8.0). */
	public function batch_files() { return $this->binary() && in_array( 'file_batch', $this->caps(), true ); }
	private function deflate() { return function_exists( 'gzdeflate' ) && function_exists( 'gzinflate' ); }

	public function remote_pairs() {
		$i = $this->info();
		return IXES_Hasher::placeholders( $i['url'], $i['abspath'] );
	}

	/** Pages through $route until 'next' is null; $each( $res ) returning false stops early. */
	public function paged( $route, array $body, callable $each, $cursor_key = 'from' ) {
		$limit = isset( $body['limit'] ) ? (int) $body['limit'] : 5000;
		$max   = $limit;
		$next  = $body[ $cursor_key ] ?? null;
		do {
			$body['limit'] = $limit;
			$body[ $cursor_key ] = $next;
			$t0  = microtime( true );
			$res = $this->post( $route, $body );
			if ( is_wp_error( $res ) ) return $res;
			$dt  = microtime( true ) - $t0;
			if ( $each( $res ) === false ) return;
			$prev = $next;
			$next = isset( $res['next'] ) ? $res['next'] : null;
			// a cursor that did not move answers the same page forever: an older remote sends a binary key's cursor
			// through JSON, which turns its bytes into '?'
			if ( $next !== null && $next === $prev ) {
				$t = isset( $body['table'] ) ? " for {$body['table']}" : '';
				return new WP_Error( 'no_progress', "paging {$route}{$t} stopped: the remote returned the same cursor twice. A binary primary key needs plugin 0.9.3 or newer on the remote." );
			}
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
	public function fetch_file( $rel, callable $write, ?callable $on_bytes = null ) {
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
				$data = (string) $res['body'];
				if ( ! isset( $res['headers']['x-envsync-total'] ) ) return new WP_Error( 'transfer', "{$rel}: binary response missing X-Envsync-Total header" );
				$total = (int) ( $res['headers']['x-envsync-total'] ?? 0 ); $sha = (string) ( $res['headers']['x-envsync-sha256'] ?? '' );
			} elseif ( isset( $res['data'] ) ) { // legacy base64 JSON
				$data = base64_decode( (string) $res['data'] ); $total = (int) ( $res['total'] ?? 0 ); $sha = (string) ( $res['sha256'] ?? '' );
			} else {
				return new WP_Error( 'transfer', "{$rel}: unexpected response at offset {$offset}: " . wp_json_encode( $res ) );
			}
			$ch->ok( microtime( true ) - $t0 ); $ch->reset_attempts();
			$next  = $offset + strlen( $data );
			$final = $next >= $total || $data === '';
			$w = $write( $offset, $data, $final, $sha );
			if ( is_wp_error( $w ) ) return $w;
			if ( $on_bytes ) $on_bytes( strlen( $data ) );
			if ( $final ) return true;
			$offset = $next;
		}
	}

	/**
	 * Push many small files in one /job/step. Safe to retry: the remote skips files that already hold these bytes.
	 * @param array $items list of [ meta (path, sha256, expect?, algo?), bytes ]
	 */
	public function send_batch( $job, array $items ) {
		$req = $this->batch_step( $job, $items );
		$ch = new IXES_Chunker();
		while ( true ) {
			$r = $this->request( $req[0], $req[1], $req[2], $req[3] );
			if ( ! is_wp_error( $r ) ) return $r;
			if ( ! $ch->fail( self::err_code( $r ) ) ) {
				return new WP_Error( 'transfer', 'batch of ' . count( $items ) . " files starting at {$items[0][0]['path']}: gave up after {$ch->attempts()} attempts: " . $r->get_error_message() );
			}
			$this->sleep_s( $ch->backoff() );
		}
	}

	/**
	 * Push one file chunk by chunk through /job/step. $first_meta (expect, algo) is merged into the offset-0 step.
	 * @return array{ok:bool,refused:bool}|WP_Error
	 */
	public function send_file( $job, $rel, $abs, array $first_meta, ?callable $on_bytes = null ) {
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
			if ( $on_bytes ) $on_bytes( strlen( $data ) );
			if ( ! empty( $r['refused'] ) ) { fclose( $fh ); return [ 'ok' => false, 'refused' => true ]; }
			if ( $final ) { fclose( $fh ); return [ 'ok' => true, 'refused' => false ]; }
			$offset += strlen( $data );
		}
	}

	/**
	 * Upload a self-update zip to /self-update/chunk, chunk by chunk, each signed with its step header. Deflated when the
	 * remote inflates packed steps: a zip's stored (uncompressed) entries would otherwise show plain PHP to a host firewall.
	 * @return true|WP_Error
	 */
	public function send_package( $id, $abs ) {
		$sha = hash_file( 'sha256', $abs ); $total = (int) filesize( $abs );
		$z   = $this->deflate() && in_array( 'packed', $this->caps(), true );
		$ch  = new IXES_Chunker();
		$fh  = fopen( $abs, 'rb' );
		if ( ! $fh ) return new WP_Error( 'io', "cannot read {$abs}" );
		$offset = 0;
		while ( true ) {
			fseek( $fh, $offset );
			$data  = (string) fread( $fh, $ch->size() );
			$final = $offset + strlen( $data ) >= $total || $data === '';
			$step  = [ 'id' => $id, 'offset' => $offset, 'final' => $final, 'sha256' => $sha ] + ( $z ? [ 'enc' => 'deflate' ] : [] );
			$t0 = microtime( true );
			$r  = $this->post( '/self-update/chunk', null, [ 'raw_body' => $z ? gzdeflate( $data, 6 ) : $data, 'step' => wp_json_encode( $step ) ] );
			if ( is_wp_error( $r ) ) {
				if ( $ch->fail( self::err_code( $r ) ) ) { $this->sleep_s( $ch->backoff() ); continue; }
				fclose( $fh );
				return new WP_Error( 'transfer', "gave up at offset {$offset} after {$ch->attempts()} attempts: " . $r->get_error_message() );
			}
			$ch->ok( microtime( true ) - $t0 ); $ch->reset_attempts();
			if ( $final ) { fclose( $fh ); return true; }
			$offset += strlen( $data );
		}
	}

	/** A 'files' push step for many(); deflated when the remote inflates it (0.8.0). */
	private function batch_step( $job, array $items ) {
		// 'packed' is how a remote says it has gzinflate; one without zlib still takes plain batches
		$z = $this->batch_files() && $this->deflate() && in_array( 'packed', $this->caps(), true );
		$step = [ 'job' => $job, 'kind' => 'files' ] + ( $z ? [ 'enc' => 'deflate' ] : [] );
		return [ 'POST', '/job/step', null, [ 'raw_body' => $z ? gzdeflate( IXES_Batch::encode( $items ), 6 ) : IXES_Batch::encode( $items ), 'step' => wp_json_encode( $step ), 'timeout' => max( self::BATCH_TIMEOUT, $this->effective_timeout() ) ] ];
	}

	/**
	 * Push batches, $parallel requests at a time. $items_for( $key ) reads one batch's files only when it goes out;
	 * $on_done( $key, $response ) runs once per batch that landed, before any error is returned.
	 * @return true|WP_Error
	 */
	public function send_batches( $job, array $keys, $parallel, callable $items_for, callable $on_done ) {
		$first = [];
		// an older remote gets them one at a time, as it always did
		return $this->waves( $keys, $this->batch_files() ? $parallel : 1, function ( $k ) use ( $job, $items_for, &$first ) {
			$items = $items_for( $k );
			$first[ $k ] = count( $items ) . ' files starting at ' . $items[0][0]['path'];
			return $this->batch_step( $job, $items );
		}, $on_done, function ( $k ) use ( &$first ) { return 'batch of ' . $first[ $k ]; } );
	}

	/**
	 * Pull batches through /file/batch, $parallel requests at a time. $on_batch( $key, items ) gets each batch
	 * already checked by IXES_Batch::open(); whatever it returns (true or WP_Error) is final for that batch.
	 * @param array $batches key => list of rel paths
	 * @return true|WP_Error
	 */
	public function fetch_batches( array $batches, $parallel, callable $on_batch ) {
		$z = $this->deflate();
		return $this->waves( array_keys( $batches ), $parallel, function ( $k ) use ( $batches, $z ) {
			return [ 'POST', '/file/batch', [ 'paths' => array_values( $batches[ $k ] ), 'deflate' => $z ], [ 'accept' => 'binary', 'timeout' => max( self::BATCH_TIMEOUT, $this->effective_timeout() ) ] ];
		}, function ( $k, $res ) use ( $batches, $on_batch ) {
			if ( ! isset( $res['headers'] ) ) return new WP_Error( 'bad_batch', 'batch answer is not binary' );
			$items = IXES_Batch::open( (string) $res['body'], (string) ( $res['headers']['x-envsync-enc'] ?? '' ), $batches[ $k ] );
			return is_wp_error( $items ) ? $items : $on_batch( $k, $items );
		}, function ( $k ) use ( $batches ) { return 'batch of ' . count( $batches[ $k ] ) . ' files starting at ' . reset( $batches[ $k ] ); } );
	}

	/**
	 * Send one request per key, $parallel at a time, each batch retried on its own (IXES_Chunker budget and backoff).
	 * $on_ok may answer a WP_Error: retried when its code is in IXES_Batch::RETRY, final otherwise.
	 * A fatal error is returned only after every other answer of its wave went through $on_ok, so what landed is recorded.
	 */
	private function waves( array $keys, $parallel, callable $req_for, callable $on_ok, callable $label ) {
		$queue = array_values( $keys ); $tries = []; $err = null;
		while ( $queue && ! $err ) {
			$wave = array_splice( $queue, 0, max( 1, (int) $parallel ) );
			$reqs = [];
			foreach ( $wave as $k ) $reqs[ $k ] = $req_for( $k );
			$res  = $this->many( $reqs, $parallel );
			$wait = 0;
			foreach ( $wave as $k ) {
				$r = $res[ $k ];
				$sent = ! is_wp_error( $r );
				if ( $sent ) $r = $on_ok( $k, $r );
				if ( ! is_wp_error( $r ) ) continue;
				// a write that failed here (disk, ownership, a refused path) is not the network's fault: no retry
				if ( $sent && ! in_array( $r->get_error_code(), IXES_Batch::RETRY, true ) ) { if ( ! $err ) $err = $r; continue; }
				if ( ! isset( $tries[ $k ] ) ) $tries[ $k ] = new IXES_Chunker();
				if ( $tries[ $k ]->fail( $sent ? null : self::err_code( $r ) ) ) { $queue[] = $k; $wait = max( $wait, $tries[ $k ]->backoff() ); continue; }
				if ( ! $err ) $err = new WP_Error( 'transfer', $label( $k ) . ": gave up after {$tries[ $k ]->attempts()} attempts: " . $r->get_error_message() );
			}
			if ( $wait && $queue && ! $err ) $this->sleep_s( $wait );
		}
		return $err ?: true;
	}
}
