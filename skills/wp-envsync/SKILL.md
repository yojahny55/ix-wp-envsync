---
name: wp-envsync
description: Sync a WordPress site between local, staging and production with the IX WP EnvSync plugin — pull a full copy down, preview a three-way diff, push back only your changes with production always winning, deploy a locally built site to a fresh install, and roll back a bad push. Use when the user asks to pull from prod, push to staging or prod, deploy a new site, sync environments, see what would change before syncing, or recover from a failed or stuck sync.
user-invocable: false
---

# WP EnvSync

Drives the IX WP EnvSync WordPress plugin through WP-CLI. Use it whenever the user wants content, code or media moved between WordPress environments.

## The loop

Every task follows the same four steps. Do not skip one.

1. **Read the state:** `wp --path=<site> envsync status --json`
2. **Act on `next.command`.** Do not compose pull, diff or push from memory when `status` already names the step.
3. **Preview anything that changes a site** with `--dry-run` (or `diff` before a push). Show the output to the user and say what it means.
4. **After the user approves, rerun the same command with `--yes`.**

Why `--yes`: `pull`, `push`, `rollback` and `unlock` each stop at a `[y/n]` prompt. Your shell cannot answer it, so without `--yes` the command aborts. `--yes` is only for a plan the user approved in this conversation.

Always pass `--path=<site root>`. The hub is the site you run commands from.

## Reading `status --json`

```json
{
  "role": "both",
  "hub_version": "0.6.2",
  "envs": {
    "prod": {
      "url": "https://client.com", "label": "prod",
      "reachable": true, "error": null,
      "remote_version": "0.6.2", "version_ok": true, "auth_via": "authorization",
      "baseline": { "created_at": 1789192836, "partial_at": null, "partial_scope": null, "age_days": 2 },
      "interrupted_pull": null,
      "remote_lock": null,
      "remote_posts": 218,
      "prefix_map": null,
      "excludes_count": 7
    }
  },
  "next": { "command": "wp envsync diff prod", "why": "ready", "env": "prod" }
}
```

- `role`: `hub` or `both` means you can run pull/diff/push from here. `remote` means this site is only a target, so go to the hub. `unconfigured` means no environment is registered yet.
- `next.why` is the reason. Quote it to the user.
- `reachable: false`: `error` says why (DNS, 401 bad token, TLS).
- `version_ok: false`: the remote runs an older plugin.
- `baseline.created_at: null`: no pull has been done for this environment.
- `remote_lock`: `{job, started, age_minutes}` means a push is running or died there.
- `interrupted_pull`: `{started, table, files_done, files_total}` means a pull stopped partway.
- `remote_posts`: the number of rows in the remote's posts table. 5 or fewer means a fresh install.
- `prefix_map`: `null` when both sites use the same table prefix. Otherwise a string such as `"ab12cd_ → wp_"` (remote prefix → hub prefix): the remote translates, and table names in plans are the hub's.

In a push plan, `new_tables` lists tables the push will create on the remote (shown as `(new)` in the table). Mention them to the user.

## What to do for each `next.command`

