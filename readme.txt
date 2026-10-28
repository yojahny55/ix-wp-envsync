=== IX WP EnvSync ===
Contributors: yojahny55
Tags: sync, deploy, staging, migration, wp-cli
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.9.22
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync a WordPress site between local, staging and production. Push only what you changed. Production always wins.

== Description ==

Pull a full copy of a site down, work on it, then push back only your changes. Anything the client changed on production is kept, never overwritten. You always see a plan before anything moves.

Built for freelance work across mixed hosting where you have WordPress admin but not always SSH.

* **Pull** a full snapshot: database rows and files.
* **Diff** three ways (baseline, local, remote) and preview the plan.
* **Push** only your delta. Production wins every conflict.
* **Roll back** a bad push from the hub.
* **Deploy** a locally built site to a fresh install.
* **Scope** a sync with `--only`, `--tables`, `--paths` and excludes.

The site you run commands from is the hub and needs WP-CLI. A remote needs only the plugin and a token.

== Installation ==

1. Upload the plugin zip on every site through Plugins > Add New > Upload, then activate it.
2. On each remote, open Tools > EnvSync and copy the token.
3. On the hub, register the remote and check it:

`wp envsync env add prod https://example.com --token=PASTE_TOKEN --label=prod`

`wp envsync status`

== Frequently Asked Questions ==

= Does a remote need SSH or WP-CLI? =

No. A remote needs the plugin and a token. Only the hub needs WP-CLI.

= What if the client edits production while I work? =

A pull records a baseline. The three-way diff keeps their changes and pushes only yours.

= Can I undo a push? =

Yes. Run `wp envsync rollback <env>` from the hub.

== Screenshots ==

1. Tools > EnvSync: token, environments, last pushes and sync excludes.
2. `wp envsync diff prod`: the plan, before anything moves.

== Changelog ==

See CHANGELOG.md in the repository.

== Upgrade Notice ==

= 0.9.22 =
Plugins a push turns on are activated properly, and the hub can list and prune a remote's push snapshots.

= 0.9.21 =
Remotes prune old push snapshots and list them under Tools → EnvSync.

= 0.9.20 =
Fixes recovery of a keyed remote after a self-update rollback.

= 0.9.19 =
Documentation, branding and CI only; no change to plugin behaviour.

= 0.9.18 =
Signed sessions: the token stops travelling once the remote holds the hub's public key.
