# Binary transfer, resumable pull, selective sync — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make 3 GB pulls survive shared hosts (raw binary chunks, adaptive size, resume after a dropped request) and let the operator sync only the tables and paths they name.

**Architecture:** The remote stays a set of short REST steps. Two routes learn an `application/octet-stream` shape next to their JSON shape, chosen by `Accept` / `Content-Type`, so 0.2 and 0.3 interoperate in both directions. The hub gains three pure classes: `IXES_Chunker` (adaptive size + retry policy), `IXES_PullState` (resume file), `IXES_Scope` (the three flags). Pull, Planner and Applier consume them; the CLI parses flags.

**Tech Stack:** PHP 7.4+, WordPress HTTP API (`wp_remote_request`), WP-CLI, PHPUnit 9.6 (pure tests, no WordPress bootstrap), bash integration script over two WP installs.

**Spec:** `docs/superpowers/specs/2026-09-22-transfer-resume-scope-design.md`

## Global Constraints

- `Requires PHP: 7.4`; no PHP 8-only syntax (no `match`, no named args, no `str_contains`).
- Every new class lives in `includes/class-ixes-<name>.php` and is autoloaded by the `IXES_` prefix rule in `ix-wp-envsync.php`.
- Unit tests run with `vendor/bin/phpunit` and must not need WordPress: only the stubs in `tests/bootstrap.php`. Add stubs there if a new pure class needs one.
- PHPCS gate: `vendor/bin/phpcs` (security/compat/i18n only). Run before each commit.
- Wire compatibility: a 0.2.2 hub against a 0.3.0 remote and a 0.3.0 hub against a 0.2.2 remote must both work. Never remove the JSON code paths.
- Commit messages: conventional commits, English, no AI attribution.
- Branch: `feat/transfer-resume-scope` (already created, holds the spec).

## File map

| File | Responsibility | Task |
|---|---|---|
| `includes/class-ixes-auth.php` | fifth signature line for the step header | 1 |
| `includes/class-ixes-chunker.php` (new) | adaptive chunk size and retry policy, pure | 2 |
| `includes/class-ixes-client.php` | `request()` options, binary responses, `fetch_file()`, `send_file()`, `caps()` | 3 |
| `includes/class-ixes-transfer.php` | `file_chunk()` binary mode, `import_rows()` REPLACE mode, `tmp_exists()` | 4, 6 |
| `includes/class-ixes-rest.php` | `Accept`/`Content-Type` branching, step header into auth, `caps` in info | 4 |
| `includes/class-ixes-applier.php` | `job_step` binary branch; `apply()` uses `send_file()`; scoped prompt text | 4, 5, 9 |
| `includes/class-ixes-pull.php` | uses `fetch_file()`; state + resume; scope | 5, 7, 9 |
| `includes/class-ixes-pullstate.php` (new) | resume file read/write/refusal, pure | 7 |
| `includes/class-ixes-planner.php` | `save()` for pull plans; scope filtering; scope line in render | 6, 9 |
| `includes/class-ixes-baseline.php` | `created_at` at commit, `delete_table()`, partial meta | 6, 9 |
| `includes/class-ixes-scope.php` (new) | the three flags, pure | 8 |
| `includes/class-ixes-cli.php` | `--fresh`, resume prompt, scope flags on pull/diff/push | 7, 9 |
| `admin/class-ixes-admin.php` | partial baseline display | 9 |
| `skills/wp-envsync/SKILL.md`, `README.md` | flag table, resume docs | 10 |
| `ix-wp-envsync.php` | 0.3.0 | 10 |
| `tests/integration.sh` | three new scenarios | 11 |

---

### Task 1: Signature covers the step header

**Files:**
- Modify: `includes/class-ixes-auth.php:18-30`
- Test: `tests/AuthTest.php`

**Interfaces:**
- Produces: `IXES_Auth::sign( $token, $method, $path, $ts, $body, $step = '' )` and `IXES_Auth::verify( $token_hash, $presented_token, $method, $path, $ts, $body, $sig, $now = null, $step = '' )`. `$step` is the raw `X-Envsync-Step` header value or `''`.

- [ ] **Step 1: Write failing tests**

Append to `tests/AuthTest.php` inside the class:

```php
	public function test_step_header_is_signed() {
		$step = '{"job":"j1","kind":"file","path":"a.txt","offset":0}';
		$sig  = IXES_Auth::sign( $this->tok, 'POST', '/envsync/v1/job/step', 5, 'bytes', $step );
		$this->assertTrue( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/envsync/v1/job/step', 5, 'bytes', $sig, 5, $step ) );
		$this->assertFalse( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/envsync/v1/job/step', 5, 'bytes', $sig, 5, '{"job":"j2"}' ), 'tampered step header must fail' );
	}
	public function test_old_client_signature_without_step_still_verifies() {
		// a 0.2.x hub signs METHOD\nPATH\nTS\nsha256(body) with no fifth line
		$msg = "POST\n/x\n1\n" . hash( 'sha256', 'b' );
		$old = hash_hmac( 'sha256', $msg, $this->tok );
		$this->assertTrue( IXES_Auth::verify( wp_hash( $this->tok ), $this->tok, 'POST', '/x', 1, 'b', $old, 1 ) );
	}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter AuthTest`
Expected: `test_step_header_is_signed` errors with "Too few arguments" or fails on the tampered assertion.

- [ ] **Step 3: Implement**

Replace `sign()` and `verify()` in `includes/class-ixes-auth.php`:

```php
	// $step: raw X-Envsync-Step header or ''. Old clients send none; the message then ends exactly
	// as it did in 0.2 (no trailing "\n"), so their signatures keep verifying.
	public static function sign( $token, $method, $path, $ts, $body, $step = '' ) {
		$msg = strtoupper( $method ) . "\n" . $path . "\n" . (int) $ts . "\n" . hash( 'sha256', (string) $body );
		if ( (string) $step !== '' ) $msg .= "\n" . $step;
		return hash_hmac( 'sha256', $msg, $token );
	}

	public static function verify( $token_hash, $presented_token, $method, $path, $ts, $body, $sig, $now = null, $step = '' ) {
		if ( $now === null ) $now = time();
		if ( ! is_string( $token_hash ) || ! is_string( $presented_token ) || $presented_token === '' ) return false;
		if ( ! hash_equals( $token_hash, wp_hash( $presented_token ) ) ) return false;
		if ( abs( $now - (int) $ts ) > self::SKEW ) return false;
		$expected = self::sign( $presented_token, $method, $path, $ts, $body, $step );
		return hash_equals( $expected, (string) $sig );
	}
```

Note: the spec says "fifth line, empty when absent". Appending the line only when non-empty is the same contract and keeps 0.2 signatures byte-identical; that is what the second test pins.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter AuthTest`
Expected: all AuthTest pass.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ixes-auth.php tests/AuthTest.php
git commit -m "feat(auth): sign the X-Envsync-Step header when present"
```

---

### Task 2: `IXES_Chunker` — adaptive size and retry policy

**Files:**
- Create: `includes/class-ixes-chunker.php`
- Test: `tests/ChunkerTest.php`

**Interfaces:**
- Produces:
  - `new IXES_Chunker( $start = 2097152, $min = 262144, $max = 4194304, $retries = 5 )`
  - `$c->size()` → current chunk size in bytes
  - `$c->ok( $seconds )` → record a successful chunk that took `$seconds`; grows after two consecutive fast (< 2 s) chunks
  - `$c->fail( $http_code_or_null )` → returns `true` if the caller should retry the same offset (size halved when the failure is transport or one of 408/413/502/503/504; for other codes size is kept), `false` when the retry budget is spent
  - `$c->backoff()` → seconds to sleep before the next attempt: 1, 2, 4, 8, 8
  - `$c->attempts()` → failed attempts so far for the current offset
  - `$c->reset_attempts()` → call after a chunk succeeds
  - `IXES_Chunker::retryable( $code )` → static, true for null (transport) and 408/413/502/503/504

- [ ] **Step 1: Write failing tests**

Create `tests/ChunkerTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class ChunkerTest extends TestCase {
	public function test_defaults() {
		$c = new IXES_Chunker();
		$this->assertSame( 2097152, $c->size() );
	}
	public function test_grows_after_two_fast_chunks_up_to_max() {
		$c = new IXES_Chunker();
		$c->ok( 0.5 ); $this->assertSame( 2097152, $c->size(), 'one fast chunk is not enough' );
		$c->ok( 0.5 ); $this->assertSame( 4194304, $c->size() );
		$c->ok( 0.5 ); $c->ok( 0.5 ); $this->assertSame( 4194304, $c->size(), 'capped at max' );
	}
	public function test_slow_chunk_resets_the_fast_streak() {
		$c = new IXES_Chunker();
		$c->ok( 0.5 ); $c->ok( 3 ); $c->ok( 0.5 );
		$this->assertSame( 2097152, $c->size() );
	}
	public function test_retryable_failure_halves_down_to_min() {
		$c = new IXES_Chunker();
		$this->assertTrue( $c->fail( 413 ) ); $this->assertSame( 1048576, $c->size() );
		$this->assertTrue( $c->fail( null ) ); $this->assertSame( 524288, $c->size() );
		$this->assertTrue( $c->fail( 502 ) ); $this->assertSame( 262144, $c->size() );
		$this->assertTrue( $c->fail( 504 ) ); $this->assertSame( 262144, $c->size(), 'floor' );
		$this->assertFalse( $c->fail( 503 ), 'fifth failure spends the budget' );
	}
	public function test_non_retryable_code_keeps_size_but_counts_attempt() {
		$c = new IXES_Chunker();
		$this->assertTrue( $c->fail( 500 ) );
		$this->assertSame( 2097152, $c->size() );
		$this->assertSame( 1, $c->attempts() );
	}
	public function test_backoff_sequence() {
		$c = new IXES_Chunker();
		$seen = [];
		for ( $i = 0; $i < 5; $i++ ) { $c->fail( 502 ); $seen[] = $c->backoff(); }
		$this->assertSame( [ 1, 2, 4, 8, 8 ], $seen );
	}
	public function test_success_resets_attempts() {
		$c = new IXES_Chunker();
		$c->fail( 502 ); $c->reset_attempts();
		$this->assertSame( 0, $c->attempts() );
	}
	public function test_retryable_static() {
		$this->assertTrue( IXES_Chunker::retryable( null ) );
		$this->assertTrue( IXES_Chunker::retryable( 413 ) );
		$this->assertFalse( IXES_Chunker::retryable( 401 ) );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter ChunkerTest`
Expected: error "Class IXES_Chunker not found".

- [ ] **Step 3: Implement**