| `next.command` | `next.why` | What you do |
|---|---|---|
| `wp envsync env add <name> <url> --token=…` | not set up, or cannot reach | Ask the user for the token from that site's **Tools → EnvSync** page. Never invent one. |
| `upload the release zip to <url>` | remote runs X, hub runs Y | Tell the user to upload the release zip through **Plugins → Add New → Upload** on that site. You cannot do it. |
| `wp envsync rescue <env>` | … crashes on every request | Run it, report what it shows, then offer `--rollback` or `--plugins-off` (see Diagnosing failures). |
| `wp envsync unlock <env>` | a push … never finished | Tell the user a push died. Nothing is rolled back. Then run `unlock <env> --yes` after approval. |
| `wp envsync pull <env>` | an interrupted pull can be resumed | Run `pull <env> --dry-run` (it shows where it stopped), then `pull <env> --yes` to resume. Use `--fresh` only if resume is refused. |
| `wp envsync push <env> --force --dry-run` | looks like a fresh install | This is a first deploy. See [First deploy](#first-deploy-onto-a-fresh-install). |
| `wp envsync pull <env>` | no baseline | Run `pull <env> --dry-run`, show it, then `pull <env> --yes`. The pull overwrites the local site. |
| `wp envsync diff <env>` | baseline is N days old | Suggest pulling first. If the user declines, continue as for "ready". |
| `wp envsync diff <env>` | ready | Run `diff <env>`, show it, then `push <env> --yes` after approval. |

## Workflows

### Bring the remote down to local

```bash
wp --path=<site> envsync pull prod --dry-run   # show this
wp --path=<site> envsync pull prod --yes       # after approval
```

The dry run lists tables and rows, files to transfer, **files to delete**, and URL rewrites. Report the delete count: those files exist only locally and will be removed. If it looks large, add `--details` to see the counts per folder.

### Ship local changes

```bash
wp --path=<site> envsync diff prod             # show this
wp --path=<site> envsync push prod --yes       # after approval
```

Point out `remote-wins` and `CONFLICTS`. Those are the user's edits that will **not** be applied, because the remote changed the same thing. That is correct behaviour, but the user needs to know which of their changes are dropped. After the push, tell the user to pull again before their next round of work.

The usual release path is to push to staging first, then to production.

### Sync only part of a site

| Situation | Command |
|---|---|
| Theme work only | `diff prod --only=themes`, then `push prod --only=themes --yes` |
| Prod's latest files for one theme | `pull prod --only=themes --paths=themes/<slug>/` |
| Client's content, keep your theme | `pull prod --only=db --tables=posts,postmeta,terms,term_taxonomy,term_relationships,termmeta` |
| Media only | `pull prod --only=uploads` |

An environment can carry a default scope (`env add <env> --only=db,uploads`, shown in `env list`), typically when code travels by git. With no scope flag the command prints `scope: … (default for <env>; --only=all syncs everything)` first. Respect it: do not add `--only=all` unless the user asks to sync code too.

Rules:
- `push` never syncs more than the `diff` you ran with the same flags. Run `diff` with exactly the flags you will push with.
- If a `--tables` pull prints a warning about splitting a family (for example posts without postmeta), run a full pull before the next push.

### First deploy onto a fresh install

The user built the site locally, and the remote is a fresh WordPress install. Pulling first would overwrite their work with the empty site. `status` recommends this path when there is no baseline and the remote has 5 posts or fewer. If the remote has more posts but the user says it is still fresh, use this path anyway. Otherwise pull first.

1. Ask the user to confirm the remote is fresh.
2. Run `push <env> --force --dry-run` and show the plan.
3. Tell the user three things before they approve:
   - Local users replace the remote's users, so they will log in with their **local** credentials.
   - Active local dev plugins go up too.
   - Nothing on the remote is deleted, so an old site's content stays mixed in. If the user wants the remote to end up equal to local, add `--mirror` to the dry run and the push: rows and files only the remote has, within the scope, are deleted (orders and users included). Report the delete counts per table and folder before approval. `rollback` restores them.
4. Run `push <env> --force --yes` (plus `--mirror` if used in the dry run) after approval.
5. Run `pull <env> --yes` to record the baseline. From now on, use the normal loop and never `--force`.

### Undo a bad push

```bash
wp --path=<site> envsync rollback prod --yes           # the last push
wp --path=<site> envsync rollback prod --job=<id> --yes
```

Restores the snapshot the remote took before the push. Only the last three jobs are kept.

### Investigate one conflict

```bash
wp --path=<site> envsync diff prod --table=wp_posts --id=2231
```

Shows the row field by field, on both sides.

## Reading a diff

```
| table    | push | insert | delete | remote-wins | kept-remote |
| wp_posts |   12 |      3 |      1 |           2 |          41 |
```

- `push`: the user's changes going up.
- `insert`: rows they created.
- `delete`: rows they deleted, which the remote has not touched.
- `remote-wins`: both sides changed it, and the remote's version stays. Report these.
- `kept-remote`: the remote changed it and the user did not. This is normal.

`baseline: none` (in JSON, `baseline_at: null`) means no pull has been done. Pull first, unless this is a first deploy (`first_deploy: true` in the manifest).

The wording names the environment: a push to staging prints `CONFLICTS (staging wins)` and `skipped (changed on staging during push)`. In JSON the keys stay `prod_wins` and `kept_prod` for every environment.

Show the user the table output. For your own reasoning, read the manifest instead (next section).

## Files agents read

Every `diff`, `push` and `pull` (including `--dry-run`) prints `manifest: <path>` as its last line and writes two files under `wp-content/envsync-*/`:

- `plans/<kind>-<env>-latest.json`: the plan (`schema: 1`). `summary` has `{files, delete, bytes, rows, conflicts}`, followed by `tables[]`, `plugins[]`, `themes[]`, `other[]`, `conflicts[]` and `warnings[]`.
  - Each plugin or theme entry has `slug`, `files`, `bytes`, `version: {before, after}`, `active: {before, after}` and `change` (`turns on`, `turns off`, `stays on`, `becomes active`, `stops being active`).
  - `before` is the site being changed. A version of `null` means not installed there, and `"?"` means that site's plugin is older than 0.5.1.
  - `--format=json` prints the same object.
- `runs/<kind>-<env>-latest.json`: the outcome of a real push or pull: `{ok, job, seconds, files, bytes, rows, stale[], error}`. It is written even when the command fails, so read it after any failure before retrying.

What to report to the user from the manifest: plugins with `change` `turns on` or `turns off`, version changes on plugins and themes, `summary.delete` when it is not zero, and every entry in `conflicts`.

When you run commands, output is piped, so there is no progress bar, only one summary line per stage. Do not add `--verbose` unless the user wants per-file lines.

## Commands

All commands take `--path=<site>`.

| Command | Purpose |
|---|---|
| `envsync status [<env>] [--json]` | Role, each environment's state, and the one next command |
| `envsync env add <name> [<url>] [--token=] [--label=] [--only=] [--basic-auth=] [--exclude=] [--add-exclude=] [--remove-exclude=] [--replace=]` | Register a remote, or update only the options you pass |
| `envsync env list` / `remove <name>` / `ping <name>` | List, remove or test environments |
| `envsync env excludes <name>` | Every excluded path with its source, and the file count still in scope |
| `envsync pull <env> [--dry-run] [--details] [--yes] [--fresh] [--verbose] [--format=json] [--flush-cache] [--only=] [--tables=] [--paths=]` | Overwrite this site from the remote and record the baseline. Resumes an interrupted pull. |
| `envsync diff <env> [--format=json] [--details] [--table= --id=] [--flush-cache] [--only=] [--tables=] [--paths=]` | Preview a push. Changes nothing. |
| `envsync push <env> [--dry-run] [--yes] [--force] [--mirror] [--verbose] [--format=json] [--plan=<file>] [--only=] [--tables=] [--paths=]` | Apply changes to the remote |
| `envsync unlock <env> [--yes]` | Clear a stuck push lock. Rolls nothing back. |
| `envsync rollback <env> [--job=<id>] [--yes]` | Restore a pre-push snapshot |
| `envsync rescue <env> [--rollback] [--job=<id>] [--plugins-off] [--yes]` | Recover a remote that crashes on every request (loads no plugins) |
| `envsync token [--rotate]` | Show or reissue this site's token (run on a remote) |

`--only` takes `db,files,uploads,themes,plugins,mu-plugins`. `--tables` takes table names or globs and implies `--only=db`. `--paths` takes wp-content paths or globs and implies `--only=files`.

## Diagnosing failures

Match the error, then act. Do not retry the same command blindly. When in doubt, run `status --json` again.

**`prefix_mismatch`**: the two sites use different table prefixes and the remote runs a plugin older than 0.6.0. Tell the user to upload the current zip to that site; from 0.6.0 each site keeps its own prefix and the remote translates table names, `<prefix>user_roles` and prefixed usermeta keys. Do not rename tables to work around it. `status` shows `prefix <remote> → <hub>` once both sides can translate.

Known limit across prefixes: a usermeta key that starts with the hub's prefix is renamed to the remote's, even when a plugin chose that name itself (with hub `wp_`, a plugin key `wp_foo_setting` becomes `<remote>foo_setting`). WordPress cannot tell such a key from a real prefixed one. The usual effect is a dismissed notice or per-user preference reappearing on the remote. Mention it only if the user reports a per-user setting that did not carry over.

**`cannot write <path>` / `cannot create directory`**: a filesystem permission problem, usually folders owned by the web-server user because plugins were installed through the browser. The message names the folder, its owner and its mode. The fix needs sudo, so give it to the user to run:

```bash
sudo chown -R <user>:<webgroup> wp-content
sudo find wp-content -type d -exec chmod 2775 {} +
sudo find wp-content -type f -exec chmod 664 {} +
```

Warn them that the `chmod 664` sweep strips execute bits from any scripts under wp-content.

**A pull that stopped partway**: `status` shows `interrupted_pull`. Fix the cause (usually permissions or a timeout), then resume with `pull <env> --dry-run` and `pull <env> --yes`. Already-transferred files are not sent again. Until it finishes, the local site can be half-updated. If a half-updated plugin crashes the site, get it up first with `wp --path=<site> --skip-plugins --skip-themes plugin deactivate <plugin>`. If resume is refused (the remote's plugin version, excludes or replace pairs changed), use `pull <env> --fresh --yes`.

**`503 … no available server`**: the host's proxy (Traefik on Coolify) has no healthy container for the site. WordPress never saw the request. Retrying will not help. Tell the user to restart the container in Coolify and to check that the site files are on a persistent volume. If the remote runs a plugin older than 0.4.2, maintenance mode during a push fails the health check and causes exactly this, so the remote needs the new zip first.

**`old_remote` / "creates N table(s) the remote lacks"**: the push has to create plugin tables, and the remote plugin is older than 0.5.1. Tell the user to upload the current zip to that site first. Nothing was changed.

**`503 … no available server`, including from `rescue`**: the host's proxy (Traefik on Coolify) has no healthy container, usually because the health check requests a PHP page that now crashes. Nothing you run can reach the site. Tell the user to point the Coolify health check at a static file (`/license.txt`), or disable it, and restart the container. Then use `rescue`. Also ask for the container log's `PHP Fatal error` line; it names the plugin and file that crash.

**`500 … critical error`, or `status` says `wp envsync rescue <env>`**: the remote crashes on every request, usually because of a plugin. Normal commands cannot reach it, so use the rescue endpoint, which loads no plugins:
1. Run `wp envsync rescue <env>` and report the active plugins, the lock and the last job.
2. Then offer the user two options:
   - `rescue <env> --rollback --yes` undoes the push.
   - `rescue <env> --plugins-off --yes` keeps the push and switches every plugin except EnvSync off; the user reactivates them in wp-admin.

   Wait for their choice.
3. If rescue does not answer, the remote runs a plugin older than 0.5.1, or the host blocks PHP under `wp-content/plugins`. Tell the user to rename the crashing plugin's folder with the host's file manager.

A push that breaks the remote mid-way already rolls back through rescue by itself. Its error says so.

**`403 Forbidden` on `/job/step`** (plain text, not a WordPress error): the host's firewall blocked a database batch, usually because of serialized PHP objects in plugin rows. Retry and plugins-off will not help. Tell the user to upload plugin 0.5.5 or newer to the remote, which sends steps compressed. Then run `unlock <env>` and push again.

**`checksum mismatch`**: the file changed on the remote during the transfer. Run it again.

**423 / "another job is running"**: a push is running or died on the remote. `status` shows the lock's age. `unlock <env> --yes` clears a lock older than two minutes. Nothing is rolled back; `rollback` still restores that job.

**Push reports skipped or stale items**: the remote changed those rows or files between the diff and the push. This is correct: production wins. If those changes mattered, pull again and redo the work.
- The one exception is `wp_options#…` rows skipped on a first deploy by a hub older than 0.5.3. That was a bug (option-id collision with the remote's transients), not a real change. Before anyone pulls, tell the user to upgrade both sides and push again. A pull after such a push copies the missing options back over the hub's own copy.

**401 / "missing token"**: the token is wrong or was rotated. Ask the user for the current one from the remote's Tools → EnvSync page, then run `env add <name> --token=<new>`. You do not need the URL again.

**401 with "behind HTTP Basic Auth"**: the web server itself asks for a password before WordPress loads. The token is fine. Ask the user for that user and password, then run `env add <name> --basic-auth=<user:pass>`. Both sides need 0.5.6 or newer (0.5.7 on the hub, where the option first parses).

## Excludes

Large junk folders (backups, caches) slow every operation.

```bash
wp --path=<site> envsync env excludes prod                                 # what is excluded now
wp --path=<site> envsync env add prod --add-exclude=ai1wm-backups/,cache/  # append
wp --path=<site> envsync env add prod --remove-exclude=cache/              # drop one
```

`--exclude=` replaces the whole list. `--add-exclude=` and `--remove-exclude=` edit it in place, so prefer those. An excluded folder is not hashed, transferred or deleted on either side.

## Guardrails

- Never run `push` without showing the user a `diff` (or a `--dry-run`) first. A push changes a client's live site.
- Never pass `--yes` to a plan the user has not approved in this conversation.
- Do not push to a `prod`-labelled environment without explicit approval in this conversation.
- Use `--force` only for a first deploy onto a fresh install the user has confirmed. Use `--mirror` only when the user asked for the remote's extra content to be deleted.
- Do not exclude `uploads/`, `themes/`, `plugins/`, `mu-plugins/` or `languages/` unless the user asks.
- Never put a token in a file, a commit, or any message that leaves the machine.
- After a push, tell the user to pull again before their next round of work.
