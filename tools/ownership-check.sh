#!/usr/bin/env bash
# «ویرایش مدیر ← پیشنهاد فروشنده ← تأیید», run on a real WooCommerce, and
# then run again with the guard TAKEN OUT.
#
# The second half is the point. A concurrency or ownership guard that passes
# its own test with the guard removed is not testing the guard — this
# repository has made that mistake once already (`alpha.20`), and the rule it
# wrote afterwards is that every such guard gets one run with the guard gone.
# Here that means restoring the pre-`alpha.26` projector, where `writeOwned()`
# always wrote, and REQUIRING the `resave` stage to fail. If it passes, this
# script fails: a check that cannot fail is a check nobody should trust.
#
#   bash tools/ownership-check.sh docs/evidence/ownership
set -u
OUT="${1:-docs/evidence/ownership}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PLUGIN="$WPROOT/wp-content/plugins/tecteb-marketplace-core"
PROJECTOR="$PLUGIN/src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php"
MERGE="$PLUGIN/src/Modules/Product/Domain/FieldMerge.php"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

mkdir -p "$OUT"
LOG="$OUT/ownership-check.txt"
: > "$LOG"
pass=0; fail=0
say() { echo "$1" | tee -a "$LOG"; }
check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass=$((pass+1)); say "ok:   $(printf '%-58s' "$name") $expected"
  else
    fail=$((fail+1)); say "FAIL: $(printf '%-58s' "$name") expected=$expected actual=$actual"
  fi
}

run_state() {
  # `wp eval-file` reads the path from the WORDPRESS root, not from this
  # repository — a stale copy there answers a command this version added with
  # «usage:», which looks like a missing feature rather than an old file. So
  # every run copies first.
  cp "$REPO/tools/ownership-state.php" "$WPROOT/ownership-state.php"
  (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root eval-file ownership-state.php run 2>/dev/null)
}

say "=== the guard in place ==="
REAL="$(run_state)"
printf '%s\n' "$REAL" | tee -a "$LOG" >/dev/null
printf '%s\n' "$REAL"

for stage in reset project edit resave compare keep keep_survives_projection hold_short accept accept_returns_the_field category_edit_survives image_swap_survives_a_sync accept_images_is_idempotent no_featured_picture_is_seen_as_a_change images_restored render prepare; do
  line="$(printf '%s\n' "$REAL" | grep "^stage=$stage " || true)"
  if [ -z "$line" ]; then
    check "$stage reported" "missing" "ok=true"
  else
    check "$stage" "$(printf '%s' "$line" | grep -o 'ok=[a-z]*' | head -1)" "ok=true"
  fi
done

# The record and the shop must agree after «نسخهٔ ووکامرس بماند». Read out of
# the printed line rather than asserted again in PHP: the point is that
# somebody outside the code can see it.
check "one version of the product, not two" \
  "$(printf '%s\n' "$REAL" | grep '^stage=keep ' | grep -o 'one_version=[a-z]*' | cut -d= -f2)" "yes"
check "preparation leaves a draft, never a sale" \
  "$(printf '%s\n' "$REAL" | grep '^stage=prepare ' | grep -o 'status=[a-z]*' | cut -d= -f2)" "draft"
check "and the prepared product is not purchasable" \
  "$(printf '%s\n' "$REAL" | grep '^stage=prepare ' | grep -o 'purchasable=[a-z]*' | cut -d= -f2)" "no"
check "the review card shows pictures, not a count" \
  "$(printf '%s\n' "$REAL" | grep '^stage=render ' | grep -o 'gallery=[a-z]*' | cut -d= -f2)" "yes"
check "and the category as a path" \
  "$(printf '%s\n' "$REAL" | grep '^stage=render ' | grep -o 'category_path=[a-z]*' | cut -d= -f2)" "yes"

# ---------------------------------------------------------- falsification
say ""
say "=== the same run with the guard removed ==="
cp "$MERGE" "$OUT/merge.bak"
# The pre-alpha.26 behaviour, in one line: write unconditionally. Nothing
# else is touched, so a failure below is about ownership and not about a
# broken file.
# Patched with python3 rather than `php -r` or `sed`: the needle contains
# `$stamp`, `$current`, `(` and `)`, and every quoting layer between bash and
# PHP mangles one of them. A patch that silently matched nothing would read
# exactly like «the guard is not there» — the one answer this script must not
# give by accident, so the needle is compared as literal bytes.
python3 - "$MERGE" <<'PYEOF'
import sys, pathlib
path = pathlib.Path(sys.argv[1])
src = path.read_text()
# `alpha.29` moved the decision out of the projector and into this pure rule,
# so the guard to remove is the rule's first answer: make it always say
# «write» and the projector is back to the pre-`alpha.26` behaviour.
needle = "    ): string {\n        if ($managerOwns) {\n"
if src.count(needle) != 1:
    sys.stderr.write("guard line not found exactly once\n")
    sys.exit(2)
path.write_text(src.replace(needle, "    ): string {\n        return self::WRITE;\n        if ($managerOwns) {\n", 1))
PYEOF
falsify_status=$?

if [ "$falsify_status" -ne 0 ]; then
  check "the guard line was found and removed" "no" "yes"
else
  BROKEN="$(run_state)"
  printf '%s\n' "$BROKEN" | tee -a "$LOG" >/dev/null
  printf '%s\n' "$BROKEN"
  broken_resave="$(printf '%s\n' "$BROKEN" | grep '^stage=resave ' | grep -o 'ok=[a-z]*' | head -1)"
  check "without the guard the manager's text is destroyed" "$broken_resave" "ok=false"
  broken_survived="$(printf '%s\n' "$BROKEN" | grep '^stage=resave ' | grep -o 'survived=[a-z]*' | cut -d= -f2)"
  check "and the run says so in one word" "$broken_survived" "no"
fi

cp "$OUT/merge.bak" "$MERGE"
rm -f "$OUT/merge.bak"
# Proved restored, not assumed: the next evidence run uses this install.
restored="$(grep -c 'return self::WRITE;' "$MERGE" || true)"
check "the installed plugin was put back" "$restored" "2"
rm -f "$WPROOT/ownership-state.php"

say ""
say "checks: $((pass+fail))  pass: $pass  fail: $fail"
[ "$fail" -eq 0 ]
