# Status command, operational fixes, onboarding — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the plugin explain its own state and the one next command, for humans (CLI text, admin panel) and agents (`--json`), and fix the five ways an operator gets stuck (dead lock, stripped Authorization header, version skew, malformed push meta, multisite).

**Architecture:** One pure report builder, `IXES_Status`, gathers facts (local options, baseline, pull state, one `/info` call per env) and applies an ordered rule table to produce `next`. Three renderers consume the same array: `wp envsync status`, the admin "Status" panel, and `--json` for agents. Each operational fix adds one fact to `/info` and one action (`/job/unlock` + `wp envsync unlock`, `X-Envsync-Token` fallback, version compare, array casts, multisite refusal). README and skill are rewritten around `status`.

**Tech Stack:** PHP 7.4+, WordPress REST + WP-CLI, PHPUnit 9.6 pure tests (stubs in `tests/bootstrap.php`), bash integration script over the two-install pair at `~/worktrees/ixes-pair`.

**Spec:** `docs/superpowers/specs/2026-09-22-status-and-onboarding-design.md`

## Global Constraints

- `Requires PHP: 7.4`; no PHP 8-only syntax. A `#[...]` attribute must sit on its own line (7.4 treats it as a comment).
- New classes live in `includes/class-ixes-<name>.php`, autoloaded by the `IXES_` prefix.
- Unit tests run with `vendor/bin/phpunit` and must not need WordPress; add stubs to `tests/bootstrap.php` only when a pure class needs them. `vendor/bin/phpcs` must stay clean.
- Wire compatibility: a 0.3 hub against a 0.4 remote and a 0.4 hub against a 0.3 remote both keep working. Token hash, timestamp and signature checks are unchanged; only the token carrier gains a fallback.
- `next` is advice: `status` exits 0 in every case.
- Commit messages: conventional commits, English, no AI attribution. Branch: `feat/status-and-onboarding` (holds the spec).
- Integration pair: `~/worktrees/ixes-pair/{a,b}`, serve with `php -S 127.0.0.1:8081 -t a` and `:8082 -t b`; run `IXES_A=… IXES_A_URL=http://127.0.0.1:8081 IXES_B=… IXES_B_URL=http://127.0.0.1:8082 tests/integration.sh`.

## File map

| File | Responsibility | Task |
|---|---|---|
| `includes/class-ixes-rest.php` | token fallback + `auth_via`; `/ping` and `/info` report it; `/job/unlock` route | 1, 3 |
| `includes/class-ixes-client.php` | send `X-Envsync-Token` too | 1 |
| `includes/class-ixes-transfer.php` | `info()` gains `lock`, `auth_via` | 2, 3 |
| `includes/class-ixes-applier.php` | lock value carries start time; `job_unlock()`; `job_start` casts via `plan_meta_shape()` | 2, 3, 4 |
| `admin/class-ixes-admin.php` | `is_array` guard; Status panel | 4, 7 |
| `ix-wp-envsync.php` | multisite refusal; version 0.4.0 | 4, 8 |
| `includes/class-ixes-status.php` (new) | report builder + `next` rules, pure | 5 |
| `includes/class-ixes-cli.php` | `status`, `unlock`, `ping` version/auth_via output | 3, 6 |
| `README.md`, `skills/wp-envsync/SKILL.md` | rewrite | 8 |
| `tests/integration.sh` | three scenarios | 9 |

---

### Task 1: Token fallback header and `auth_via`

**Files:**
- Modify: `includes/class-ixes-rest.php:25-36` (`auth()`), `:11` (`/ping`)
- Modify: `includes/class-ixes-client.php:26` (headers)
- Modify: `includes/class-ixes-auth.php` (new pure helper)
- Test: `tests/AuthTest.php`

**Interfaces:**
- Produces: `IXES_Auth::token_from_headers( $authorization, $x_token )` → `[ $token, $via ]` where `$via` is `'authorization'`, `'x-envsync-token'` or `null`. `IXES_Rest::$auth_via` static string set per request; `IXES_Rest::auth_via()` getter. `/ping` returns `auth_via`.

- [ ] **Step 1: Failing tests**

Append to `tests/AuthTest.php`:

```php
	public function test_token_from_authorization_header() {
		$this->assertSame( [ 'abc', 'authorization' ], IXES_Auth::token_from_headers( 'Bearer abc', '' ) );
	}
	public function test_token_falls_back_to_x_envsync_token() {
		$this->assertSame( [ 'abc', 'x-envsync-token' ], IXES_Auth::token_from_headers( '', 'abc' ) );
		$this->assertSame( [ 'abc', 'x-envsync-token' ], IXES_Auth::token_from_headers( 'Basic zzz', 'abc' ), 'a non-Bearer Authorization header is ignored' );
	}
	public function test_authorization_wins_when_both_present() {
		$this->assertSame( [ 'one', 'authorization' ], IXES_Auth::token_from_headers( 'Bearer one', 'two' ) );
	}
	public function test_no_token_in_either_header() {
		$this->assertSame( [ '', null ], IXES_Auth::token_from_headers( '', '' ) );
	}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit --filter AuthTest` → errors: undefined method.

- [ ] **Step 3: Implement**

In `includes/class-ixes-auth.php` add:

```php
	/**
	 * Some hosts strip the Authorization header before PHP sees it. The hub therefore also sends
	 * X-Envsync-Token; the remote prefers Authorization and falls back. Returns [ token, carrier ].
	 */
	public static function token_from_headers( $authorization, $x_token ) {
		$authorization = (string) $authorization; $x_token = trim( (string) $x_token );
		if ( $authorization !== '' && stripos( $authorization, 'Bearer ' ) === 0 ) return [ trim( substr( $authorization, 7 ) ), 'authorization' ];
		if ( $x_token !== '' ) return [ $x_token, 'x-envsync-token' ];
		return [ '', null ];
	}
```

In `includes/class-ixes-rest.php` replace the header handling inside `auth()`:

