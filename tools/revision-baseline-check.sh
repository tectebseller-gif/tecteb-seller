#!/usr/bin/env bash
# The approval path on a real WooCommerce — and then the same run with each new
# guard TAKEN BACK OUT, one line at a time.
#
# Four lines arrived in this round: the lock on the revision form, the new
# baseline after a revision, the sync result being read, and the merge rule
# measuring the manager's half against the agreement instead of the stamp. Each
# gets a run where the stage it governs is REQUIRED to fail. A guard that passes
# with the guard removed is not a guard.
#
#   bash tools/revision-baseline-check.sh docs/evidence/revision-baseline
set -u
OUT="${1:-docs/evidence/revision-baseline}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PLUGIN="$WPROOT/wp-content/plugins/tecteb-marketplace-core"
MERGE="$PLUGIN/src/Modules/Product/Domain/FieldMerge.php"
REVIEW="$PLUGIN/src/Modules/Product/Application/ReviewProducts.php"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

mkdir -p "$OUT"
LOG="$OUT/revision-baseline-check.txt"
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

STAGES="reset a_published_product_gets_a_revision approving_the_revision_moves_the_agreement and_a_revision_back_to_a_reaches_the_shop an_edit_during_a_revision_review_refuses_it and_pressing_again_asks_once_and_settles the_managers_title_becomes_the_agreement_without_a_stamp and_the_old_stamp_is_not_a_conflict a_failed_sync_is_not_a_review and_the_same_button_works_once_the_shop_does a_failed_sync_leaves_the_revision_pending and_the_revision_goes_through_afterwards restored"

run_state() {
  # `wp eval-file` reads from the WORDPRESS root, not from this repository — a
  # stale copy there answers a new command with «usage:», which reads like a
  # missing feature rather than an old file.
  cp "$REPO/tools/revision-baseline-state.php" "$WPROOT/revision-baseline-state.php"
  (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root eval-file revision-baseline-state.php run 2>/dev/null)
}
stage_of() { printf '%s\n' "$2" | grep "^stage=$1 " | grep -o 'ok=[a-z]*' | head -1; }
field_of() { printf '%s\n' "$3" | grep "^stage=$1 " | grep -o "$2=[^ ]*" | cut -d= -f2-; }

