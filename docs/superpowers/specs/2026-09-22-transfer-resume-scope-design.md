# Binary transfer, resumable pull, and selective sync — design

Target version: 0.3.0. Baseline: v0.2.2 (`b833e10`).

## Why

The largest site synced today is about 3 GB of `wp-content`. Pulls die mid-way on shared hosts (timeouts, 413/502 on big bodies) and restart from zero. Every file chunk travels as base64 inside JSON, a 33 % size penalty and a JSON decode of every 2.7 MB body on both sides. And a sync is all-or-nothing: there is no way to move only the theme, only uploads, or only a few tables.

Competing tools (WP Migrate, WPvivid, Duplicator) move raw bytes with tunable chunk sizes, resume after a dropped request, and let the operator pick media / themes / plugins / database. This release brings those three capabilities to EnvSync without giving up its short-step remote design.

Out of scope for this release (next round): stuck-lock recovery, Authorization-header fallback, hub/remote version gate beyond the `caps` list, multisite, onboarding for new users.

## 1. Binary transfer with adaptive chunks

### Wire format

Two routes change shape. Everything else is untouched.

**`POST /file/get`** (remote → hub). Request stays JSON: `{ "path", "offset", "size" }`. The response depends on the hub's `Accept` header:

- `Accept: application/octet-stream` → body is the raw bytes; headers `X-Envsync-Total` (file size), `X-Envsync-Size` (bytes in this chunk), `X-Envsync-Sha256` (whole-file hash from the hash cache). Errors are still JSON with the usual HTTP status.
- anything else → the current `{ data: base64, size, total, sha256 }` JSON body. A 0.2.x hub talking to a 0.3 remote keeps working.

**`POST /job/step` with `kind=file`** (hub → remote). The hub sends `Content-Type: application/octet-stream`, the raw chunk as the body, and the step metadata in one header:

```
X-Envsync-Step: {"job":"...","kind":"file","path":"themes/x/style.css","offset":0,"final":false,"sha256":"...","expect":"...","algo":"xxh128"}
```

The remote branches on content type: JSON body → existing behaviour (base64 `data` field); octet-stream → metadata from the header, bytes from the body. A 0.2.x hub pushing to a 0.3 remote keeps working.

**Signature.** `IXES_Auth::sign()` message becomes

```
METHOD \n PATH \n TS \n sha256(body) \n step-header-or-empty
```

The fifth line is the raw `X-Envsync-Step` value, or an empty string when the header is absent. Old clients never send the header, so their signatures still verify (the message ends with `\n`); the remote computes the same line from what it received. Body hashing is unchanged, so binary bodies sign exactly like JSON ones.

**`/info`** gains `"caps": ["binary", "scope"]`. The hub reads it once and falls back to JSON transfer when `binary` is absent.

### Client

`IXES_Client::request()` gains a fourth argument `$opts` with `raw_body` (string, sent as octet-stream), `accept` (`json` | `binary`) and `headers` (extra). For binary responses it returns `[ 'body' => string, 'headers' => array ]` instead of decoded JSON.

Two new methods own the chunk loops that `Pull::run()` and `Applier::apply()` currently inline:

- `IXES_Client::fetch_file( $rel, $dest_writer, $expect_total = null )` — pulls one file chunk by chunk, calling `IXES_Transfer::write_file_chunk()` for each.
- `IXES_Client::send_file( $job, $rel, $abs, $meta )` — pushes one file chunk by chunk through `/job/step`; returns `[ 'ok' => bool, 'refused' => bool ]`.

Both use one `IXES_Chunker` helper that holds the adaptive size:

| Rule | Value |
|---|---|
| start | 2 MB |
| grow | ×2 after two consecutive chunks under 2 s, cap 4 MB |
| shrink | ÷2 on transport error or HTTP 408 / 413 / 502 / 503 / 504, floor 256 KB |
| retry | same offset, up to 5 attempts, back-off 1, 2, 4, 8, 8 s |
| give up | `WP_Error( 'transfer', "…at offset N after 5 attempts: <last error>" )` |

The chosen size is per file transfer and starts fresh for each file; there is no global learned value (a single 200 MB video and a thousand 40 KB thumbnails want different sizes anyway).

Integrity stays as today: whole-file sha256 verified on the receiving side when the last chunk lands. No per-chunk hash.

### Remote memory

`file_chunk()` gains an `$as_binary` flag. In binary mode it `fread`s straight into the response with no base64 and no JSON, so peak memory per request drops from roughly three times the chunk to one. `job_step` file branch in binary mode reads `php://input` once, no `json_decode` of a multi-MB body.

