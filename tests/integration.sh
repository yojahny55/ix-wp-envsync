#!/usr/bin/env bash
# Two-install end-to-end: A = "prod", B = hub.
# Usage: IXES_A=/path/a IXES_A_URL=http://a.local IXES_B=/path/b IXES_B_URL=http://b.local tests/integration.sh
set -euo pipefail
: "${IXES_A:?}" "${IXES_A_URL:?}" "${IXES_B:?}" "${IXES_B_URL:?}"
PLUGIN="$(cd "$(dirname "$0")/.." && pwd)"
A() { wp --path="$IXES_A" --url="$IXES_A_URL" "$@"; }
B() { wp --path="$IXES_B" --url="$IXES_B_URL" "$@"; }
die() { echo "FAIL: $*" >&2; exit 1; }

for P in "$IXES_A" "$IXES_B"; do
  rm -rf "$P/wp-content/plugins/ix-wp-envsync"; ln -s "$PLUGIN" "$P/wp-content/plugins/ix-wp-envsync"
done
A plugin activate ix-wp-envsync >/dev/null; B plugin activate ix-wp-envsync >/dev/null
TOKEN=$(A envsync token --rotate)
B envsync env remove prod >/dev/null 2>&1 || true
B envsync env add prod "$IXES_A_URL" --token="$TOKEN" --label=prod
B envsync env ping prod
CODE=$(curl -s -o /dev/null -w '%{http_code}' -H 'Authorization: Bearer 0000' "$IXES_A_URL/?rest_route=/envsync/v1/info")
[ "$CODE" = "401" ] || die "bad token was not rejected (got $CODE)"

# seed prod
A post delete $(A post list --post_type=post --format=ids) --force >/dev/null 2>&1 || true
PX=$(A post create --post_title="X" --post_content="x1" --post_status=publish --porcelain)
PY=$(A post create --post_title="Y" --post_content="y1" --post_status=publish --porcelain)
mkdir -p "$IXES_A/wp-content/themes/ixtest"; echo "/* v1 */" > "$IXES_A/wp-content/themes/ixtest/style.css"

# 1. pull
B envsync pull prod --yes
[ "$(B post get "$PX" --field=post_content)" = "x1" ] || die "pull did not bring post X"
[ -f "$IXES_B/wp-content/themes/ixtest/style.css" ] || die "pull did not bring theme file"

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
dd if=/dev/urandom of="$IXES_A/wp-content/uploads/big.bin" bs=1M count=40 status=none
# clean start with the big file in the plan; this is the run that gets killed mid-transfer
timeout 8 env WP_CLI_STRICT_ARGS_MODE=1 wp --path="$IXES_B" --url="$IXES_B_URL" envsync pull prod --fresh --yes >/dev/null 2>&1 || true
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

echo "ALL OK"
