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
prod  ←  local
  baseline: 2026-09-08 02:06
```

**Pull before push.** Without a baseline, diff can only compare two sides, not three. It says so instead of guessing.

```
prod  ←  local
  baseline: none — first deploy, local overwrites prod
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
prod  ←  local
  baseline: 2026-09-08 02:06
  512 files · 38.4 MB · 131 rows

DATABASE
+-------------+------+--------+--------+-------------+-------------+
| table       | push | insert | delete | remote-wins | kept-remote |
+-------------+------+--------+--------+-------------+-------------+
| wp_posts    |   12 |      3 |      1 |           2 |          41 |
| wp_postmeta |   87 |     19 |      4 |           0 |         310 |
+-------------+------+--------+--------+-------------+-------------+

PLUGINS
+-----------------------+-------+--------+-----------------+----------+
| plugin                | files | size   | version         | active   |
+-----------------------+-------+--------+-----------------+----------+
| polylang              |   535 | 9.8 MB | 3.6.1 → 3.7.0   | stays on |
| wp-mail-smtp          |   875 | 7.2 MB | — → 4.4.0       | turns on |
+-----------------------+-------+--------+-----------------+----------+

THEMES
+--------------+-------+--------+---------+--------+
| theme        | files | size   | version | active |
+--------------+-------+--------+---------+--------+
| mk-adventure |    14 | 0.3 MB | 1.4     | active |
+--------------+-------+--------+---------+--------+

OTHER FILES
+---------------+-------+---------+
| folder        | files | size    |
+---------------+-------+---------+
| uploads/2026/ |    88 | 21.1 MB |
+---------------+-------+---------+

CONFLICTS (prod wins)
  wp_posts             #2231  Services

plan saved: …/plans/plan-prod-20260908-020644.json
manifest: …/plans/diff-prod-latest.json
```

Every DB and FILES row uses the same counts:

| Column | Meaning |
|---|---|
| `push` | your changes going up |
| `insert` | rows or files you created |
| `delete` | rows or files you deleted, that production hasn't touched |
| `remote-wins` | both sides changed it; the remote's version stays |
| `kept-remote` | the remote changed it, you did not; left alone |

`CONFLICTS (prod wins)` (named after the environment: `CONFLICTS (staging wins)` when you push to staging) lists every `remote-wins` row and file by name. Nothing has changed yet — `diff` only reads and reports.

In **PLUGINS** and **THEMES**, `version` reads *before → after* for the site being changed. `—` means not installed, and `?` means the other site runs a plugin older than 0.5.1 that doesn't report versions. `active` says whether the plugin turns on, turns off or stays on, and which theme becomes active. A plugin appears even with no files moving if only its on/off state changes.

While a push or pull runs you see one progress bar per stage, with the transfer rate for files:

```
Files     1.2 GB / 3.0 GB  4.1 MB/s  40% [=========>              ] 4:52 / 12:10
Database  5/10             50% [============>             ] 0:03 / 0:06
```

Add `--verbose` for the old one-line-per-file output. When the output is piped, as it is for agents and CI, there is no bar, only one line per stage (`files: 6953 (212.4 MB) in 3m12s, 1.1 MB/s`).

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

1. **On the new site:** install WordPress. Its table prefix may differ from your local site's (see [Different table prefixes](#different-table-prefixes)). Upload the plugin zip, activate it, and copy the token from **Tools → EnvSync**.
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

### A default scope per environment (optional)

When a site's themes and plugins travel by git, you can tell an environment to sync only the database and media by default:

```bash
wp envsync env add staging --only=db,uploads
```

From then on `pull`, `diff` and `push` for `staging` use that scope when you pass no `--only`, `--tables` or `--paths`, and say so in their first line:

```
scope: db,uploads (default for staging; --only=all syncs everything)
```

An explicit flag always wins, and `--only=all` syncs everything for that one command. `env list` shows each environment's default. `wp envsync env add staging --only=` removes it. Environments without one sync everything, as before.

### If a pull is interrupted

If `pull` drops partway through, just run the same command again. It picks up where it left off; answer `y` when it asks to resume. `--fresh` throws that progress away and starts the pull over instead.

The resume state lives at `wp-content/envsync-*/pull-<env>.json`. It's written automatically while the pull runs and removed once it finishes.

---

## When something is stuck

**A push broke the remote (every page shows "critical error").** Usually a plugin that crashes once it is switched on. Normal requests fail too, so EnvSync ships a separate rescue endpoint, `rescue.php`, that starts WordPress without any plugins or theme:

```bash
wp envsync rescue prod                 # what the remote looks like with plugins off
wp envsync rescue prod --rollback      # undo the push that broke it (lock and maintenance cleared too)
wp envsync rescue prod --plugins-off   # keep the push, switch every plugin except EnvSync off
```

`status` recommends `rescue` when a remote answers with a 500. A push that breaks the remote mid-way rolls back through rescue on its own. When you run `push` in a terminal without `--yes`, a failed step asks what to do instead of quitting: retry, roll back, switch the remote's plugins off and retry, or leave it as is.

Rescue needs 0.5.1 or newer on the remote. It also won't work where the host or a security plugin blocks PHP files under `wp-content/plugins` (All-In-One Security has such an option). For a remote on an older version, use the host's file manager and rename the crashing plugin's folder under `wp-content/plugins`. WordPress then switches it off.

**`503 no available server` on Coolify or Docker.** The proxy stopped routing to the container, usually because its health check requests a WordPress page, and that page crashed. Nothing reaches the site then, not even `rescue`. Point the health check at a static file such as `/license.txt`, restart the container, then run `wp envsync rescue <env>`. A static file is served without PHP, so a crashing plugin no longer takes the whole container out.

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

**`401` on a password-protected staging site.** When the web server asks for a user and password before WordPress loads (nginx `auth_basic`, Apache `.htpasswd`, a panel's "password protect directory"), every request stops there. `status` says so:

```
  unreachable: remote 401 on /info: the site is behind HTTP Basic Auth; register its credentials with --basic-auth=<user:pass>
