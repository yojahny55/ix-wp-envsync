#!/usr/bin/env bash
# Two-install end-to-end: A = "prod", B = hub.
# Usage: IXES_A=/path/a IXES_A_URL=http://a.local IXES_B=/path/b IXES_B_URL=http://b.local tests/integration.sh
set -euo pipefail
: "${IXES_A:?}" "${IXES_A_URL:?}" "${IXES_B:?}" "${IXES_B_URL:?}"
PLUGIN="$(cd "$(dirname "$0")/.." && pwd)"
A() { wp --path="$IXES_A" --url="$IXES_A_URL" "$@"; }
B() { wp --path="$IXES_B" --url="$IXES_B_URL" "$@"; }
die() { echo "FAIL: $*" >&2; exit 1; }
A config delete ENVSYNC_TEST_DROP_AUTHORIZATION >/dev/null 2>&1 || true   # a run that died inside scenario 12 must not poison the next run

for P in "$IXES_A" "$IXES_B"; do
  rm -rf "$P/wp-content/plugins/ix-wp-envsync"; ln -s "$PLUGIN" "$P/wp-content/plugins/ix-wp-envsync"
done
A plugin activate ix-wp-envsync >/dev/null; B plugin activate ix-wp-envsync >/dev/null
TOKEN=$(A envsync token --rotate)
B envsync env remove prod >/dev/null 2>&1 || true
B envsync env add prod "$IXES_A_URL" --token="$TOKEN" --label=prod
B envsync env ping prod
PA=$(A db prefix); PB=$(B db prefix)   # the two installs may use different table prefixes
echo "prefixes: remote $PA, hub $PB"
roles() { "$1" user list --role=administrator --field=user_login | head -1; }
CODE=$(curl -s -o /dev/null -w '%{http_code}' -H 'Authorization: Bearer 0000' "$IXES_A_URL/?rest_route=/envsync/v1/info")
[ "$CODE" = "401" ] || die "bad token was not rejected (got $CODE)"

# seed prod
A post delete $(A post list --post_type=post --format=ids) --force >/dev/null 2>&1 || true
PX=$(A post create --post_title="X" --post_content="x1" --post_status=publish --porcelain)
PY=$(A post create --post_title="Y" --post_content="y1" --post_status=publish --porcelain)
mkdir -p "$IXES_A/wp-content/themes/ixtest"; echo "/* v1 */" > "$IXES_A/wp-content/themes/ixtest/style.css"

# 1. pull
B envsync pull prod --fresh --yes   # --fresh: a leftover resume state from an earlier run must not be auto-resumed here
[ "$(B post get "$PX" --field=post_content)" = "x1" ] || die "pull did not bring post X"
[ -f "$IXES_B/wp-content/themes/ixtest/style.css" ] || die "pull did not bring theme file"
[ -n "$(roles B)" ] || die "no administrator on the hub after the pull (user_roles or capabilities not translated)"

# 2. diverge: B edits X + theme, A edits Y
B post update "$PX" --post_content="x2" >/dev/null
echo "/* v2 */" > "$IXES_B/wp-content/themes/ixtest/style.css"
NEW=$(B post create --post_title="New local" --post_content="n" --post_status=publish --porcelain)
[ "$NEW" -gt 1000000 ] || die "auto_increment offset not applied (got $NEW)"
B term create category ixcat --porcelain >/dev/null 2>&1 || true  # may already exist from an earlier run's pull
B post term add "$NEW" category ixcat >/dev/null
A post update "$PY" --post_content="y2" >/dev/null

# 3. diff shows push for X, kept for Y, no conflicts
OUT=$(B envsync diff prod)
echo "$OUT" | grep -q "CONFLICTS" && die "unexpected conflict:\n$OUT"