```php
	private static $auth_via = null;
	public static function auth_via() { return self::$auth_via; }
	...
		list( $token, $via ) = IXES_Auth::token_from_headers( $req->get_header( 'authorization' ), $req->get_header( 'x-envsync-token' ) );
		if ( $token === '' ) return new WP_Error( 'auth', 'missing token', [ 'status' => 401 ] );
		self::$auth_via = $via;
```

(remove the old `$hdr`/`$token` lines; the `verify()` call keeps using `$token`.)

`/ping` route: `function () { return [ 'ok' => true, 'time' => time(), 'auth_via' => self::auth_via() ]; }`.

In `includes/class-ixes-client.php` headers array add after `'Authorization'`: `'X-Envsync-Token' => $this->env['token'],`.

- [ ] **Step 4: Run** `vendor/bin/phpunit` → green. `vendor/bin/phpcs`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ixes-auth.php includes/class-ixes-rest.php includes/class-ixes-client.php tests/AuthTest.php
git commit -m "feat(auth): accept the token from X-Envsync-Token when Authorization is stripped, report auth_via"
```

---

### Task 2: Lock carries its start time; `/info` reports lock and auth_via

**Files:**
- Modify: `includes/class-ixes-applier.php:6-7, 27-29, 110, 189` (lock value), new `lock_info()`
- Modify: `includes/class-ixes-transfer.php` `info()`
- Test: `tests/ApplierLockTest.php` (new, pure helpers only)

**Interfaces:**
- Produces: `IXES_Applier::lock_value( $job, $started )` → `"$job|$started"`; `IXES_Applier::parse_lock( $value )` → `[ 'job' => string, 'started' => int|null ]` (accepts the 0.3 bare-job form: `started` null); `IXES_Applier::current_job()` → job string from the transient or `''`; `IXES_Applier::lock_info()` → `[ 'job', 'started' ] | null`. `/info` gains `'lock' => IXES_Applier::lock_info()` and `'auth_via' => IXES_Rest::auth_via()`.

- [ ] **Step 1: Failing tests**

Create `tests/ApplierLockTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class ApplierLockTest extends TestCase {
	public function test_lock_value_round_trips() {
		$v = IXES_Applier::lock_value( '20260922-101500-ab12cd', 1758535000 );
		$this->assertSame( '20260922-101500-ab12cd|1758535000', $v );
		$this->assertSame( [ 'job' => '20260922-101500-ab12cd', 'started' => 1758535000 ], IXES_Applier::parse_lock( $v ) );
	}
	public function test_parse_lock_accepts_pre_04_bare_job() {
		$this->assertSame( [ 'job' => '20260922-101500-ab12cd', 'started' => null ], IXES_Applier::parse_lock( '20260922-101500-ab12cd' ) );
	}
	public function test_parse_lock_of_nothing() {
		$this->assertSame( [ 'job' => '', 'started' => null ], IXES_Applier::parse_lock( false ) );
	}
}
```

`IXES_Applier` references `IXES_Rest::NS` and WordPress functions only inside method bodies, so the autoloader can load it in tests. If `class-ixes-applier.php` fails to load under PHPUnit because of a top-level WordPress call, stop and report; do not stub around it.

- [ ] **Step 2: Run** `vendor/bin/phpunit --filter ApplierLockTest` → fails.

- [ ] **Step 3: Implement**

In `includes/class-ixes-applier.php` add after `const ORDER`:

```php
	// Lock transient value is "job|started". 0.3 wrote the bare job id; parse_lock() accepts both.
	public static function lock_value( $job, $started ) { return $job . '|' . (int) $started; }
	public static function parse_lock( $value ) {
		if ( ! is_string( $value ) || $value === '' ) return [ 'job' => '', 'started' => null ];
		$parts = explode( '|', $value, 2 );
		return [ 'job' => $parts[0], 'started' => isset( $parts[1] ) ? (int) $parts[1] : null ];
	}
	public static function current_job() { return self::parse_lock( get_transient( self::LOCK ) )['job']; }
	/** @return array{job:string,started:int|null}|null */
	public static function lock_info() {
		$l = self::parse_lock( get_transient( self::LOCK ) );
		return $l['job'] === '' ? null : $l;
	}
```

Change every lock comparison:
- `job_start`: `set_transient( self::LOCK, self::lock_value( $job, time() ), HOUR_IN_SECONDS );`
- `job_step` and `job_finish`: `if ( self::current_job() !== (string) ( $p['job'] ?? '' ) ) return new WP_Error( 'nojob', ... );`
- `job_start`'s guard: `if ( self::current_job() !== '' ) return new WP_Error( 'locked', ... );`

In `includes/class-ixes-transfer.php` `info()` add:

```php
			'lock'            => IXES_Applier::lock_info(),
			'auth_via'        => IXES_Rest::auth_via(),
```

- [ ] **Step 4: Run** full suite + phpcs. Commit:

```bash
git add includes/class-ixes-applier.php includes/class-ixes-transfer.php tests/ApplierLockTest.php
git commit -m "feat(remote): lock records its start time; /info reports lock and auth_via"
```

---

### Task 3: `/job/unlock` and `wp envsync unlock`

**Files:**
- Modify: `includes/class-ixes-applier.php` (new `job_unlock()`)
- Modify: `includes/class-ixes-rest.php:18-21` (route)
- Modify: `includes/class-ixes-cli.php` (new `unlock` command; `ping` output)
- Test: none pure beyond Task 2; covered by integration in Task 9.

**Interfaces:**
- Produces: `IXES_Applier::job_unlock( array $p )` → `[ 'ok' => true, 'job', 'age_minutes' ]` or `WP_Error( 'nolock', 404 )` / `WP_Error( 'too_recent', 409 )`. Route `POST /job/unlock`. CLI `wp envsync unlock <env> [--yes]`.

- [ ] **Step 1: Applier**

```php
	const UNLOCK_MIN_AGE = 120; // seconds: a lock younger than this is a live push

	public static function job_unlock( array $p ) {
		$l = self::lock_info();
		if ( ! $l ) return new WP_Error( 'nolock', 'no push is locked', [ 'status' => 404 ] );
		$age = $l['started'] ? time() - $l['started'] : null;
		if ( $age !== null && $age < self::UNLOCK_MIN_AGE ) return new WP_Error( 'too_recent', "lock is only {$age}s old; a push may still be running", [ 'status' => 409 ] );
		self::maintenance( false );
		delete_transient( self::LOCK );
		return [ 'ok' => true, 'job' => $l['job'], 'age_minutes' => $age === null ? null : (int) floor( $age / 60 ) ];
	}
