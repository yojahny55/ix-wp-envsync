# Status command, operational fixes, and onboarding — design

Target version: 0.4.0. Baseline: v0.3.0.

## Why

Two rounds of feedback that share one cause. Operators get stuck on things the plugin knows but does not say: a push died and the remote lock stays for an hour with no way out; a host strips the `Authorization` header and every command says "missing token"; hub and remote run different versions and nobody notices. New users, human or AI agent, cannot tell what state a site is in or what to run next, so the README has to explain the whole model up front.

The fix for both is one idea: the plugin explains its own state. A `status` report gathers the facts, decides the single recommended next command, and is rendered three ways: CLI text for humans, `--json` for agents, and a panel on the admin page. The operational fixes each add one fact to that report and one action to resolve it.

Out of scope: token via wp-config constant, `--token=-` from stdin, salt-independent token hashing, audit log, interactive setup wizard.

## 1. The status report

### `IXES_Status::build( $env_name = null )`

Pure report builder. Takes an optional env name (default: all registered envs). Uses `IXES_Client` for the one `/info` call per env; the info source is injectable for tests.

```
role:      'hub' | 'remote' | 'both' | 'unconfigured'
           hub  = at least one env registered
           remote = ixes_token_hash option present
           both = both; unconfigured = neither
hub_version: IXES_VERSION
token:     { issued: bool, shown_pending: bool }        # remote-side facts
envs:      { <name>: {
             url, label,
             reachable: bool, error: string|null,
             remote_version: string|null, version_ok: bool|null,
             baseline: { created_at: int|null, partial_at: int|null, partial_scope: string|null, age_days: int|null },
             interrupted_pull: { started, table, cursor, files_done, files_total } | null,
             remote_lock: { job, started, age_minutes } | null,
             excludes_count: int
           } }
next:      { command: string, why: string, env: string|null }   # exactly one
```

### `next` rules

The first matching rule wins. Evaluated per env in registration order; the first env that produces a rule other than the last one decides.

| # | Condition | command | why |
|---|---|---|---|
| 1 | role unconfigured | `wp envsync token` (if this will be a remote) or `wp envsync env add <name> <url> --token=…` (if this is the hub) | this site is not set up yet |
| 2 | role remote only | `wp envsync token` if not yet shown, else none | this site is a remote; commands run from the hub |
| 3 | env unreachable | `wp envsync env add <name> --token=<new>` | cannot reach <url>: <error> |
| 4 | remote_version < hub_version | upload the release zip to <url> | remote runs <v>, hub runs <v> |
| 5 | remote_lock age ≥ 10 min | `wp envsync unlock <name>` | a push started <age> ago never finished |
| 6 | interrupted_pull | `wp envsync pull <name>` (or `--fresh`) | an interrupted pull can be resumed |
| 7 | no baseline (created_at null) | `wp envsync pull <name>` | no baseline: pull before any push |
| 8 | baseline age ≥ 7 days | `wp envsync diff <name>` | baseline is <n> days old; consider pulling first |
| 9 | otherwise | `wp envsync diff <name>` | ready |

Version comparison uses `version_compare()`. A remote lock younger than 10 minutes is reported but does not change `next`.

### Remote-side additions to `/info`

- `lock: { job, started } | null` from the `ixes_lock` transient and the job's `meta.json` `started` value (null when no lock).
- `auth_via: 'authorization' | 'x-envsync-token'` (also returned by `/ping`).

Nothing new is hashed; `status` costs one HTTP call per env.

## 2. Operational fixes

### Stuck lock

- New route `POST /job/unlock`. Clears the `ixes_lock` transient and removes `.maintenance`. Rolls nothing back. Refuses with 409 `too_recent` when the lock is younger than 2 minutes (a live push). Returns `{ ok, job, age_minutes }`.
- `wp envsync unlock <env>`: shows the lock's job id and age, confirms (`--yes` skips), calls the route. Prints a reminder that the last push's snapshot is still there for `rollback`.
- `job_start` also writes `started` into the lock transient value as `job|started` so age is known even if `meta.json` is unreadable.

### Authorization header stripped by the host

- `IXES_Client::request()` sends the token in both `Authorization: Bearer <token>` and `X-Envsync-Token: <token>`.
- `IXES_Rest::auth()` reads `Authorization` first; if absent or not `Bearer`, reads `X-Envsync-Token`. Token hash, timestamp and signature checks are unchanged. The header actually used is remembered for the request and returned by `/ping` and `/info` as `auth_via`.
- `wp envsync env ping <env>` prints `auth via Authorization` or `auth via X-Envsync-Token (this host strips the Authorization header; that is fine)`.