# 4. push
B envsync push prod --yes
[ "$(A post get "$PX" --field=post_content)" = "x2" ] || die "X not pushed"
[ "$(A post get "$PY" --field=post_content)" = "y2" ] || die "Y was overwritten (prod must win)"
[ "$(A post get "$NEW" --field=post_title)" = "New local" ] || die "new local post not inserted"
A post term list "$NEW" category --field=name | grep -qx ixcat || die "term relationship (no-PK set_insert) not pushed"
grep -q v2 "$IXES_A/wp-content/themes/ixtest/style.css" || die "theme file not pushed"
[ -f "$IXES_A/.maintenance" ] && die "maintenance file left behind"
SD=$(ls -d "$IXES_B/wp-content/envsync-"*)
php -r '$j=json_decode(file_get_contents($argv[1]),true); exit(($j["schema"]??0)===1 && isset($j["summary"]["files"],$j["plugins"],$j["themes"],$j["other"])?0:1);' "$SD/plans/push-prod-latest.json" || die "push manifest missing or malformed"
php -r '$j=json_decode(file_get_contents($argv[1]),true); exit(($j["ok"]??false)===true && !empty($j["job"])?0:1);' "$SD/runs/push-prod-latest.json" || die "push result file missing or not ok"
B envsync diff prod --format=json | php -r '$j=json_decode(stream_get_contents(STDIN),true); exit(($j["kind"]??"")==="diff"?0:1);' || die "diff --format=json is not the manifest"

# 5. conflict: both edit X after a fresh pull
B envsync pull prod --yes
B post update "$PX" --post_content="x-local" >/dev/null
A post update "$PX" --post_content="x-prod" >/dev/null
OUT=$(B envsync diff prod)
echo "$OUT" | grep -q "CONFLICTS" || die "conflict not reported:\n$OUT"
B envsync push prod --yes
[ "$(A post get "$PX" --field=post_content)" = "x-prod" ] || die "prod did not win the conflict"

# 6. rollback restores the pre-push state of the last job (Y untouched, X still x-prod)
B post update "$PY" --post_content="y-local" >/dev/null
B envsync pull prod --yes >/dev/null
B post update "$PY" --post_content="y-local" >/dev/null
B envsync push prod --yes >/dev/null
[ "$(A post get "$PY" --field=post_content)" = "y-local" ] || die "push before rollback failed"
B envsync rollback prod --yes
[ "$(A post get "$PY" --field=post_content)" = "x-prod" ] && die "rollback restored wrong row"
[ "$(A post get "$PY" --field=post_content)" = "y2" ] || die "rollback did not restore Y (got $(A post get "$PY" --field=post_content))"

# 7. a pull killed mid-way resumes and completes
dd if=/dev/urandom of="$IXES_A/wp-content/uploads/big.bin" bs=1M count=200 status=none
# clean start with the big file in the plan; this run is killed the moment the partial file appears,
# so the kill lands inside the file phase whatever the machine's speed
rm -f "$IXES_B/wp-content/uploads/big.bin" "$IXES_B/wp-content/uploads/big.bin.ixes-tmp"
# run wp directly (not the B function): $! must be the php process, or kill -9 only takes the subshell and the pull keeps running
wp --path="$IXES_B" --url="$IXES_B_URL" envsync pull prod --fresh --yes >/dev/null 2>&1 &
PULL_PID=$!
for _ in $(seq 1 600); do [ -f "$IXES_B/wp-content/uploads/big.bin.ixes-tmp" ] && break; sleep 0.1; done
[ -f "$IXES_B/wp-content/uploads/big.bin.ixes-tmp" ] || die "pull never reached the big file (still running: $(kill -0 $PULL_PID 2>/dev/null && echo yes || echo no))"
kill -9 $PULL_PID 2>/dev/null || true; wait $PULL_PID 2>/dev/null || true
ls "$IXES_B/wp-content/envsync-"*/pull-prod.json >/dev/null 2>&1 || die "no resume state after an interrupted pull"
OUT=$(B envsync pull prod --yes)
echo "$OUT" | grep -q "interrupted pull" || die "resume prompt not shown"
cmp "$IXES_A/wp-content/uploads/big.bin" "$IXES_B/wp-content/uploads/big.bin" || die "big file differs after resume"
ls "$IXES_B/wp-content/envsync-"*/pull-prod.json 2>/dev/null && die "state file left behind after a completed pull"