```

- [ ] **Step 2: Route** — in `register()`, add `'unlock'` to the `foreach ( [ 'start', 'step', 'finish', 'abort' ] ...)` list (it dispatches to `job_unlock`).

- [ ] **Step 3: CLI** — add to `includes/class-ixes-cli.php`:

```php
	/**
	 * Clear a push lock left behind by a hub that died mid-push. Rolls nothing back.
	 * ## OPTIONS
	 *
	 * <env>
	 * : Environment name.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 */
	public function unlock( $args, $assoc ) {
		$env = $this->get_env( $args[0] ); $c = $this->client( $args[0] );
		$info = $this->fail_if_error( $c->info() );
		$lock = $info['lock'] ?? null;
		if ( ! $lock ) WP_CLI::error( "no push is locked on {$env['name']}" );
		$age = $lock['started'] ? (int) floor( ( time() - $lock['started'] ) / 60 ) . ' min' : 'unknown age';
		$this->confirm( $assoc, "Clear the lock from job {$lock['job']} ({$age}) on {$env['name']}? Nothing is rolled back; 'wp envsync rollback {$env['name']}' still restores that job's snapshot." );
		$r = $this->fail_if_error( $c->post( '/job/unlock', [] ) );
		WP_CLI::success( "unlocked {$env['name']} (job {$r['job']})" );
	}
```

And extend `ping` (the `$action === 'ping'` branch in `env()`):

```php
		if ( $action === 'ping' ) {
			$c = $this->client( $args[1] );
			$r = $this->fail_if_error( $c->get( '/ping' ) );
			$info = $this->fail_if_error( $c->info() );
			$via = ( $r['auth_via'] ?? 'authorization' ) === 'x-envsync-token' ? 'X-Envsync-Token (this host strips the Authorization header; that is fine)' : 'Authorization';
			WP_CLI::success( 'ok, remote time ' . date( 'c', $r['time'] ) . ", remote {$info['plugin']}, auth via {$via}" );
			if ( version_compare( (string) $info['plugin'], IXES_VERSION, '<' ) ) WP_CLI::warning( "remote runs {$info['plugin']}, hub runs " . IXES_VERSION . ": upload the release zip to {$this->get_env( $args[1] )['url']}" );
			elseif ( version_compare( (string) $info['plugin'], IXES_VERSION, '>' ) ) WP_CLI::log( "note: remote runs {$info['plugin']}, newer than this hub (" . IXES_VERSION . ')' );
			return;
		}
```

- [ ] **Step 4:** `php -l` all three, phpcs, phpunit. Commit:

```bash
git add includes/class-ixes-applier.php includes/class-ixes-rest.php includes/class-ixes-cli.php
git commit -m "feat: unlock a dead push from the hub; ping reports version skew and auth carrier"
```

---

### Task 4: Hardening — malformed `job_start`, admin guard, multisite

**Files:**
- Modify: `includes/class-ixes-applier.php` `job_start()`
- Modify: `admin/class-ixes-admin.php:52`
- Modify: `ix-wp-envsync.php:42-47`
- Test: `tests/ApplierLockTest.php` (add one test for the shape helper)

**Interfaces:**
- Produces: `IXES_Applier::plan_meta_shape( $raw )` → array with `tables` (array) and `files` (`[ 'push' => array, 'delete' => array ]`), tolerant of any input.

- [ ] **Step 1: Failing test** (append to `tests/ApplierLockTest.php`)

```php
	public function test_plan_meta_shape_survives_garbage() {
		$this->assertSame( [ 'tables' => [], 'files' => [ 'push' => [], 'delete' => [] ] ], IXES_Applier::plan_meta_shape( 'not an array' ) );
		$s = IXES_Applier::plan_meta_shape( [ 'tables' => [ 'wp_posts' => [ 'pk' => 'ID' ] ], 'files' => [ 'push' => 'x' ] ] );
		$this->assertSame( [ 'wp_posts' => [ 'pk' => 'ID' ] ], $s['tables'] );
		$this->assertSame( [ 'x' ], $s['files']['push'] );
		$this->assertSame( [], $s['files']['delete'] );
	}
```

- [ ] **Step 2: Implement**

```php
	/** Whatever the hub sent, meta.json gets arrays where the admin page and rollback expect arrays. */
	public static function plan_meta_shape( $raw ) {
		$raw = is_array( $raw ) ? $raw : [];
		$files = is_array( $raw['files'] ?? null ) ? $raw['files'] : [];
		return [
			'tables' => is_array( $raw['tables'] ?? null ) ? $raw['tables'] : [],
			'files'  => [ 'push' => (array) ( $files['push'] ?? [] ), 'delete' => (array) ( $files['delete'] ?? [] ) ],
		] + $raw;
	}
```

In `job_start()`: first line `$p['plan_meta'] = self::plan_meta_shape( $p['plan_meta'] ?? null );` and keep the rest reading from `$p['plan_meta']`.

Admin (`class-ixes-admin.php:52`): replace `count( $m['plan']['tables'] )` with `count( (array) ( $m['plan']['tables'] ?? [] ) )` and guard `$m['job']`/`$m['started']` with `?? ''` / `?? 0`.

Activation (`ix-wp-envsync.php`): first line inside the activation closure:

```php
	if ( is_multisite() ) wp_die( 'EnvSync does not support multisite yet.', 'EnvSync', [ 'back_link' => true ] );
