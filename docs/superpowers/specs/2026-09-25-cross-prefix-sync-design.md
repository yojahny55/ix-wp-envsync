# Sync across different table prefixes — design

Date: 2026-09-25. Target version: 0.6.0.

## Problem

EnvSync refuses to sync when the hub and the remote use different `$table_prefix`:

```
Error: remote prefix 'ab12cd_' differs from local 'wp_'; v0.1 requires identical prefixes
```

Hosts such as Hostinger generate a random prefix on every install, so a local site built with `wp_` cannot pull from, or deploy to, such a remote without renaming one side's tables first. Renaming by hand is error-prone: WordPress stores the prefix inside rows too (`<prefix>user_roles`, `<prefix>capabilities`), and missing one leaves every user without a role.

Only `pull` checks the prefix today (`class-ixes-pull.php:45`). `diff` and `push` do not: with different prefixes every local table looks new, and the push fails on `create_table_refusal`.

## Goal

- Each site keeps its own prefix. The hub can sync with several remotes that use different prefixes from the hub and from each other.
- `pull`, `diff`, `push` (including a first deploy with `--force` onto a fresh install), `rollback` and `rescue` all work across prefixes.
- After a pull from a remote with another prefix, users log in on the hub with the same roles. After a push, the remote's users keep theirs.