# Patched with python3 and byte-exact needles: the lines carry `$`, `(`, `)`
# and newlines, and a patch that silently matched nothing would read exactly
# like «the guard is not there» — the one answer this script must not give by
# accident.
patch_out() {
  python3 - "$1" "$2" <<'PYEOF'
import sys, pathlib
path, which = pathlib.Path(sys.argv[1]), sys.argv[2]
PATCHES = {
    # alpha.29: approveRevision recorded no new agreement, so a revision back
    # to an earlier value looked like no change at all.
    'no_baseline_after_a_revision': (
        "            if ($sync->ok && !$this->settleBaseline($product->id)) {\n",
        "            if (false) {\n",
    ),
    # alpha.29: and it carried no lock, so a manager's edit made while the page
    # was open was written over without a word.
    'no_lock_on_the_revision_form': (
        "        if ($product->status === ProductStatus::Published && $this->storefront !== null) {\n",
        "        if (false) {\n",
    ),
    # alpha.29: publish()'s result was discarded on the queue's approval.
    'the_sync_result_is_ignored_again': (
        "        if (!self::synced($sync)) {\n            // The status goes back where it was.",
        "        if (false) {\n            // The status goes back where it was.",
    ),
    # alpha.29: and on the revision path too.
    'the_revision_sync_result_is_ignored': (
        "            if (!self::synced($sync)) {\n                // Everything this method wrote goes back.",
        "            if (false) {\n                // Everything this method wrote goes back.",
    ),
    # alpha.29: the manager's half of the comparison was measured against the
    # projection stamp, so an agreement that adopted their value left the old
    # stamp behind to manufacture a conflict.
    'the_old_stamp_still_decides': (
        "        $agreed = $hasBaseline && hash_equals(self::fingerprint($baseline), self::fingerprint($shop));\n        $managerChanged = !$agreed && !hash_equals($stamp, self::fingerprint($shop));\n",
        "        $managerChanged = !hash_equals($stamp, self::fingerprint($shop));\n",
    ),
}
needle, replacement = PATCHES[which]
src = path.read_text()
if src.count(needle) != 1:
    sys.stderr.write("needle for %s not found exactly once (%d)\n" % (which, src.count(needle)))
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
check "the installed package is the one under test" "$VERSION" "${TMC_EXPECT_VERSION:-0.1.0-alpha.31}"
check "the upgrade gate reached schema 19" "$SCHEMA" "19"

say ""
say "=== every guard in place ==="
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
check "the agreement followed the first revision" \
  "$(field_of approving_the_revision_moves_the_agreement agreed "$REAL")" "yes"
check "and a revision back to the earlier value reached the shop" \
  "$(field_of and_a_revision_back_to_a_reaches_the_shop shop_is_a "$REAL")" "yes"
check "so the record and the shop are one version, not two" \
  "$(field_of and_a_revision_back_to_a_reaches_the_shop two_versions "$REAL")" "no"
check "an edit during a revision review is refused by name" \
  "$(field_of an_edit_during_a_revision_review_refuses_it code "$REAL")" "storefront_moved"
check "and that edit survived the refusal" \
  "$(field_of an_edit_during_a_revision_review_refuses_it edit_survived "$REAL")" "yes"
check "pressing again asks exactly one question, on the title" \
  "$(field_of and_pressing_again_asks_once_and_settles questions "$REAL")" "title"
check "and settling it leaves one value" \
  "$(field_of and_pressing_again_asks_once_and_settles settle "$REAL")" "proposal_accepted"
check "the manager's title became the agreement" \
  "$(field_of the_managers_title_becomes_the_agreement_without_a_stamp baseline_is_the_managers "$REAL")" "yes"
check "and no stamp claimed it for the marketplace" \
  "$(field_of the_managers_title_becomes_the_agreement_without_a_stamp stamp_untouched "$REAL")" "yes"
check "the vendor's next title was not called a conflict" \
  "$(field_of and_the_old_stamp_is_not_a_conflict vendor_title_reached_the_shop "$REAL")" "yes"
check "a refused sync is named, not reported as a review" \
  "$(field_of a_failed_sync_is_not_a_review code "$REAL")" "sync_failed"
check "and the status went back where it was" \
  "$(field_of a_failed_sync_is_not_a_review status "$REAL")" "submitted"
check "with the agreement untouched" \
  "$(field_of a_failed_sync_is_not_a_review baseline_untouched "$REAL")" "yes"
check "a refused sync leaves the revision pending" \
  "$(field_of a_failed_sync_leaves_the_revision_pending still_pending "$REAL")" "yes"
check "and the live product exactly as it was" \
  "$(field_of a_failed_sync_leaves_the_revision_pending shop_untouched "$REAL")" "yes"
check "and the record rolled back with it" \
  "$(field_of a_failed_sync_leaves_the_revision_pending record_rolled_back "$REAL")" "yes"

# ---------------------------------------------------------- falsification
falsify "$REVIEW" no_baseline_after_a_revision        and_a_revision_back_to_a_reaches_the_shop
falsify "$REVIEW" no_lock_on_the_revision_form        an_edit_during_a_revision_review_refuses_it
falsify "$REVIEW" the_sync_result_is_ignored_again    a_failed_sync_is_not_a_review
falsify "$REVIEW" the_revision_sync_result_is_ignored a_failed_sync_leaves_the_revision_pending
falsify "$MERGE"  the_old_stamp_still_decides         and_the_old_stamp_is_not_a_conflict

say ""
for pair in "$MERGE:src/Modules/Product/Domain/FieldMerge.php" \
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

rm -f "$WPROOT/revision-baseline-state.php"
say ""
say "checks: $((pass+fail))   passed: $pass   failed: $fail"
[ "$fail" -eq 0 ]