```

- [ ] **Step 3:** phpunit, phpcs, `php -l`. Commit:

```bash
git add includes/class-ixes-applier.php admin/class-ixes-admin.php ix-wp-envsync.php tests/ApplierLockTest.php
git commit -m "fix: tolerate malformed push metadata, guard the admin page, refuse multisite activation"
```

---

### Task 5: `IXES_Status` report builder

**Files:**
- Create: `includes/class-ixes-status.php`
- Test: `tests/StatusTest.php`
- Modify: `tests/bootstrap.php` (stubs listed below)

**Interfaces:**
- Produces: `IXES_Status::build( $env_name = null, callable $info_for = null, array $ctx = null )` → the spec's report array. `$info_for( array $env )` returns the `/info` array or `WP_Error`; default uses `IXES_Client`. `$ctx` overrides for tests: `[ 'now', 'hub_version', 'envs', 'token_issued', 'token_pending', 'baseline' => fn(name) => [created_at, partial_at, partial_scope], 'pull_state' => fn(name) => array|null ]`; default reads WordPress. `IXES_Status::RULES` documents the order. `IXES_Status::render_text( array $report )` → string.

- [ ] **Step 1: Bootstrap stubs** (append before the autoloader; skip any that already exist)

```php
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['ixes_test_options'][ $k ] ?? $d; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['ixes_test_transients'][ $k ] ?? false; } }
if ( ! defined( 'IXES_VERSION' ) ) define( 'IXES_VERSION', '0.4.0' );
if ( ! function_exists( 'home_url' ) ) { function home_url() { return 'http://hub.test'; } }
```

- [ ] **Step 2: Failing tests**

Create `tests/StatusTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase {
	private $now = 1758600000;

	private function ctx( array $over = [] ) {
		return $over + [
			'now' => $this->now, 'hub_version' => '0.4.0', 'token_issued' => false, 'token_pending' => false,
			'envs' => [ 'prod' => [ 'name' => 'prod', 'url' => 'https://p.test', 'label' => 'prod', 'excludes' => [] ] ],
			'baseline' => function ( $n ) { return [ 'created_at' => $this->now - 86400, 'partial_at' => null, 'partial_scope' => null ]; },
			'pull_state' => function ( $n ) { return null; },
		];
	}
	private function info( array $over = [] ) {
		return function ( $env ) use ( $over ) { return $over + [ 'plugin' => '0.4.0', 'lock' => null, 'auth_via' => 'authorization', 'url' => $env['url'] ]; };
	}
	private function next( array $ctx, $info ) { return IXES_Status::build( null, $info, $ctx )['next']; }

	public function test_unconfigured_site() {
		$r = IXES_Status::build( null, $this->info(), $this->ctx( [ 'envs' => [] ] ) );
		$this->assertSame( 'unconfigured', $r['role'] );
		$this->assertStringContainsString( 'not set up', $r['next']['why'] );
	}
	public function test_remote_only_role() {
		$r = IXES_Status::build( null, $this->info(), $this->ctx( [ 'envs' => [], 'token_issued' => true ] ) );
		$this->assertSame( 'remote', $r['role'] );
		$this->assertStringContainsString( 'remote', $r['next']['why'] );
	}
	public function test_unreachable_env() {
		$info = function () { return new WP_Error( 'http', 'cURL error 7' ); };
		$n = $this->next( $this->ctx(), $info );
		$this->assertStringContainsString( 'env add prod', $n['command'] );
		$this->assertStringContainsString( 'cURL error 7', $n['why'] );
	}
	public function test_old_remote_version() {
		$n = $this->next( $this->ctx(), $this->info( [ 'plugin' => '0.3.0' ] ) );
		$this->assertStringContainsString( 'upload the release zip', $n['command'] );
	}
	public function test_stale_lock_beats_interrupted_pull() {
		$ctx = $this->ctx( [ 'pull_state' => function () { return [ 'started' => $this->now - 600, 'table' => 'wp_posts', 'cursor' => '5', 'files_done' => 1, 'files_total' => 9 ]; } ] );
		$n = $this->next( $ctx, $this->info( [ 'lock' => [ 'job' => 'j1', 'started' => $this->now - 1200 ] ] ) );
		$this->assertSame( 'wp envsync unlock prod', $n['command'] );
	}
	public function test_young_lock_does_not_change_next() {
		$n = $this->next( $this->ctx(), $this->info( [ 'lock' => [ 'job' => 'j1', 'started' => $this->now - 60 ] ] ) );
		$this->assertSame( 'wp envsync diff prod', $n['command'] );
	}
	public function test_interrupted_pull() {
		$ctx = $this->ctx( [ 'pull_state' => function () { return [ 'started' => $this->now - 600, 'table' => null, 'cursor' => null, 'files_done' => 3, 'files_total' => 9 ]; } ] );
		$n = $this->next( $ctx, $this->info() );
		$this->assertSame( 'wp envsync pull prod', $n['command'] );
		$this->assertStringContainsString( 'resume', $n['why'] );
	}
	public function test_no_baseline() {
		$ctx = $this->ctx( [ 'baseline' => function () { return [ 'created_at' => null, 'partial_at' => null, 'partial_scope' => null ]; } ] );
		$n = $this->next( $ctx, $this->info() );
		$this->assertSame( 'wp envsync pull prod', $n['command'] );
		$this->assertStringContainsString( 'no baseline', $n['why'] );
	}
	public function test_old_baseline() {
		$ctx = $this->ctx( [ 'baseline' => function () { return [ 'created_at' => $this->now - 10 * 86400, 'partial_at' => null, 'partial_scope' => null ]; } ] );
		$n = $this->next( $ctx, $this->info() );
		$this->assertSame( 'wp envsync diff prod', $n['command'] );
		$this->assertStringContainsString( '10 days', $n['why'] );
	}
	public function test_ready() {
		$r = IXES_Status::build( null, $this->info(), $this->ctx() );
		$this->assertSame( 'hub', $r['role'] );
		$this->assertSame( [ 'command' => 'wp envsync diff prod', 'why' => 'ready', 'env' => 'prod' ], $r['next'] );
		$this->assertTrue( $r['envs']['prod']['reachable'] );
		$this->assertTrue( $r['envs']['prod']['version_ok'] );
		$this->assertSame( 1, $r['envs']['prod']['baseline']['age_days'] );
	}
	public function test_first_non_ready_env_decides() {
		$ctx = $this->ctx( [ 'envs' => [
			'prod'    => [ 'name' => 'prod', 'url' => 'https://p.test', 'label' => 'prod', 'excludes' => [] ],
			'staging' => [ 'name' => 'staging', 'url' => 'https://s.test', 'label' => 'staging', 'excludes' => [] ],
		] ] );
		$info = function ( $env ) { return $env['name'] === 'staging' ? new WP_Error( 'http', 'down' ) : [ 'plugin' => '0.4.0', 'lock' => null, 'auth_via' => 'authorization', 'url' => $env['url'] ]; };
		$this->assertSame( 'staging', $this->next( $ctx, $info )['env'] );
	}
	public function test_render_text_contains_next_line() {
		$r = IXES_Status::build( null, $this->info(), $this->ctx() );
		$t = IXES_Status::render_text( $r );
		$this->assertStringContainsString( 'prod  https://p.test  (prod)', $t );
		$this->assertStringContainsString( 'Next: wp envsync diff prod', $t );
	}
}
```

- [ ] **Step 3: Run** → class not found.

- [ ] **Step 4: Implement**

Create `includes/class-ixes-status.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * "Where am I and what do I do next." Pure apart from the injectable readers; one /info call per env.
 * The rule order below is the contract: the first rule that matches decides `next`.
 */