Create `includes/class-ixes-chunker.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adaptive chunk size and retry budget for one file transfer, in either direction.
 * Pure: no I/O. The caller sleeps backoff() seconds itself.
 */
class IXES_Chunker {
	const FAST = 2.0; // seconds; two consecutive chunks under this double the size

	private $size; private $min; private $max; private $retries;
	private $fast_streak = 0; private $attempts = 0;

	public function __construct( $start = 2097152, $min = 262144, $max = 4194304, $retries = 5 ) {
		$this->size = (int) $start; $this->min = (int) $min; $this->max = (int) $max; $this->retries = (int) $retries;
	}

	public static function retryable( $code ) {
		return $code === null || in_array( (int) $code, [ 408, 413, 502, 503, 504 ], true );
	}

	public function size() { return $this->size; }
	public function attempts() { return $this->attempts; }
	public function reset_attempts() { $this->attempts = 0; }

	public function ok( $seconds ) {
		if ( $seconds < self::FAST ) {
			$this->fast_streak++;
			if ( $this->fast_streak >= 2 ) { $this->size = min( $this->max, $this->size * 2 ); $this->fast_streak = 0; }
		} else {
			$this->fast_streak = 0;
		}
	}

	/** @return bool true = retry the same offset, false = budget spent */
	public function fail( $code ) {
		$this->attempts++;
		$this->fast_streak = 0;
		if ( self::retryable( $code ) ) $this->size = max( $this->min, (int) ( $this->size / 2 ) );
		return $this->attempts < $this->retries;
	}

	public function backoff() {
		return min( 8, 1 << max( 0, $this->attempts - 1 ) );
	}
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter ChunkerTest`
Expected: 8 tests pass.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ixes-chunker.php tests/ChunkerTest.php
git commit -m "feat: adaptive chunk size and retry policy"
```

---

### Task 3: Client — binary requests and the shared file loops

**Files:**
- Modify: `includes/class-ixes-client.php` (whole file)
- Test: `tests/ClientLoopTest.php` (tests the loops through an injectable transport)

**Interfaces:**
- Consumes: `IXES_Chunker` (Task 2), `IXES_Auth::sign( …, $step )` (Task 1).
- Produces:
  - `IXES_Client::request( $method, $route, $body = null, array $opts = [] )` — `$opts['raw_body']` string sends octet-stream; `$opts['accept']` `'binary'` asks for octet-stream and returns `[ 'body' => string, 'headers' => array ]` on 2xx; `$opts['headers']` extra request headers; `$opts['step']` string is sent as `X-Envsync-Step` and signed.
  - `IXES_Client::caps()` → array from `/info` (`[]` for a 0.2 remote).
  - `IXES_Client::fetch_file( $rel, callable $write )` — `$write( $offset, $data, $final, $sha256 )` must return `true` or `WP_Error`; returns `true` or `WP_Error`.
  - `IXES_Client::send_file( $job, $rel, $abs, array $first_meta )` — `$first_meta` is merged into the offset-0 step (`expect`, `algo`); returns `[ 'ok' => bool, 'refused' => bool ]` or `WP_Error`.
  - Protected `transport( $url, array $args )` wrapping `wp_remote_request`; tests subclass and override it.

- [ ] **Step 1: Add the bootstrap stubs the client needs outside WordPress**

Append to `tests/bootstrap.php` before the autoloader:

```php
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $msg; private $data;
		public function __construct( $code = '', $msg = '', $data = null ) { $this->code = $code; $this->msg = $msg; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->msg; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; } }
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) { function wp_remote_retrieve_header( $r, $h ) { return $r['headers'][ strtolower( $h ) ] ?? ''; } }
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) { function wp_remote_retrieve_headers( $r ) { return $r['headers'] ?? []; } }
```

- [ ] **Step 2: Write failing tests**

Create `tests/ClientLoopTest.php`. The fake transport scripts responses per call and records what it received:

```php
<?php
use PHPUnit\Framework\TestCase;

class FakeClient extends IXES_Client {
	public $script = [];   // list of callables ($url,$args) => response array | WP_Error
	public $calls  = [];
	public $slept  = [];
	protected function transport( $url, array $args ) {
		$this->calls[] = $args;
		$fn = array_shift( $this->script );
		if ( ! $fn ) throw new RuntimeException( 'unscripted call' );
		return $fn( $url, $args );
	}
	protected function sleep_s( $s ) { $this->slept[] = $s; }
}

class ClientLoopTest extends TestCase {
	private function client() { return new FakeClient( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] ); }
	private static function bin( $body, $total, $sha ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => $body, 'headers' => [ 'x-envsync-total' => (string) $total, 'x-envsync-size' => (string) strlen( $body ), 'x-envsync-sha256' => $sha ] ];
	}

	public function test_fetch_file_streams_chunks_to_writer_and_stops_at_total() {
		$c = $this->client();
		$sha = hash( 'sha256', 'helloworld' );
		$c->script = [
			function () use ( $sha ) { return self::bin( 'hello', 10, $sha ); },
			function () use ( $sha ) { return self::bin( 'world', 10, $sha ); },
		];
		$got = [];
		$r = $c->fetch_file( 'a.txt', function ( $offset, $data, $final, $s ) use ( &$got ) { $got[] = [ $offset, $data, $final, $s ]; return true; } );
		$this->assertTrue( $r );
		$this->assertSame( [ [ 0, 'hello', false, $sha ], [ 5, 'world', true, $sha ] ], $got );
		$this->assertSame( 'application/octet-stream', $c->calls[0]['headers']['Accept'] );
		$body = json_decode( $c->calls[1]['body'], true );
		$this->assertSame( 5, $body['offset'] );
	}

	public function test_fetch_file_retries_same_offset_with_smaller_chunk_on_413() {
		$c = $this->client();
		$sha = hash( 'sha256', 'abc' );
		$c->script = [
			function () { return [ 'response' => [ 'code' => 413 ], 'body' => 'too big', 'headers' => [] ]; },
			function () use ( $sha ) { return self::bin( 'abc', 3, $sha ); },
		];
		$r = $c->fetch_file( 'a.txt', function () { return true; } );
		$this->assertTrue( $r );
		$first  = json_decode( $c->calls[0]['body'], true );
		$second = json_decode( $c->calls[1]['body'], true );
		$this->assertSame( 0, $second['offset'] );
		$this->assertSame( $first['size'] / 2, $second['size'] );
		$this->assertSame( [ 1 ], $c->slept );
	}

	public function test_fetch_file_gives_up_after_budget_with_offset_in_message() {
		$c = $this->client();
		for ( $i = 0; $i < 5; $i++ ) $c->script[] = function () { return new WP_Error( 'http_request_failed', 'cURL error 28' ); };
		$r = $c->fetch_file( 'a.txt', function () { return true; } );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertStringContainsString( 'offset 0', $r->get_error_message() );
		$this->assertStringContainsString( 'cURL error 28', $r->get_error_message() );
	}

	public function test_fetch_file_falls_back_to_json_body_when_remote_lacks_binary() {
		$c = $this->client();
		$c->set_caps( [] ); // 0.2 remote
		$sha = hash( 'sha256', 'xy' );
		$c->script = [ function () use ( $sha ) { return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'data' => base64_encode( 'xy' ), 'size' => 2, 'total' => 2, 'sha256' => $sha ] ) ]; } ];
		$got = [];
		$this->assertTrue( $c->fetch_file( 'a.txt', function ( $o, $d, $f, $s ) use ( &$got ) { $got[] = [ $o, $d, $f ]; return true; } ) );
		$this->assertSame( [ [ 0, 'xy', true ] ], $got );
		$this->assertSame( 'application/json', $c->calls[0]['headers']['Accept'] );
	}

	public function test_send_file_puts_metadata_in_step_header_and_bytes_in_body() {
		$c = $this->client();
		$c->set_caps( [ 'binary' ] );
		$tmp = tempnam( sys_get_temp_dir(), 'ixes' ); file_put_contents( $tmp, 'payload' );
		$c->script = [ function () { return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'ok' => true ] ) ]; } ];
		$r = $c->send_file( 'job1', 'themes/x/a.txt', $tmp, [ 'expect' => 'h', 'algo' => 'sha1' ] );
		$this->assertSame( [ 'ok' => true, 'refused' => false ], $r );
		$a = $c->calls[0];
		$this->assertSame( 'payload', $a['body'] );
		$this->assertSame( 'application/octet-stream', $a['headers']['Content-Type'] );
		$step = json_decode( $a['headers']['X-Envsync-Step'], true );
		$this->assertSame( [ 'job' => 'job1', 'kind' => 'file', 'path' => 'themes/x/a.txt', 'offset' => 0, 'final' => true, 'sha256' => hash( 'sha256', 'payload' ), 'expect' => 'h', 'algo' => 'sha1' ], $step );
		unlink( $tmp );
	}

	public function test_send_file_reports_refusal_and_stops() {
		$c = $this->client();
		$c->set_caps( [ 'binary' ] );
		$tmp = tempnam( sys_get_temp_dir(), 'ixes' ); file_put_contents( $tmp, str_repeat( 'z', 3000000 ) );
		$c->script = [ function () { return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'ok' => false, 'refused' => [ 'themes/x/a.txt' ] ] ) ]; } ];
		$r = $c->send_file( 'job1', 'themes/x/a.txt', $tmp, [] );
		$this->assertSame( [ 'ok' => false, 'refused' => true ], $r );
		$this->assertCount( 1, $c->calls, 'no second chunk after a refusal' );
		unlink( $tmp );
	}

	public function test_send_file_json_fallback_for_old_remote() {
		$c = $this->client();
		$c->set_caps( [] );
		$tmp = tempnam( sys_get_temp_dir(), 'ixes' ); file_put_contents( $tmp, 'p' );
		$c->script = [ function () { return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => json_encode( [ 'ok' => true ] ) ]; } ];
		$c->send_file( 'j', 'a.txt', $tmp, [] );
		$b = json_decode( $c->calls[0]['body'], true );
		$this->assertSame( base64_encode( 'p' ), $b['data'] );
		$this->assertSame( 'file', $b['kind'] );
		unlink( $tmp );
	}
}
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/phpunit --filter ClientLoopTest`
Expected: errors — `transport` not overridable / `fetch_file` undefined.

- [ ] **Step 4: Implement the client**

Replace `includes/class-ixes-client.php` with:

```php
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
		$is_bin = stripos( (string) wp_remote_retrieve_header( $res, 'content-type' ), 'application/octet-stream' ) === 0;
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
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit`
Expected: all pass (ClientLoopTest 7 tests). If `IXES_Rest::NS` is undefined in the test context, the autoloader loads `class-ixes-rest.php`; it only defines the class, so this works without WordPress.

- [ ] **Step 6: PHPCS and commit**

```bash
vendor/bin/phpcs
git add includes/class-ixes-client.php tests/ClientLoopTest.php tests/bootstrap.php
git commit -m "feat(client): binary requests, adaptive file loops, remote caps"
```

---

### Task 4: Remote side — binary `/file/get`, binary `/job/step`, `caps`

**Files:**
- Modify: `includes/class-ixes-transfer.php` (`info()`, `file_chunk()`)
- Modify: `includes/class-ixes-rest.php` (`auth()`, `file_get()`, job routes)
- Modify: `includes/class-ixes-applier.php` (`job_step()` file branch)
- Test: none pure; verified by Task 11 integration. Keep `php -l` and `phpcs` green.

**Interfaces:**
- Consumes: `IXES_Auth::verify( …, $step )` (Task 1).
- Produces:
  - `IXES_Transfer::file_chunk( $rel, $offset, $size, $as_binary = false )` — binary mode returns `[ 'bin' => string, 'size' => int, 'total' => int, 'sha256' => string ]`.
  - `IXES_Applier::job_step( array $p )` accepts, for `kind=file`, either `$p['data']` (base64) or `$p['bin']` (raw string) — the REST layer fills one of them.
  - `/info` includes `'caps' => [ 'binary', 'scope' ]`.

- [ ] **Step 1: `info()` caps**

In `includes/class-ixes-transfer.php`, inside the array returned by `info()`, add after `'plugin' => IXES_VERSION,`:

```php
			'caps'            => [ 'binary', 'scope' ],
