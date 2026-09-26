#!/usr/bin/env bash
# Each of the three gaps, reproduced on the code that shipped as `alpha.29`.
#
# The owner asked for it in those words: «برای هر مورد، آزمون بازتولید روی
# alpha.29 و قبولی پس از اصلاح ارائه کن». So the two files this round changed
# are put back to their `alpha.29` bytes — read out of git, not retyped — and
# the new tests are RUN against them. Every test named below must fail there
# and pass here; a reproduction that passes on the old code reproduces nothing,
# and this script fails when that happens.
#
#   bash tools/alpha29-reproduction.sh docs/evidence/alpha29-reproduction
set -u
OUT="${1:-docs/evidence/alpha29-reproduction}"
BASE="${TMC_BASE_REF:-ff91e8b}"          # the commit that built alpha.29
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"
[ -f .env.testing ] && { set -a; . ./.env.testing; set +a; }

FILES="src/Modules/Product/Domain/FieldMerge.php src/Modules/Product/Domain/StorefrontField.php src/Modules/Product/Application/ReviewProducts.php"
mkdir -p "$OUT"
LOG="$OUT/alpha29-reproduction.txt"
: > "$LOG"
pass=0; fail=0
say() { echo "$1" | tee -a "$LOG"; }
check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass=$((pass+1)); say "ok:   $(printf '%-62s' "$name") $expected"
  else
    fail=$((fail+1)); say "FAIL: $(printf '%-62s' "$name") expected=$expected actual=$actual"
  fi
}

# Item 1 — approveRevision had no lock, no baseline and no sync check.
# Item 2 — the manager's half was measured against the projection stamp.
# Item 3 — publish()'s result was discarded.
ITEM1="testEveryApprovalLeavesTheBaselineEqualToWhatTheShopHolds testApprovingARevisionIsRefusedWhenTheShopMovedUnderTheReviewer"
ITEM2="testAnOldStampIsNotEvidenceOnceTheBaselineHasMoved"
ITEM3="testAnApprovalWhoseSyncFailedIsNotAReviewAndLeavesTheProductInTheQueue testARevisionWhoseSyncFailedStaysPendingAndLeavesTheLiveProductAsItWas testASuspensionTheShopRefusedIsReportedAndNotRecorded"
# Item 4 — not in the report. This round's own evidence run found it: the
# reconciliation wrote the manager's value into the record for a field whose
# proposal was HELD, which made the question disappear from the review screen.
ITEM4="testAFieldWithAHeldProposalIsNotReconciledAndDoesNotMoveTheAgreement"

run_suites() {
  vendor/bin/phpunit --testsuite unit --filter FieldMergeTest 2>&1
  vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
    --filter ApprovalSyncAndBaselineTest 2>&1
}
failed_names() { printf '%s\n' "$1" | grep -o '::test[A-Za-z]*' | sed 's/^:://' | sort -u; }

say "base: $BASE ($(git log -1 --format=%s "$BASE" | cut -c1-60))"
for f in $FILES; do
  differs="$(git diff --quiet "$BASE" -- "$f" && echo same || echo different)"
  check "$(basename "$f") differs from $BASE" "$differs" "different"
  cp "$f" "$OUT/$(basename "$f").fixed"
done

say ""
say "=== the two files put back to their alpha.29 bytes ==="
for f in $FILES; do
  git show "$BASE:$f" > "$f"
  check "$(basename "$f") is now the alpha.29 file" \
    "$(git diff --quiet "$BASE" -- "$f" && echo same || echo different)" "same"
done

BEFORE="$(run_suites)"
printf '%s\n' "$BEFORE" > "$OUT/before-the-fix.txt"
BEFORE_FAILED="$(failed_names "$BEFORE")"
say ""
say "tests that failed on alpha.29:"
printf '%s\n' "$BEFORE_FAILED" | sed 's/^/  /' | tee -a "$LOG"

for item in 1 2 3 4; do
  eval "names=\$ITEM$item"
  for name in $names; do
    check "item $item reproduced: $name" \
      "$(printf '%s\n' "$BEFORE_FAILED" | grep -Fx "$name" > /dev/null && echo failed || echo passed)" "failed"
  done
done

say ""
say "=== the fix put back ==="
for f in $FILES; do
  cp "$OUT/$(basename "$f").fixed" "$f"
  rm -f "$OUT/$(basename "$f").fixed"
  check "$(basename "$f") is the shipped file again" \
    "$(git diff --quiet -- "$f" && echo unchanged || echo changed)" "changed"
done

AFTER="$(run_suites)"
printf '%s\n' "$AFTER" > "$OUT/after-the-fix.txt"
AFTER_FAILED="$(failed_names "$AFTER")"
check "nothing fails after the fix" "${AFTER_FAILED:-none}" "none"
check "both suites report OK" "$(printf '%s\n' "$AFTER" | grep -c '^OK ')" "2"

say ""
say "checks: $((pass+fail))   passed: $pass   failed: $fail"
[ "$fail" -eq 0 ]
