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
A post update "$PY" --post_content="y2" >/dev/null

# 3. diff shows push for X, kept for Y, no conflicts
OUT=$(B envsync diff prod)
echo "$OUT" | grep -q "CONFLICTS" && die "unexpected conflict:\n$OUT"

# 4. push
B envsync push prod --yes
[ "$(A post get "$PX" --field=post_content)" = "x2" ] || die "X not pushed"
[ "$(A post get "$PY" --field=post_content)" = "y2" ] || die "Y was overwritten (prod must win)"
[ "$(A post get "$NEW" --field=post_title)" = "New local" ] || die "new local post not inserted"
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

echo "ALL OK"