```

- [ ] **Step 2: `file_chunk()` binary mode**

Replace `file_chunk()` in `includes/class-ixes-transfer.php`:

```php
	public static function file_chunk( $rel, $offset, $size, $as_binary = false ) {
		$rel = self::safe_rel( $rel );
		if ( ! $rel || self::excluded_path( $rel, IXES_Env::default_excludes() ) ) return new WP_Error( 'bad_path', 'path refused', [ 'status' => 400 ] );
		$p = WP_CONTENT_DIR . '/' . $rel;
		if ( ! is_file( $p ) ) return new WP_Error( 'not_found', 'no such file', [ 'status' => 404 ] );
		$fh = fopen( $p, 'rb' ); fseek( $fh, $offset ); $data = fread( $fh, $size ); fclose( $fh );
		if ( $data === false ) $data = '';
		// cached: this used to rehash the whole file on every 2 MB chunk (O(n^2) on big media)
		$sha = IXES_Hashcache::hash( $p, $rel, 'sha256' );
		IXES_Hashcache::save();
		if ( $sha === false ) return new WP_Error( 'io', 'cannot hash file', [ 'status' => 500 ] );
		if ( $as_binary ) return [ 'bin' => $data, 'size' => strlen( $data ), 'total' => filesize( $p ), 'sha256' => $sha ];
		return [ 'data' => base64_encode( $data ), 'size' => strlen( $data ), 'total' => filesize( $p ), 'sha256' => $sha ];
	}
```

- [ ] **Step 3: REST — header into auth, Accept branch, Content-Type branch**

In `includes/class-ixes-rest.php` replace `auth()`, `file_get()` and the job route registration:

```php
	public static function auth( WP_REST_Request $req ) {
		if ( ! is_ssl() && ! ( defined( 'ENVSYNC_ALLOW_HTTP' ) && ENVSYNC_ALLOW_HTTP ) ) return new WP_Error( 'https', 'https required', [ 'status' => 403 ] );
		$hdr = $req->get_header( 'authorization' );
		if ( ! $hdr || stripos( $hdr, 'Bearer ' ) !== 0 ) return new WP_Error( 'auth', 'missing token', [ 'status' => 401 ] );
		$token = trim( substr( $hdr, 7 ) );
		$ok = IXES_Auth::verify(
			(string) get_option( 'ixes_token_hash' ), $token, $req->get_method(),
			$req->get_route(), (int) $req->get_header( 'x-envsync-ts' ),
			(string) $req->get_body(), (string) $req->get_header( 'x-envsync-sig' ),
			null, (string) $req->get_header( 'x-envsync-step' )
		);
		return $ok ? true : new WP_Error( 'auth', 'bad signature', [ 'status' => 401 ] );
	}

	private static function wants_binary( WP_REST_Request $req ) {
		return stripos( (string) $req->get_header( 'accept' ), 'application/octet-stream' ) !== false;
	}

	public static function file_get( WP_REST_Request $req ) {
		$p   = $req->get_json_params();
		$bin = self::wants_binary( $req );
		$r   = IXES_Transfer::file_chunk( $p['path'] ?? '', (int) ( $p['offset'] ?? 0 ), (int) ( $p['size'] ?? 2097152 ), $bin );
		if ( is_wp_error( $r ) || ! $bin ) return $r;
		// Raw bytes: bypass the JSON encoder entirely so a 4 MB chunk costs 4 MB, not 3x that.
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . $r['size'] );
		header( 'X-Envsync-Total: ' . $r['total'] );
		header( 'X-Envsync-Size: ' . $r['size'] );
		header( 'X-Envsync-Sha256: ' . $r['sha256'] );
		echo $r['bin']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file body, not HTML
		exit;
	}

	/** Step params: JSON body, or X-Envsync-Step header plus raw body when the hub sends octet-stream. */
	private static function step_params( WP_REST_Request $req ) {
		if ( stripos( (string) $req->get_header( 'content-type' ), 'application/octet-stream' ) === 0 ) {
			$p = json_decode( (string) $req->get_header( 'x-envsync-step' ), true );
			if ( ! is_array( $p ) ) return [];
			$p['bin'] = (string) $req->get_body();
			return $p;
		}
		$p = $req->get_json_params();
		return is_array( $p ) ? $p : [];
	}
```

and change the job route loop to use it:

```php
		foreach ( [ 'start', 'step', 'finish', 'abort' ] as $op ) {
			$r( '/job/' . $op, 'POST', function ( $req ) use ( $op ) { return self::applier( 'job_' . $op, self::step_params( $req ) ); } );
		}
```

`applier()` already does `is_array( $params ) ? $params : []`; keep it.

- [ ] **Step 4: `job_step()` file branch reads either shape**

In `includes/class-ixes-applier.php`, in the `if ( $kind === 'file' )` block, replace the `write_file_chunk` call:

```php
			$bytes = array_key_exists( 'bin', $p ) ? (string) $p['bin'] : base64_decode( (string) ( $p['data'] ?? '' ) );
			$r = IXES_Transfer::write_file_chunk( $rel, (int) ( $p['offset'] ?? 0 ), $bytes, ! empty( $p['final'] ), (string) ( $p['sha256'] ?? '' ) );
```

- [ ] **Step 5: Lint, tests, commit**

```bash
php -l includes/class-ixes-rest.php && php -l includes/class-ixes-transfer.php && php -l includes/class-ixes-applier.php
vendor/bin/phpcs && vendor/bin/phpunit
git add includes/class-ixes-rest.php includes/class-ixes-transfer.php includes/class-ixes-applier.php
git commit -m "feat(remote): octet-stream file get and step, caps in info"
```

---

### Task 5: Pull and push use the shared loops

**Files:**
- Modify: `includes/class-ixes-pull.php:102-119` (file transfer loop in `run()`)
- Modify: `includes/class-ixes-applier.php:290-307` (file push loop in `apply()`)

**Interfaces:**
- Consumes: `IXES_Client::fetch_file()`, `IXES_Client::send_file()` (Task 3).

- [ ] **Step 1: Pull**

In `includes/class-ixes-pull.php` `run()`, replace the `foreach ( $plan['files']['transfer'] as $i => $rel ) { … }` loop with:

```php
		foreach ( $plan['files']['transfer'] as $i => $rel ) {
			$log( "file " . ( $i + 1 ) . "/{$n} {$rel}" );
			$r = $c->fetch_file( $rel, function ( $offset, $data, $final, $sha ) use ( $rel ) {
				return IXES_Transfer::write_file_chunk( $rel, $offset, $data, $final, $sha );
			} );
			// A path this side refuses is a policy difference between the two plugin
			// versions, not a transfer failure: skip it rather than abort the pull.
			if ( is_wp_error( $r ) && $r->get_error_code() === 'bad_path' ) { $skipped[] = $rel; continue; }
			if ( is_wp_error( $r ) ) return $r;
		}
```

- [ ] **Step 2: Push**

In `includes/class-ixes-applier.php` `apply()`, replace the `foreach ( $plan['files']['push'] as $rel ) { … }` block (from `$abs = …` through `if ( ! $refused ) $log( "file {$rel}" );`) with:

```php
		foreach ( $plan['files']['push'] as $rel ) {
			$abs = WP_CONTENT_DIR . '/' . $rel;
			if ( ! is_file( $abs ) ) continue;
			$meta = array_key_exists( $rel, $file_hashes ) ? [ 'expect' => $file_hashes[ $rel ], 'algo' => $plan['algo'] ] : [];
			$r = $c->send_file( $job, $rel, $abs, $meta );
			if ( is_wp_error( $r ) ) return $fail( $r );
			if ( $r['refused'] ) { $stale[] = "file: {$rel}"; continue; }
			$log( "file {$rel}" );
		}
