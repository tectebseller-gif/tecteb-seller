#!/usr/bin/env bash
# Both `TitleSortRepair` gaps, reproduced on the code that shipped as `alpha.34`.
#
# The owner asked for it in those words: «ابتدا نقص‌ها را روی alpha.34 بازتولید
# کن و سپس همان آزمون‌ها را روی اصلاح اجرا کن». So the one file this round
# changed is put back to its `alpha.34` bytes — read out of git, not retyped —
# and the new tests are RUN against it. Every test named below must fail there
# and pass here; a reproduction that passes on the old code reproduces nothing,
# and this script fails when that happens.
#
# The two gaps:
#
#   1. `rewrite()` answered with a COUNT, so a refused write could not reach the
#      caller. `sweep()` moved its cursor to the end of the window anyway and,
#      on a short window, recorded `finished` and stamped the new BUILD — so the
#      unwritten row was never looked at again. `audit()` read the same zero as
#      «clean».
#   2. The `UPDATE` was keyed on `id` alone, so a title saved through the
#      ordinary path between the repair's SELECT and its UPDATE had the old
#      name's key written over its new one.
#
#   bash tools/alpha34-reproduction.sh docs/evidence/alpha34-reproduction
set -u
OUT="${1:-docs/evidence/alpha34-reproduction}"
BASE="${TMC_BASE_REF:-810b10d}"          # the commit that built alpha.34
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"
[ -f .env.testing ] && { set -a; . ./.env.testing; set +a; }

# Only the service changes. `TitleSortRepairBatch` is NEW in `alpha.35`, so it
# has no `alpha.34` bytes to go back to — and it must stay on disk, or the
# restored service would reference a class that does not exist and the tree
# would fatal on load. The load check below is what proves that did not happen:
# a reproduction that cannot run is not a reproduction that found nothing.
FILES="src/Modules/Product/Infrastructure/TitleSortRepair.php"
mkdir -p "$OUT"
LOG="$OUT/alpha34-reproduction.txt"
: > "$LOG"
pass=0; fail=0
say() { echo "$1" | tee -a "$LOG"; }
check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass=$((pass+1)); say "ok:   $(printf '%-64s' "$name") $expected"
  else
    fail=$((fail+1)); say "FAIL: $(printf '%-64s' "$name") expected=$expected actual=$actual"
  fi
}

# Item 1 — a refused write ended the sweep as a success.
# Item 2 — the write was keyed on `id` alone.
ITEM1="testARefusedWriteStopsTheSweepWhereverItHappens testARefusedWriteInTheAuditIsNotReportedAsClean testAfterTheFailureIsLiftedTheRetryFinishesWithoutDoubleCounting"
ITEM2="testAConcurrentSaveKeepsItsOwnKeyAndItsOwnTitle testARowThatMovedAndIsStillWrongStaysUnresolved testTheGuardIsNotFooledByACaseInsensitiveCollation"

run_suites() {
  vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
    --filter TitleSortRepairFailureTest 2>&1
  # `alpha.34`'s own repair tests, on the same swapped file: a fix that broke
  # the bounded/resumable behaviour the last round measured would show up here
  # rather than three evidence runs later.
  vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
    --filter TitleSortRepairTest 2>&1
}
failed_names() { printf '%s\n' "$1" | grep -o '::test[A-Za-z]*' | sed 's/^:://' | sort -u; }

say "base: $BASE ($(git log -1 --format=%s "$BASE" | cut -c1-60))"
for f in $FILES; do
  differs="$(git diff --quiet "$BASE" -- "$f" && echo same || echo different)"
  check "$(basename "$f") differs from $BASE" "$differs" "different"
  cp "$f" "$OUT/$(basename "$f").fixed"
done

say ""
say "=== the changed file put back to its alpha.34 bytes ==="
for f in $FILES; do
  git show "$BASE:$f" > "$f"
  check "$(basename "$f") is now the alpha.34 file" \
    "$(git diff --quiet "$BASE" -- "$f" && echo same || echo different)" "same"
done

BEFORE="$(run_suites)"
printf '%s\n' "$BEFORE" > "$OUT/before-the-fix.txt"
BEFORE_FAILED="$(failed_names "$BEFORE")"

# Two suites were asked to run, so two RESULT lines are the proof that they did
# — «Tests: …» when something failed, «OK (…)» when nothing did.
check "the swapped tree still loads and runs the tests" \
  "$(printf '%s\n' "$BEFORE" | grep -cE '^(Tests: |OK \()')" "2"
check "and nothing fatalled while loading it" \
  "$(printf '%s\n' "$BEFORE" | grep -c 'Fatal error')" "0"
say ""
say "tests that failed on alpha.34:"
printf '%s\n' "$BEFORE_FAILED" | sed 's/^/  /' | tee -a "$LOG"

for item in 1 2; do
  eval "names=\$ITEM$item"
  for name in $names; do
    check "item $item reproduced: $name" \
      "$(printf '%s\n' "$BEFORE_FAILED" | grep -Fx "$name" > /dev/null && echo failed || echo passed)" "failed"
  done
done

# The one test that must PASS on alpha.34 as well: `alpha.8`'s rule that a zero
# row count is a success was already honoured, and this round must not have
# «fixed» it into a failure. A reproduction script that expects everything to
# fail cannot tell a defect from a badly written test.
check "and the alpha.8 zero-rows rule was already right on alpha.34" \
  "$(printf '%s\n' "$BEFORE_FAILED" | grep -Fx 'testZeroChangedRowsIsNotTreatedAsAFailure' > /dev/null && echo failed || echo passed)" "passed"

say ""
say "=== the fix put back ==="
for f in $FILES; do
  # Compared with the copy taken before this script touched anything — NOT with
  # the last commit, which answers «changed» only while the fix is uncommitted.
  before_hash="$(sha256sum "$OUT/$(basename "$f").fixed" | cut -d' ' -f1)"
  cp "$OUT/$(basename "$f").fixed" "$f"
  rm -f "$OUT/$(basename "$f").fixed"
  check "$(basename "$f") is byte-identical to what it was" \
    "$(sha256sum "$f" | cut -d' ' -f1)" "$before_hash"
done

AFTER="$(run_suites)"
printf '%s\n' "$AFTER" > "$OUT/after-the-fix.txt"
AFTER_FAILED="$(failed_names "$AFTER")"
check "nothing fails after the fix" "${AFTER_FAILED:-none}" "none"
check "both suites report OK" "$(printf '%s\n' "$AFTER" | grep -c '^OK ')" "2"

say ""
say "checks: $((pass+fail))   passed: $pass   failed: $fail"
[ "$fail" -eq 0 ]