### Files

`class-ixes-rest.php` (Accept / Content-Type branching, header into auth), `class-ixes-auth.php` (fifth line), `class-ixes-transfer.php` (`file_chunk` binary mode), `class-ixes-client.php` (`request` opts, `fetch_file`, `send_file`), new `class-ixes-chunker.php`, `class-ixes-applier.php` (job_step binary branch; `apply()` uses `send_file`), `class-ixes-pull.php` (`run()` uses `fetch_file`).

## 2. Resumable pull

### State file

`Pull::run()` writes `<storage>/pull-<env>.json` when it starts and after every committed unit:

```json
{
  "env": "prod",
  "plan": "plans/plan-pull-prod-20260922-101500.json",
  "started": 1758535000,
  "remote_plugin": "0.3.0",
  "scope": { "only": [], "tables": [], "paths": [] },
  "tables_done": ["wp_posts"],
  "table": "wp_postmeta",
  "cursor": "184233",
  "files_done": 512
}
```

- `plan` — pull plans are now saved by `Planner::save()` (today only diff plans are). The plan is the source of truth for the table list, the file transfer list (sorted) and the delete list.
- `cursor` — the `next` value the remote returned for the last page that was fully processed (rows inserted **and** baseline written). Null means "table not started".
- `files_done` — number of entries of the plan's transfer list that finished, in order.

The state file is removed right after the file phase completes (i.e. after `write_files`, before `after_import`). The tmp tables are dropped only by a successful `import_commit` or by `--fresh`; a crash leaves them in place on purpose.

### Rerun

`wp envsync pull <env>`:

1. If no state file → fresh pull as today.
2. If a state file exists → print
   `An interrupted pull of prod from 2026-09-22 10:15 stopped in wp_postmeta at row 184233, 512/2300 files done.`
   then `Resume? [y/n]` (auto-yes with `--yes`). `--fresh` skips the prompt, drops the state file, the tmp tables and the saved plan, and starts over.
3. Resume is refused (with the reason and a hint to use `--fresh`) when: the remote `/info` reports a different `plugin` version than `remote_plugin`; the env's excludes or extra_replace no longer match those recorded in the plan; the saved plan file is missing; or the tmp table for `table` no longer exists.

On resume the table loop starts at `table`, re-issuing `/dump` from `cursor`. `import_rows()` uses `REPLACE INTO` for tables with a primary key so a re-sent page is harmless; for no-PK tables the cursor is the offset, and the page after it was never inserted (cursor is written only after insert + baseline), so `INSERT` is safe. Files resume at index `files_done`; a leftover `.ixes-tmp` for that file is discarded because the first chunk is written with offset 0.

### Baseline

`Baseline::reset()` runs only on a fresh pull. `created_at` is written at commit time (after `import_commit` and the file phase), not at start, so an abandoned pull never leaves a baseline that claims to be fresh. Until then `exists()` returns false for a brand-new env, and for an env with an older baseline the old `created_at` stays.

### Push

Not resumable, by design. It snapshots, applies in steps and rolls back on any failure; deltas are small. The stuck-lock case is a next-round item.

### Files

`class-ixes-pull.php` (state read/write, resume branch, refusal checks), `class-ixes-planner.php` (`save()` accepts pull plans, file name `plan-pull-<env>-<ts>.json`), `class-ixes-transfer.php` (`import_rows` REPLACE mode, `tmp_exists()`), `class-ixes-baseline.php` (`created_at` at commit), `class-ixes-cli.php` (`--fresh`, prompt).

## 3. Selective sync

### Flags

All three are accepted by `pull`, `diff` and `push`, and combine:

| Flag | Values | Effect |
|---|---|---|
| `--only=` | comma list of `db`, `files`, `uploads`, `themes`, `plugins`, `mu-plugins` | coarse cut; `files` = all of wp-content; folder names = that prefix |
| `--tables=` | comma list of names or globs (`posts`, `wp_wc_*`) | narrows inside `db`; matched with and without the table prefix |
| `--paths=` | comma list of prefixes (`themes/mk/`) or globs (`uploads/2026/*`) | narrows inside `files` |

Omitted flags mean "everything", so today's commands behave exactly as before. `--tables` alone implies `--only=db`; `--paths` alone implies `--only=files`.

### `IXES_Scope`