class IXES_Status {
	const LOCK_STALE_MIN = 10;   // minutes before a remote lock is presumed dead
	const BASELINE_OLD_DAYS = 7;
	const RULES = [ 'unconfigured', 'remote_only', 'unreachable', 'old_remote', 'stale_lock', 'interrupted_pull', 'no_baseline', 'old_baseline', 'ready' ];

	private static function defaults() {
		return [
			'now'           => time(),
			'hub_version'   => IXES_VERSION,
			'token_issued'  => (bool) get_option( 'ixes_token_hash' ),
			'token_pending' => (bool) get_transient( 'ixes_token_show' ),
			'envs'          => IXES_Env::all(),
			'baseline'      => function ( $name ) {
				$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $name . '.sqlite' );
				return [ 'created_at' => $bl->meta( 'created_at' ), 'partial_at' => $bl->meta( 'partial_at' ), 'partial_scope' => $bl->meta( 'partial_scope' ) ];
			},
			'pull_state'    => function ( $name ) {
				$s = IXES_PullState::load( $name );
				if ( ! $s ) return null;
				$plan = is_file( (string) $s->get( 'plan' ) ) ? json_decode( file_get_contents( $s->get( 'plan' ) ), true ) : null;
				return [ 'started' => $s->get( 'started' ), 'table' => $s->get( 'table' ), 'cursor' => $s->get( 'cursor' ), 'files_done' => $s->get( 'files_done' ), 'files_total' => is_array( $plan ) ? count( $plan['files']['transfer'] ?? [] ) : null ];
			},
		];
	}

	public static function build( $env_name = null, callable $info_for = null, array $ctx = null ) {
		$ctx = ( $ctx ?: [] ) + self::defaults();
		if ( $info_for === null ) $info_for = function ( array $env ) { return ( new IXES_Client( $env ) )->info(); };
		$envs = $ctx['envs'];
		if ( $env_name !== null ) $envs = isset( $envs[ $env_name ] ) ? [ $env_name => $envs[ $env_name ] ] : [];
		$is_hub = ! empty( $ctx['envs'] );
		$role = $is_hub && $ctx['token_issued'] ? 'both' : ( $is_hub ? 'hub' : ( $ctx['token_issued'] ? 'remote' : 'unconfigured' ) );
		$report = [ 'role' => $role, 'hub_version' => $ctx['hub_version'], 'token' => [ 'issued' => $ctx['token_issued'], 'shown_pending' => $ctx['token_pending'] ], 'envs' => [], 'next' => null ];

		if ( $role === 'unconfigured' ) {
			$report['next'] = [ 'command' => 'wp envsync token  (remote)  |  wp envsync env add <name> <url> --token=…  (hub)', 'why' => 'this site is not set up yet', 'env' => null ];
			return $report;
		}
		if ( $role === 'remote' ) {
			$report['next'] = [ 'command' => $ctx['token_pending'] ? 'wp envsync token' : '', 'why' => 'this site is a remote; run pull/diff/push from the hub', 'env' => null ];
			return $report;
		}

		$decided = null;
		foreach ( $envs as $name => $env ) {
			$e = self::env_facts( $env, $info_for, $ctx );
			$report['envs'][ $name ] = $e;
			$n = self::next_for( $name, $env, $e, $ctx );
			if ( $decided === null && $n['why'] !== 'ready' ) $decided = $n;
			if ( $decided === null && $name === array_key_last( $envs ) ) $decided = $n;
		}
		$report['next'] = $decided ?: [ 'command' => '', 'why' => 'no such environment', 'env' => $env_name ];
		return $report;
	}

	private static function env_facts( array $env, callable $info_for, array $ctx ) {
		$now = $ctx['now'];
		$e = [ 'url' => $env['url'], 'label' => $env['label'] ?? '', 'reachable' => false, 'error' => null, 'remote_version' => null, 'version_ok' => null, 'auth_via' => null,
			'baseline' => null, 'interrupted_pull' => null, 'remote_lock' => null, 'excludes_count' => count( (array) ( $env['excludes'] ?? [] ) ) ];
		$info = $info_for( $env );
		if ( is_wp_error( $info ) ) { $e['error'] = $info->get_error_message(); }
		else {
			$e['reachable'] = true;
			$e['remote_version'] = (string) ( $info['plugin'] ?? '' );
			$e['version_ok'] = $e['remote_version'] === '' ? null : version_compare( $e['remote_version'], $ctx['hub_version'], '>=' );
			$e['auth_via'] = $info['auth_via'] ?? null;
			if ( ! empty( $info['lock']['job'] ) ) {
				$st = $info['lock']['started'] ?? null;
				$e['remote_lock'] = [ 'job' => $info['lock']['job'], 'started' => $st, 'age_minutes' => $st ? (int) floor( ( $now - $st ) / 60 ) : null ];
			}
		}
		$b = $ctx['baseline']( $env['name'] );
		$e['baseline'] = $b + [ 'age_days' => $b['created_at'] ? (int) floor( ( $now - $b['created_at'] ) / 86400 ) : null ];
		$e['interrupted_pull'] = $ctx['pull_state']( $env['name'] );
		return $e;
	}

	private static function next_for( $name, array $env, array $e, array $ctx ) {
		$cmd = function ( $c, $why ) use ( $name ) { return [ 'command' => $c, 'why' => $why, 'env' => $name ]; };
		if ( ! $e['reachable'] ) return $cmd( "wp envsync env add {$name} --token=<new token>", "cannot reach {$env['url']}: {$e['error']}" );
		if ( $e['version_ok'] === false ) return $cmd( "upload the release zip to {$env['url']}", "remote runs {$e['remote_version']}, hub runs {$ctx['hub_version']}" );
		$lock = $e['remote_lock'];
		if ( $lock && ( $lock['age_minutes'] === null || $lock['age_minutes'] >= self::LOCK_STALE_MIN ) ) return $cmd( "wp envsync unlock {$name}", 'a push started ' . ( $lock['age_minutes'] === null ? 'some time' : $lock['age_minutes'] . ' minutes' ) . ' ago never finished' );
		if ( $e['interrupted_pull'] ) return $cmd( "wp envsync pull {$name}", 'an interrupted pull can be resumed (or start over with --fresh)' );
		if ( empty( $e['baseline']['created_at'] ) ) return $cmd( "wp envsync pull {$name}", 'no baseline: pull before any push' );
		if ( $e['baseline']['age_days'] >= self::BASELINE_OLD_DAYS ) return $cmd( "wp envsync diff {$name}", "baseline is {$e['baseline']['age_days']} days old; consider pulling first" );
		return $cmd( "wp envsync diff {$name}", 'ready' );
	}

	public static function render_text( array $r ) {
		$o = [];
		if ( $r['role'] === 'unconfigured' ) {
			$o[] = 'This site is not set up for EnvSync yet.';
			$o[] = '  If this is a remote (production/staging): run  wp envsync token  and copy the token to your hub.';
			$o[] = '  If this is the hub (your local site):     run  wp envsync env add prod https://client.com --token=…';
			return implode( "\n", $o ) . "\n";
		}
		if ( $r['role'] === 'remote' ) {
			$o[] = 'This site is a remote (token issued). Run pull, diff and push from the hub.';
			if ( $r['token']['shown_pending'] ) $o[] = '  The token has not been read yet: wp envsync token';
			return implode( "\n", $o ) . "\n";
		}
		$d = function ( $t ) { return $t ? date( 'Y-m-d H:i', (int) $t ) : '-'; };
		foreach ( $r['envs'] as $name => $e ) {
			$o[] = "{$name}  {$e['url']}  ({$e['label']})";
			if ( ! $e['reachable'] ) { $o[] = "  unreachable: {$e['error']}"; }
			else {
				$via = $e['auth_via'] === 'x-envsync-token' ? 'X-Envsync-Token' : 'Authorization';
				$o[] = "  remote {$e['remote_version']}  hub {$r['hub_version']}  auth via {$via}" . ( $e['version_ok'] === false ? '  (remote is older)' : '' );
			}
			$b = $e['baseline'];
			$line = '  baseline ' . ( $b['created_at'] ? $d( $b['created_at'] ) . " ({$b['age_days']} days)" : 'none' );
			if ( $b['partial_at'] ) $line .= ' · partial ' . $d( $b['partial_at'] ) . " ({$b['partial_scope']})";
			$o[] = $line;
			if ( $p = $e['interrupted_pull'] ) $o[] = '  interrupted pull: started ' . $d( $p['started'] ) . ( $p['table'] ? ", stopped in {$p['table']}" : ', tables done' ) . ", {$p['files_done']}/" . ( $p['files_total'] ?? '?' ) . ' files';
			if ( $l = $e['remote_lock'] ) $o[] = "  lock: job {$l['job']}, " . ( $l['age_minutes'] === null ? 'unknown age' : "{$l['age_minutes']} min old" );
			$o[] = '';
		}
		$o[] = "Next: {$r['next']['command']}   ({$r['next']['why']})";
		return implode( "\n", $o ) . "\n";
	}
}
```

`array_key_last()` is PHP 7.3+, fine. The bootstrap needs `WP_Error`/`is_wp_error` (already stubbed in 0.3.0).

- [ ] **Step 5: Run** `vendor/bin/phpunit` → all green (12 new). phpcs.

- [ ] **Step 6: Commit**

```bash
git add includes/class-ixes-status.php tests/StatusTest.php tests/bootstrap.php
git commit -m "feat: IXES_Status report builder with ordered next rules"
```

---

### Task 6: `wp envsync status`

**Files:**
- Modify: `includes/class-ixes-cli.php`

- [ ] **Step 1: Add the command**

```php
	/**
	 * Show this site's role, each environment's state, and the one recommended next command.
	 * ## OPTIONS
	 *
	 * [<env>]
	 * : Only this environment.
	 *
	 * [--json]
	 * : Machine-readable report (what agents should read).
	 */
	public function status( $args, $assoc ) {
		$r = IXES_Status::build( $args[0] ?? null );
		if ( ! empty( $assoc['json'] ) ) { WP_CLI::line( wp_json_encode( $r, JSON_PRETTY_PRINT ) ); return; }
		WP_CLI::line( IXES_Status::render_text( $r ) );
	}