# 8. --only=themes push: theme file goes up, prod-edited post untouched
B envsync pull prod --yes >/dev/null
echo "/* v3 */" > "$IXES_B/wp-content/themes/ixtest/style.css"
B post update "$PX" --post_content="x-local-only" >/dev/null
A post update "$PY" --post_content="y-prod-edit" >/dev/null
B envsync diff prod --only=themes | grep -q "scope: themes" || die "scope not shown in diff"
B envsync push prod --only=themes --yes >/dev/null
grep -q v3 "$IXES_A/wp-content/themes/ixtest/style.css" || die "theme not pushed with --only=themes"
[ "$(A post get "$PX" --field=post_content)" != "x-local-only" ] || die "db row pushed despite --only=themes"
[ "$(A post get "$PY" --field=post_content)" = "y-prod-edit" ] || die "prod edit lost"
B envsync env list | grep -q "partial" && die "a scoped push must not mark the baseline partial"
B envsync pull prod --only=uploads --yes >/dev/null
B envsync env list | grep -q "partial .*(uploads)" || die "partial pull not reflected in env list"

# 9. a 0.2 hub asks for JSON: the remote must still answer base64
TS=$(date +%s); BODY='{"path":"themes/ixtest/style.css","offset":0,"size":1024}'
MSG=$(printf 'POST\n/envsync/v1/file/get\n%s\n%s' "$TS" "$(printf '%s' "$BODY" | sha256sum | cut -d' ' -f1)")
SIG=$(printf '%s' "$MSG" | openssl dgst -sha256 -hmac "$TOKEN" | sed 's/^.* //')
OUT=$(curl -s -H "Authorization: Bearer $TOKEN" -H "X-Envsync-Ts: $TS" -H "X-Envsync-Sig: $SIG" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "$BODY" "$IXES_A_URL/?rest_route=/envsync/v1/file/get")
echo "$OUT" | grep -q '"data":"' || die "JSON file/get no longer served: $OUT"
echo "$OUT" | php -r '$j=json_decode(stream_get_contents(STDIN),true); exit(hash("sha256",base64_decode($j["data"]))===$j["sha256"]?0:1);' || die "JSON chunk hash mismatch"

# 10. status reports an interrupted pull and recommends resuming
dd if=/dev/urandom of="$IXES_A/wp-content/uploads/big2.bin" bs=1M count=200 status=none
rm -f "$IXES_B/wp-content/uploads/big2.bin" "$IXES_B/wp-content/uploads/big2.bin.ixes-tmp"
wp --path="$IXES_B" --url="$IXES_B_URL" envsync pull prod --fresh --yes >/dev/null 2>&1 &
PULL_PID=$!
for _ in $(seq 1 600); do [ -f "$IXES_B/wp-content/uploads/big2.bin.ixes-tmp" ] && break; sleep 0.1; done
kill -9 $PULL_PID 2>/dev/null || true; wait $PULL_PID 2>/dev/null || true
OUT=$(B envsync status prod)
echo "$OUT" | grep -q "interrupted pull" || die "status did not report the interrupted pull:\n$OUT"
echo "$OUT" | grep -q "Next: wp envsync pull prod" || die "status did not recommend resuming"
B envsync status --json | php -r '$j=json_decode(stream_get_contents(STDIN),true); exit($j["next"]["command"]==="wp envsync pull prod"?0:1);' || die "status --json next mismatch"
B envsync pull prod --yes >/dev/null
cmp "$IXES_A/wp-content/uploads/big2.bin" "$IXES_B/wp-content/uploads/big2.bin" || die "resume after status failed"

