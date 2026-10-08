#!/usr/bin/env bash
# The two `alpha.37` defects, reproduced on the bytes that shipped as
# `alpha.37` — and the fix measured on the same tests.
#
# The owner asked for it in those words: «هر دو نقص نام‌برده را روی بایت‌های
# alpha.37 بازتولید کن؛ همان سناریوها پس از اصلاح قبول شوند». So the THREE
# source files this round changed are put back to their `alpha.37` bytes — read
# out of git, never retyped — and this round's own tests are run against them.
#
# **Why this round's PHPUnit tests can be used here, where `alpha.36` needed a
# probe.** `alpha.36` did not have the classes the tests name, so the tree
# fatalled at load and zero tests ran — which looks exactly like a reproduction
# and proves nothing (the `alpha.32` trap). `alpha.37` has every class and
# every method these tests call; only the GUARD inside two of them differs. So
# the tests load, run, and answer about behaviour.
#
# Guarded against that trap anyway, in three ways:
#
#   - the number of tests PHPUnit says it executed is read, and a run that
#     executed none is a hard error, not a reproduction;
#   - every reverted file's hash is compared with `git show` before the run and
#     with the pre-touch hash after the restore;
#   - a CONTROL list of tests that must PASS on `alpha.37` is asserted too. A
#     revert that broke the tree would fail those, and the script would say so
#     instead of reporting twelve reproductions.
#
# The two defects:
#
#   1. `markSeen()` took its timestamp at WRITE time and guarded on it, so the
#      order enforced was the order in which requests finished. A slow request
#      that had rendered the previous version wrote last, won the guard, and
#      put the older token over the newer one.
#   2. `M0021ReviewSeen::carryOverLegacyMarks()` cast the usermeta existence
#      read straight to int, so a FAILED read (`null`) read as «the table is
#      not there» and the carry-over returned in silence while the schema
#      advanced to 21 with nothing carried.
#
#   bash tools/alpha37-reproduction.sh docs/evidence/alpha37-reproduction
set -u
OUT="${1:-docs/evidence/alpha37-reproduction}"
BASE="${TMC_BASE_REF:-346a1dd}"          # the commit that built alpha.37
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"

mkdir -p "$OUT"
LOG="$OUT/alpha37-reproduction.txt"
: > "$LOG"
pass=0; fail=0
say() { echo "$1" | tee -a "$LOG"; }
check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass=$((pass+1)); say "ok:   $(printf '%-66s' "$name") $expected"
  else
    fail=$((fail+1)); say "FAIL: $(printf '%-66s' "$name") expected=$expected actual=$actual"
  fi
}

# The files this round changed in the payload. `tests/` and `tools/` are NOT
# reverted: the tests are the instrument, and reverting them would be asking
# `alpha.37` whether it agrees with itself.
FILES="
src/Modules/Product/Infrastructure/DbReviewSeenStore.php
src/Modules/Product/Infrastructure/ReviewQueueSql.php
src/Modules/Product/Infrastructure/Migrations/M0021ReviewSeen.php
"

# Tests that must FAIL on `alpha.37` and PASS after the restore. Each one is a
# scenario the owner named.
BROKEN="
ReviewSeenLateWriteTest::testTheLateRecordOfAnOlderSubmissionDoesNotRestoreTheNotification
ReviewSeenLateWriteTest::testTheLateRecordOfAnOlderProposalDoesNotRestoreTheNotification
ReviewSeenLateWriteTest::testTheNewerUndisplayedVersionStaysUnseen
ReviewSeenLateWriteTest::testTheOtherManagersMarkIsUntouched
ReviewSeenLateWriteTest::testAMarkIsNotWrittenForAProductThatIsNotThere
ReviewSeenTest::testALateRecordOfAnOlderViewCannotPullANewerOneBackwards
ReviewSeenTest::testASubmissionArrivingAfterTheReadIsStillUnseen
ReviewDetailSnapshotTest::testASubmissionLandingAfterTheSnapshotStaysUnseen
ReviewDetailSnapshotTest::testANewerProposalLandingMidRenderIsNotTheOneRecorded
ReviewSeenCarryOverTest::testAFailedExistenceReadStopsTheUpgradeInsteadOfSkippingTheCarryOver
ReviewSeenCarryOverTest::testTheRetryAfterTheErrorIsClearedCompletesTheCarryOver
ReviewSeenCarryOverTest::testTheGateHoldsItsCooldownAndThenCompletes
"

