#!/usr/bin/env bash
# «یک ویرایش فروشنده، یک پرسش» on a real WooCommerce — and then the same run
# with each rule TAKEN BACK OUT, one line at a time.
#
# Five rules arrived in this round and each gets a run of its own with that
# rule reverted, where the stage it governs is REQUIRED to fail. A guard that
# passes with the guard removed is not a guard; this repository learned that
# in `alpha.20` and has paid for every exception since.
#
#   bash tools/unified-review-check.sh docs/evidence/unified-review
set -u
OUT="${1:-docs/evidence/unified-review}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PLUGIN="$WPROOT/wp-content/plugins/tecteb-marketplace-core"
MERGE="$PLUGIN/src/Modules/Product/Domain/FieldMerge.php"
FIELDS="$PLUGIN/src/Modules/Product/Infrastructure/WooCommerce/WooCommerceStorefrontFields.php"
PROJECTOR="$PLUGIN/src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php"
REVIEW="$PLUGIN/src/Modules/Product/Application/ReviewProducts.php"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

mkdir -p "$OUT"
LOG="$OUT/unified-review-check.txt"
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

STAGES="reset the_marketplace_sets_an_address_once manager_edits_in_woocommerce one_vendor_edit_asks_nothing approval_writes_only_what_the_vendor_changed the_record_agrees_with_the_shop a_vendor_save_does_not_move_the_address a_real_conflict_asks_once an_edit_during_review_refuses_the_approval and_pressing_again_goes_through the_managers_message_reaches_the_vendor one_shop_cannot_read_anothers history_only_grows a_rejected_product_is_still_findable restored"

run_state() {
  # `wp eval-file` reads from the WORDPRESS root, not from this repository —
  # a stale copy there answers a new command with «usage:», which reads like
  # a missing feature rather than an old file.
  cp "$REPO/tools/unified-review-state.php" "$WPROOT/unified-review-state.php"
  (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root eval-file unified-review-state.php run 2>/dev/null)
}
stage_of() { printf '%s\n' "$2" | grep "^stage=$1 " | grep -o 'ok=[a-z]*' | head -1; }
field_of() { printf '%s\n' "$3" | grep "^stage=$1 " | grep -o "$2=[^ ]*" | cut -d= -f2-; }