```

- [ ] **Step 3: Lint, tests, commit**

```bash
vendor/bin/phpcs && vendor/bin/phpunit
git add includes/class-ixes-pull.php includes/class-ixes-applier.php
git commit -m "feat: pull and push transfer files through the adaptive client loops"
```

---

### Task 6: Storage groundwork for resume — pull plans on disk, REPLACE imports, baseline at commit

**Files:**
- Modify: `includes/class-ixes-planner.php:108-113` (`save()`)
- Modify: `includes/class-ixes-transfer.php` (`import_rows()`, new `tmp_exists()`)
- Modify: `includes/class-ixes-baseline.php` (`reset()`, new `delete_table()`, new `commit()`)
- Test: `tests/BaselineTest.php`

**Interfaces:**
- Produces:
  - `IXES_Planner::save( array $plan, $kind = 'diff' )` → path; pull plans are named `plan-pull-<env>-<ts>.json`.
  - `IXES_Transfer::import_rows( $table, array $rows, array $pairs, $replace = false )` — `REPLACE INTO` when true.
  - `IXES_Transfer::tmp_exists( $table )` → bool.
  - `IXES_Baseline::reset()` no longer sets `created_at`; `IXES_Baseline::commit()` sets `created_at = time()`; `IXES_Baseline::delete_table( $table )` removes that table's rows.

- [ ] **Step 1: Failing baseline tests**

Append to `tests/BaselineTest.php` (look at its existing setUp for how a temp path is made; reuse it):

```php
	public function test_reset_does_not_mark_created_until_commit() {
		$bl = new IXES_Baseline( $this->path );
		$bl->reset();
		$this->assertFalse( $bl->exists(), 'reset alone must not claim a fresh baseline' );
		$bl->commit();
		$this->assertTrue( $bl->exists() );
	}
	public function test_delete_table_removes_only_that_table() {
		$bl = new IXES_Baseline( $this->path );
		$bl->write_rows( 'wp_posts', [ 1 => 'a' ] ); $bl->write_rows( 'wp_terms', [ 1 => 'b' ] );
		$bl->delete_table( 'wp_posts' );
		$this->assertSame( [], $bl->rows( 'wp_posts' ) );
		$this->assertSame( [ 1 => 'b' ], $bl->rows( 'wp_terms' ) );
	}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter BaselineTest`
Expected: `commit`/`delete_table` undefined; first assertion fails because `reset()` sets `created_at`.

- [ ] **Step 3: Baseline changes**

In `includes/class-ixes-baseline.php`, replace `reset()` and add two methods:

```php
	public function reset() {
		if ( $this->pdo ) {
			$this->pdo->exec( 'DELETE FROM rows; DELETE FROM files; DELETE FROM meta;' );
		} else {
			$this->json = [ 'rows' => [], 'files' => [], 'meta' => [] ];
			$this->save_json();
		}
	}

	/** Marks the baseline as complete. Called once the pull has committed tables and finished files. */
	public function commit() { $this->meta( 'created_at', time() ); }

	public function delete_table( $table ) {
		if ( $this->pdo ) {
			$st = $this->pdo->prepare( 'DELETE FROM rows WHERE tbl = ?' ); $st->execute( [ $table ] );
		} else {
			unset( $this->json['rows'][ $table ] ); $this->save_json();
		}
	}
```

- [ ] **Step 4: Transfer changes**

In `includes/class-ixes-transfer.php`:

Change the `import_rows` signature and the INSERT keyword:

```php
	public static function import_rows( $table, array $rows, array $pairs, $replace = false ) {
```
and
```php
			$sql = ( $replace ? 'REPLACE' : 'INSERT' ) . " INTO `{$tmp}` (`" . implode( '`,`', $cols ) . "`) VALUES " . implode( ',', $vals );
```

Add after `tmp_name()`:

```php
	public static function tmp_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::tmp_name( $table ) ) );
	}
```

- [ ] **Step 5: Planner save for pull plans**

Replace `save()` in `includes/class-ixes-planner.php`:

```php
	public static function save( array $plan, $kind = 'diff' ) {
		$dir = ixes_storage_dir() . '/plans'; wp_mkdir_p( $dir );
		$stamp = date( 'Ymd-His', $plan['created'] ?? time() );
		$path  = $dir . '/plan-' . ( $kind === 'pull' ? 'pull-' : '' ) . $plan['env'] . '-' . $stamp . '.json';
		file_put_contents( $path, json_encode( $plan ) );
		return $path;
	}
```

And in `IXES_Pull::plan()` add `'created' => time(),` to the returned array (first key).

Note for the admin page: `admin/class-ixes-admin.php` globs `plans/plan-*.json` for "Last plan"; pull plans now match that glob. Change the glob there to `plans/plan-[a-z]*-*.json` is wrong (env names can start with any letter). Instead exclude by prefix: replace the glob line with

```php
		$plans = array_filter( (array) glob( ixes_storage_dir() . '/plans/plan-*.json' ), function ( $f ) { return strpos( basename( $f ), 'plan-pull-' ) !== 0; } );
```

- [ ] **Step 6: Callers of `reset()` must now commit**

In `includes/class-ixes-pull.php` `run()`, after `IXES_Transfer::after_import( … ); IXES_Transfer::offset_auto_increment();` and before `$log( 'done' );` add:

```php
		$bl->commit();