# 11. a dead push left a lock; status reports it; unlock clears it; the next push works
wp --path="$IXES_A" eval 'set_transient( IXES_Applier::LOCK, IXES_Applier::lock_value( "20260101-000000-abcdef", time() - 700 ), HOUR_IN_SECONDS );' >/dev/null   # 700s clears both UNLOCK_MIN_AGE (120s) and status's LOCK_STALE_MIN (10min); no .maintenance file — a real one blocks our own REST calls (503)
OUT=$(B envsync status prod)
echo "$OUT" | grep -q "lock: job 20260101-000000-abcdef" || die "status did not report the remote lock:\n$OUT"
echo "$OUT" | grep -q "Next: wp envsync unlock prod" || die "status did not recommend unlock:\n$OUT"
B envsync unlock prod --yes >/dev/null || die "unlock failed"
[ -f "$IXES_A/.maintenance" ] && die "unlock left .maintenance behind"
B post update "$PX" --post_content="x-lock-test" >/dev/null
B envsync push prod --yes >/dev/null || die "push after unlock failed"
[ "$(A post get "$PX" --field=post_content)" = "x-lock-test" ] || die "push after unlock did not apply"

# 12. Authorization header stripped: the X-Envsync-Token fallback carries the request
wp --path="$IXES_A" config set ENVSYNC_TEST_DROP_AUTHORIZATION true --raw >/dev/null
sleep 3   # A runs behind a long-lived php -S process with opcache.revalidate_freq=2s; give it time to notice the wp-config.php edit
B envsync env ping prod | grep -q "auth via X-Envsync-Token" || die "ping did not report the fallback carrier"
B envsync pull prod --fresh --yes >/dev/null || die "pull failed with Authorization stripped"
wp --path="$IXES_A" config delete ENVSYNC_TEST_DROP_AUTHORIZATION >/dev/null
sleep 3

# 13. a plugin's own table exists only on the hub: push creates it, fills it, and rollback drops it; many small files go in batches
A db query "DROP TABLE IF EXISTS ${PA}ixdemo_log" >/dev/null; B db query "DROP TABLE IF EXISTS ${PB}ixdemo_log" >/dev/null
B db query "CREATE TABLE ${PB}ixdemo_log ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, msg varchar(50) NOT NULL, PRIMARY KEY (id) ) ENGINE=InnoDB" >/dev/null
B db query "INSERT INTO ${PB}ixdemo_log (msg) VALUES ('one'),('two'),('three')" >/dev/null
mkdir -p "$IXES_B/wp-content/themes/ixtest/parts"
for i in $(seq 1 150); do echo "/* part $i */" > "$IXES_B/wp-content/themes/ixtest/parts/p$i.css"; done
B envsync diff prod | grep -q "${PB}ixdemo_log (new)" || die "new table not shown in the plan"
B envsync push prod --yes >/dev/null || die "push with a new table failed"
[ "$(A db query "SELECT COUNT(*) FROM ${PA}ixdemo_log" --skip-column-names)" = "3" ] || die "new table not created and filled on the remote"
[ "$(ls "$IXES_A/wp-content/themes/ixtest/parts" | wc -l)" = "150" ] || die "batched small files missing on the remote"
cmp "$IXES_A/wp-content/themes/ixtest/parts/p77.css" "$IXES_B/wp-content/themes/ixtest/parts/p77.css" || die "batched file content differs"
B envsync rollback prod --yes >/dev/null
[ -z "$(A db query "SHOW TABLES LIKE '${PA}ixdemo_log'" --skip-column-names)" ] || die "rollback did not drop the created table"
[ -e "$IXES_A/wp-content/themes/ixtest/parts/p1.css" ] && die "rollback left batched files behind"
B db query "DROP TABLE ${PB}ixdemo_log" >/dev/null; rm -rf "$IXES_B/wp-content/themes/ixtest/parts"

# 13b. a plugin's own folder named like a default exclude (cache/) still syncs
mkdir -p "$IXES_B/wp-content/plugins/ixnest/src/cache"; echo "<?php // nested" > "$IXES_B/wp-content/plugins/ixnest/src/cache/load.php"
B envsync push prod --yes >/dev/null || die "push of a nested cache/ folder failed"
[ -f "$IXES_A/wp-content/plugins/ixnest/src/cache/load.php" ] || die "nested cache/ folder inside a plugin was excluded"
rm -rf "$IXES_B/wp-content/plugins/ixnest" "$IXES_A/wp-content/plugins/ixnest"