```

- [ ] **Step 2:** `php -l`, phpcs. Manual check on the live hub: `wp --path=/var/www/html/others/mkadventure envsync status` prints one block for `prod` and a `Next:` line (prod runs 0.3.0 until the zip is uploaded, so expect the "upload the release zip" rule). Commit:

```bash
git add includes/class-ixes-cli.php
git commit -m "feat(cli): wp envsync status with --json"
```

---

### Task 7: Admin "Status" panel

**Files:**
- Modify: `admin/class-ixes-admin.php` (`page()`, new `status_section()`)

- [ ] **Step 1: Implement**

In `page()`, right after `echo '<div class="wrap"><h1>EnvSync</h1>';` and the error notice, call `self::status_section();`. Add:

```php
	/** Same report as `wp envsync status`, cached 60 s so a dead remote cannot slow the page. */
	private static function status_section() {
		$r = get_transient( 'ixes_status_report' );
		if ( ! is_array( $r ) ) { $r = IXES_Status::build(); set_transient( 'ixes_status_report', $r, 60 ); }
		echo '<h2>Status</h2>';
		$roles = [ 'hub' => 'This site is the hub: you run pull, diff and push from here.', 'remote' => 'This site is a remote: commands run from your hub.', 'both' => 'This site is both a hub and a remote.', 'unconfigured' => 'This site is not set up yet.' ];
		echo '<p>' . esc_html( $roles[ $r['role'] ] ) . '</p>';
		if ( $r['envs'] ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Environment</th><th>Reachable</th><th>Version</th><th>Baseline</th><th>Interrupted pull</th><th>Lock</th></tr></thead><tbody>';
			foreach ( $r['envs'] as $name => $e ) {
				$b = $e['baseline'];
				printf( '<tr><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $name ), esc_html( $e['url'] ),
					$e['reachable'] ? 'yes' : '<span style="color:#b32d2e">no: ' . esc_html( (string) $e['error'] ) . '</span>',
					esc_html( $e['remote_version'] ?: '?' ) . ( $e['version_ok'] === false ? ' <em>(older than hub)</em>' : '' ),
					esc_html( $b['created_at'] ? wp_date( 'Y-m-d H:i', $b['created_at'] ) . " ({$b['age_days']} d)" : 'none' ) . ( $b['partial_at'] ? '<br><small>partial ' . esc_html( wp_date( 'Y-m-d H:i', $b['partial_at'] ) . " ({$b['partial_scope']})" ) . '</small>' : '' ),
					$e['interrupted_pull'] ? esc_html( "{$e['interrupted_pull']['files_done']}/" . ( $e['interrupted_pull']['files_total'] ?? '?' ) . ' files' ) : '—',
					$e['remote_lock'] ? esc_html( "job {$e['remote_lock']['job']}, " . ( $e['remote_lock']['age_minutes'] === null ? '?' : $e['remote_lock']['age_minutes'] ) . ' min' ) : '—'
				);
			}
			echo '</tbody></table>';
		}
		if ( ! empty( $r['next']['command'] ) ) echo '<div class="notice notice-info inline"><p><strong>Next:</strong> <code>' . esc_html( $r['next']['command'] ) . '</code> &mdash; ' . esc_html( $r['next']['why'] ) . '</p></div>';
	}