```

(Task 7 restructures `run()`; keep the call there too.)

- [ ] **Step 7: Run everything, commit**

```bash
vendor/bin/phpcs && vendor/bin/phpunit
git add includes/class-ixes-baseline.php includes/class-ixes-transfer.php includes/class-ixes-planner.php includes/class-ixes-pull.php admin/class-ixes-admin.php tests/BaselineTest.php
git commit -m "feat: pull plans on disk, REPLACE imports, baseline marked complete at commit"
```

---

### Task 7: Resumable pull

**Files:**
- Create: `includes/class-ixes-pullstate.php`
- Modify: `includes/class-ixes-pull.php` (`run()` restructure)
- Modify: `includes/class-ixes-cli.php` (`pull()`: `--fresh`, resume prompt)
- Test: `tests/PullStateTest.php`

**Interfaces:**
- Consumes: Task 6 (`save( $plan, 'pull' )`, `import_rows( …, true )`, `tmp_exists()`, `commit()`), Task 5 (`fetch_file`).
- Produces:
  - `IXES_PullState::path( $env_name )` → `<storage>/pull-<env>.json`
  - `IXES_PullState::load( $env_name )` → instance or null
  - `IXES_PullState::start( $env_name, $plan_path, $remote_plugin, array $scope )` → new instance, saved
  - `$s->table_done( $name )`, `$s->cursor( $name, $next )`, `$s->files_done( $n )` — each saves
  - `$s->get( $key )` — `env|plan|started|remote_plugin|scope|tables_done|table|cursor|files_done`
  - `$s->describe()` → the human sentence for the prompt
  - `$s->refusal( $remote_plugin, array $plan_excludes, array $env_excludes, array $plan_extra, array $env_extra, $tmp_exists )` → `''` when resumable, else the reason
  - `$s->clear()` — deletes the file
  - `IXES_Pull::discard( array $env )` — drops state, tmp tables and the saved pull plan (for `--fresh`)

- [ ] **Step 1: Failing tests**

Create `tests/PullStateTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class PullStateTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-ps-' . getmypid() . '-' . uniqid();
		mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
	}
	public function test_no_state_loads_null() { $this->assertNull( IXES_PullState::load( 'prod' ) ); }

	public function test_start_then_progress_round_trips() {
		$s = IXES_PullState::start( 'prod', '/plans/p.json', '0.3.0', [] );
		$s->cursor( 'wp_posts', null );
		$s->cursor( 'wp_posts', '500' );
		$s->table_done( 'wp_posts' );
		$s->cursor( 'wp_postmeta', '184233' );
		$s->files_done( 512 );
		$r = IXES_PullState::load( 'prod' );
		$this->assertSame( [ 'wp_posts' ], $r->get( 'tables_done' ) );
		$this->assertSame( 'wp_postmeta', $r->get( 'table' ) );
		$this->assertSame( '184233', $r->get( 'cursor' ) );
		$this->assertSame( 512, $r->get( 'files_done' ) );
		$this->assertSame( '/plans/p.json', $r->get( 'plan' ) );
	}
	public function test_table_done_clears_cursor() {
		$s = IXES_PullState::start( 'prod', 'p', '0.3.0', [] );
		$s->cursor( 'wp_posts', '9' ); $s->table_done( 'wp_posts' );
		$this->assertNull( $s->get( 'table' ) ); $this->assertNull( $s->get( 'cursor' ) );
	}
	public function test_describe_mentions_table_row_and_files() {
		$s = IXES_PullState::start( 'prod', 'p', '0.3.0', [] );
		$s->cursor( 'wp_postmeta', '184233' ); $s->files_done( 512 );
		$d = $s->describe( 2300 );
		$this->assertStringContainsString( 'prod', $d );
		$this->assertStringContainsString( 'wp_postmeta', $d );
		$this->assertStringContainsString( '184233', $d );
		$this->assertStringContainsString( '512/2300', $d );
	}
	public function test_refusal_reasons() {
		$s = IXES_PullState::start( 'prod', 'p', '0.3.0', [] );
		$s->cursor( 'wp_posts', '1' );
		$this->assertSame( '', $s->refusal( '0.3.0', [ 'a/' ], [ 'a/' ], [], [], true ) );
		$this->assertStringContainsString( 'version', $s->refusal( '0.3.1', [ 'a/' ], [ 'a/' ], [], [], true ) );
		$this->assertStringContainsString( 'excludes', $s->refusal( '0.3.0', [ 'a/' ], [ 'b/' ], [], [], true ) );
		$this->assertStringContainsString( 'replace', $s->refusal( '0.3.0', [], [], [ [ 'x', 'y' ] ], [], true ) );
		$this->assertStringContainsString( 'wp_posts', $s->refusal( '0.3.0', [], [], [], [], false ) );
	}
	public function test_clear_removes_file() {
		$s = IXES_PullState::start( 'prod', 'p', '0.3.0', [] );
		$s->clear();
		$this->assertNull( IXES_PullState::load( 'prod' ) );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter PullStateTest`
Expected: class not found.

- [ ] **Step 3: Implement `IXES_PullState`**

Create `includes/class-ixes-pullstate.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resume file for an interrupted pull: <storage>/pull-<env>.json.
 * Written after every unit of work that is safe to skip on rerun (a page inserted AND its baseline written,
 * a whole file landed). Pure apart from file I/O in the storage dir.
 */
class IXES_PullState {
	private $d;

	private function __construct( array $d ) { $this->d = $d; }

	public static function path( $env_name ) { return ixes_storage_dir() . '/pull-' . $env_name . '.json'; }

	public static function load( $env_name ) {
		$f = self::path( $env_name );
		if ( ! is_file( $f ) ) return null;
		$d = json_decode( (string) file_get_contents( $f ), true );
		return is_array( $d ) && isset( $d['env'] ) ? new self( $d ) : null;
	}

	public static function start( $env_name, $plan_path, $remote_plugin, array $scope ) {
		$s = new self( [
			'env' => $env_name, 'plan' => $plan_path, 'started' => time(), 'remote_plugin' => (string) $remote_plugin,
			'scope' => $scope, 'tables_done' => [], 'table' => null, 'cursor' => null, 'files_done' => 0,
		] );
		$s->save();
		return $s;
	}

	private function save() { file_put_contents( self::path( $this->d['env'] ), json_encode( $this->d ) ); }

	public function get( $k ) { return $this->d[ $k ] ?? null; }

	public function cursor( $table, $next ) { $this->d['table'] = $table; $this->d['cursor'] = $next; $this->save(); }
	public function table_done( $table ) {
		if ( ! in_array( $table, $this->d['tables_done'], true ) ) $this->d['tables_done'][] = $table;
		$this->d['table'] = null; $this->d['cursor'] = null; $this->save();
	}
	public function files_done( $n ) { $this->d['files_done'] = (int) $n; $this->save(); }
	public function clear() { $f = self::path( $this->d['env'] ); if ( is_file( $f ) ) unlink( $f ); }

	public function describe( $files_total ) {
		$where = $this->d['table']
			? "stopped in {$this->d['table']}" . ( $this->d['cursor'] !== null ? " at row {$this->d['cursor']}" : '' )
			: 'finished tables';
		return sprintf( 'An interrupted pull of %s from %s %s, %d/%d files done.', $this->d['env'], date( 'Y-m-d H:i', (int) $this->d['started'] ), $where, (int) $this->d['files_done'], (int) $files_total );
	}

	/** '' when the pull can be resumed, otherwise a one-line reason. */
	public function refusal( $remote_plugin, array $plan_excludes, array $env_excludes, array $plan_extra, array $env_extra, $tmp_exists ) {
		if ( (string) $remote_plugin !== $this->d['remote_plugin'] ) return "remote plugin version changed ({$this->d['remote_plugin']} → {$remote_plugin})";
		if ( array_values( $plan_excludes ) !== array_values( $env_excludes ) ) return 'env excludes changed since the pull started';
		if ( array_values( $plan_extra ) !== array_values( $env_extra ) ) return 'env replace pairs changed since the pull started';
		if ( $this->d['table'] && ! $tmp_exists ) return "tmp table for {$this->d['table']} is gone";
		return '';
	}
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter PullStateTest`
Expected: 6 tests pass.

- [ ] **Step 5: Restructure `IXES_Pull::run()` around the state**

Replace `run()` in `includes/class-ixes-pull.php` with the version below, and add `discard()`. `$plan['tables']` is the `/info` table list; `$plan['files']['transfer']` is already sorted by the remote manifest order (keep `sort( $transfer, SORT_STRING )` in `plan()` to make the index stable — add that line after `$transfer = …`).

```php
	/** Drop everything an interrupted pull left behind. */
	public static function discard( array $env ) {
		$s = IXES_PullState::load( $env['name'] );
		if ( ! $s ) return;
		$plan = is_file( (string) $s->get( 'plan' ) ) ? json_decode( file_get_contents( $s->get( 'plan' ) ), true ) : null;
		if ( is_array( $plan ) ) IXES_Transfer::drop_tmp_tables( array_column( $plan['tables'], 'name' ) );
		if ( is_file( (string) $s->get( 'plan' ) ) ) unlink( $s->get( 'plan' ) );
		$s->clear();
	}

	/**
	 * @param IXES_PullState|null $state  null = fresh pull; an instance = resume from it (plan must be the saved one)
	 */
	public static function run( array $env, IXES_Client $c, array $plan, callable $log, $state = null ) {
		global $wpdb;
		$pairs = $plan['pairs'];
		$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $env['name'] . '.sqlite' );
		if ( $state === null ) {
			$bl->reset();
			$path  = IXES_Planner::save( $plan, 'pull' );
			$state = IXES_PullState::start( $env['name'], $path, (string) ( $plan['info']['plugin'] ?? '' ), (array) ( $plan['scope'] ?? [] ) );
		}
		$bl->meta( 'algo', $plan['algo'] ); $bl->meta( 'source_url', $plan['info']['url'] );
		list( $extra_prod ) = IXES_Env::extras( $env );
		$hash_pairs = IXES_Hasher::placeholders( $plan['info']['url'], $plan['info']['abspath'], $extra_prod );
		$done    = (array) $state->get( 'tables_done' );
		$resume  = $state->get( 'table' );

		foreach ( $plan['tables'] as $t ) {
			$name = $t['name'];
			if ( in_array( $name, $done, true ) ) continue;
			$from = null;
			if ( $resume === $name ) { $from = $state->get( 'cursor' ); $log( "table {$name} (resuming at " . ( $from === null ? 'start' : $from ) . ")" ); }
			else {
				$log( "table {$name} ({$t['rows']} rows)" );
				$b = IXES_Transfer::import_begin( $name );
				if ( is_wp_error( $b ) ) { $log( 'skip: ' . $b->get_error_message() ); continue; }
				$bl->delete_table( $name );
			}
			$pk = $t['pk'];
			$row_err = null;
			$r = $c->paged( '/dump', [ 'table' => $name, 'limit' => 5000, 'from' => $from ], function ( $res ) use ( $name, $pairs, $hash_pairs, $bl, $pk, $plan, $state, &$row_err ) {
				if ( $row_err ) return;
				$ins = IXES_Transfer::import_rows( $name, $res['rows'], $pairs, (bool) $pk );
				if ( is_wp_error( $ins ) ) { $row_err = $ins; return; }
				$map = [];
				foreach ( $res['rows'] as $row ) {
					$h = IXES_Hasher::hash_row( $row, $hash_pairs, $plan['algo'] );
					if ( $pk ) $map[ $row[ $pk ] ] = $h; else $map[ $h ] = $h;
				}
				$bl->write_rows( $name, $map );
				$state->cursor( $name, $res['next'] ?? null ); // this page is now safe to skip on rerun
			} );
			if ( is_wp_error( $r ) ) return $r;
			if ( $row_err ) return $row_err;
			$state->table_done( $name );
			$done[] = $name;
		}
		if ( ! $done ) return new WP_Error( 'nothing_imported', 'no tables were imported' );
		IXES_Transfer::preserve_local_options( $done );
		$commit = IXES_Transfer::import_commit( $done );
		if ( is_wp_error( $commit ) ) { IXES_Transfer::drop_tmp_tables( $done ); return $commit; }
		wp_cache_flush();
		$bl->meta( 'opt_active_plugins', json_encode( get_option( 'active_plugins', [] ) ) );

		$n = count( $plan['files']['transfer'] );
		$skipped = [];
		$start = (int) $state->get( 'files_done' );
		foreach ( $plan['files']['transfer'] as $i => $rel ) {
			if ( $i < $start ) continue;
			$log( "file " . ( $i + 1 ) . "/{$n} {$rel}" );
			$r = $c->fetch_file( $rel, function ( $offset, $data, $final, $sha ) use ( $rel ) {
				return IXES_Transfer::write_file_chunk( $rel, $offset, $data, $final, $sha );
			} );
			if ( is_wp_error( $r ) && $r->get_error_code() === 'bad_path' ) { $skipped[] = $rel; $state->files_done( $i + 1 ); continue; }
			if ( is_wp_error( $r ) ) return $r;
			$state->files_done( $i + 1 );
		}
		if ( $skipped ) $log( 'skipped ' . count( $skipped ) . ' excluded path(s) offered by the remote, e.g. ' . $skipped[0] );
		$undeleted = 0;
		foreach ( $plan['files']['delete'] as $rel ) if ( ! IXES_Transfer::delete_file( $rel ) ) $undeleted++;
		if ( $undeleted ) $log( "warning: {$undeleted} stale file(s) could not be deleted (check ownership under wp-content)" );
		$bl->write_files( $plan['files']['remote'] );
		$state->clear();

		IXES_Transfer::after_import( IXES_Env::local_url(), IXES_Env::local_abspath() );
		IXES_Transfer::offset_auto_increment();
		$bl->commit();
		$log( 'done' );
		return true;
	}
```

Two things this relies on that are true today: `import_commit()` renames all tmp tables in one statement, so a crash before it leaves every tmp table in place; `paged()` starts from `$body['from']` when given (it sets `$body[$cursor_key] = $next` with `$next = null` on the first loop — change the initialisation to `$next = $body[ $cursor_key ] ?? null;` in `IXES_Client::paged()` so a supplied starting cursor is honoured).

Error-path note: a `WP_Error` return from a resumed run leaves the tmp tables and the state in place on purpose (that is what makes the next rerun resumable). The previous `drop_tmp_tables` on error is intentionally gone; `--fresh` is the cleanup.

- [ ] **Step 6: CLI**

In `includes/class-ixes-cli.php`, `pull()`: add the `--fresh` option to the docblock:

```
	 * [--fresh]
	 * : Discard an interrupted pull and start over.
```

Replace the body of `pull()`:

```php
	public function pull( $args, $assoc ) {
		if ( ! empty( $assoc['flush-cache'] ) ) IXES_Hashcache::flush();
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		if ( ! empty( $assoc['fresh'] ) ) IXES_Pull::discard( $env );
		$state = IXES_PullState::load( $env['name'] );
		if ( $state ) {
			$plan = is_file( (string) $state->get( 'plan' ) ) ? json_decode( file_get_contents( $state->get( 'plan' ) ), true ) : null;
			$info = $this->fail_if_error( $c->info() );
			$why  = ! is_array( $plan ) ? 'saved plan file is missing' : $state->refusal(
				(string) ( $info['plugin'] ?? '' ), (array) $plan['excludes'], IXES_Pull::excludes( $env ),
				(array) ( $plan['extra_replace'] ?? [] ), (array) $env['extra_replace'],
				$state->get( 'table' ) ? IXES_Transfer::tmp_exists( $state->get( 'table' ) ) : true
			);
			if ( $why ) WP_CLI::error( "cannot resume: {$why}. Run again with --fresh to start over." );
			WP_CLI::log( $state->describe( count( $plan['files']['transfer'] ) ) );
			if ( ! empty( $assoc['dry-run'] ) ) return;
			$this->confirm( $assoc, 'Resume?' );
			$this->fail_if_error( IXES_Pull::run( $env, $c, $plan, $this->logger(), $state ) );
			WP_CLI::success( "pulled {$env['name']}; baseline recorded" );
			return;
		}
		$plan = $this->fail_if_error( IXES_Pull::plan( $env, $c ) );
		// ... existing plan printing (rows, files, rewrite, excludes, --details) unchanged ...
		if ( ! empty( $assoc['dry-run'] ) ) return;
		$this->confirm( $assoc, 'This OVERWRITES the local database and wp-content. Continue?' );
		$this->fail_if_error( IXES_Pull::run( $env, $c, $plan, $this->logger() ) );
		WP_CLI::success( "pulled {$env['name']}; baseline recorded" );
	}
```

Keep the existing plan-printing block verbatim where the comment says so. For the refusal check to work, `IXES_Pull::plan()` must record `'extra_replace' => (array) $env['extra_replace']` in the plan array (add it next to `'excludes' => $ex`).

- [ ] **Step 7: Lint, tests, commit**

```bash
vendor/bin/phpcs && vendor/bin/phpunit
git add includes/class-ixes-pullstate.php includes/class-ixes-pull.php includes/class-ixes-client.php includes/class-ixes-cli.php tests/PullStateTest.php
git commit -m "feat: resumable pull with state file, --fresh to discard"
```

---

### Task 8: `IXES_Scope`

**Files:**
- Create: `includes/class-ixes-scope.php`
- Test: `tests/ScopeTest.php`

**Interfaces:**
- Produces:
  - `IXES_Scope::from_assoc( array $assoc, $prefix )` — reads `only`, `tables`, `paths`; throws `InvalidArgumentException` on an unknown `--only` value. `$prefix` is `$wpdb->prefix` (passed in so the class stays pure).
  - `IXES_Scope::from_array( array $a, $prefix )`, `$s->to_array()` → `[ 'only' => [], 'tables' => [], 'paths' => [] ]`
  - `$s->is_full()`, `$s->db_wanted()`, `$s->files_wanted()`, `$s->table_in( $name )`, `$s->path_in( $rel )`, `$s->label()`
  - `$s->family_warnings( array $selected_tables )` → list of strings
  - `IXES_Scope::ONLY` = `[ 'db', 'files', 'uploads', 'themes', 'plugins', 'mu-plugins' ]`

- [ ] **Step 1: Failing tests**

Create `tests/ScopeTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase {
	private function s( array $assoc ) { return IXES_Scope::from_assoc( $assoc, 'wp_' ); }

	public function test_no_flags_is_full() {
		$s = $this->s( [] );
		$this->assertTrue( $s->is_full() ); $this->assertTrue( $s->db_wanted() ); $this->assertTrue( $s->files_wanted() );
		$this->assertTrue( $s->table_in( 'wp_anything' ) ); $this->assertTrue( $s->path_in( 'uploads/x.jpg' ) );
		$this->assertSame( 'everything', $s->label() );
	}
	public function test_only_db_disables_files() {
		$s = $this->s( [ 'only' => 'db' ] );
		$this->assertTrue( $s->db_wanted() ); $this->assertFalse( $s->files_wanted() );
		$this->assertFalse( $s->path_in( 'themes/a/style.css' ) );
	}
	public function test_only_themes_and_uploads() {
		$s = $this->s( [ 'only' => 'themes,uploads' ] );
		$this->assertFalse( $s->db_wanted() );
		$this->assertTrue( $s->path_in( 'themes/a/style.css' ) );
		$this->assertTrue( $s->path_in( 'uploads/2026/a.jpg' ) );
		$this->assertFalse( $s->path_in( 'plugins/x/x.php' ) );
		$this->assertFalse( $s->path_in( 'index.php' ) );
	}
	public function test_only_files_means_all_of_wp_content() {
		$s = $this->s( [ 'only' => 'files' ] );
		$this->assertTrue( $s->path_in( 'index.php' ) ); $this->assertTrue( $s->path_in( 'plugins/x/x.php' ) );
	}
	public function test_tables_implies_only_db_and_matches_with_or_without_prefix() {
		$s = $this->s( [ 'tables' => 'posts,wp_postmeta,wc_*' ] );
		$this->assertFalse( $s->files_wanted() );
		$this->assertTrue( $s->table_in( 'wp_posts' ) ); $this->assertTrue( $s->table_in( 'wp_postmeta' ) );
		$this->assertTrue( $s->table_in( 'wp_wc_orders' ) ); $this->assertFalse( $s->table_in( 'wp_options' ) );
	}
	public function test_paths_implies_only_files_prefix_and_glob() {
		$s = $this->s( [ 'paths' => 'themes/mk/,uploads/2026/*' ] );
		$this->assertFalse( $s->db_wanted() );
		$this->assertTrue( $s->path_in( 'themes/mk/style.css' ) ); $this->assertTrue( $s->path_in( 'themes/mk/inc/a.php' ) );
		$this->assertFalse( $s->path_in( 'themes/mkx/style.css' ) );
		$this->assertTrue( $s->path_in( 'uploads/2026/09/a.jpg' ) ); $this->assertFalse( $s->path_in( 'uploads/2025/a.jpg' ) );
	}
	public function test_only_narrows_paths_and_tables() {
		$s = $this->s( [ 'only' => 'themes', 'paths' => 'uploads/*' ] );
		$this->assertFalse( $s->path_in( 'uploads/a.jpg' ), 'paths cannot widen beyond --only' );
		$this->assertFalse( $s->path_in( 'themes/a/x' ), 'and themes are narrowed by --paths' );
	}
	public function test_unknown_only_throws() {
		$this->expectException( InvalidArgumentException::class );
		$this->s( [ 'only' => 'media' ] );
	}
	public function test_label_and_round_trip() {
		$s = $this->s( [ 'only' => 'themes', 'paths' => 'themes/mk/' ] );
		$this->assertSame( 'themes, paths themes/mk/', $s->label() );
		$r = IXES_Scope::from_array( $s->to_array(), 'wp_' );
		$this->assertSame( $s->to_array(), $r->to_array() );
		$this->assertSame( 'tables wp_posts', $this->s( [ 'tables' => 'wp_posts' ] )->label() );
	}
	public function test_family_warning() {
		$s = $this->s( [ 'tables' => 'posts' ] );
		$w = $s->family_warnings( [ 'wp_posts' ] );
		$this->assertCount( 1, $w );
		$this->assertStringContainsString( 'wp_posts selected without wp_postmeta', $w[0] );
		$this->assertSame( [], $this->s( [ 'tables' => 'posts,postmeta' ] )->family_warnings( [ 'wp_posts', 'wp_postmeta' ] ) );
		$this->assertSame( [], $this->s( [] )->family_warnings( [ 'wp_posts' ] ), 'no warning without --tables' );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter ScopeTest`
Expected: class not found.

- [ ] **Step 3: Implement**

Create `includes/class-ixes-scope.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * What a pull/diff/push touches. Built from --only / --tables / --paths. Pure.
 * --only narrows categories; --tables narrows inside db; --paths narrows inside files. Omitted = everything.
 */
class IXES_Scope {
	const ONLY = [ 'db', 'files', 'uploads', 'themes', 'plugins', 'mu-plugins' ];
	const FOLDER = [ 'uploads' => 'uploads/', 'themes' => 'themes/', 'plugins' => 'plugins/', 'mu-plugins' => 'mu-plugins/' ];
	const FAMILIES = [
		[ 'posts', 'postmeta' ],
		[ 'terms', 'term_taxonomy', 'term_relationships', 'termmeta' ],
		[ 'users', 'usermeta' ],
		[ 'comments', 'commentmeta' ],
	];

	private $only; private $tables; private $paths; private $prefix;

	private function __construct( array $only, array $tables, array $paths, $prefix ) {
		$this->only = $only; $this->tables = $tables; $this->paths = $paths; $this->prefix = (string) $prefix;
	}

	private static function list( $v ) { return array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ) ) ); }

	public static function from_assoc( array $assoc, $prefix ) {
		$only   = self::list( $assoc['only'] ?? '' );
		$tables = self::list( $assoc['tables'] ?? '' );
		$paths  = self::list( $assoc['paths'] ?? '' );
		foreach ( $only as $o ) if ( ! in_array( $o, self::ONLY, true ) ) throw new InvalidArgumentException( "--only accepts " . implode( '|', self::ONLY ) . ", got '{$o}'" );
		if ( ! $only && $tables ) $only = [ 'db' ];
		if ( ! $only && $paths )  $only = [ 'files' ];
		return new self( $only, $tables, $paths, $prefix );
	}

	public static function from_array( array $a, $prefix ) {
		return new self( (array) ( $a['only'] ?? [] ), (array) ( $a['tables'] ?? [] ), (array) ( $a['paths'] ?? [] ), $prefix );
	}
	public function to_array() { return [ 'only' => $this->only, 'tables' => $this->tables, 'paths' => $this->paths ]; }

	public function is_full() { return ! $this->only && ! $this->tables && ! $this->paths; }
	public function db_wanted() { return ! $this->only || in_array( 'db', $this->only, true ); }
	public function files_wanted() {
		if ( ! $this->only ) return true;
		foreach ( $this->only as $o ) if ( $o !== 'db' ) return true;
		return false;
	}

	public function table_in( $name ) {
		if ( ! $this->db_wanted() ) return false;
		if ( ! $this->tables ) return true;
		$bare = strpos( $name, $this->prefix ) === 0 ? substr( $name, strlen( $this->prefix ) ) : $name;
		foreach ( $this->tables as $pat ) {
			if ( fnmatch( $pat, $name ) || fnmatch( $pat, $bare ) ) return true;
		}
		return false;
	}

	public function path_in( $rel ) {
		if ( ! $this->files_wanted() ) return false;
		if ( $this->only && ! in_array( 'files', $this->only, true ) ) {
			$hit = false;
			foreach ( $this->only as $o ) if ( isset( self::FOLDER[ $o ] ) && strpos( $rel, self::FOLDER[ $o ] ) === 0 ) { $hit = true; break; }
			if ( ! $hit ) return false;
		}
		if ( ! $this->paths ) return true;
		foreach ( $this->paths as $pat ) {
			$pat = ltrim( $pat, '/' );
			if ( substr( $pat, -1 ) === '/' ) { if ( strpos( $rel, $pat ) === 0 ) return true; }
			elseif ( fnmatch( $pat, $rel ) ) return true;
		}
		return false;
	}

	public function label() {
		if ( $this->is_full() ) return 'everything';
		$parts = [];
		if ( $this->only )   $parts[] = implode( ',', $this->only );
		if ( $this->tables ) $parts[] = 'tables ' . implode( ',', $this->tables );
		if ( $this->paths )  $parts[] = 'paths ' . implode( ',', $this->paths );
		return implode( ', ', $parts );
	}

	/** One line per split family, e.g. "wp_posts selected without wp_postmeta; pull the full db before the next push". */
	public function family_warnings( array $selected ) {
		if ( ! $this->tables ) return [];
		$out = [];
		foreach ( self::FAMILIES as $fam ) {
			$in = []; $missing = [];
			foreach ( $fam as $t ) { $full = $this->prefix . $t; if ( in_array( $full, $selected, true ) ) $in[] = $full; else $missing[] = $full; }
			if ( $in && $missing ) $out[] = implode( ',', $in ) . ' selected without ' . implode( ',', $missing ) . '; pull the full db before the next push';
		}
		return $out;
	}
}
```

Note `fnmatch()` requires the `*` glob to match `/` too: PHP's `fnmatch` without `FNM_PATHNAME` lets `*` cross slashes, which is what the `uploads/2026/*` test expects.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter ScopeTest`
Expected: 10 tests pass.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ixes-scope.php tests/ScopeTest.php
git commit -m "feat: IXES_Scope for --only/--tables/--paths"
```

---

### Task 9: Scope applied to pull, diff and push

**Files:**
- Modify: `includes/class-ixes-pull.php` (`plan()`, `run()`)
- Modify: `includes/class-ixes-planner.php` (`build()`, `render_text()`)
- Modify: `includes/class-ixes-cli.php` (`pull()`, `diff()`, `push()`, `env list`)
- Modify: `includes/class-ixes-applier.php` (no change to `apply()` needed: it consumes the already-filtered plan)
- Modify: `includes/class-ixes-baseline.php` (partial meta helpers)
- Modify: `admin/class-ixes-admin.php` (baseline column)
- Test: `tests/PlannerRenderTest.php` (scope line)

**Interfaces:**
- Consumes: `IXES_Scope` (Task 8), `IXES_PullState` (Task 7).
- Produces:
  - `IXES_Pull::plan( array $env, IXES_Client $c, IXES_Scope $scope = null )` — plan gains `'scope' => $scope->to_array()`.
  - `IXES_Planner::build( array $env, IXES_Client $c, IXES_Scope $scope = null )` — plan gains `'scope'`.
  - `IXES_Baseline::baseline_label()` → `'2026-09-12'`, `'2026-09-12 · partial 2026-09-22 (themes)'` or `'-'`.

- [ ] **Step 1: Failing render test**

Append to `tests/PlannerRenderTest.php` (see its existing plan fixture builder and reuse it):

```php
	public function test_render_shows_scope_when_not_full() {
		$plan = $this->plan();
		$plan['scope'] = [ 'only' => [ 'themes' ], 'tables' => [], 'paths' => [ 'themes/mk/' ] ];
		$this->assertStringContainsString( 'scope: themes, paths themes/mk/', IXES_Planner::render_text( $plan ) );
		unset( $plan['scope'] );
		$this->assertStringNotContainsString( 'scope:', IXES_Planner::render_text( $plan ) );
	}
```

If the file has no `plan()` fixture helper, build the smallest plan array that `render_text()` accepts (`env`, `baseline_at`, `two_way`, `tables` = `[]`, `files` with the four keys as `[]`, `active_plugins` null, `conflict_detail` `[]`).

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter PlannerRenderTest`
Expected: the scope assertion fails.

- [ ] **Step 3: Planner**

In `includes/class-ixes-planner.php`:

`build()` signature: `public static function build( array $env, IXES_Client $c, IXES_Scope $scope = null ) {` and right after `global $wpdb;`:

```php
		if ( $scope === null ) $scope = IXES_Scope::from_array( [], $wpdb->prefix );
```

Add `'scope' => $scope->to_array(),` to the `$plan = [ … ]` array.

In the table loop, first line inside `foreach ( $info['tables'] as $t ) {`:

```php
			if ( ! $scope->table_in( $t['name'] ) ) continue;
```

The `active_plugins` merge is inside `if ( $name === $wpdb->options )`, which is only reached when options are in scope, so nothing to add there.

Files: wrap the file section:

```php
		$plan['files'] = [ 'push' => [], 'delete' => [], 'conflict' => [], 'kept' => [] ];
		$plan['remote_file_hashes'] = [];
		if ( $scope->files_wanted() ) {
			$remote_files = [];
			$r = $c->paged( '/hash/files', [ 'excludes' => $ex, 'algo' => $algo, 'limit' => 2000 ], function ( $res ) use ( &$remote_files ) { $remote_files += $res['files']; }, 'cursor' );
			if ( is_wp_error( $r ) ) return $r;
			$remote_files = self::in_scope( IXES_Pull::drop_excluded( $remote_files, $ex ), $scope );
			$local_files  = self::in_scope( IXES_Transfer::local_manifest( $ex, $algo ), $scope );
			$base_files   = $two_way ? [] : self::in_scope( $bl->files(), $scope );
			$fd = IXES_Differ::diff( $base_files, $local_files, $remote_files );
			$plan['files'] = [ 'push' => array_merge( $fd['push'], $fd['insert'] ), 'delete' => $fd['delete'], 'conflict' => $fd['conflict'], 'kept' => $fd['kept'] ];
			foreach ( array_merge( $plan['files']['push'], $plan['files']['delete'] ) as $rel ) $plan['remote_file_hashes'][ $rel ] = $remote_files[ $rel ] ?? null;
		}
		return $plan;
```

Add the helper:

```php
	private static function in_scope( array $manifest, IXES_Scope $scope ) {
		foreach ( array_keys( $manifest ) as $rel ) if ( ! $scope->path_in( $rel ) ) unset( $manifest[ $rel ] );
		return $manifest;
	}
```

`render_text()`: after the first `$o[] = sprintf( '%s  ←  local …' )` line add:

```php
		if ( ! empty( $plan['scope'] ) ) {
			$sc = IXES_Scope::from_array( (array) $plan['scope'], '' );
			if ( ! $sc->is_full() ) $o[] = '  scope: ' . $sc->label();
		}
```

(prefix `''` is fine for `label()`; it does not match tables.)

- [ ] **Step 4: Pull plan and run**

In `includes/class-ixes-pull.php`:

`plan()` signature: `public static function plan( array $env, IXES_Client $c, IXES_Scope $scope = null ) {`; after `global $wpdb;`: `if ( $scope === null ) $scope = IXES_Scope::from_array( [], $wpdb->prefix );`.

Tables: replace `'tables' => $info['tables'],` with

```php
			'tables' => array_values( array_filter( $info['tables'], function ( $t ) use ( $scope ) { return $scope->table_in( $t['name'] ); } ) ),
```

Files: wrap the manifest fetch and diff:

```php
		$remote = []; $local = []; $transfer = []; $delete = [];
		if ( $scope->files_wanted() ) {
			$r = $c->paged( '/hash/files', [ 'excludes' => $ex, 'algo' => $algo, 'limit' => 2000 ], function ( $res ) use ( &$remote ) { $remote += $res['files']; }, 'cursor' );
			if ( is_wp_error( $r ) ) return $r;
			$remote = self::drop_excluded( $remote, $ex );
			foreach ( array_keys( $remote ) as $rel ) if ( ! $scope->path_in( $rel ) ) unset( $remote[ $rel ] );
			$local = IXES_Transfer::local_manifest( $ex, $algo );
			foreach ( array_keys( $local ) as $rel ) if ( ! $scope->path_in( $rel ) ) unset( $local[ $rel ] );
			$transfer = array_keys( array_diff_assoc( $remote, $local ) ); sort( $transfer, SORT_STRING );
			$delete   = array_keys( array_diff_key( $local, $remote ) );
		}
```

and add `'scope' => $scope->to_array(),` plus `'warnings' => $scope->family_warnings( array_column( $tables_in_scope, 'name' ) ),` to the returned array (compute `$tables_in_scope` once and use it for `'tables'`).

`run()`: derive the scope at the top, after `$pairs = $plan['pairs'];`:

```php
		$scope = IXES_Scope::from_array( (array) ( $plan['scope'] ?? [] ), $wpdb->prefix );
		$partial = ! $scope->is_full();
```

Fresh-start branch: replace `$bl->reset();` with `if ( ! $partial ) $bl->reset();` (a partial pull keeps the rest of the baseline; per-table `delete_table()` already runs before each import).

After `import_commit` succeeds, guard the options-dependent steps:

```php
		$options_in = in_array( $wpdb->options, $done, true );
		if ( $options_in ) { wp_cache_flush(); $bl->meta( 'opt_active_plugins', json_encode( get_option( 'active_plugins', [] ) ) ); }
```

`preserve_local_options( $done )` already no-ops when options are not in `$done`. Guard the tail:

```php
		if ( $options_in ) IXES_Transfer::after_import( IXES_Env::local_url(), IXES_Env::local_abspath() );
		IXES_Transfer::offset_auto_increment( $done );
		if ( $partial ) { $bl->meta( 'partial_at', time() ); $bl->meta( 'partial_scope', $scope->label() ); }
		else $bl->commit();
```

Files: the `write_files( $plan['files']['remote'] )` call becomes per-path when partial:

```php
		if ( $partial ) { foreach ( $plan['files']['delete'] as $rel ) $bl->delete_file( $rel ); }
		$bl->write_files( $plan['files']['remote'] );
```

`$plan['files']['remote']` only holds in-scope paths, so `write_files` (INSERT OR REPLACE) refreshes exactly those; deleted in-scope paths are removed with the new `delete_file()`.

And the `if ( ! $done ) return new WP_Error( 'nothing_imported' … )` check must allow files-only pulls: change to

```php
		if ( ! $done && $scope->db_wanted() ) return new WP_Error( 'nothing_imported', 'no tables were imported' );
		if ( $done ) { /* existing preserve/commit/cache block */ }
