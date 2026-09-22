# IX WP EnvSync

Sync a WordPress site between local, staging and production without exporting archives by hand.

Pull a full copy down, work on it, then push back **only what you changed**. Anything the client changed on production is kept, never overwritten. You always see a plan before anything moves.

Built for freelance work across mixed hosting (Hostinger, IONOS, Plesk, Coolify) where you have WordPress admin but not always SSH.

---

## Which site is which

One plugin runs on every site you sync. The site you run commands from is the **hub**. Every other site — usually production or staging — is a **remote**. The hub needs WP-CLI; a remote needs only the plugin and a token.

```
   your laptop (hub)                    client hosting (remote)
   ┌──────────────────┐   pull  ◄──     ┌──────────────────┐
   │ WordPress + WP-CLI│                 │ WordPress + token │
   │ runs the commands │   push  ──►     │ answers over REST │
   └──────────────────┘                 └──────────────────┘
```

---

## Install

1. Upload the plugin zip to every site through **Plugins → Add New → Upload**, then activate it.
2. On each remote, open **Tools → EnvSync** and copy the token shown there.
3. On the hub, register the remote, then check it:

```bash
wp envsync env add prod https://client.com --token=PASTE_TOKEN --label=prod
wp envsync status
```

`status` reports whether the connection works, and tells you what to run next.

### Rotating a token

Press **Rotate token** on the remote's Tools → EnvSync page. The old token stops working right away. Then update the hub:

```bash
wp envsync env add prod --token=NEW_TOKEN
wp envsync status prod
```

Re-running `env add` updates only the options you pass. Your excludes, label and replace pairs stay as they were.

---

## How it thinks

**Baseline.** A pull records a fingerprint of every row and file, taken right after it finishes. `diff` and `push` compare that fingerprint against production and against your local site.

```
prod  ←  local          baseline: 2026-09-08 02:06
```

**Pull before push.** Without a baseline, diff can only compare two sides, not three. It says so instead of guessing.

```
prod  ←  local          baseline: NONE (2-way)   !! everything different would OVERWRITE prod
```

**Prod wins.** When you and production both changed the same row, production's version is kept. Your edit is not applied.

```
CONFLICTS (prod wins)
```

**Conflicts.** Each conflicting row or file is listed by name. Check that list before every push.

```
  wp_posts             #2231  Services
```

The three-way compare works the same for every row and file:

| What happened | Result |
|---|---|
| You changed a row, production did not | Pushed |
| Production changed a row, you did not | Kept, not touched |
| You both changed the same row | **Production wins**, reported as a conflict |
| You created a row | Inserted |
| You deleted a row, production did not touch it | Deleted |
| Production deleted or changed it, you deleted it | Kept |

Files follow the same rules. A theme file you edited goes up; a plugin that auto-updated on production stays as it is.

---

## Reading a diff

```bash
wp envsync diff prod
```

prints a plan like this:

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
  wp_posts             #2231  Services
```

Every DB and FILES row uses the same counts:

| Column | Meaning |
|---|---|
| `push` | your changes going up |
| `insert` | rows or files you created |
| `delete` | rows or files you deleted, that production hasn't touched |
| `prod-wins` | both sides changed it; production's version stays |
| `kept-prod` | production changed it, you did not; left alone |

`CONFLICTS (prod wins)` lists every `prod-wins` row and file by name. Nothing has changed yet — `diff` only reads and reports.

`push` shows the same plan, then waits for your confirmation. Add `--yes` to skip the prompt, `--dry-run` to stop after the plan.

---

## Day to day

```bash
wp envsync status prod          # check where things stand
wp envsync pull prod            # fresh copy of production, records the baseline
                                 # ... do your work ...
