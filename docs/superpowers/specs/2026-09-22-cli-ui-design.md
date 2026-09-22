# CLI UI: plan tables, byte progress, agent manifests (0.5.0)

## Why

A 7,000-file push prints one line per file, and errors scroll away. Plan columns break on long names. `active_plugins` is one wrapped line, so nobody can see which plugins switch on or off. Agents parse terminal text, or the raw plan dump from `diff --json`.

## Decisions (approved in brainstorming)

- Plugin and theme rows show versions (option B).
- Progress is one bar per stage, and the files bar counts bytes (option C).
- Humans and agents read the same data: one manifest, rendered as tables or as JSON.

## Plan display (diff, push, push --dry-run, pull --dry-run)

1. **Header.** Arrow direction and a plain-words baseline line: `baseline: 2026-09-08 02:06`, or `baseline: none — first deploy, local overwrites <env>` for a two-way push, or `baseline: none` for a pull. Then `scope: <label>` when the scope is partial (the integration suite greps `scope: themes`). Then the totals: `N files · X MB · N rows`.
2. **DATABASE.** A table with columns table, push, insert, delete, prod-wins, kept-prod. Only rows with a non-zero count.
3. **PLUGINS.** A table with columns plugin, files, size, version, active.
   - version: `before → after` when the two differ, one value when they are the same. `—` means not installed on that side, `?` means the other side runs a plugin older than 0.5.0.
   - active: `turns on`, `turns off`, `stays on`, or empty.
   - A row appears when the plugin has files to push/transfer/delete, or when its active state changes.
4. **THEMES.** Same columns as PLUGINS. active: `becomes active`, `stops being active`, `active`, or empty.
5. **OTHER FILES.** Grouped by folder: `uploads/<year>/`, `languages/`, `mu-plugins/`, and any other top-level folder; loose files go under `wp-content root`. Columns: files, size.
6. **CONFLICTS (prod wins).** Only when there are conflicts; same content as today.
7. Deleted files count in `files` as a separate `delete` column, shown only when some group has deletes.
8. `--details` still prints the per-file and per-row lists after the tables.
9. Footer: `plan saved: <path>`, the manifest path.

"before" is the side being changed: the remote for a push, the hub for a pull. "after" is what that side will have. For a plugin with files moving, that is the source side's version. For a plugin with no files moving, it stays the same.

## Manifest (agents)

`IXES_Report::build()` returns an array; `render_text()` and `json_encode` both consume it.

```json
{
  "schema": 1,
  "kind": "diff|push|pull",
  "env": "staging", "url": "https://…", "created": 1789000000,
  "direction": "push|pull",
  "baseline_at": null, "first_deploy": true, "scope": "everything",
  "summary": { "files": 6953, "delete": 0, "bytes": 222700000, "rows": 4560, "conflicts": 0 },
  "tables":  [ { "name": "wp_posts", "push": 2, "insert": 489, "delete": 0, "prod_wins": 0, "kept_prod": 0 } ],
  "plugins": [ { "slug": "polylang", "files": 535, "delete": 0, "bytes": 9800000,
                 "version": { "before": "3.6.1", "after": "3.7.0" },
                 "active":  { "before": false, "after": true }, "change": "turns on" } ],
  "themes":  [ { "slug": "…", "files": 170, "delete": 0, "bytes": 6100000, "version": { … }, "active": { … }, "change": "becomes active" } ],
  "other":   [ { "group": "uploads/2026/", "files": 101, "delete": 0, "bytes": 96300000 } ],
  "conflicts": [ { "type": "row", "table": "wp_posts", "id": "2231", "title": "Services" }, { "type": "file", "path": "…" } ],
  "warnings": []
}
```

- Version values: a string, `null` for not installed, `"?"` for unknown.
- `bytes`: `null` when sizes are unknown (a pull from a pre-0.5.0 remote).
- Files: the report goes to `plans/<kind>-<env>-latest.json` under the storage dir, and to a timestamped copy. `--format=json` prints it instead of the tables. `diff --json` is an alias, and the raw plan dump is retired from the CLI.
- Result: `push` and `pull` write `runs/<kind>-<env>-latest.json` with `{ schema, kind, env, ok, job, started, finished, seconds, files, bytes, rows, stale[], error }`. It is written on both success and failure.

## Progress (push and pull)

- `IXES_Progress` has `stage( $label, $total_bytes, $total_files )`, `file( $rel, $bytes )`, `bytes( $n )`, `note( $msg )` and `end()`.
- On a TTY without `--verbose`: `WP_CLI\Utils\make_progress_bar`, ticked in KB. The message shows `Files  12.3/210.4 MB  4.1 MB/s`. Database stages tick per table.
- With `--verbose`, or when piped: no bar. `--verbose` prints today's per-file lines. When piped, one summary line per stage is printed (`files: 6953 (212.4 MB) in 3m12s, 1.1 MB/s`).
- Retries, skips and warnings go through `WP_CLI::warning`, so they stay visible.
- Byte counts come from an optional `$on_bytes` callback on `IXES_Client::send_file` and `fetch_file`.

## Remote additions

- `/info` adds `inventory`: `{ plugins: {slug: version}, themes: {slug: version}, stylesheet }`.
- `/hash/files` returns `sizes: {rel: bytes}` when the request sends `sizes: true`.
- Hub fallback: when `inventory` or `sizes` is missing, versions are `"?"` and bytes are `null`. Nothing refuses.

## Out of scope

Colour themes, interactive selection, a per-file bar, and changes to `status`.

## Testing

- Unit tests for `IXES_Report::build`: version before/after (push and pull), `?` and `—`, the active changes for plugins and themes, grouping of other files, zero-row DATABASE filtering, conflicts, and `bytes` null.
- A unit test for `render_text`: column alignment with a long slug, and that the `CONFLICTS` and `scope:` strings are present.
- A unit test for `IXES_Progress` in piped mode: summary lines only.
- Integration: the existing suite stays green, `plans/diff-prod-latest.json` exists with `schema: 1`, and `runs/push-prod-latest.json` has `ok: true`.
