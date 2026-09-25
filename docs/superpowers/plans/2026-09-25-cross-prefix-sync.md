# Cross-prefix sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a hub and a remote with different `$table_prefix` pull, diff, push, roll back and first-deploy, with user roles intact on both sides.

**Architecture:** The hub keeps working in its own table names. It announces its prefix in a signed `X-Envsync-Prefix` header once the remote advertises `prefix_map`. The remote sets a request-scoped `IXES_Prefix` and translates table names, `<prefix>user_roles` and prefixed usermeta keys at its REST boundary, before hashing and before writing.

**Tech Stack:** PHP 7.4+, WordPress, WP-CLI, PHPUnit 9.

**Spec:** `docs/superpowers/specs/2026-09-25-cross-prefix-sync-design.md`

## Global Constraints

- Version 0.6.0 in the plugin header and `IXES_VERSION`.
- Equal prefixes: no header, signature byte-identical to 0.5.7.
- Prefix format: `/^[A-Za-z0-9_]+$/`; anything else is `400 bad prefix`.
- Translated values: only `options.option_name` `<prefix>user_roles` and `usermeta.meta_key`.
- Rescue calls never carry the prefix header.

## Review Focus

- Overlapping prefixes (`wp_` hub, `wp_abc_` remote and the reverse): exact round trip, no double prefixing. Pinned in Task 1.
- A leftover `<hub prefix>capabilities` row on the remote (orphan) must never reach the hub as a real key. Pinned in Task 1 (`row_out` orphan) and Task 3 (dump drops it).
- A hub row whose key carries the remote's prefix must not be promoted to a real remote key on push. Pinned in Task 1 (`step_in` refused).
- A remote on 0.5.x with a different prefix: `pull`, `diff` and `push` refuse before any change. Pinned in Task 4.
- A tampered prefix header must fail the signature. Pinned in Task 2.

---

### Task 1: `IXES_Prefix` translation class

**Files:**
- Create: `includes/class-ixes-prefix.php`
- Test: `tests/PrefixTest.php`

**Interfaces:**
- Produces: `new IXES_Prefix( string $local, string $peer )`; `table_in( $peer_name ): ?string`; `table_out( $local_name ): ?string`; `bare( $local_name ): string`; `row_in( $bare, array $row ): ?array`; `row_out( $bare, array $row ): ?array`; `sql_in( $sql ): string`; `step_in( array $p ): array` (adds `prefix_refused` for `rows`); `start_in( array $p ): array`; static `current(): ?IXES_Prefix`, `set_current( ?IXES_Prefix )`, `valid( $prefix ): bool`.

- [ ] **Step 1: Write the failing tests** covering: table in/out and null for foreign names; the usermeta key rule both ways; orphans (`row_out` of `wp_capabilities` on a `ab_` site talking to a `wp_` hub is null); `user_roles` and its orphan; overlapping `wp_`/`wp_abc_` round trip in both directions; untouched tables; `sql_in` for `CREATE TABLE` and `REFERENCES`; `step_in` for `rows` (orphan refused), `delete_rows`, `create_table`, `option`, unknown table becomes `''`; `start_in` re-keys `plan_meta['tables']`; `valid()`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter PrefixTest`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement** the class per the spec's key rule (check the source prefix first, then the target prefix as orphan).
- [ ] **Step 4: Run** the filter again. Expected: PASS.
- [ ] **Step 5: Commit** `feat(prefix): translation class for table names, user_roles and usermeta keys`.

### Task 2: signed prefix header, remote context

**Files:**
- Modify: `includes/class-ixes-auth.php` (`sign`, `verify` gain `$prefix = ''`)
- Modify: `includes/class-ixes-rest.php` (`auth`, `hash_rows`, `dump`, `applier`)
- Modify: `includes/class-ixes-transfer.php` (`dump` translates rows out, `info` caps add `prefix_map`)
- Modify: `includes/class-ixes-applier.php` (`stale` translates the current row out; `job_step` seeds `$refused` from `prefix_refused`)
- Test: `tests/AuthTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: `IXES_Auth::sign( $token, $method, $path, $ts, $body, $step = '', $prefix = '' )`; the same trailing `$prefix` on `verify()`. Message suffix `"\nprefix:" . $prefix` only when non-empty.