```

`offset_auto_increment( array $imported = null )` in `IXES_Transfer`: add the optional argument and skip tables not in it:

```php
	public static function offset_auto_increment( array $imported = null ) {
		global $wpdb;
		$map = [ $wpdb->posts => 'ID', $wpdb->postmeta => 'meta_id', $wpdb->terms => 'term_id', $wpdb->term_taxonomy => 'term_taxonomy_id', $wpdb->comments => 'comment_ID', $wpdb->users => 'ID' ];
		foreach ( $map as $t => $pk ) {
			if ( $imported !== null && ! in_array( $t, $imported, true ) ) continue;
			$max = (int) $wpdb->get_var( "SELECT MAX(`{$pk}`) FROM `{$t}`" );
			$wpdb->query( "ALTER TABLE `{$t}` AUTO_INCREMENT = " . ( $max + 1000000 ) );
		}
	}
```

- [ ] **Step 5: Baseline helpers**

In `includes/class-ixes-baseline.php` add:

```php
	public function delete_file( $path ) {
		if ( $this->pdo ) { $st = $this->pdo->prepare( 'DELETE FROM files WHERE path = ?' ); $st->execute( [ $path ] ); }
		else { unset( $this->json['files'][ $path ] ); $this->save_json(); }
	}

	/** "2026-09-12", "2026-09-12 · partial 2026-09-22 (themes)" or "-" for CLI and admin tables. */
	public function baseline_label() {
		$c = $this->meta( 'created_at' ); $p = $this->meta( 'partial_at' );
		if ( ! $c && ! $p ) return '-';
		$out = $c ? date( 'Y-m-d H:i', (int) $c ) : 'none';
		if ( $p && ( ! $c || $p > $c ) ) $out .= ' · partial ' . date( 'Y-m-d H:i', (int) $p ) . ' (' . $this->meta( 'partial_scope' ) . ')';
		return $out;
	}