### Version skew

- `status` and `ping` compare `/info` `plugin` with `IXES_VERSION`. Older remote: rule 4 above and a `ping` warning. Newer remote: a `ping` note ("remote is newer; update the hub"). No hard refusal; `caps` keeps mixed versions working.

### Malformed `job_start`

- `IXES_Applier::job_start()` casts `$p['plan_meta']`, `['tables']`, `['files']`, `['files']['push']`, `['files']['delete']` with `(array)` before use and stores the cast copy in `meta.json`.
- `admin/class-ixes-admin.php` guards the "Last received push" `count()` with `is_array`.

### Multisite

- Activation hook: `if ( is_multisite() ) wp_die( 'EnvSync does not support multisite yet.' )` before anything is installed.

## 3. Surfaces

### CLI

`wp envsync status [<env>] [--json]`

Text, per env:

```
prod  https://client.com  (prod)
  remote 0.4.0  hub 0.4.0  auth via Authorization
  baseline 2026-09-12 14:03 (10 days) · partial 2026-09-22 (themes)
  interrupted pull: started 2026-09-22 10:15, stopped in wp_postmeta, 512/2300 files
  lock: job 20260922-101500-ab12cd, 47 min old

Next: wp envsync unlock prod   (a push started 47 minutes ago never finished)
```

Unconfigured site:

```
This site is not set up for EnvSync yet.
  If this is a remote (production/staging): run  wp envsync token  and copy the token to your hub.
  If this is the hub (your local site):     run  wp envsync env add prod https://client.com --token=…
```

`--json` prints the report array. Exit code is 0 in every case; `next` is advice, not an error.

### Admin page

A "Status" panel at the top of Tools → EnvSync, rendered from the same array: role line, one row per env (reachable, versions, baseline, interrupted pull, lock), and the `next` line as a highlighted notice. Cached 60 seconds in a transient like the dir sizes, so a dead remote cannot slow the page. Existing token and excludes sections stay below.

### README

Rewritten in the order a new user meets things:

1. Which site is which (hub vs remote, four sentences, an ASCII drawing).
2. Install: three steps, the last is `wp envsync status`.
3. How it thinks: baseline, pull before push, prod wins, conflicts. Two sentences each, each followed by the exact diff-output line that shows it.
4. Reading a diff: the plan table with every column explained.
5. Day to day: the four commands.
6. Sync only part of a site; If a pull is interrupted (from 0.3.0, unchanged).
7. When something is stuck: unlock; "missing token" on hosts that strip the header; version mismatch. Each with the `status` line that reveals it.
8. Commands reference.

Style rules: short sentences, one idea each, no jargon before its output has been shown.

### Agent skill

`skills/wp-envsync/SKILL.md` opens with: run `wp envsync status --json` first; act on `next`. Then the flag table from 0.3.0. Then a table mapping each `next.command` to what the agent must tell the human before running it:

| next.command | tell the human |
|---|---|
| `pull` | this overwrites the local site's database and files with production |
| `push` | this changes production; show the diff first |
| `unlock` | a push died on production; nothing is rolled back; `rollback` is available |
| `env add` | needs a token from the remote's Tools → EnvSync page |

Agents never run `push` or `unlock` without stating what `status` reported.

## 4. Testing

Unit (no WordPress):

- `StatusTest`: `IXES_Status::build()` with a stub info source, one case per `next` rule in order, plus "lock younger than 10 min does not change next" and "first env with a non-default rule wins".
- `AuthTest`: token accepted from `X-Envsync-Token` when `Authorization` absent; `Authorization` wins when both present; `auth_via` reflects the carrier.
- `ApplierMetaTest`: the `job_start` meta shape survives a string `plan_meta` (pure helper extracted for the cast).

Integration (`tests/integration.sh`):

- `status` on the hub reports an interrupted pull and `next` names `pull`.
- A push killed mid-way leaves a lock; `status` reports it; `unlock` clears it; a following push succeeds.
- With a constant on A that makes the remote ignore `Authorization`, `ping` reports `auth via X-Envsync-Token` and a pull still works.

## 5. Rollout

0.4.0 on both sides. A 0.4 hub against a 0.3 remote: no `lock`/`auth_via` in `/info`, so `status` shows "unknown" for those and rule 4 fires (upload the zip). A 0.3 hub against a 0.4 remote works unchanged.
