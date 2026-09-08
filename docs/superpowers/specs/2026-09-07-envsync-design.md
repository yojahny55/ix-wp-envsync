# ix-wp-envsync — Design Spec

Date: 2026-09-07
Status: approved in brainstorming, pending implementation plan

## Problem

Freelance support for WordPress sites on heterogeneous hosts (Hostinger, IONOS, Plesk on Lightsail, Coolify). Current workflow: All-in-One migration export, URL rewrite, import locally, change, re-export, import to staging, client review, re-export, import to prod. Slow, manual, and a full prod overwrite risks losing data prod created meanwhile (orders, form entries, client edits, users).

## Goal

One plugin that syncs DB + files between local, staging and prod in every direction, with:

- prod data always prevailing over local/staging on conflict,
- a mandatory human-readable plan shown before anything moves,
- no requirement for SSH or WP-CLI on client hosts,
- resumable, chunked transfers that survive shared-host PHP limits.

## Non-goals (YAGNI)

Multisite, remote-to-remote sync without a hub, scheduled/auto sync, S3/offloaded media, per-user UI, replacing full backups.

## Workflow model

- **Pull** (prod → local, prod → staging, local → staging): full overwrite of target from source, URL rewrite, then record a **baseline** on the hub.
- **Push to prod** (local → prod, staging → prod): 3-way diff against the baseline. Local-changed rows/files go up; prod-changed stay; both-changed = prod wins and is reported.
- **ID collisions** avoided by bumping `AUTO_INCREMENT` on the hub after each pull (+1,000,000 on posts, postmeta, terms, term_taxonomy, comments, users). Prod is never offset.

## 1. Roles and environments

One plugin, installed on every site. Role by config:

- **Hub**: the site driving a sync (normally local). Has WP-CLI commands, admin page, baseline store, HTTP client.
- **Remote**: any site being pulled from or pushed to. Exposes REST only, plus a settings page showing/rotating its token.

Any site can be hub or remote; the command is run from the source site. Hub stores environments in one option `ixes_envs`: `{ name, url, token, label(prod|staging|local), extra_replace: [[from,to],...], excludes: [] }`.

CLI: `wp envsync env add <name> <url> --token=... [--label=prod]`, `env list`, `env remove`.

## 2. Auth and transport

- Token: 32 random bytes, generated on remote at activation, shown once, stored hashed (`wp_hash`). Header `Authorization: Bearer <token>`.
- Signing: headers `X-Envsync-Ts` and `X-Envsync-Sig = HMAC-SHA256(token, method|path|ts|sha256(body))`. Reject if |now − ts| > 300 s or signature mismatch. Constant-time compare.
- HTTPS required; `ENVSYNC_ALLOW_HTTP` constant permits plain HTTP for `.local` hubs.
- Endpoints ignore cookies/nonces entirely; token is the only credential.
- Every remote call is a bounded step with a cursor:
  - `GET  /envsync/v1/info` — WP version, table prefix, tables + row counts, PHP limits, site URL, abspath, hash algo.
  - `POST /envsync/v1/hash/rows` — `{table, from_pk, limit}` → `{rows: {pk: hash}, next}`.
  - `POST /envsync/v1/hash/files` — `{cursor}` → `{files: {path: hash}, next}`.
  - `POST /envsync/v1/dump` — `{table, from_pk, limit}` → rows as JSON.
  - `POST /envsync/v1/file/get` — `{path, offset, size}` → base64 chunk + sha256 of full file.
  - `POST /envsync/v1/job/start|step|finish|abort` — apply on remote (section 5).
  - `POST /envsync/v1/rollback` — `{job}`.
- Chunk sizes: 5,000 rows, 2 MB file chunks; hub halves them if a step exceeds 10 s.
- Locking: remote transient `ixes_lock` per job; a second hub gets HTTP 423.
- Reuse: read `wp-ixflare-migrate` chunker and serialized find/replace; lift if solid.

## 3. Pull / overwrite

Steps, all resumable by job id stored on the hub:

1. `info` + file manifest from source.
2. Show plan: tables + row counts, file count and MB delta vs target manifest, URL/path pairs, excludes. Require confirm or `--yes`.
3. Dump tables in PK chunks into `<prefix>_ixes_tmp_<table>` on target; `RENAME TABLE` swap atomically per table at the end.
4. Find/replace during import: `source.url → target.url`, `source.abspath → target.abspath`, plus env `extra_replace`. Serialized-safe (recursive unserialize/replace/serialize, fallback string replace on failure).
5. Files: transfer only paths whose hash differs from target manifest; delete target files absent on source inside synced dirs.
6. Default excludes: `cache/`, `wp-config.php`, `.htaccess`, `.env`, `debug.log`, `object-cache.php`, `advanced-cache.php`, `wp-content/envsync/`, `ixes_*` options/tables. Editable per env.
7. Baseline (hub only): write `baseline-<env>.sqlite` in the storage dir with tables `rows(table, pk, hash)` and `files(path, hash)`. Row hashing normalises URLs (scheme-full, JSON-escaped and protocol-relative), abspath, and each env `extra_replace` value to placeholders before hashing; both sides receive their own side of each pair so hashes compare equal.
8. ID offset on hub (see model).
9. Flush rewrite rules, object cache, and set `siteurl`/`home` to target.

