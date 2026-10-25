#!/usr/bin/env bash
# Falsify every guard this round added, by putting the defect back.
#
# This round the guarantee being measured is one sentence of the owner's:
# «برابری مجموعهٔ شناسه‌ها را قبل و بعد از اعمال، ویرایش و پذیرش اندازه بگیر؛
# برابری تعداد کافی نیست.» So every mutation below restores one many→one
# collapse, and the suites must notice the SET changing — not a count.
#
# A green suite proves nothing about a guard until the guard's absence turns
# it red. So each mutation is applied to the QUIET tree — no suite running
# beside it (`alpha.74`) — the relevant suites are run, the result is recorded,
# and the file is restored and compared BYTE FOR BYTE.
#
# ⛔ A mutation that does not apply is a FAILURE, not a passing guard: nothing
# was measured, and from outside that is byte-identical to «this guard cannot
# be broken» (`alpha.77`).
#
#   bash tools/alpha82-falsification.sh docs/evidence/alpha82-falsification
set -uo pipefail

OUT="${1:-docs/evidence/alpha82-falsification}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO"
mkdir -p "$OUT"
report="$OUT/falsification.txt"

# shellcheck disable=SC1091
set -a; . ./.env.testing; set +a

UNIT=(vendor/bin/phpunit --testsuite unit)
ARCH=(vendor/bin/phpunit --testsuite architecture)
CONTRACT=(vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php)
# ⛔ The filter has to name every class that now asserts about the SET, and
# two were missing on the first run: the approval tests and the review page.
# A filter that leaves out the class holding the killing test reports
# «survived» about a guard that is measured — the same false negative as a
# mutation that does not apply.
DB=(vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php
    --filter 'CategoryLinkFlowTest|CategoryLinkReadTest|CatalogueIntakeTest|ProductFlowTest|ApprovalSyncAndBaselineTest|ProductAdminPagesTest')

tally() {   # prints e.g. "unit:2 arch:0 contract:1 db:3"
  printf 'unit:%s ' "$(count_failures "${UNIT[@]}")"
  printf 'arch:%s ' "$(count_failures "${ARCH[@]}")"
  printf 'contract:%s ' "$(count_failures "${CONTRACT[@]}")"
  printf 'db:%s' "$(count_failures "${DB[@]}")"
}

# ⛔ WARNINGS COUNT, AND SO DOES «no summary at all».
#
# Two readings in this round's first run were wrong because of this function.
# Removing the fast-path narrowing refusal makes the code read `$missing[0]` on
# an empty array: the suites say «Warnings: 2» and nothing else, and a tally
# that only grepped Failures and Errors called that «survived» — a guard whose
# absence the suite DOES report, reported as unmeasured. And when the suite
# printed no summary line at all (a half-built schema, after I killed an
# earlier run mid-suite) the tally was the string `?`, which `grep ':[1-9]'`
# reads as green. «I could not measure» must never look like «the guard is
# unbreakable» (`alpha.77`), so it is loud and it is red.
count_failures() {
  local line
  line="$("$@" 2>&1 | grep -E '^(OK|OK \(|Tests:|ERRORS|FAILURES|WARNINGS)' | tail -1)"
  case "$line" in
    OK*) echo 0 ;;
    '') echo "nosummary" ;;
    *) echo "$line" | grep -oE '(Failures|Errors|Warnings): [0-9]+' | grep -oE '[0-9]+' \
         | paste -sd+ | bc 2>/dev/null || echo "nosummary" ;;
  esac
}

total="$(python3 tools/alpha82-mutations.py count)"
fail=0
{
  echo "# Falsification of the whole-category-set round"
  echo "# date=$(date -u +%Y-%m-%dT%H:%M:%SZ)  mutations=$total"
  echo "# Every count below is the number of FAILING tests with the defect put back."
  echo
  echo "## healthy tree"
  echo "   $(tally)"
} >"$report"

echo "healthy: $(tail -1 "$report")"

for i in $(seq 0 $((total - 1))); do
  name="$(python3 tools/alpha82-mutations.py name "$i")"
  file="$(python3 tools/alpha82-mutations.py file "$i")"
  expect="$(python3 tools/alpha82-mutations.py expect "$i")"
  before="$(sha256sum "$file" | cut -d' ' -f1)"
  cp "$file" "$OUT/.restore"

  if ! python3 tools/alpha82-mutations.py apply "$i"; then
    echo "FAIL $name — the mutation did not apply, so nothing was measured" | tee -a "$report"
    cp "$OUT/.restore" "$file"
    fail=$((fail + 1))
    continue
  fi

  counts="$(tally)"
  cp "$OUT/.restore" "$file"
  after="$(sha256sum "$file" | cut -d' ' -f1)"

  {
    echo
    echo "## $i $name"
    echo "   file:     $file"
    echo "   expected: $expect"
    echo "   failures: $counts"
    echo "   restored: $([ "$before" = "$after" ] && echo byte-for-byte || echo DIFFERS)"
  } >>"$report"

  if [ "$before" != "$after" ]; then
    echo "FAIL $name — the file was not restored byte for byte"
    fail=$((fail + 1))
    continue
  fi
  if echo "$counts" | grep -q 'nosummary'; then
    echo "FAIL $i $name — a suite printed NO summary: $counts (measurement broken, not a passing guard)"
    fail=$((fail + 1))
  elif echo "$counts" | grep -qE ':[1-9]'; then
    echo "ok   $i $name → $counts"
  else
    echo "FAIL $i $name — SURVIVED: $counts"
    fail=$((fail + 1))
  fi
done

rm -f "$OUT/.restore"
echo "" >>"$report"
echo "mutations that survived or did not apply: $fail" | tee -a "$report"
echo "wrote $report"
exit "$fail"