# Patched with python3 and byte-exact needles: the lines carry `$`, `(`, `)`
# and newlines, and a patch that silently matched nothing would read exactly
# like «the rule is not there» — the one answer this script must not give by
# accident.
patch_out() {
  python3 - "$1" "$2" <<'PYEOF'
import sys, pathlib
path, which = pathlib.Path(sys.argv[1]), sys.argv[2]
PATCHES = {
    # Before this round every difference was a possible conflict, including a
    # manager edit to a field the vendor never touched.
    'skip_is_a_conflict_again': (
        "        if (self::fingerprint($baseline) === self::fingerprint($record)) {\n",
        "        if (false) {\n",
    ),
    # And the generated description re-raised its own question on every save.
    'a_generated_field_is_just_another_conflict': (
        "        if ($derived && $managerChanged) {\n            return self::MANAGER;\n        }\n",
        "",
    ),
    # `alpha.28` had no lock on the shop side at all.
    'no_lock_on_the_shop_side': (
        "        if ($seen === []) {\n            return [];          // nothing was claimed, so nothing is checked\n        }\n",
        "        return [];\n",
    ),
    # The slug was written on every projection, with no ownership test.
    'the_address_is_rewritten_every_time': (
        "        $slugIsOurs = $wcProductId <= 0\n",
        "        $slugIsOurs = true || $wcProductId <= 0\n",
    ),
    # Without the reconciliation the record keeps the vendor's own draft and
    # the two sides drift into two versions of one product.
    'no_reconciliation_after_approval': (
        "        $values = $this->storefront->storefrontValues($product);\n        if ($values === []) {\n",
        "        $values = $this->storefront->storefrontValues($product);\n        if (true) {\n",
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
  check "the installed plugin still parses ($which)" \
    "$($PHPBIN -l "$file" > /dev/null 2>&1 && echo yes || echo no)" "yes"
}

VERSION="$(cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root plugin get tecteb-marketplace-core --field=version 2>/dev/null)"
SCHEMA="$(cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root option get tmc_schema_version 2>/dev/null)"
say "installed plugin: ${VERSION:-unknown}   schema: ${SCHEMA:-unknown}"
check "the upgrade gate reached schema 19" "$SCHEMA" "19"
# Asked for the NAME, not a count: `wp db query` prints a header line above
# the row, so a `grep -c` answers 2 for one table — a number that looks like
# a failure while describing a healthy install.
DECISIONS="$(cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root db query "SHOW TABLES LIKE '%tmc_product_decisions'" 2>/dev/null | grep -x '[a-z_]*tmc_product_decisions' | head -1)"
check "the decision table exists" "${DECISIONS:+found}" "found"

say ""
say "=== every rule in place ==="
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

# Read out of the printed lines, so somebody outside the code can see it.
check "one vendor edit produced no questions at all" \
  "$(field_of one_vendor_edit_asks_nothing questions "$REAL")" "none"
check "and the manager's title was skipped, not asked about" \
  "$(printf '%s\n' "$REAL" | grep '^stage=one_vendor_edit_asks_nothing ' | grep -o 'title:[a-z]*' | cut -d: -f2)" "skip"
# The pipe is part of the needle on purpose: `description:` also matches
# inside `short_description:`, and the first hit wins — a cut that read the
# wrong field would report the wrong verdict without ever looking wrong.
check "and their generated text became theirs" \
  "$(printf '%s\n' "$REAL" | grep '^stage=one_vendor_edit_asks_nothing ' | grep -o '|description:[a-z]*' | cut -d: -f2)" "manager"
check "approval applied the one field the vendor changed" \
  "$(field_of approval_writes_only_what_the_vendor_changed short_applied "$REAL")" "yes"
check "and left the manager's title alone" \
  "$(field_of approval_writes_only_what_the_vendor_changed manager_title_survived "$REAL")" "yes"
check "and their long description with it" \
  "$(field_of approval_writes_only_what_the_vendor_changed manager_long_survived "$REAL")" "yes"
check "after approval there is one version, not two" \
  "$(field_of the_record_agrees_with_the_shop one_version "$REAL")" "yes"
check "a real disagreement still asks, once" \
  "$(field_of a_real_conflict_asks_once questions "$REAL")" "short_description"
check "an edit during review is refused by name" \
  "$(field_of an_edit_during_review_refuses_the_approval code "$REAL")" "storefront_moved"
check "and that edit survived the refusal" \
  "$(field_of an_edit_during_review_refuses_the_approval edit_survived "$REAL")" "yes"
check "the manager's note has a date" \
  "$(field_of the_managers_message_reaches_the_vendor has_date "$REAL")" "yes"
check "another shop reads nothing" \
  "$(field_of one_shop_cannot_read_anothers other_vendor_sees "$REAL")" "nothing"
check "a rejected product is still in the manager's list" \
  "$(field_of a_rejected_product_is_still_findable in_archived_list "$REAL")" "yes"
check "and the status counters add up to the total" \
  "$(field_of a_rejected_product_is_still_findable counts_add_up "$REAL")" "yes"

# ---------------------------------------------------------- falsification
falsify "$MERGE"     skip_is_a_conflict_again                   one_vendor_edit_asks_nothing
falsify "$MERGE"     a_generated_field_is_just_another_conflict one_vendor_edit_asks_nothing
falsify "$FIELDS"    no_lock_on_the_shop_side                   an_edit_during_review_refuses_the_approval
falsify "$PROJECTOR" the_address_is_rewritten_every_time        a_vendor_save_does_not_move_the_address
falsify "$REVIEW"    no_reconciliation_after_approval           the_record_agrees_with_the_shop

say ""
for pair in "$MERGE:src/Modules/Product/Domain/FieldMerge.php" \
            "$FIELDS:src/Modules/Product/Infrastructure/WooCommerce/WooCommerceStorefrontFields.php" \
            "$PROJECTOR:src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php" \
            "$REVIEW:src/Modules/Product/Application/ReviewProducts.php"; do
  installed="${pair%%:*}"; source="${pair#*:}"
  same="$(python3 -c "import sys,pathlib; print('same' if pathlib.Path(sys.argv[1]).read_bytes()==pathlib.Path(sys.argv[2]).read_bytes() else 'different')" "$installed" "$REPO/$source")"
  check "$(basename "$source") matches the repository again" "$same" "same"
done

say ""
say "=== the shipped code, once more, after every patch was reverted ==="
FINAL="$(run_state)"
printf '%s\n' "$FINAL" | tee -a "$LOG" >/dev/null
printf '%s\n' "$FINAL"
check "no stage fails on the restored install" \
  "$(printf '%s\n' "$FINAL" | grep -c 'ok=false' || true)" "0"

rm -f "$WPROOT/unified-review-state.php"
say ""
say "checks: $((pass+fail))  pass: $pass  fail: $fail"
[ "$fail" -eq 0 ]