- [ ] **Step 1: Failing tests:** a signature made with prefix `ab_` verifies with `ab_` and fails with `''` and `cd_`; without a prefix the signature equals the 0.5.7 message hash.
- [ ] **Step 2: Run** `--filter AuthTest`. Expected: FAIL.
- [ ] **Step 3: Implement** the auth change, then the REST context (`auth` validates the header, verifies with it, calls `IXES_Prefix::set_current()`), the REST table translation (`hash_rows`, `dump`), the `applier()` wrapper (`start_in` for `job_start`, `step_in` for `job_step`), `Transfer::dump` (`row_out` after the option filter, orphans dropped), `Applier::stale` (`row_out` before hashing, orphan = no row), `job_step` `$refused` seed, `prefix_map` cap.
- [ ] **Step 4: Run** the full suite. Expected: PASS.
- [ ] **Step 5: Commit** `feat(prefix): signed X-Envsync-Prefix header and remote-side translation`.

### Task 3: hub client and refusal gate

**Files:**
- Modify: `includes/class-ixes-client.php` (`info` maps names and turns the header on; `request` sends and signs it, never for rescue; public `$hub_prefix` override for tests)
- Modify: `includes/class-ixes-pull.php` (`prefix_refusal( array $info, $local ): ?WP_Error`, used in `plan`)
- Modify: `includes/class-ixes-planner.php` (`build` calls `prefix_refusal`)
- Test: `tests/ClientLoopTest.php`, `tests/PrefixTest.php`

**Interfaces:**
- Consumes: Tasks 1, 2.
- Produces: cached `info['hub_prefix']` when mapped; `info['tables'][*]['name']` in hub names.

- [ ] **Step 1: Failing tests:** after `info()` with `prefix: ab_` and cap `prefix_map`, the next `get()` carries `X-Envsync-Prefix: wp_` and a signature that verifies only with it; table names come back as `wp_*`; `rescue()` carries no header; equal prefixes or no cap send no header; `prefix_refusal` for equal, mapped and unmapped.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the full suite. Expected: PASS.
- [ ] **Step 5: Commit** `feat(prefix): hub announces its prefix and refuses an old remote with another one`.

### Task 4: status, docs, version

**Files:**
- Modify: `includes/class-ixes-status.php` (`remote_posts` via `hub_prefix`; `prefix_map` fact and text)
- Modify: `README.md`, `skills/wp-envsync/SKILL.md`, `ix-wp-envsync.php` (0.6.0)
- Test: `tests/StatusTest.php`

- [ ] **Step 1: Failing test:** a mapped `info` (`prefix: ab_`, `hub_prefix: wp_`, table `wp_posts` with 7 rows) gives `remote_posts` 7 and `prefix_map` `ab_ → wp_`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**; README requirement and first-deploy step 1 no longer demand the same prefix; skill `prefix_mismatch` entry says to upload 0.6.0 to the remote; version bump.
- [ ] **Step 4: Run** the full suite. Expected: PASS.
- [ ] **Step 5: Commit** `feat(prefix): status shows the prefix map; docs; bump to 0.6.0`.

### Task 5: integration across prefixes

**Files:**
- Modify: `tests/integration.sh` (after the cycle, assert an administrator still has the `administrator` role on both sides)

- [ ] **Step 1:** Create two throwaway installs, prefixes `ixa_` (remote) and `wp_` (hub), `ENVSYNC_ALLOW_HTTP` on both, served over HTTP.
- [ ] **Step 2:** Run `tests/integration.sh` against them. Expected: `ALL OK`.
- [ ] **Step 3:** Recreate with equal prefixes and run again. Expected: `ALL OK`.
- [ ] **Step 4: Commit** `test(integration): check roles survive the cycle`.