wp envsync diff prod            # preview what would change
wp envsync push prod            # apply, after you review the plan
```

Run `status` whenever you're unsure what state a site is in. It names the one command to run next.

---

## First deploy: local to a new site

You built the site locally and production (or staging) is a fresh WordPress install. There is no baseline yet, and pulling first would overwrite your work with the empty site. Push with `--force` instead, then pull once to record the baseline.

> `status` spots this case. When there is no baseline and the remote has 5 posts or fewer (a fresh install), it recommends `wp envsync push <env> --force --dry-run` instead of `pull`.

1. **On the new site:** install WordPress with the **same table prefix** as your local site (the plugin stops on a mismatch). Upload the plugin zip, activate it, and copy the token from **Tools → EnvSync**.
2. **On your local site (the hub):** register it and check the connection:

   ```bash
   wp envsync env add prod https://client.com --token=PASTE_TOKEN --label=prod
   wp envsync status prod
   ```

3. **Preview.** Nothing moves:

   ```bash
   wp envsync push prod --force --dry-run
   ```

4. **Push:**

   ```bash
   wp envsync push prod --force
   ```

   The remote takes a snapshot first, so `wp envsync rollback prod` undoes it.

5. **Record the baseline:**

   ```bash
   wp envsync pull prod
   ```

   Both sides are identical now, so this changes nothing locally. From here on, use the normal [day-to-day](#day-to-day) loop, without `--force`.

What `--force` does when there is no baseline:

| Row or file | Result |
|---|---|
| Only on local | Inserted on the remote |
| On both sides, different | **Local overwrites the remote** |
| Only on the remote | Kept. Nothing is deleted |

Check these before you push:

- **Users come from local.** Local user #1 replaces the remote's admin, so afterwards you log in with your **local** username and password.
- **Dev plugins go too.** Query Monitor, debug tools and the like are copied and activated. Deactivate them locally first, or narrow the push, for example `--only=db,themes,uploads`.
- **The remote should be empty.** Nothing is deleted, so pushing onto an old live site leaves its old posts and pages next to yours. To replace an existing site, reinstall WordPress on it first.
- **What stays on the remote:** its own `siteurl`/`home`, cron, transients and EnvSync token. Local URLs in content are rewritten to the remote's URL. `wp-config.php`, `.htaccess`, caches, `.git/` and `node_modules/` are never sent.
- **Big uploads are fine.** Files go up in resumable chunks. If the push dies, run `wp envsync status prod`; it tells you what to do next (usually `unlock`, then push again).

---

## Sync only part of a site

You don't always need the whole thing. `--only=`, `--tables=` and `--paths=` narrow a `pull`, `diff` or `push` down to just the part you're working on.

| Situation | Command |
|---|---|
| First pull of a big site | `wp envsync pull prod`. If it drops, run the same command again and answer `y` to resume. `--fresh` starts over. |
| Working on the theme, want prod's latest theme files | `wp envsync pull prod --only=themes --paths=themes/<slug>/` |
| Client edited content, want it locally without touching your theme | `wp envsync pull prod --only=db --tables=posts,postmeta,terms,term_taxonomy,term_relationships,termmeta` |
| Fresh media only | `wp envsync pull prod --only=uploads` |
| Ship theme work | `wp envsync diff prod --only=themes`, then `wp envsync push prod --only=themes` |
| After any `--tables` pull that split a family (the plan prints a warning) | run a full `wp envsync pull prod` before the next push |

A `push` never syncs more than the `diff` you last checked with the same flags. Always `diff` with the flags you're about to `push` with.

### If a pull is interrupted

If `pull` drops partway through, just run the same command again. It picks up where it left off; answer `y` when it asks to resume. `--fresh` throws that progress away and starts the pull over instead.

The resume state lives at `wp-content/envsync-*/pull-<env>.json`. It's written automatically while the pull runs and removed once it finishes.

---

## When something is stuck

**A push died and left a lock.** `status` shows the job id and how long it has been stuck:

```
  lock: job 20260922-101500-ab12cd, 47 min old

Next: wp envsync unlock prod   (a push started 47 minutes ago never finished)
```

Run `wp envsync unlock prod` to clear it. Nothing is rolled back — `rollback` can still restore that push's snapshot.

**"missing token" on a host that strips the Authorization header.** The plugin also sends the token as `X-Envsync-Token`, so most hosts recover on their own. If a remote still rejects every request, `status` reports it as unreachable:

Some hosts log request headers verbatim, so a request carrying `X-Envsync-Token` can put the token in full into that host's access logs — treat those logs as sensitive.

```
  unreachable: remote 401 on /info: missing token
```

Re-register the token from that remote's Tools → EnvSync page:

```bash
wp envsync env add prod --token=<new token>
wp envsync status prod
```

**Hub and remote run different plugin versions.** `status` and `ping` compare versions and flag a remote that's behind:

```
  remote 0.3.0  hub 0.4.0  auth via Authorization  (remote is older)
