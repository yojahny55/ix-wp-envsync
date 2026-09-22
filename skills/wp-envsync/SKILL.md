---
name: wp-envsync
description: Sync a WordPress site between local, staging and production with the IX WP EnvSync plugin — pull a full copy down, preview a three-way diff, push back only your changes with production always winning, and roll back a bad push. Use when the user asks to pull from prod, push to staging or prod, sync environments, see what would change before syncing, migrate a site between environments, or recover from a failed sync.
user-invocable: false
---

# WP EnvSync

Drives the IX WP EnvSync WordPress plugin. Use it whenever the user wants content, code or media moved between WordPress environments.

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

## The one rule

**Never run `push` without showing the user a `diff` first.** The whole point of this plugin is that a human sees what moves before it moves. A push applies to a client's live site.

`pull` and `push` are destructive. Show the plan, wait for the user, then act. `--yes` is only for a plan the user has already approved in this conversation.

## Model

One plugin on every site. The site you run commands from is the **hub** (needs WP-CLI); the others are **remotes** (need only the plugin and a token, reached over HTTPS).

- **pull** = replace the hub with a copy of the remote, then record a **baseline** (hashes of every row and file).
- **push** = three-way compare of baseline, hub now, remote now. Your changes go up; remote-changed rows are kept; both-changed means the remote wins and is reported as a conflict.

The baseline is the safety mechanism. Without a recent pull the plugin can only compare two sides and will refuse to push without `--force`.

## Before doing anything

Always establish these, do not assume:

```bash
wp --path=<site> envsync env list       # which environments exist, and baseline dates
wp --path=<site> envsync env ping <env> # connectivity and credentials
```

If `env list` is empty the user needs to register the remote, which requires a token from that site's Tools → EnvSync page. Ask for it; never invent one.

Always pass `--path=` explicitly when the working directory is not the site root.

## Workflows

### Bring production down to local

```bash
wp envsync pull prod --dry-run   # show this output to the user
wp envsync pull prod             # only after they approve
```

The dry run reports table and row counts, files to transfer, files to delete, and the URL rewrites. **Report the delete count to the user.** Those files exist locally and not on the remote, and they will be removed. If the count looks large, get the detail before proceeding rather than guessing:

```bash
wp envsync pull <env> --dry-run --details
```

### Ship local changes

```bash
wp envsync diff prod             # show the full plan to the user
wp envsync push prod             # after approval
```

Point out the `prod-wins` and `CONFLICTS` sections explicitly. Those are the user's edits that will **not** be applied because production changed the same thing. That is correct behavior, not an error, but the user needs to know which of their changes are being dropped.

Typical release path: push to staging for review, then to production.

### Undo a bad push

```bash
wp envsync rollback prod
```

Restores the snapshot the remote took before the push. Only the last three jobs are kept.

### Investigate one conflict

```bash
wp envsync diff prod --table=wp_posts --id=2231
```

Shows a field-by-field comparison of that row on both sides.

## Reading a diff

```
prod  ←  local          baseline: 2026-09-08 02:06
  wp_posts   push 12   insert 3   delete 1   prod-wins 2   kept-prod 41
```

- `push` — the user's changes going up.
- `insert` — rows they created.
- `delete` — rows they deleted that the remote has not touched.
- `prod-wins` — both sides changed it; the remote's version stays. Report these.
- `kept-prod` — the remote changed it and the user did not. Normal, not a problem.

`baseline: NONE (2-way)` means no pull has been done for this environment. Do not reach for `--force`. Tell the user to pull first. The one exception is a first deploy (see below).

## First deploy onto a fresh install

If the user built the site locally and the remote is a **fresh WordPress install** they are deploying to for the first time, pulling first would wipe their local work with the empty site. `status` recommends `push <env> --force --dry-run` when there is no baseline and the remote has 5 posts or fewer. For a remote with more posts that the user says is still fresh, ignore the `pull` that `status` recommends. Either way, run:

1. `wp envsync push <env> --force --dry-run`. Show the plan to the user.
2. Before they approve, tell them three things. Local users replace the remote's users, so they will log in with their local credentials. Active local dev plugins go up too. Nothing on the remote is deleted, so an old site's content stays mixed in.
3. `wp envsync push <env> --force` after explicit approval.
4. `wp envsync pull <env>` to record the baseline. After that, the normal loop applies and `--force` is never used again.

Confirm that the remote really is fresh (ask the user) before using this path. If it has real content, the path is pull first.

