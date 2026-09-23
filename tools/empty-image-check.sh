#!/usr/bin/env bash
# «مقدار خالی» و «حذف تصویر اصلی», run on a real WooCommerce — and then run
# again with each fix TAKEN BACK OUT, one line at a time.
#
# The second half is the point, and it is this repository's oldest rule about
# guards: a check that passes with the thing it checks removed is not a check.
# So every one of the four lines this phase added gets a run of its own with
# that line reverted to what `alpha.27` had, and the stage it governs is
# REQUIRED to fail. If it passes, this script fails.
#
#   bash tools/empty-image-check.sh docs/evidence/empty-and-images
set -u
OUT="${1:-docs/evidence/empty-and-images}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PLUGIN="$WPROOT/wp-content/plugins/tecteb-marketplace-core"
FIELDS="$PLUGIN/src/Modules/Product/Infrastructure/WooCommerce/WooCommerceStorefrontFields.php"
PROJECTOR="$PLUGIN/src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

mkdir -p "$OUT"
LOG="$OUT/empty-image-check.txt"
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

STAGES="reset hold_empty_short accept_empty_clears_the_field empty_survives_the_sync absent_proposal_is_not_an_empty_one an_empty_title_proposal_is_refused a_silent_save_failure_is_not_a_success and_the_retry_goes_through marketplace_removes_the_main_picture removal_survives_the_next_sync all_pictures_removed manager_owned_pictures_are_not_cleared unsettled_pictures_are_not_cleared pictures_restored"

run_state() {
  # `wp eval-file` reads the path from the WORDPRESS root, not from this
  # repository — a stale copy there answers a command this version added with
  # «usage:», which looks like a missing feature rather than an old file.
  cp "$REPO/tools/empty-image-state.php" "$WPROOT/empty-image-state.php"
  (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root eval-file empty-image-state.php run 2>/dev/null)
}

stage_of() { printf '%s\n' "$2" | grep "^stage=$1 " | grep -o 'ok=[a-z]*' | head -1; }
field_of() { printf '%s\n' "$3" | grep "^stage=$1 " | grep -o "$2=[^ ]*" | cut -d= -f2; }

# Patched with python3 rather than `php -r` or `sed`: the needles carry `$`,
# `(`, `)` and newlines, and every quoting layer between bash and PHP mangles
# one of them. A patch that silently matched nothing would read exactly like
# «the guard is not there» — the one answer this script must not give by
# accident, so the needle is compared as literal bytes and a miss exits 2.
patch_out() {
  python3 - "$1" "$2" <<'PYEOF'
import sys, pathlib
path, which = pathlib.Path(sys.argv[1]), sys.argv[2]
PATCHES = {
    # alpha.27 wrote a field only when the value was non-empty, so «پاک شود»
    # was never applied.
    'empty_is_never_written': (
        "        $changes = ProjectedFieldOwnership::fingerprint($value)\n"
        "            !== ProjectedFieldOwnership::fingerprint($before);\n",
        "        $changes = $value !== '';\n",
    ),
    # alpha.27 trusted `save() > 0`, which is the product id and comes back
    # from a save that changed nothing.
    'no_read_back_after_the_save': (
        "        if ($changes && ProjectedFieldOwnership::fingerprint($after)\n"
        "            === ProjectedFieldOwnership::fingerprint($before)) {\n",
        "        if (false) {\n",
    ),
    # alpha.27 had no required-field check on this path at all.
    'no_required_field_check': (
        "        if ($value === '' && !StorefrontField::mayBeEmpty($field)) {\n",
        "        if (false) {\n",
    ),
    # alpha.27's projector: zero never reached WooCommerce.
    'zero_main_image_is_skipped': (
        "                $p->set_image_id($images->main);\n",
        "                if ($images->main > 0) { $p->set_image_id($images->main); }\n",
    ),
}
needle, replacement = PATCHES[which]
src = path.read_text()
if src.count(needle) != 1:
    sys.stderr.write("needle for %s not found exactly once\n" % which)
    sys.exit(2)
path.write_text(src.replace(needle, replacement, 1))
PYEOF
}

