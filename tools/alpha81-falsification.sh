#!/usr/bin/env bash
# Falsify every guard this round added, by putting the defect back.
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
#   bash tools/alpha81-falsification.sh docs/evidence/alpha81-falsification
set -uo pipefail

OUT="${1:-docs/evidence/alpha81-falsification}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO"
mkdir -p "$OUT"
report="$OUT/falsification.txt"

# shellcheck disable=SC1091
set -a; . ./.env.testing; set +a

UNIT=(vendor/bin/phpunit --testsuite unit)
ARCH=(vendor/bin/phpunit --testsuite architecture)
CONTRACT=(vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php)
DB=(vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php
    --filter 'CategoryLinkFlowTest|CategoryLinkReadTest')

tally() {   # prints e.g. "unit:2 arch:0 contract:1 db:3"
  printf 'unit:%s ' "$(count_failures "${UNIT[@]}")"
  printf 'arch:%s ' "$(count_failures "${ARCH[@]}")"
  printf 'contract:%s ' "$(count_failures "${CONTRACT[@]}")"
  printf 'db:%s' "$(count_failures "${DB[@]}")"
}

count_failures() {
  local line
  line="$("$@" 2>&1 | grep -E '^(OK|OK \(|Tests:|ERRORS|FAILURES)' | tail -1)"
  case "$line" in
    OK*) echo 0 ;;
    *) echo "$line" | grep -oE '(Failures|Errors): [0-9]+' | grep -oE '[0-9]+' | paste -sd+ | bc 2>/dev/null || echo "?" ;;
  esac
}

total="$(python3 tools/alpha81-mutations.py count)"
fail=0
{
  echo "# Falsification of the category-relationship round"
  echo "# date=$(date -u +%Y-%m-%dT%H:%M:%SZ)  mutations=$total"
  echo "# Every count below is the number of FAILING tests with the defect put back."
  echo
  echo "## healthy tree"
  echo "   $(tally)"
} >"$report"

echo "healthy: $(tail -1 "$report")"

for i in $(seq 0 $((total - 1))); do
  name="$(python3 tools/alpha81-mutations.py name "$i")"
  file="$(python3 tools/alpha81-mutations.py file "$i")"
  expect="$(python3 tools/alpha81-mutations.py expect "$i")"
  before="$(sha256sum "$file" | cut -d' ' -f1)"
  cp "$file" "$OUT/.restore"

  if ! python3 tools/alpha81-mutations.py apply "$i"; then
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
  if echo "$counts" | grep -qE ':[1-9]'; then
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