```

Use it: in `class-ixes-cli.php` `env list` replace the `'baseline' => …` expression with `'baseline' => $bl->baseline_label()`; in `admin/class-ixes-admin.php` the Environments row replace the fourth `%s` argument with `esc_html( $bl->baseline_label() )`.

- [ ] **Step 6: CLI flags**

In `includes/class-ixes-cli.php` add to the docblocks of `pull`, `diff` and `push`:

```
	 * [--only=<parts>]
	 * : Comma list of db,files,uploads,themes,plugins,mu-plugins. Default: everything.
	 *
	 * [--tables=<tables>]
	 * : Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
	 *
	 * [--paths=<paths>]
	 * : Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.
```

Add a private helper:

```php
	private function scope( $assoc ) {
		global $wpdb;
		try { return IXES_Scope::from_assoc( $assoc, $wpdb->prefix ); }
		catch ( InvalidArgumentException $e ) { WP_CLI::error( $e->getMessage() ); }
	}
```

- `pull()`: fresh branch `IXES_Pull::plan( $env, $c, $this->scope( $assoc ) )`; after printing excludes, print `foreach ( (array) ( $plan['warnings'] ?? [] ) as $w ) WP_CLI::warning( $w );` and `if ( ! empty( $plan['scope'] ) ) WP_CLI::log( '  scope: ' . IXES_Scope::from_array( $plan['scope'], '' )->label() );`. On the resume branch, if any of the three flags is set, `WP_CLI::error( 'scope flags cannot change while resuming; use --fresh' )`.
- `diff()`: `IXES_Planner::build( $env, $c, $this->scope( $assoc ) )`.
- `push()`: `IXES_Planner::build( $env, $c, $this->scope( $assoc ) )`; when `--plan=` is given and any scope flag is also set, `WP_CLI::error( '--plan carries its own scope; drop --only/--tables/--paths' )`. Confirmation string becomes `"Apply this plan (scope: " . IXES_Scope::from_array( (array) ( $plan['scope'] ?? [] ), '' )->label() . ") to {$env['name']} ({$env['url']})?"`.

- [ ] **Step 7: Lint, tests, commit**

```bash
vendor/bin/phpcs && vendor/bin/phpunit
git add includes admin tests/PlannerRenderTest.php
git commit -m "feat: selective sync with --only, --tables and --paths on pull, diff and push"
```

---

### Task 10: Docs, skill, version

**Files:**
- Modify: `skills/wp-envsync/SKILL.md`, `README.md`, `ix-wp-envsync.php`

- [ ] **Step 1: Skill**

Add to `skills/wp-envsync/SKILL.md` a section (place it after the daily-workflow section; read the file first to match its heading style):

```markdown
## Which flags for which job

