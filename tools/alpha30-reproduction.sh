#!/usr/bin/env bash
# Each of the three save-path gaps, reproduced on the code that shipped as
# `alpha.30`.
#
# The owner asked for it in those words: «ابتدا نقص را روی alpha.30 بازتولید و
# سپس اصلاح را اثبات کن». So the three files this round changed are put back to
# their `alpha.30` bytes — read out of git, not retyped — and the new tests are
# RUN against them. Every test named below must fail there and pass here; a
# reproduction that passes on the old code reproduces nothing, and this script
# fails when that happens.
#
#   bash tools/alpha30-reproduction.sh docs/evidence/alpha30-reproduction
set -u
OUT="${1:-docs/evidence/alpha30-reproduction}"
BASE="${TMC_BASE_REF:-685803e}"          # the commit that built alpha.30
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"
[ -f .env.testing ] && { set -a; . ./.env.testing; set +a; }

FILES="src/Modules/Product/Infrastructure/DbProductRepository.php src/Modules/Product/Application/ReviewProducts.php src/Modules/Product/Presentation/ProductMessages.php"
mkdir -p "$OUT"
LOG="$OUT/alpha30-reproduction.txt"
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

# Item 1 — approveRevision() looked at neither saveSpecs() nor saveImages().
# Item 2 — restoreProduct() wrote three times and checked nothing.
# Item 3 — saveImages() threw the DELETE's answer away, so a failed delete left
#          the old pictures beside the new ones and still answered `true`.
ITEM1="testAnOptionalSpecThatDidNotSaveStopsTheRevisionFromBeingApproved"
ITEM2="testARestoreThatFailedIsSaidOutLoudRatherThanAssumed testAReadinessRefusalWhoseRestoreFailedNamesBoth"
ITEM3="testAFailedGalleryDeleteLeavesTheOldPicturesAloneAndSaysSo testAFailedPictureInsertLeavesNoHalfWrittenGallery testAFailedSpecInsertLeavesTheAnswersAsTheyWere"

run_suites() {
  vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
    --filter SaveAndRestoreFailureTest 2>&1
  # The previous round's own tests, on the same swapped files: a fix to the save
  # path that broke the approval path would show up here rather than three
  # evidence runs later.
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
say "=== the three files put back to their alpha.30 bytes ==="
for f in $FILES; do
  git show "$BASE:$f" > "$f"
  check "$(basename "$f") is now the alpha.30 file" \
    "$(git diff --quiet "$BASE" -- "$f" && echo same || echo different)" "same"
done

BEFORE="$(run_suites)"
printf '%s\n' "$BEFORE" > "$OUT/before-the-fix.txt"
BEFORE_FAILED="$(failed_names "$BEFORE")"
say ""
say "tests that failed on alpha.30:"
printf '%s\n' "$BEFORE_FAILED" | sed 's/^/  /' | tee -a "$LOG"

for item in 1 2 3; do
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