## Commands

| Command | Purpose |
|---|---|
| `envsync env add <name> [<url>] [--token=] [--label=] [--exclude=] [--add-exclude=] [--remove-exclude=] [--replace=]` | Register a remote, or update only the options you pass |
| `envsync env list` / `remove <name>` / `ping <name>` | Manage and test environments |
| `envsync env excludes <name>` | List every excluded path with its source, and the file count still in scope |
| `envsync status [<env>] [--json]` | Report role, each env's state, and the one recommended next command |
| `envsync pull <env> [--dry-run] [--details] [--yes] [--flush-cache]` | Overwrite this site from the remote, record baseline |
| `envsync diff <env> [--details] [--json] [--table= --id=] [--flush-cache]` | Preview a push, changes nothing |
| `envsync push <env> [--dry-run] [--yes] [--plan=<file>] [--force]` | Apply changes to the remote |
| `envsync unlock <env> [--yes]` | Clear a stuck push lock; rolls nothing back |
| `envsync rollback <env> [--job=<id>]` | Restore the pre-push snapshot |
| `envsync token [--rotate]` | Show or reissue this site's token |

`--json` on `diff` is the right choice when you need to reason about a plan programmatically rather than show it.

## Diagnosing failures

Match the error, then act. Do not retry the same command blindly.

**`prefix_mismatch`** — the two sites use different table prefixes. This version cannot bridge that. Report it; the sites must be aligned first.

**`cannot write <path>` / `cannot create directory`** — a filesystem permission problem, usually folders owned by the web-server user because plugins were installed through the browser. The message names the folder, owner and mode. The fix needs sudo, so give it to the user to run rather than attempting it:

```bash
sudo chown -R <user>:<webgroup> wp-content
sudo find wp-content -type d -exec chmod 2775 {} +
sudo find wp-content -type f -exec chmod 664 {} +
```

Warn them that a `chmod 664` sweep strips execute bits from any scripts under wp-content.

**A pull that failed partway** left the database replaced and files half-copied. The site may fatal on a half-updated plugin. Recover in this order:

1. Get the site up: `wp --skip-plugins --skip-themes plugin deactivate <broken-plugin>`
2. Clean leftovers: `find wp-content -name '*.ixes-tmp' -delete`
3. Fix the underlying cause (usually permissions).
4. Run the pull again. Already-transferred files are skipped, so it is cheap.

**`checksum mismatch`** — the file changed on the remote mid-transfer. Re-run.

**423 / "another job is running"** — a push died on the remote; run `wp envsync status <env>` to see the lock's age, and `wp envsync unlock <env>` clears it once it is older than two minutes. Nothing is rolled back — `rollback` still restores that job.

**Push reports skipped or stale items** — the remote changed those rows or files between the diff and the apply. Correct behavior, production wins. Pull again and redo the work if those changes mattered.

## Excludes

Large junk folders (backups, caches) slow every operation. Check them in the admin at Tools → EnvSync, or set them when registering:

```bash
wp envsync env excludes prod                                        # what is excluded now
wp envsync env add prod --add-exclude=ai1wm-backups/,cache/         # append, never retype
wp envsync env add prod --remove-exclude=cache/                     # drop one
```

Check `env excludes` before changing anything: `--exclude=` replaces the whole list, while `--add-exclude=`/`--remove-exclude=` edit it in place. Prefer the latter two.

Excluding **protects** a folder: it is not hashed, transferred or deleted on either side. Never exclude `uploads/`, `themes/` or `plugins/` without the user explicitly asking, since media or code silently stops syncing.

Re-running `env add` on an existing name updates only the options you pass; everything else is kept. To rotate a token: rotate it on the remote, then `wp envsync env add <name> --token=<new>` on the hub. No URL needed.

## Guardrails

- Do not run `push` against a `prod`-labelled environment without explicit approval in the current conversation.
- Do not use `--force`, except for a first deploy onto a fresh install that the user has confirmed (see above). Otherwise the correct action is to pull.
- Do not exclude `uploads/`, `themes/`, `plugins/`, `mu-plugins/` or `languages/` on your own initiative.
- Do not put a token in a file, a commit, or any message that leaves the machine. Read it from the admin page or `envsync token`.
- After a push, tell the user to pull again before their next round of work, so the baseline stays current.
- If the user asks to sync a site that has no baseline and no recent pull, pull first, unless it is a first deploy onto a fresh install.