```

Upload the new release zip to that site through **Plugins → Add New**.

---

## Commands

### `wp envsync env <action>`

| Action | Use |
|---|---|
| `add <name> <url>` | Register a remote. Needs `--token=`. Re-run it on an existing name to update only what you pass. |
| `list` | Show every environment and when it was last pulled. |
| `remove <name>` | Forget an environment. |
| `ping <name>` | Check connectivity and credentials. |
| `excludes <name>` | List every path excluded for this environment, and how many files remain in scope. |

Options for `add`:

- `--token=<token>` — required the first time, from the remote's Tools → EnvSync page.
- `--label=prod|staging|local` — what kind of environment this is.
- `--exclude=<paths>` — comma-separated wp-content paths to leave out of sync entirely, e.g. `--exclude=ai1wm-backups/,cache/`. Replaces the whole list.
- `--add-exclude=<paths>` — add to the existing list without retyping it.
- `--remove-exclude=<paths>` — drop entries from the existing list.
- `--replace=<pairs>` — extra comma-separated `search:replace` pairs applied alongside the URL rewrite, for cases like a per-environment domain constant.

### `wp envsync status [<env>]`

Reports this site's role, each environment's state, and the one recommended next command.

- `--json` — machine-readable report, for scripts and agents.

Exit code is always 0; `next` is advice, not an error.

### `wp envsync pull <env>`

Replaces this site with a copy of `<env>` and records a new baseline.

- `--dry-run` — print the plan and stop.
- `--details` — break the file counts down by directory, so you can see what would be deleted.
- `--yes` — skip the confirmation.
- `--flush-cache` — discard the file hash cache and rehash everything.
- `--fresh` — Discard an interrupted pull and start over.
- `--only=<parts>` — Comma list of db,files,uploads,themes,plugins,mu-plugins. Default: everything.
- `--tables=<tables>` — Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
- `--paths=<paths>` — Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.

This **overwrites the local database and wp-content**. It is the destructive one. It is also the one you run most.

### `wp envsync diff <env>`

Shows what a push would do. Reads nothing but hashes over the wire, changes nothing on either side, and saves the plan to the storage folder.

- `--details` — list every affected row id and file path.
- `--json` — machine-readable output.
- `--table=<table> --id=<pk>` — field-by-field diff of a single row, useful for understanding one conflict.
- `--flush-cache` — rehash all files.
- `--only=<parts>` — Comma list of db,files,uploads,themes,plugins,mu-plugins. Default: everything.
- `--tables=<tables>` — Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
- `--paths=<paths>` — Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.

### `wp envsync push <env>`

Applies your changes to `<env>`. Production-changed rows are always kept.

- `--dry-run`, `--yes` — as above.
- `--plan=<file>` — apply a plan saved earlier. Refuses if anything it covers has changed on the remote since.
- `--force` — only when there is no baseline. Overwrites rows that would otherwise be treated as conflicts. Use it for a [first deploy](#first-deploy-local-to-a-new-site) onto a fresh install; for a site with real content, pull first instead.
- `--only=<parts>` — Comma list of db,files,uploads,themes,plugins,mu-plugins. Default: everything.
- `--tables=<tables>` — Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
- `--paths=<paths>` — Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.

Before applying, the remote snapshots every row and file the plan touches, and goes into maintenance mode for the duration. Visitors see the maintenance page. Requests from the server itself (`127.0.0.1`, `::1`) are let through, so a Docker or Coolify health check stays green during a push.

### `wp envsync unlock <env>`

Clears a stuck push lock left by a hub that died mid-push. Rolls nothing back — `rollback` can still restore that push's snapshot.

- `--yes` — skip the confirmation.

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

From the terminal, `--add-exclude=` appends and `--remove-exclude=` drops, so you never retype what is already there:

```bash
wp envsync env add prod --add-exclude=uploads/rank-math/,cache/
wp envsync env excludes prod
```

`env excludes` shows the whole effective list and marks each entry as `always`, `default`, or `this env`. Only `this env` rows can be removed.

Paths are relative to wp-content. A trailing slash means the folder and everything under it, and nested paths work, such as `uploads/rank-math/`.

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

**A pull that stops partway leaves the local site half-updated until you finish it.** Files may be only partly copied, which can leave a plugin half-updated and the site erroring. Fix the cause and run the same pull again; it resumes where it stopped (see [If a pull is interrupted](#if-a-pull-is-interrupted)). A push does not have this problem, because it snapshots first and can be rolled back.

**`503 no available server` means the host's proxy, not WordPress.** Coolify's Traefik returns it when it has no healthy container for the site. Before 0.4.2, a push's maintenance mode failed the container's health check, so the proxy dropped the site mid-push and the push died with this error. Upload 0.4.2 or newer to the remote, restart the container in Coolify, then push again. Also check that the site's files are on a persistent volume in Coolify. Without one, a restarted container starts from a blank WordPress.

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