```

Register the credentials alongside the token:

```bash
wp envsync env add staging --basic-auth=USER:PASSWORD
wp envsync status staging
```

The hub then sends those credentials in the `Authorization` header, and the EnvSync token travels only in `X-Envsync-Token`. Both sides need 0.5.6 or newer (0.5.7 on the hub, where the option first parses): an older remote lets WordPress try the proxy's user as an application password, and that fails the request with its own 401.

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
- `--basic-auth=<user:pass>` — credentials for a remote whose web server asks for a password (HTTP Basic Auth), common on staging sites. Pass `--basic-auth=` to remove them. Stored in plain text in the hub's database, like the token.
- `--exclude=<paths>` — comma-separated wp-content paths to leave out of sync entirely, e.g. `--exclude=ai1wm-backups/,cache/`. Replaces the whole list.
- `--add-exclude=<paths>` — add to the existing list without retyping it.
- `--remove-exclude=<paths>` — drop entries from the existing list.
- `--only=<parts>` — optional default scope for this environment's `pull`, `diff` and `push`, e.g. `db,uploads` when code travels by git. `--only=` or `--only=all` removes it. See [A default scope per environment](#a-default-scope-per-environment-optional).
- `--replace=<pairs>` — extra comma-separated `search:replace` pairs applied alongside the URL rewrite, for cases like a per-environment domain constant.

### `wp envsync status [<env>]`

Reports this site's role, each environment's state, and the one recommended next command.

- `--json` — machine-readable report, for scripts and agents.

Exit code is always 0; `next` is advice, not an error.

### `wp envsync pull <env>`

Replaces this site with a copy of `<env>` and records a new baseline.

- `--dry-run` — print the plan and stop.
- `--details` — break the file counts down by directory, so you can see what would be deleted.
- `--verbose` — one line per file and table instead of progress bars.
- `--format=json` — with `--dry-run`, print the manifest instead of the tables.
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
- `--format=json` (or `--json`) — print the manifest instead of the tables. See [For AI agents](#for-ai-agents).
- `--table=<table> --id=<pk>` — field-by-field diff of a single row, useful for understanding one conflict.
- `--flush-cache` — rehash all files.
- `--only=<parts>` — Comma list of db,files,uploads,themes,plugins,mu-plugins. Default: everything.
- `--tables=<tables>` — Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
- `--paths=<paths>` — Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.

### `wp envsync push <env>`

Applies your changes to `<env>`. Production-changed rows are always kept.

- `--dry-run`, `--yes`, `--verbose` — as above. `--format=json` with `--dry-run` prints the manifest.
- `--plan=<file>` — apply a plan saved earlier. Refuses if anything it covers has changed on the remote since.
- `--force` — only when there is no baseline. Overwrites rows that would otherwise be treated as conflicts. Use it for a [first deploy](#first-deploy-local-to-a-new-site) onto a fresh install; for a site with real content, pull first instead.
- `--only=<parts>` — Comma list of db,files,uploads,themes,plugins,mu-plugins. Default: everything.
- `--tables=<tables>` — Comma list of table names or globs (posts, wp_wc_*). Implies --only=db.
- `--paths=<paths>` — Comma list of wp-content paths (themes/mk/) or globs (uploads/2026/*). Implies --only=files.

Before applying, the remote snapshots every row and file the plan touches, and goes into maintenance mode for the duration.

Database steps go up deflated as binary, the same way as files. Host firewalls such as Hostinger's score the serialized PHP objects inside plugin rows (Action Scheduler jobs, many options) as an attack, and block a plain JSON batch with `403 Forbidden`. Compressed bytes are not pattern-matched. Both sides need 0.5.5 or newer for this; against an older remote the hub sends plain JSON.

Small files (up to 512 KB) go up in batches of up to 4 MB per request, so a first deploy of thousands of plugin files takes a few dozen requests instead of thousands. Larger files go in resumable chunks.

Options are matched by row ID, but on a fresh remote those IDs are often taken by WordPress's own transients. A pushed option whose ID is held by a transient or other excluded option there is placed by its name instead. It is not reported as "changed on prod". Upgrade both sides to 0.5.3 before a first deploy.

Tables that exist only on your site, typically ones a plugin creates for itself (Action Scheduler, security logs, SEO indexes), are created on the remote first and marked `(new)` in the plan. Plugins switch on as the last step, after their tables and data are in place. `rollback` drops any table the push created. Visitors see the maintenance page. Requests from the server itself (`127.0.0.1`, `::1`) are let through, so a Docker or Coolify health check stays green during a push.

### `wp envsync unlock <env>`

Clears a stuck push lock left by a hub that died mid-push. Rolls nothing back — `rollback` can still restore that push's snapshot.

- `--yes` — skip the confirmation.

### `wp envsync rescue <env>`

Recovers a remote that crashes on every request, through `rescue.php` (no plugins, no theme loaded). With no flag it only reports the active plugins, the lock and the last job.

- `--rollback` — restore the locked push (or the last one), then clear the lock and the maintenance file.
- `--job=<id>` — roll back this job instead.
- `--plugins-off` — deactivate every plugin except EnvSync.
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

Paths are relative to wp-content. A trailing slash means the folder and everything under it, and nested paths work, such as `uploads/rank-math/`. A folder exclude matches only at that path: `cache/` is `wp-content/cache/`, not a plugin's own `src/cache/` folder. The exceptions are `.git/` and `node_modules/`, which are skipped wherever they appear.

Some things are always excluded and cannot be synced: `wp-config.php`, `.htaccess`, `.env`, `debug.log`, drop-ins, `.git`, `node_modules`, this plugin's own folder, and its storage folder.

---

## Different table prefixes

Hosts such as Hostinger give every install a random table prefix, so your local `wp_` site and the remote often differ. Each site keeps its own prefix; nothing is renamed.

`status` shows the pair when they differ:

```
  remote 0.6.0  hub 0.6.0  auth via Authorization  prefix ab12cd_ → wp_