Out of scope: a database shared by several installs whose prefixes overlap (`SHOW TABLES LIKE prefix%` can already pick up the other install's tables; unchanged, documented). Prefix strings inside arbitrary option or meta values (use `--replace=` for those).

## Design

### Principle: the hub always works in its own names

Everything the hub computes or stores (planner, baseline, scope and `--tables`, plan and run manifests, pull state) keeps using the hub's table names. It does not change. The remote translates at its REST boundary, in both directions, so the rows and hashes it returns are already in the hub's form and the rows it receives are turned into its own form before they touch the database.

Row hashes must be comparable across sides. A remote that hashed `ab12cd_capabilities` while the hub hashed `wp_capabilities` would report every such row as changed. That is why the translation cannot live on the hub alone: the remote translates each row **before** hashing it.

### Protocol

- `/info` is unchanged except for a new capability, `prefix_map`. It still reports the remote's own `prefix` and table names.
- The hub (`IXES_Client::info()`) compares the remote prefix with its own. When they differ and the remote has `prefix_map`, the client:
  - rewrites the table names in the cached `info` to the hub's prefix and records `info['hub_prefix']`;
  - sends `X-Envsync-Prefix: <hub prefix>` on every later request, except rescue calls.
- The prefix header is covered by the HMAC signature: `IXES_Auth::sign()` appends `"\nprefix:" . $prefix` when a prefix is given. With no header, the message is exactly what it is today, so equal-prefix syncs and older sites are unaffected.
- `IXES_Rest::auth()` reads the header, includes it in `verify()`, checks it against `/^[A-Za-z0-9_]+$/`, and when it differs from the site's own prefix sets the request context `IXES_Prefix::set_current( new IXES_Prefix( $wpdb->prefix, $header ) )`.

### Compatibility

| Hub | Remote | Prefixes | Result |
|---|---|---|---|
| any | any | equal | as today, no header, same signature |
| 0.6.0 | < 0.6.0 | different | refused before anything changes: `remote prefix 'X' differs from local 'Y'; upload 0.6.0 or newer to the remote to sync across prefixes` |
| < 0.6.0 | 0.6.0 | different | refused by the old hub, as today |
| 0.6.0 | 0.6.0 | different | translated |

The refusal is one helper, `IXES_Pull::prefix_refusal( $info )`, called by `IXES_Pull::plan()` and by `IXES_Planner::build()`, so `diff` and `push` are covered too.

### `IXES_Prefix`: pure translation

One class, no database access, fully unit-tested.

```php
new IXES_Prefix( $local, $peer );        // this site's prefix, the other side's
->table_in( $peer_name )  : ?string      // peer table name -> local, null if it does not carry the peer prefix
->table_out( $local_name ): ?string      // local -> peer
->row_in( $bare, $row )   : ?array       // peer-form row -> local form; null = orphan, leave it out
->row_out( $bare, $row )  : ?array       // local-form row -> peer form; null = orphan
->sql_in( $sql )          : string       // CREATE TABLE and REFERENCES names, peer -> local
IXES_Prefix::current() / set_current()   // request-scoped context, null on the hub
```

`$bare` is the table name without its prefix (`options`, `usermeta`).

**Key rule** for a key `k` translated from prefix `A` to prefix `B`:

1. `k` starts with `A`: becomes `B . substr( k, strlen( A ) )`.
2. otherwise, `k` starts with `B`: an **orphan**. It carries the other side's prefix, typically left over from an earlier prefix change. The row is left out of the sync.
3. otherwise: unchanged.

Rule 1 is checked first, so overlapping prefixes (`wp_` and `wp_abc_`) round-trip exactly: `wp_abc_x` on a `wp_` site becomes `wp_abc_abc_x` and comes back as `wp_abc_x`.

**Which values** (WordPress core's own prefix use, the same set migration tools rewrite):

- `options`: `option_name` equal to `A . 'user_roles'` becomes `B . 'user_roles'`; equal to `B . 'user_roles'` is an orphan; everything else unchanged.
- `usermeta`: `meta_key` follows the key rule. That covers `capabilities`, `user_level`, `user-settings`, `user-settings-time`, `persisted_preferences`, `dashboard_quick_press_last_post_id`, and anything a plugin stores with `update_user_option()`.
- Every other table and column is unchanged.

**SQL**: `sql_in()` rewrites the name in `CREATE TABLE \`<A>...\`` and in every `REFERENCES \`<A>...\`` to `B`. `create_table_refusal()` then validates the translated SQL exactly as today.

### Where the remote translates

With `$map = IXES_Prefix::current()` (null means no translation, which is always the case on the hub):

- `IXES_Rest::hash_rows()` and `dump()`: `table` goes through `$map->table_in()`; an unknown name stays an error. `IXES_Transfer::dump()` passes each row through `row_out()` after its existing option filter and drops orphans. `hash_rows()` hashes the dumped rows, so it hashes the translated form.
- `job/start`: the keys of `plan_meta['tables']` go through `table_in()`. The snapshot (`rows-<table>.json`, `meta.json`) is written in the remote's own names, so `rollback` and `rescue --rollback` restore without any translation.
- `job/step`, via `IXES_Prefix::step_in( $p )` in the REST wrapper:
  - `rows`, `delete_rows`: `table` through `table_in()`; each row through `row_in()`. Orphan rows are removed and their keys returned in `refused`.
  - `create_table`: `table` through `table_in()`, `sql` through `sql_in()`.
  - `option`: the name follows the options rule (only `user_roles` can change).
- `IXES_Applier::stale()`: the current remote row goes through `row_out()` before it is hashed, so it compares with the hash the hub saw. An orphan counts as "no row", as an excluded option does today.

### Hub-side changes

- `IXES_Client`: prefix detection in `info()`, the header in `request()` (not for rescue), the signature argument.
- `IXES_Pull::prefix_refusal()` replaces the check at `class-ixes-pull.php:45` and is also called from `IXES_Planner::build()`.
- `IXES_Status`: `remote_posts` looks up `(hub_prefix ?? prefix) . 'posts'`. The environment line shows `prefix ab12cd_ → wp_` when the prefixes differ.

### Errors and edge cases

- A header with characters outside `[A-Za-z0-9_]`: `400 bad prefix`, nothing runs.
- Orphans on a push: reported in the step's `refused` list, like excluded options today, so the run manifest lists them.
- Orphan id collision: a hub row whose id matches a remote orphan's id is seen by the planner as an insert. The stale check treats the orphan as "no row", so the push overwrites the orphan. The orphan was junk the remote could not use either.
- First deploy onto a fresh install: the remote already has its own `<B>user_roles`. The hub's translated row has the same name and a different `option_id`. `$wpdb->replace()` resolves it through the UNIQUE `option_name`, as it already does for every option on a first deploy.

## Testing

Unit (`vendor/bin/phpunit`):

- `PrefixTest`: table in/out; the key rule, including orphans and both directions of an overlapping pair (`wp_`/`wp_abc_`) with an exact round trip; `user_roles` and its orphan; untouched tables and columns; `sql_in()` for `CREATE TABLE` and `REFERENCES`; `step_in()` for `rows`, `delete_rows`, `create_table` and `option`.
- `AuthTest`: a signature made with a prefix only verifies with the same prefix; without a prefix it is byte-identical to today's.
- `ClientLoopTest`: after an `info()` that reports another prefix and `prefix_map`, later requests carry `X-Envsync-Prefix` and a signature that includes it, the cached table names use the hub prefix, and rescue calls carry no header. With equal prefixes, or without the cap, no header is sent.
- `StatusTest`: `remote_posts` with a translated `info`.
- `prefix_refusal()`: equal prefixes, differing with the cap, differing without it.

Integration (`tests/integration.sh`, two throwaway installs): run the existing full cycle with **different prefixes** on the two installs. Check that after the pull an administrator on the hub still has the `administrator` role, and after a push the remote's administrator still has theirs. Then run the same cycle once more with equal prefixes to confirm nothing regressed.