## 4. Diff engine (push to prod)

Inputs per table: `base`, `local`, `remote` as `pk → hash`; per file: `path → hash`. Pure function, no IO.

| base | local | remote | result |
|---|---|---|---|
| = | = | = | untouched |
| ≠ local | changed | = base | push |
| = local | = | changed | keep prod |
| ≠ | changed | changed, ≠ local | conflict → prod wins, reported |
| absent | present | absent | insert |
| absent | absent | present | keep prod |
| present | absent | = base | delete |
| present | absent | changed | keep prod, reported |
| changed | changed | = local | untouched (already equal) |

Tables without a single-column PK (composite or none): hash full row, set semantics: insert new local rows, never delete on prod.

Options table:
- never pushed: `siteurl`, `home`, `_transient_*`, `_site_transient_*`, `cron`, `ixes_*`, `recently_activated`.
- `active_plugins`: apply local's (activations − deactivations since base) onto prod's list.

Apply-time staleness: every pushed/deleted row carries the remote hash the plan saw (`null` for inserts); the remote rehashes and skips rows that changed or appeared since, reporting them as stale. Files carry the expected remote hash on their first chunk and are refused the same way.

No baseline for this env: 2-way diff local vs remote, everything shown as "overwrite", push refuses without `--force`.

## 5. Plan, preview, apply

`wp envsync diff <env>`: builds plan, prints per-table counts (push/insert/delete/prod-wins/kept-prod), per-dir file counts, and a CONFLICTS block with local/prod modified dates. Saves `wp-content/envsync/plan-<env>-<ts>.json`. Flags: `--verbose` (row level), `--table= --id=` (field diff), `--json`.

`wp envsync push <env>`: rebuilds plan, prints, confirms (or `--yes`), applies. `--plan=<file>` applies a saved plan, refusing if any planned row's remote hash has changed. `--dry-run` on both.

Apply order on remote, each step idempotent and resumable:
1. snapshot touched rows/files → `wp-content/envsync/rollback-<job>.tar`
2. maintenance mode on
3. files (write to `.ixes-tmp`, rename)
4. DB rows in FK-safe order: users, terms, term_taxonomy, posts, postmeta, term_relationships, comments, commentmeta, other tables, options last
5. flush rewrite rules + object cache
6. maintenance mode off

Admin page on hub: env list, last five plans with outcome, read-only plan table. Remote admin page: token show/rotate, last job log.

## 6. Safety and rollback

- `wp envsync rollback <env> [--job=]` restores the snapshot from a job; last three kept.
- Never-pushed list (section 3 excludes) enforced in code on the remote, not only on the hub.
- Storage dir `wp-content/envsync-<16 hex random>/` (suffix in option `ixes_storage_suffix`) so snapshots and plans are not enumerable on hosts where `.htaccess` is inert; also `index.php` + `.htaccess` deny.
- The `active_plugins` option is snapshotted before the option step and restored on rollback.
- The remote token grants write access to the DB and wp-content (plugin PHP); documented as an admin-equivalent credential.
- Explicit doc note: not a backup tool.

## 7. Structure

```
ix-wp-envsync/
  ix-wp-envsync.php
  includes/
    class-env.php        env registry
    class-auth.php       token + HMAC (both sides)
    class-rest.php       remote endpoints
    class-client.php     hub HTTP client, chunk/resume loop
    class-hasher.php     row/file hashing, URL normalisation
    class-baseline.php   sqlite read/write
    class-differ.php     3-way classification (pure)
    class-transfer.php   dump/import/find-replace/file chunks
    class-planner.php    plan build + render (text/json)
    class-applier.php    apply plan, snapshot, rollback
    class-cli.php        wp envsync commands
  admin/                 hub + remote settings pages
  tests/                 PHPUnit for differ + hasher; integration script
```

## 8. Testing

- PHPUnit: `class-differ` against every row of the table in section 4 plus `active_plugins` merge; `class-hasher` URL normalisation incl. serialized strings and JSON in postmeta.
- Integration script `tests/integration.sh`: two local WP installs (A = "prod", B = hub). Pull A→B; edit post X + theme file on B; edit post Y on A; push B→A; assert A has both edits and the conflict report is empty. Then edit post X on both; push; assert A keeps its version and plan lists X under CONFLICTS.

## Decisions with chosen defaults

- Hash: `xxh128` when PHP ≥ 8.1 on both sides, else `sha1`. Algo negotiated in `info`.
- Baseline store: sqlite via PDO; fallback to a JSON file if PDO sqlite is missing on the hub.
- Minimum PHP 7.4 on remotes, 8.1 on hub.
- v0.1 requires identical table prefixes on both sides (detected at plan time, errors out). Prefix translation deferred.