```php
IXES_Scope::from_assoc( $assoc )   // parses the three flags; throws InvalidArgumentException on unknown --only values
->db_wanted()      // bool
->files_wanted()   // bool
->table_in( $name )// bool, glob via fnmatch, prefix-insensitive
->path_in( $rel )  // bool, prefix or fnmatch on the wp-content-relative path
->is_full()        // no flags given
->label()          // "themes, tables wp_posts" for prompts and plan headers
->to_array() / from_array()  // stored in plans and the pull state
```

Env excludes still apply on top; scope never re-includes an excluded path.

### Where it applies

**Pull.** `Pull::plan()` lists only in-scope tables and files. `Pull::run()`:
- imports and commits only in-scope tables; out-of-scope tables are never touched
- transfers only in-scope files; deletes only in-scope local files that are absent on the remote
- runs `preserve_local_options()` and `after_import()` only when `wp_options` is in scope; runs `offset_auto_increment()` only for tables that were imported
- baseline: rows for each imported table are replaced (`delete where tbl = ?` then write); file entries for in-scope paths are rewritten and out-of-scope entries kept. On a full pull `created_at` is set; on a partial pull `partial_at` and `partial_scope` (the label) are set and `created_at` is left alone.

**Diff / push.** `Planner::build()` skips hashing tables outside scope and filters both file manifests with `path_in()` before diffing. The `active_plugins` merge runs only when `wp_options` is in scope. The plan stores `scope` (array) and `render_text()` prints `scope: themes, tables wp_posts` under the header line. `push --plan=<file>` uses the scope stored in the file and refuses if flags are also passed. The confirmation reads `Apply this plan (scope: …) to prod (https://…)?`.

**Family warning.** When `--tables` selects some but not all of a family, the plan output prints one line, e.g.
`warning: wp_posts selected without wp_postmeta; pull the full db before the next push`.
Families: posts/postmeta; terms/term_taxonomy/term_relationships/termmeta; users/usermeta; comments/commentmeta. Never blocks.

**Display.** `env list` and the admin Environments table show `2026-09-12 · partial 2026-09-22 (themes)` when `partial_at` is newer than `created_at`.

### Files

New `class-ixes-scope.php`; `class-ixes-cli.php` (flag docs, passing scope), `class-ixes-pull.php`, `class-ixes-planner.php`, `class-ixes-applier.php` (prompt text), `class-ixes-baseline.php` (`delete_table()`, `partial_*` meta), `admin/class-ixes-admin.php` (display).

## 4. Agent skill and README

`skills/wp-envsync/SKILL.md` gains a section "Which flags for which job":

| Situation | Command |
|---|---|
| First pull of a big site | `wp envsync pull prod` — if it drops, rerun the same command and answer `y` to resume |
| Working on the theme, want prod's latest theme files | `wp envsync pull prod --only=themes --paths=themes/<slug>/` |
| Client edited content, want it locally without touching your theme | `wp envsync pull prod --only=db --tables=posts,postmeta,terms,term_taxonomy,term_relationships,termmeta` |
| Fresh media only | `wp envsync pull prod --only=uploads` |
| Ship theme work | `wp envsync diff prod --only=themes` then `wp envsync push prod --only=themes` |
| After any `--tables` pull that split a family | do a full `wp envsync pull prod` before the next push |

Plus a paragraph on the resume prompt, `--fresh`, and the rule that scope on push never widens beyond what the plan shows.

README gains "Sync only part of a site" with the same table, and a paragraph "If a pull is interrupted".

## 5. Testing

Unit (PHPUnit, no WordPress):

- `ScopeTest` — flag parsing, implied `--only`, globs with and without prefix, path prefixes, `is_full`, `label`, family warning.
- `ChunkerTest` — grow/shrink/floor/cap rules and retry budget with a scripted sequence of outcomes.
- `AuthTest` — signature with and without the step header; an old-style signature still verifies.
- `PullResumeTest` — state file transitions with a stub client: fresh start, crash after a page, resume from cursor, refusal on version mismatch, `--fresh`.

Integration (`tests/integration.sh`, two real installs):

- pull killed with `timeout` mid-file, rerun with `--yes`, verify the file count and a row after the cursor
- `push prod --only=themes` after the client edited a post on prod: theme file arrives, post untouched
- old-hub compatibility: run one `/file/get` with `Accept: application/json` against the new remote and verify the base64 body still decodes and hashes

CI gates unchanged; the new files fall under the existing PHPCS ruleset.

## 6. Rollout

Version 0.3.0 on both sides. Order does not matter: a 0.3 hub detects a 0.2 remote through the missing `caps` and uses JSON transfer without scope on the remote side (scope on pull is hub-side only, so it still works; scope on push/diff also works because filtering happens on the hub). A 0.2 hub against a 0.3 remote works unchanged.
