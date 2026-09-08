# IX WP EnvSync

Sync a WordPress site between local, staging and production without exporting archives by hand.

Pull a full copy down, work on it, then push back **only what you changed**. Anything the client changed on production is kept, never overwritten. You always see a plan before anything moves.

Built for freelance work across mixed hosting (Hostinger, IONOS, Plesk, Coolify) where you have WordPress admin but not always SSH.

---

## How it works

One plugin is installed on every site. The site you run commands from is the **hub**; every other site is a **remote**.

- The **hub** needs WP-CLI. That is normally your local machine.
- A **remote** needs nothing but the plugin and a token. It exposes a small REST API and does its work in short, resumable steps, so shared hosts with strict time limits are fine.

Two operations do all the work:

**Pull** replaces the hub with a copy of the remote. Database, uploads, themes, plugins. URLs and file paths are rewritten as it imports. Afterwards the hub records a **baseline**: a fingerprint of every row and file as it was at that moment.

**Push** compares three things: the baseline, your site now, and production now. That three-way comparison is what makes the plugin safe:

| What happened | Result |
|---|---|
| You changed a row, production did not | Pushed |
| Production changed a row, you did not | Kept, not touched |
| You both changed the same row | **Production wins**, reported as a conflict |
| You created a row | Inserted |
| You deleted a row, production did not touch it | Deleted |
| Production deleted or changed it, you deleted it | Kept |

Files follow the same rules. A theme file you edited goes up; a plugin that auto-updated on production stays as it is.

The baseline is what makes this work, so **pull before each round of work**. Without a fresh baseline the plugin can only compare two sides, and it will refuse to push rather than guess.

---

## Install

1. Upload the plugin zip to every site through **Plugins → Add New → Upload**, and activate it.
2. On each remote go to **Tools → EnvSync** and copy the token. It is shown once. (`wp envsync token` prints it too, and `--rotate` issues a new one.)
3. On your local site, register each remote:

```bash
wp envsync env add prod https://client.com --token=PASTE_TOKEN --label=prod
wp envsync env add staging https://staging.client.com --token=PASTE_TOKEN --label=staging
wp envsync env ping prod
```

`ping` confirms the URL, the token and the signature all work.

---

## Daily workflow

```bash
wp envsync pull prod            # fresh copy of production, records the baseline
                                # ... do your work ...
wp envsync diff prod            # preview: exactly what would change
wp envsync push staging --yes   # full overwrite of staging for client review
wp envsync push prod            # apply to production after approval
```

A `diff` reads a plan like this:

```
prod  ←  local          baseline: 2026-09-08 02:06
DB
  wp_posts        push 12   insert 3   delete 1   prod-wins 2   kept-prod 41
  wp_postmeta     push 87   insert 19  delete 4   prod-wins 0   kept-prod 310
  wp_wc_orders    push 0    insert 0   delete 0   prod-wins 0   kept-prod 58
FILES
  themes/mk-adventure/            push 14   delete 2
  plugins/advanced-custom-fields/ kept-prod
CONFLICTS (prod wins)
  wp_posts #2231  "Services"
```

Read it as: 12 rows go up, 3 are new, 41 rows production changed are left alone, and one page you both edited stays as production has it. Nothing has happened yet.

`push` shows the same plan and waits for confirmation. Add `--yes` to skip the prompt, `--dry-run` to stop after the plan.

---

## Commands

### `wp envsync env <action>`

| Action | Use |
|---|---|
| `add <name> <url>` | Register a remote. Needs `--token=`. |
| `list` | Show every environment and when it was last pulled. |
| `remove <name>` | Forget an environment. |
| `ping <name>` | Check connectivity and credentials. |

Options for `add`:

- `--token=<token>` — required, from the remote's Tools → EnvSync page.
- `--label=prod|staging|local` — what kind of environment this is.
- `--exclude=<paths>` — comma-separated wp-content folders to leave out of sync entirely, e.g. `--exclude=ai1wm-backups/,cache/`.
- `--replace=<pairs>` — extra comma-separated `search:replace` pairs applied alongside the URL rewrite, for cases like a per-environment domain constant.

### `wp envsync pull <env>`

Replaces this site with a copy of `<env>` and records a new baseline.

- `--dry-run` — print the plan and stop.
- `--details` — break the file counts down by directory, so you can see what would be deleted.
- `--yes` — skip the confirmation.
- `--flush-cache` — discard the file hash cache and rehash everything.

This **overwrites the local database and wp-content**. It is the destructive one. It is also the one you run most.

### `wp envsync diff <env>`

Shows what a push would do. Reads nothing but hashes over the wire, changes nothing on either side, and saves the plan to the storage folder.