# Tests that must PASS on BOTH builds. Without these a broken revert would
# report a perfect reproduction.
CONTROL="
ReviewSeenLateWriteTest::testDecidingAProposalReturnsTheSamePairAsBeforeItExisted
ReviewSeenCarryOverTest::testAFailureReadingTheLegacyPagesAlsoStopsTheUpgrade
ReviewSeenCarryOverTest::testAnAbsentUserMetaTableIsNotAFailure
ReviewSeenTest::testTwoRecordsAtOnceLoseNothing
ReviewDetailSnapshotTest::testViewingChangesNoStatusDecisionBaselineOrProposal
"

SNAP="$(mktemp -d)"
restore() {
  for f in $FILES; do
    if [ -s "$SNAP/$(echo "$f" | tr / _)" ]; then
      cp "$SNAP/$(echo "$f" | tr / _)" "$f"
    fi
  done
}
trap 'restore; rm -rf "$SNAP"' EXIT

# ---------------------------------------------------------------- 1. snapshot
for f in $FILES; do
  cp "$f" "$SNAP/$(echo "$f" | tr / _)"
done
for f in $FILES; do
  h="$(sha256sum "$SNAP/$(echo "$f" | tr / _)" | cut -c1-16)"
  if [ -z "$h" ]; then say "FAIL: empty snapshot hash for $f"; exit 1; fi
  eval "FIXED_$(echo "$f" | tr ./- ___)='$h'"
done
say "snapshot: $(echo $FILES | wc -w) files in $SNAP"

# ------------------------------------------------- 2. the alpha.37 bytes, back
for f in $FILES; do
  git show "$BASE:$f" > "$f" || { say "FAIL: git show $BASE:$f"; exit 1; }
  want="$(git show "$BASE:$f" | sha256sum | cut -c1-16)"
  got="$(sha256sum "$f" | cut -c1-16)"
  check "reverted $(basename "$f")" "$got" "$want"
done
# Measured, not assumed: a tree that will not even parse runs no tests.
for f in $FILES; do
  php -l "$f" > /dev/null 2>&1 || { say "FAIL: $f does not parse on alpha.37 bytes"; exit 1; }
done

# -------------------------------------------------------------- 3. the runs
run_one() {
  # Prints "<result> <count>": passed|failed|errored, and how many tests ran.
  local filter="$1" out
  out="$(set -a; . ./.env.testing; set +a; \
    vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
      --filter "$filter" --no-progress 2>&1)"
  local count
  count="$(printf '%s\n' "$out" | sed -n 's/^.*Tests: \([0-9]*\).*$/\1/p' | tail -1)"
  if [ -z "$count" ]; then
    count="$(printf '%s\n' "$out" | sed -n 's/^OK (\([0-9]*\) test.*$/\1/p' | tail -1)"
  fi
  [ -z "$count" ] && count=0
  if printf '%s\n' "$out" | grep -q '^OK ('; then
    echo "passed $count"
  elif printf '%s\n' "$out" | grep -qE '^(FAILURES|ERRORS|There w)'; then
    echo "failed $count"
  else
    echo "nothing $count"
  fi
}

phase() {
  local label="$1"
  for t in $BROKEN; do
    r="$(run_one "$t")"
    check "$label ran        ${t##*::}" "$(echo "$r" | cut -d' ' -f2)" "1"
    check "$label result     ${t##*::}" "$(echo "$r" | cut -d' ' -f1)" "$2"
  done
  for t in $CONTROL; do
    r="$(run_one "$t")"
    check "$label control    ${t##*::}" "$(echo "$r" | cut -d' ' -f1)" "passed"
  done
}

say ""
say "--- on the alpha.37 bytes: the named scenarios must FAIL, the controls must pass"
phase "alpha.37" "failed"

# ------------------------------------------------------------- 4. the fix back
restore
for f in $FILES; do
  var="FIXED_$(echo "$f" | tr ./- ___)"
  got="$(sha256sum "$f" | cut -c1-16)"
  check "restored $(basename "$f")" "$got" "$(eval echo "\$$var")"
done

say ""
say "--- on the alpha.38 bytes: every one of them must PASS"
phase "alpha.38" "passed"

say ""
say "pass=$pass fail=$fail"
[ "$fail" -eq 0 ]