| Situation | Command |
|---|---|
| First pull of a big site | `wp envsync pull prod`. If it drops, run the same command again and answer `y` to resume. `--fresh` starts over. |
| Working on the theme, want prod's latest theme files | `wp envsync pull prod --only=themes --paths=themes/<slug>/` |
| Client edited content, want it locally without touching your theme | `wp envsync pull prod --only=db --tables=posts,postmeta,terms,term_taxonomy,term_relationships,termmeta` |
| Fresh media only | `wp envsync pull prod --only=uploads` |
| Ship theme work | `wp envsync diff prod --only=themes`, then `wp envsync push prod --only=themes` |
| After any `--tables` pull that split a family (the plan prints a warning) | run a full `wp envsync pull prod` before the next push |

Rules the agent must follow:
- Scope on `push` never widens beyond what `diff` showed with the same flags. Run `diff` first with the flags you intend to push with.
- A partial pull refreshes only the parts of the baseline it touched. `env list` shows `partial <date> (<scope>)` next to the full baseline date.
- Resume refuses when the remote plugin version or the env's excludes/replace pairs changed since the pull started; use `--fresh`.
```

- [ ] **Step 2: README**

Add a section "Sync only part of a site" after "Daily workflow" with the same table, and a short "If a pull is interrupted" paragraph: rerun the same command, answer `y`; `--fresh` discards; where the state file lives (`wp-content/envsync-*/pull-<env>.json`). Add the three flags to the `pull`, `diff`, `push` option lists in "Commands".

- [ ] **Step 3: Version**

In `ix-wp-envsync.php` set `Version: 0.3.0` and `IXES_VERSION` to `'0.3.0'`.

- [ ] **Step 4: Commit**

```bash
vendor/bin/phpcs && vendor/bin/phpunit
git add skills README.md ix-wp-envsync.php
git commit -m "docs: selective sync and resume; bump to 0.3.0"
```

---

### Task 11: Integration scenarios

**Files:**
- Modify: `tests/integration.sh`

Runs against two real installs (`IXES_A` = prod, `IXES_B` = hub). Append before `echo "ALL OK"`:

- [ ] **Step 1: Interrupted pull resumes**

```bash
# 7. a pull killed mid-way resumes and completes
dd if=/dev/urandom of="$IXES_A/wp-content/uploads/big.bin" bs=1M count=40 status=none
B envsync pull prod --fresh --yes >/dev/null   # clean start with the big file in the plan
timeout 8 env WP_CLI_STRICT_ARGS_MODE=1 wp --path="$IXES_B" --url="$IXES_B_URL" envsync pull prod --fresh --yes >/dev/null 2>&1 || true
ls "$IXES_B/wp-content/envsync-"*/pull-prod.json >/dev/null 2>&1 || die "no resume state after an interrupted pull"
B envsync pull prod --yes | grep -q "interrupted pull" || die "resume prompt not shown"
cmp "$IXES_A/wp-content/uploads/big.bin" "$IXES_B/wp-content/uploads/big.bin" || die "big file differs after resume"
ls "$IXES_B/wp-content/envsync-"*/pull-prod.json 2>/dev/null && die "state file left behind after a completed pull"
```

If 8 seconds is not enough to get past the table phase on the test machines, raise the `count=` or lower the timeout until the kill lands inside the file phase; the assertion that matters is the `cmp`.

- [ ] **Step 2: Scoped push leaves prod content alone**

```bash
# 8. --only=themes push: theme file goes up, prod-edited post untouched
B envsync pull prod --yes >/dev/null
echo "/* v3 */" > "$IXES_B/wp-content/themes/ixtest/style.css"
B post update "$PX" --post_content="x-local-only" >/dev/null
A post update "$PY" --post_content="y-prod-edit" >/dev/null
B envsync diff prod --only=themes | grep -q "scope: themes" || die "scope not shown in diff"
B envsync push prod --only=themes --yes >/dev/null
grep -q v3 "$IXES_A/wp-content/themes/ixtest/style.css" || die "theme not pushed with --only=themes"
[ "$(A post get "$PX" --field=post_content)" != "x-local-only" ] || die "db row pushed despite --only=themes"
[ "$(A post get "$PY" --field=post_content)" = "y-prod-edit" ] || die "prod edit lost"
B envsync env list | grep -q "partial" && die "a scoped push must not mark the baseline partial"
B envsync pull prod --only=uploads --yes >/dev/null
B envsync env list | grep -q "partial .*(uploads)" || die "partial pull not reflected in env list"
```

- [ ] **Step 3: Old-hub JSON path still served**

```bash
# 9. a 0.2 hub asks for JSON: the remote must still answer base64
TS=$(date +%s); BODY='{"path":"themes/ixtest/style.css","offset":0,"size":1024}'
MSG=$(printf 'POST\n/envsync/v1/file/get\n%s\n%s' "$TS" "$(printf '%s' "$BODY" | sha256sum | cut -d' ' -f1)")
SIG=$(printf '%s' "$MSG" | openssl dgst -sha256 -hmac "$TOKEN" | sed 's/^.* //')
OUT=$(curl -s -H "Authorization: Bearer $TOKEN" -H "X-Envsync-Ts: $TS" -H "X-Envsync-Sig: $SIG" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "$BODY" "$IXES_A_URL/?rest_route=/envsync/v1/file/get")
echo "$OUT" | grep -q '"data":"' || die "JSON file/get no longer served: $OUT"
echo "$OUT" | php -r '$j=json_decode(stream_get_contents(STDIN),true); exit(hash("sha256",base64_decode($j["data"]))===$j["sha256"]?0:1);' || die "JSON chunk hash mismatch"
```

(The `$TOKEN` variable is set near the top of the script by `A envsync token --rotate`.)

- [ ] **Step 4: Run and commit**

Run: `IXES_A=… IXES_A_URL=… IXES_B=… IXES_B_URL=… tests/integration.sh`
Expected: `ALL OK`.

```bash
git add tests/integration.sh
git commit -m "test: integration scenarios for resume, scoped push and JSON fallback"
```

---

## Self-review

**Spec coverage.**
- §1 wire format: Task 4 (remote), Task 3 (client), Task 1 (signature). `caps`: Task 4 + `IXES_Client::caps()`. Chunker rules table: Task 2. Remote memory: Task 4 `file_chunk` binary. ✔
- §2 state file fields: Task 7 `start()` writes all nine keys. Rerun/refusal/`--fresh`: Task 7 step 6. REPLACE and cursor-after-baseline: Task 6 + Task 7 step 5. `created_at` at commit: Task 6. ✔
- §3 flags, `IXES_Scope` API, pull/diff/push behaviour, family warning, display: Tasks 8 and 9. `push --plan` refusing extra flags: Task 9 step 6. `active_plugins` only when options in scope: covered by the existing `if ( $name === $wpdb->options )` inside the filtered loop. ✔
- §4 skill and README: Task 10. §5 tests: `ScopeTest` (T8), `ChunkerTest` (T2), `AuthTest` (T1), `PullStateTest` (T7, the spec's "PullResumeTest"), `ClientLoopTest` (T3, the spec's "ClientChunkTest"); integration (T11). §6 rollout: `caps()` fallback in T3, JSON paths retained in T4. ✔

**Type consistency.** `fetch_file( $rel, callable $write )` used identically in T5 and T7. `send_file( $job, $rel, $abs, array $first_meta )` returns `[ 'ok', 'refused' ]` (T3) and T5 reads `$r['refused']`. `IXES_Planner::save( $plan, 'pull' )` (T6) called in T7. `IXES_Scope::from_array( array, $prefix )` (T8) used in T9 with `$wpdb->prefix` where tables matter and `''` for labels only. `import_rows( …, $replace )` fourth arg bool in T7. `offset_auto_increment( $done )` new optional arg defined in T9 before use. ✔

**Placeholders.** Task 7 step 6 says "existing plan printing … unchanged" and Task 10 step 2 describes README prose rather than quoting it; both point at existing text the implementer can read in place. No TBDs.