```

Also invalidate the cache where state changes on this site: in `IXES_CLI` after a successful `pull`, `push`, `unlock` and `env add/remove`, call `delete_transient( 'ixes_status_report' );` (one helper `private function forget_status()`).

- [ ] **Step 2:** `php -l`, phpcs. Open Tools → EnvSync on the live hub and confirm the panel renders above the token section. Commit:

```bash
git add admin/class-ixes-admin.php includes/class-ixes-cli.php
git commit -m "feat(admin): Status panel with the recommended next command"
```

---

### Task 8: README, skill, version

**Files:**
- Modify: `README.md` (rewrite), `skills/wp-envsync/SKILL.md` (rewrite top), `ix-wp-envsync.php` (0.4.0)

- [ ] **Step 1: README** — rewrite in the spec's eight-section order. Keep every existing command table and the 0.3.0 sections ("Sync only part of a site", "If a pull is interrupted") verbatim where they fit. New section 1 uses this drawing:

```
   your laptop (hub)                    client hosting (remote)
   ┌──────────────────┐   pull  ◄──     ┌──────────────────┐
   │ WordPress + WP-CLI│                 │ WordPress + token │
   │ runs the commands │   push  ──►     │ answers over REST │
   └──────────────────┘                 └──────────────────┘
```

Section 3 pairs each concept with the diff line that shows it, e.g. "prod wins" with `CONFLICTS (prod wins)`; section 7 pairs each stuck state with its `status` line (`lock: job …`, `auth via X-Envsync-Token`, `(remote is older)`). Style: sentences under 20 words, one idea each, no term before its output.

- [ ] **Step 2: Skill** — replace the opening with:

```markdown
## Start here

Run `wp envsync status --json` and act on `next.command`. Do not compose pull/diff/push from memory when `status` already says what to do.

| next.command | tell the human before running it |
|---|---|
| `wp envsync pull <env>` | this overwrites the local site's database and files with the remote's |
| `wp envsync push <env>` | this changes production; show the diff output first |
| `wp envsync unlock <env>` | a push died on the remote; nothing is rolled back; `rollback` still restores that job |
| `wp envsync env add …` | needs a token from the remote's Tools → EnvSync page |
| upload the release zip | the remote runs an older plugin; a human uploads the zip through Plugins → Add New |