falsify() {
  local file="$1" which="$2" stage="$3"
  say ""
  say "=== with «$which» taken back out ==="
  cp "$file" "$OUT/falsify.bak"
  if ! patch_out "$file" "$which"; then
    check "the line for $which was found and removed" "no" "yes"
    rm -f "$OUT/falsify.bak"
    return
  fi
  local BROKEN
  BROKEN="$(run_state)"
  printf '%s\n' "$BROKEN" | tee -a "$LOG" >/dev/null
  printf '%s\n' "$BROKEN"
  check "without it, $stage fails" "$(stage_of "$stage" "$BROKEN")" "ok=false"
  cp "$OUT/falsify.bak" "$file"
  rm -f "$OUT/falsify.bak"
  # Proved restored, not assumed: the next round runs against this install.
  check "the installed plugin was put back ($which)" \
    "$($PHPBIN -l "$file" > /dev/null 2>&1 && echo yes || echo no)" "yes"
}

VERSION="$(cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root plugin get tecteb-marketplace-core --field=version 2>/dev/null)"
say "installed plugin: ${VERSION:-unknown}"
say "=== both fixes in place ==="
REAL="$(run_state)"
printf '%s\n' "$REAL" | tee -a "$LOG" >/dev/null
printf '%s\n' "$REAL"

for stage in $STAGES; do
  line="$(stage_of "$stage" "$REAL")"
  if [ -z "$line" ]; then
    check "$stage reported" "missing" "ok=true"
  else
    check "$stage" "$line" "ok=true"
  fi
done

# Read out of the printed lines rather than asserted again inside PHP: the
# point is that somebody outside the code can see the answer.
check "the short description really is empty" \
  "$(field_of accept_empty_clears_the_field really_empty "$REAL")" "yes"
check "and the stamp matches what WooCommerce stored" \
  "$(field_of accept_empty_clears_the_field stamp_matches_stored_value "$REAL")" "yes"
check "an absent proposal took the record's value, not nothing" \
  "$(field_of absent_proposal_is_not_an_empty_one took_the_record_value "$REAL")" "yes"
check "a failed save kept the proposal" \
  "$(field_of a_silent_save_failure_is_not_a_success proposal_kept "$REAL")" "yes"
check "and reported no success" \
  "$(field_of a_silent_save_failure_is_not_a_success reported_success "$REAL")" "no"
check "the featured picture was really removed" \
  "$(field_of marketplace_removes_the_main_picture featured_removed "$REAL")" "yes"
check "the gallery order survived the removal" \
  "$(field_of marketplace_removes_the_main_picture gallery_order_kept "$REAL")" "yes"
check "and nothing was promoted into the empty slot" \
  "$(field_of marketplace_removes_the_main_picture first_gallery_picture_promoted "$REAL")" "no"
check "a manager-owned arrangement was left alone" \
  "$(field_of manager_owned_pictures_are_not_cleared featured_kept "$REAL")" "yes"
check "an unsettled one too, and it was recorded as a proposal" \
  "$(field_of unsettled_pictures_are_not_cleared recorded_as_a_proposal "$REAL")" "yes"

# ---------------------------------------------------------- falsification
falsify "$FIELDS"     empty_is_never_written       accept_empty_clears_the_field
falsify "$FIELDS"     no_required_field_check      an_empty_title_proposal_is_refused
falsify "$FIELDS"     no_read_back_after_the_save  a_silent_save_failure_is_not_a_success
falsify "$PROJECTOR"  zero_main_image_is_skipped   marketplace_removes_the_main_picture

# And the install is demonstrably the shipped one again, byte for byte.
say ""
FIELDS_OK="$(python3 -c "import sys,pathlib; a=pathlib.Path(sys.argv[1]).read_bytes(); b=pathlib.Path(sys.argv[2]).read_bytes(); print('same' if a==b else 'different')" "$FIELDS" "$REPO/src/Modules/Product/Infrastructure/WooCommerce/WooCommerceStorefrontFields.php")"
PROJ_OK="$(python3 -c "import sys,pathlib; a=pathlib.Path(sys.argv[1]).read_bytes(); b=pathlib.Path(sys.argv[2]).read_bytes(); print('same' if a==b else 'different')" "$PROJECTOR" "$REPO/src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php")"
check "WooCommerceStorefrontFields.php matches the repository again" "$FIELDS_OK" "same"
check "WooCommerceProjector.php matches the repository again" "$PROJ_OK" "same"

# One last clean run, so the evidence ends with the shipped code passing.
say ""
say "=== the shipped code, once more, after every patch was reverted ==="
FINAL="$(run_state)"
printf '%s\n' "$FINAL" | tee -a "$LOG" >/dev/null
printf '%s\n' "$FINAL"
check "no stage fails on the restored install" \
  "$(printf '%s\n' "$FINAL" | grep -c 'ok=false' || true)" "0"

rm -f "$WPROOT/empty-image-state.php"
say ""
say "checks: $((pass+fail))  pass: $pass  fail: $fail"
[ "$fail" -eq 0 ]
