# IX WP EnvSync

Pull a full snapshot from prod or staging. Push back only what you changed. Prod always wins. See the plan before anything moves.

## Install
1. Upload and activate on every site (local, staging, prod).
2. On each remote: Tools → EnvSync, copy the one-time token (or `wp envsync token`).
3. On your local site:
   wp envsync env add prod https://client.com --token=... --label=prod
   wp envsync env add staging https://client.yojahny.dev --token=... --label=staging

## Daily flow
   wp envsync pull prod --yes          # fresh copy + baseline
   ...work...
   wp envsync push staging --yes       # full overwrite for client review
   wp envsync diff prod                # see exactly what would change
   wp envsync push prod                # confirm, apply; prod-changed rows are kept
   wp envsync rollback prod            # if needed, restores the pre-push snapshot

## Requirements
- **The remote token is a full site takeover.** It grants write access to that site's database and to its whole `wp-content`, plugin PHP included. Treat it like an admin password: never commit it, never share it, rotate it when in doubt.
- Both sites must use the same table prefix (`wp envsync pull` errors out on a mismatch).
- Same table schema on both sides (v0.1 does not create tables).
- Pull is not resumable: if it fails part-way, fix the cause and run it again from the start.
- HTTPS on remotes (or `define('ENVSYNC_ALLOW_HTTP', true)` for local dev).
- Not a backup tool. Keep your backups.
