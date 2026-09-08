---
name: wp-envsync
description: Sync a WordPress site between local, staging and production with the IX WP EnvSync plugin — pull a full copy down, preview a three-way diff, push back only your changes with production always winning, and roll back a bad push. Use when the user asks to pull from prod, push to staging or prod, sync environments, see what would change before syncing, migrate a site between environments, or recover from a failed sync.
user-invocable: false
---

# WP EnvSync

Drives the IX WP EnvSync WordPress plugin. Use it whenever the user wants content, code or media moved between WordPress environments.

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

`baseline: NONE (2-way)` means no pull has been done for this environment. Do not reach for `--force`. Tell the user to pull first.

## Commands

| Command | Purpose |
|---|---|
| `envsync env add <name> <url> --token=<t> [--label=] [--exclude=] [--replace=]` | Register a remote |
| `envsync env list` / `remove <name>` / `ping <name>` | Manage and test environments |
| `envsync pull <env> [--dry-run] [--details] [--yes] [--flush-cache]` | Overwrite this site from the remote, record baseline |
| `envsync diff <env> [--details] [--json] [--table= --id=] [--flush-cache]` | Preview a push, changes nothing |
| `envsync push <env> [--dry-run] [--yes] [--plan=<file>] [--force]` | Apply changes to the remote |
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

**423 / "another job is running"** — a push is already in progress from another machine, or a previous one died holding the lock. The lock expires after an hour.

**Push reports skipped or stale items** — the remote changed those rows or files between the diff and the apply. Correct behavior, production wins. Pull again and redo the work if those changes mattered.

## Excludes

Large junk folders (backups, caches) slow every operation. Check them in the admin at Tools → EnvSync, or set them when registering:

```bash
wp envsync env add prod https://client.com --token=<t> --exclude=ai1wm-backups/,cache/,litespeed/
```

Excluding **protects** a folder: it is not hashed, transferred or deleted on either side. Never exclude `uploads/`, `themes/` or `plugins/` without the user explicitly asking, since media or code silently stops syncing.

Re-running `env add` on an existing name updates only the options you pass; everything else is kept. To rotate a token: rotate it on the remote, then `wp envsync env add <name> --token=<new>` on the hub. No URL needed.

## Guardrails

- Do not run `push` against a `prod`-labelled environment without explicit approval in the current conversation.
- Do not use `--force`. It exists for a missing baseline; the correct action is to pull.
- Do not exclude `uploads/`, `themes/`, `plugins/`, `mu-plugins/` or `languages/` on your own initiative.
- Do not put a token in a file, a commit, or any message that leaves the machine. Read it from the admin page or `envsync token`.
- After a push, tell the user to pull again before their next round of work, so the baseline stays current.
- If the user asks to sync a site that has no baseline and no recent pull, pull first.