Never run `push` or `unlock` without stating what `status` reported.
```

Keep the 0.3.0 flag table below it.

- [ ] **Step 3: Version** `0.4.0` in header and `IXES_VERSION`. phpcs, phpunit (the bootstrap defines `IXES_VERSION` only if undefined, so tests are unaffected). Commit:

```bash
git add README.md skills/wp-envsync/SKILL.md ix-wp-envsync.php
git commit -m "docs: README around status, agent skill starts with status; bump to 0.4.0"
```

---

### Task 9: Integration scenarios

**Files:**
- Modify: `tests/integration.sh` (append before `echo "ALL OK"`)

- [ ] **Step 1: Scenarios**

```bash
# 10. status reports an interrupted pull and recommends resuming
dd if=/dev/urandom of="$IXES_A/wp-content/uploads/big2.bin" bs=1M count=200 status=none
rm -f "$IXES_B/wp-content/uploads/big2.bin" "$IXES_B/wp-content/uploads/big2.bin.ixes-tmp"
wp --path="$IXES_B" --url="$IXES_B_URL" envsync pull prod --fresh --yes >/dev/null 2>&1 &
PULL_PID=$!
for _ in $(seq 1 600); do [ -f "$IXES_B/wp-content/uploads/big2.bin.ixes-tmp" ] && break; sleep 0.1; done
kill -9 $PULL_PID 2>/dev/null || true; wait $PULL_PID 2>/dev/null || true
OUT=$(B envsync status prod)
echo "$OUT" | grep -q "interrupted pull" || die "status did not report the interrupted pull:\n$OUT"
echo "$OUT" | grep -q "Next: wp envsync pull prod" || die "status did not recommend resuming"
B envsync status --json | php -r '$j=json_decode(stream_get_contents(STDIN),true); exit($j["next"]["command"]==="wp envsync pull prod"?0:1);' || die "status --json next mismatch"
B envsync pull prod --yes >/dev/null
cmp "$IXES_A/wp-content/uploads/big2.bin" "$IXES_B/wp-content/uploads/big2.bin" || die "resume after status failed"

# 11. a push killed mid-way leaves a lock; status reports it; unlock clears it; the next push works
B post update "$PX" --post_content="x-lock-test" >/dev/null
dd if=/dev/urandom of="$IXES_B/wp-content/uploads/pushbig.bin" bs=1M count=200 status=none
wp --path="$IXES_B" --url="$IXES_B_URL" envsync push prod --yes >/dev/null 2>&1 &
PUSH_PID=$!
for _ in $(seq 1 600); do [ -f "$IXES_A/wp-content/uploads/pushbig.bin.ixes-tmp" ] && break; sleep 0.1; done
kill -9 $PUSH_PID 2>/dev/null || true; wait $PUSH_PID 2>/dev/null || true
OUT=$(B envsync status prod)
echo "$OUT" | grep -q "lock: job" || die "status did not report the remote lock:\n$OUT"
B envsync unlock prod --yes 2>&1 | grep -q "too_recent\|only" && { sleep 125; B envsync unlock prod --yes >/dev/null; }   # lock younger than 2 min is refused by design
[ -f "$IXES_A/.maintenance" ] && die "unlock left .maintenance behind"
B envsync push prod --yes >/dev/null || die "push after unlock failed"
[ "$(A post get "$PX" --field=post_content)" = "x-lock-test" ] || die "push after unlock did not apply"

# 12. Authorization header stripped: the X-Envsync-Token fallback carries the request
wp --path="$IXES_A" config set ENVSYNC_TEST_DROP_AUTHORIZATION true --raw >/dev/null
B envsync env ping prod | grep -q "auth via X-Envsync-Token" || die "ping did not report the fallback carrier"
B envsync pull prod --fresh --yes >/dev/null || die "pull failed with Authorization stripped"
wp --path="$IXES_A" config delete ENVSYNC_TEST_DROP_AUTHORIZATION >/dev/null
```

Scenario 12 needs a test-only hook on the remote: in `IXES_Rest::auth()`, when `defined( 'ENVSYNC_TEST_DROP_AUTHORIZATION' ) && ENVSYNC_TEST_DROP_AUTHORIZATION`, pass `''` instead of the Authorization header to `token_from_headers()`. Add that one line (with a comment saying it simulates a host that strips the header) in this task.

For scenario 11: the kill must land while the 200 MB file is being pushed, which takes long enough that the lock is under 2 minutes old when `unlock` first runs; the script tolerates the refusal and waits. If the pair is fast enough that the whole scenario runs in under 2 minutes anyway, that wait is the cost.

- [ ] **Step 2: Run** the whole suite on the pair (start both servers first). Expected: `ALL OK`. Commit:

```bash
git add tests/integration.sh includes/class-ixes-rest.php
git commit -m "test(integration): status on an interrupted pull, unlock after a dead push, token fallback"
```

---

## Self-review

**Spec coverage.** §1 report shape and rule table → Task 5 (`build`, `next_for`, `RULES`); `/info` `lock` + `auth_via` → Task 2. §2 stuck lock → Tasks 2–3; header fallback → Task 1 (+ `ping` in Task 3); version skew → Task 3 (`ping`) and Task 5 (rule 4); malformed `job_start` + admin guard → Task 4; multisite → Task 4. §3 CLI → Task 6; admin panel → Task 7; README + skill → Task 8. §4 tests: StatusTest (T5), AuthTest (T1), ApplierLockTest covers the meta-shape helper (T4; the spec's "ApplierMetaTest" lives there), integration (T9). §5 rollout: `env_facts()` tolerates missing `lock`/`auth_via` (0.3 remote); 0.3 hub ignores the new `/info` keys. ✔

**Type consistency.** `IXES_Auth::token_from_headers()` returns `[ token, via ]` (T1) and `auth()` destructures it the same way. `IXES_Applier::lock_info()` shape `[ 'job', 'started' ]` (T2) matches what `IXES_Status::env_facts()` reads and what `unlock` CLI reads (T3). `IXES_Status::build( $env, $info_for, $ctx )` (T5) called with one arg in T6/T7. `plan_meta_shape()` defined in T4 before use. ✔

**Placeholders.** README rewrite (T8 step 1) is described by section with the drawing and the pairing rule rather than full prose; the executing implementer writes the prose from the spec's §3 list. No TBDs.