# 13c. the remote holds a transient at the option_id of a new hub option: the option must still arrive (not "changed on prod")
B option delete ixcollide >/dev/null 2>&1 || true; A option delete ixcollide >/dev/null 2>&1 || true
B option add ixcollide "hub-value" >/dev/null
CID=$(B db query "SELECT option_id FROM ${PB}options WHERE option_name='ixcollide'" --skip-column-names)
A db query "DELETE FROM ${PA}options WHERE option_id=$CID" >/dev/null
A db query "INSERT INTO ${PA}options (option_id, option_name, option_value, autoload) VALUES ($CID, '_transient_ixcollide_probe', 'remote-transient', 'no')" >/dev/null
OUT=$(B envsync push prod --yes 2>&1) || die "push with an option-id collision failed:\n$OUT"
echo "$OUT" | grep -q "during push" && die "colliding option reported as changed on prod:\n$OUT"
[ "$(A option get ixcollide)" = "hub-value" ] || die "option whose id a remote transient held did not arrive"
[ "$(A db query "SELECT option_value FROM ${PA}options WHERE option_name='_transient_ixcollide_probe'" --skip-column-names)" = "remote-transient" ] || die "the remote transient was overwritten"
B envsync rollback prod --yes >/dev/null
[ -z "$(A db query "SELECT option_id FROM ${PA}options WHERE option_name='ixcollide'" --skip-column-names)" ] || die "rollback left the re-keyed option behind"
A db query "DELETE FROM ${PA}options WHERE option_name='_transient_ixcollide_probe'" >/dev/null; B option delete ixcollide >/dev/null

# 14. a plugin that fatals on every web request (not under WP-CLI)
BOOM='<?php
/*
Plugin Name: IX Boom
*/
if ( ! defined( "WP_CLI" ) ) throw new Error( "ixboom" );'
mkdir -p "$IXES_B/wp-content/plugins/ixboom"; echo "$BOOM" > "$IXES_B/wp-content/plugins/ixboom/ixboom.php"
B plugin activate ixboom >/dev/null
# 14a. a push that switches it on breaks the remote at the last step; normal abort fails, so it rolls back through rescue.php
OUT=$(B envsync push prod --yes 2>&1) && die "a push that breaks the remote reported success"
echo "$OUT" | grep -q "through the rescue endpoint" || die "push did not roll back through rescue:\n$OUT"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$IXES_A_URL/")" != "500" ] || die "remote still broken after the rescue rollback"
A --skip-plugins option get active_plugins --format=json | grep -q ixboom && die "rescue rollback left ixboom active"
[ -e "$IXES_A/wp-content/plugins/ixboom/ixboom.php" ] && die "rescue rollback left the pushed plugin file"
[ -f "$IXES_A/.maintenance" ] && die "rescue rollback left .maintenance behind"
# 14b. a remote broken outside a push: status points at rescue, and --plugins-off revives it
mkdir -p "$IXES_A/wp-content/plugins/ixboom"; echo "$BOOM" > "$IXES_A/wp-content/plugins/ixboom/ixboom.php"
A --skip-plugins option update active_plugins '["ix-wp-envsync/ix-wp-envsync.php","ixboom/ixboom.php"]' --format=json >/dev/null
[ "$(curl -s -o /dev/null -w '%{http_code}' "$IXES_A_URL/")" = "500" ] || die "test setup: remote is not broken"
B envsync status prod | grep -q "Next: wp envsync rescue prod" || die "status did not recommend rescue for a crashing remote"
B envsync rescue prod | grep -q "ixboom/ixboom.php" || die "rescue status did not list the active plugins"
B envsync rescue prod --plugins-off --yes >/dev/null || die "rescue --plugins-off failed"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$IXES_A_URL/")" != "500" ] || die "remote still broken after --plugins-off"
B envsync env ping prod >/dev/null || die "REST not back after --plugins-off"
rm -rf "$IXES_A/wp-content/plugins/ixboom"; B plugin deactivate ixboom >/dev/null; rm -rf "$IXES_B/wp-content/plugins/ixboom"

[ -n "$(roles A)" ] || die "no administrator left on the remote after the pushes"
[ -n "$(roles B)" ] || die "no administrator left on the hub"
echo "ALL OK"