```

The remote translates at its end. Your hub always works, plans and records its baseline in its own table names. WordPress also stores the prefix inside a few rows, and those are translated too:

- the `<prefix>user_roles` option (the role definitions);
- every usermeta key that starts with the prefix: `<prefix>capabilities`, `<prefix>user_level`, `<prefix>user-settings`, and what plugins store with `update_user_option()`.

Nothing else is rewritten. If a plugin stores a table name inside one of its own settings, add a pair for it: `wp envsync env add prod --replace=ab12cd_mytable:wp_mytable`.

A usermeta key that carries the *other* site's prefix, typically left over from an earlier prefix change, is left out of the sync on both sides, and a push lists it as refused.

Both sides need 0.6.0 or newer. Against an older remote the hub stops before anything changes:

```
remote prefix 'ab12cd_' differs from local 'wp_'; upload 0.6.0 or newer to the remote to sync across prefixes
```

A database shared by several installs whose prefixes overlap (`wp_` and `wp_2_`) is not supported: the remote's table listing can pick up the other install's tables.

---

## What is never touched

Options that are specific to one environment stay put on both sides: `siteurl`, `home`, `cron`, transients and the plugin's own settings. Everything else in `wp_options`, including theme mods and plugin settings, syncs normally.

`active_plugins` is merged rather than copied: plugins you activated or deactivated locally are applied on top of production's list, so a plugin the client enabled meanwhile is not silently turned off.

---

## Requirements

- Sites may use **different table prefixes** when both run 0.6.0 or newer. With an older remote, the prefixes must match; the hub stops on a mismatch before changing anything.
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

Agents should read files rather than terminal text:

| File (under `wp-content/envsync-*/`) | Written by | Holds |
|---|---|---|
| `plans/<kind>-<env>-latest.json` | `diff`, `push`, `pull` (including `--dry-run`) | The plan as JSON (`schema: 1`): `summary`, `tables`, `plugins`, `themes`, `other`, `conflicts`, `warnings`. Same data as the tables. |
| `runs/<kind>-<env>-latest.json` | `push`, `pull` | The outcome: `ok`, `job`, `seconds`, `files`, `bytes`, `rows`, `stale`, `error`. Written on failure too. |

`--format=json` prints the same plan to stdout. Every plan command prints the manifest path in its last line (`manifest: …`).

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
