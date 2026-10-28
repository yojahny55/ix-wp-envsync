# Changelog

All notable changes to IX WP EnvSync. Versions follow the plugin header. Releases from
0.9.13 on are tagged `vX.Y.Z`; earlier entries are reconstructed from the commit history.

## 0.9.21 — 2026-10-28
- Push snapshots no longer pile up on the remote: it keeps the last three jobs (`IXES_KEEP_JOBS`) and none older than 14 days (`IXES_JOB_TTL_DAYS`). It prunes at every job start, finish, abort and unlock, and once a day by cron, so an aborted, unlocked or crashed push no longer leaves its copy behind for good. The locked job is never touched, and a rolled-back job does not count toward the three.
- Each job's `meta.json` records whether it finished, was rolled back cleanly or was unlocked.
- Tools → EnvSync lists the push snapshots with their state and size, with a Delete button per job and "Delete all but the last".
- `wp envsync prune-jobs [--dry-run]` frees the space now, on the remote.
- A plan whose rollback copy passes 200 MB or 1000 files (`IXES_SNAPSHOT_WARN_MB`, `IXES_SNAPSHOT_WARN_FILES`) warns before you confirm, and `push --yes` prints the warning.

## 0.9.20 — 2026-10-26
- A self-update rolled back by the health check no longer leaves a keyed remote answering 404 on `/session` with no guidance: the 404 now says to run `env add --token`.
- After the rollback the hub forgets the key only when the restored remote answers 404 on `/session`; any other failure keeps it, so a slow or failing remote is not silently downgraded to token mode.

## 0.9.19 — 2026-10-20
- Documentation and branding assets: logo, icons, banners, diagrams, screenshots, LICENSE, CONTRIBUTING.
- Redrawn diagrams: aligned arrows, a joined three-way merge, and what each side holds.
- CI actions moved to Node 24 releases.
- No change to plugin behaviour.

## 0.9.18 — 2026-10-14
- Signed sessions: once a remote holds the hub's public key, the token stops travelling.
- The public key is bound to the token it was derived from.

## 0.9.17 — 2026-10-09
- A pulled site renders its own URLs: caches are cleared after a pull.

## 0.9.16 — 2026-10-09
- A signed per-request nonce refuses replayed requests.
- The push lock is taken atomically.
- No file is served through a symlink that leaves `wp-content`.
- Hardening when the remote loses the capability.

## 0.9.15 — 2026-10-09
- No `active_plugins` step is planned when that option is excluded.

## 0.9.14 — 2026-10-01
- A paged read that crashes the remote with a 500 shrinks its page and retries.

## 0.9.13 — 2026-10-01
- Windows path separators are normalised in file listings.

## 0.9.12 — 2026-09-30
- Elementor's cache is cleared after push, rollback and pull.

## 0.9.11 — 2026-09-30
- `push` reuses the dry run's plan (`--plan`) and writes `progress.json`.
- A reused plan is refused once pushed, or when the remote's active plugins changed.

## 0.9.10 — 2026-09-30
- Every plugin on either side is listed, with presence and orphan folders.

## 0.9.9 — 2026-09-30
- `--exclude-options` keeps option rows out on both sides.
- `--exclude-tables` with a `@logs` preset, plus per-environment table defaults.

## 0.9.8 — 2026-09-30
- `diff --id` says where a row is; `diff --list` names the rows in a column.
- Diff counts are accurate: a pushed row hashes the same on both sides afterwards.

## 0.9.7 — 2026-09-30
- mu-plugins section with staged upload and a bare rescue.

## 0.9.4 – 0.9.6 — 2026-09-28 to 2026-09-30
- `wp envsync self-update <env>` installs EnvSync on a remote and rolls back if the site breaks.
- Transient network failures are retried; resume keeps the matching scope.

## 0.8.0 – 0.9.3 — 2026-09-28
- Batched, compressed, parallel file transfer (`--parallel=<n>`).
- Pull seeds files from wordpress.org when they match the remote's hash.
- Binary and non-UTF-8 cells travel losslessly.

## 0.7.x — 2026-09-25 to 2026-09-28
- Dropped tables propagate, checked and backed up first (`--drop-tables`).
- Pull creates the tables and columns the other side has.

## 0.6.x — 2026-09-25
- Table-prefix translation between sites with different prefixes.
- A default `--only` scope per environment; `push --mirror` for a first deploy.

## 0.5.x — 2026-09-22 to 2026-09-25
- Rescue endpoint and failure menu for a push that breaks the remote.
- HTTP Basic Auth for remotes behind it (`env add --basic-auth`).
- Tables and progress bars; `--format=json` for agents.

## 0.4.x — 2026-09-22
- `wp envsync status` and an admin Status panel with the recommended next command.

## 0.3.x and earlier — 2026-09-07 to 2026-09-22
- Token and HMAC request signing, 3-way differ, baseline store, REST transfer, rollback.
- Selective sync with `--only`, `--tables` and `--paths`; resumable pull.
- Exclude manager on the Tools → EnvSync page.