- `--details` — list every affected row id and file path.
- `--json` — machine-readable output.
- `--table=<table> --id=<pk>` — field-by-field diff of a single row, useful for understanding one conflict.
- `--flush-cache` — rehash all files.

### `wp envsync push <env>`

Applies your changes to `<env>`. Production-changed rows are always kept.

- `--dry-run`, `--yes` — as above.
- `--plan=<file>` — apply a plan saved earlier. Refuses if anything it covers has changed on the remote since.
- `--force` — only when there is no baseline. Overwrites rows that would otherwise be treated as conflicts. Avoid it; pull first instead.

Before applying, the remote snapshots every row and file the plan touches, and goes into maintenance mode for the duration.

### `wp envsync rollback <env>`

Restores the snapshot from the last push. Undoes changed rows, removes rows and files the push created, and restores files it replaced.

- `--job=<id>` — restore a specific job instead of the last one. The last three are kept.

### `wp envsync token`

Prints this site's token, or `--rotate` to issue a new one. Rotating immediately invalidates the old token.

---

## Excluding folders

Backups, caches and log folders are large, useless to sync, and slow everything down. Go to **Tools → EnvSync → Sync excludes** to see every wp-content folder with its size on both sides, and tick what to skip. Backup and cache folders are flagged and pre-ticked until you save once; after that your choices stick.

Excluding a folder **protects** it. It stops being hashed, transferred, or deleted, on both sides. It does not get removed from either environment.

Never exclude `uploads`, `themes` or `plugins` unless you are deliberately deploying that part another way, for example shipping the theme by git. Media stops syncing and there is no visible symptom.

`--exclude=` on `env add` does the same thing from the terminal.

Some things are always excluded and cannot be synced: `wp-config.php`, `.htaccess`, `.env`, `debug.log`, drop-ins, `.git`, `node_modules`, this plugin's own folder, and its storage folder.

---

## What is never touched

Options that are specific to one environment stay put on both sides: `siteurl`, `home`, `cron`, transients and the plugin's own settings. Everything else in `wp_options`, including theme mods and plugin settings, syncs normally.

`active_plugins` is merged rather than copied: plugins you activated or deactivated locally are applied on top of production's list, so a plugin the client enabled meanwhile is not silently turned off.

---

## Requirements

- Both sites must run the **same table prefix**. The plugin detects a mismatch and stops. Hosts that generate a random prefix need the sites aligned first.
- Both sites must have the **same table structure**. This version does not create missing tables.
- HTTPS on remotes. For local development over plain HTTP, add `define( 'ENVSYNC_ALLOW_HTTP', true );` to `wp-config.php`.
- PHP 7.4 or newer on remotes, 8.1 or newer on the hub.
- **This is not a backup tool.** Keep your backups.

---

## Things worth knowing

**The token is an admin-level credential.** It grants write access to the database and to wp-content, including plugin PHP. Treat it like a password. Rotate it if it leaks.

**A pull is not resumable.** If it fails partway, the database is already replaced while files are only partly copied, which can leave a plugin half-updated and the site erroring. Fix the cause and run the pull again; it will finish the remaining files. A push does not have this problem, because it snapshots first and can be rolled back.

**File permissions matter.** If plugins were installed through the browser, their folders are owned by the web-server user, and a pull run from your shell cannot write into them. The error names the folder, its owner and its mode. The usual fix, adjusted for your user and web-server group:

```bash
sudo chown -R youruser:www-data wp-content
sudo find wp-content -type d -exec chmod 2775 {} +
sudo find wp-content -type f -exec chmod 664 {} +
```

**File hashes are cached** by modification time and size, so unchanged files are not re-read. A file rewritten in place to exactly the same size within the same second is missed. Use `--flush-cache` if you suspect that.

**Keep both sides on the same plugin version.** Different versions can have different exclude rules. The hub filters anything it would refuse, so a mismatch is handled safely, but matching versions avoid surprises.

---

## For AI agents

A skill describing this plugin for coding agents lives in [`skills/wp-envsync/SKILL.md`](skills/wp-envsync/SKILL.md). Copy that folder into `~/.claude/skills/` so an agent can drive the sync correctly, including the rules about never pushing without a diff.

---

## Development

```bash
composer install
vendor/bin/phpunit                    # unit tests

IXES_A=/path/to/prod-install IXES_A_URL=http://127.0.0.1:8081 \
IXES_B=/path/to/hub-install  IXES_B_URL=http://127.0.0.1:8082 \
  tests/integration.sh                # full pull/push/conflict/rollback cycle
```

The integration script needs two throwaway WordPress installs, both with `ENVSYNC_ALLOW_HTTP` defined. It prints `ALL OK` on success.

The design document is in `docs/superpowers/specs/`.

## License

GPL-2.0-or-later.
