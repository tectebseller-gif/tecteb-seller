#!/usr/bin/env bash
# Falsify each guard this round adds, by putting the defect back.
#
# ⛔ Run it on a QUIET tree. Mutating code while a suite is running is the same
# shape as two runners on one database (`alpha.74`), and the answer is about
# neither tree.
#
# Every mutation is applied, measured, and restored byte-for-byte — the hashes
# taken before are checked after, because `git checkout -- <path>` hands back
# uncommitted work of your own as well (`alpha.71`). And a mutation that fails
# to APPLY fails the whole script: a run that measured nothing looks exactly
# like a guard that cannot be broken.
set -uo pipefail

OUT="${1:-docs/evidence/alpha77-falsification}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"
mkdir -p "$OUT"
LOG="$OUT/falsification.txt"
: > "$LOG"

if [ -f .env.testing ]; then set -a; . ./.env.testing; set +a; fi

MUT="python3 tools/alpha77-mutations.py"
COUNT="$($MUT count)"

# Every file any mutation touches, snapshotted once.
mapfile -t FILES < <(for i in $(seq 1 "$COUNT"); do $MUT file "$i"; done | sort -u)

SNAP="$(mktemp -d)"
trap 'for f in "${FILES[@]}"; do cp "$SNAP/$f" "$f" 2>/dev/null || true; done; rm -rf "$SNAP"' EXIT
for f in "${FILES[@]}"; do
  mkdir -p "$SNAP/$(dirname "$f")"
  cp "$f" "$SNAP/$f"
done

restore() { for f in "${FILES[@]}"; do cp "$SNAP/$f" "$f"; done; }

# Which suites each mutation is measured by. One line per mutation, in order.
SUITES=(
  "unit:ShopRecordPreviewTest"
  "unit:ShopRecordPreviewTest"
  "database:DokanRecordImportTest"
  "architecture:RecordImportIsHistoryOnlyTest|ProgressFollowsTheWorkTest database:DokanRecordImportTest"
  "architecture:StepNamesComeFromTheListTest unit:StepTimingTest"
  "database:HandoverPageRecordsTest"
  "database:MigrationCountReportTest"
  "database:MigrationCountReportTest"
  "database:DokanRecordImportTest"
  "architecture:RecordImportIsHistoryOnlyTest"
)

run_suite() {
  local spec="$1" suite filter
  suite="${spec%%:*}"
  filter="${spec#*:}"
  if [ "$suite" = 'database' ]; then
    vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php --filter "$filter" 2>&1 | tail -6
  else
    vendor/bin/phpunit --testsuite "$suite" --filter "$filter" 2>&1 | tail -6
  fi
}

NOT_APPLIED=0
SURVIVED=0

for i in $(seq 1 "$COUNT"); do
  printf '\n=== MUTATION %d: %s ===\n' "$i" "$($MUT name "$i")" | tee -a "$LOG"
  if ! $MUT apply "$i" 2>>"$LOG"; then
    printf 'MUTATION FAILED TO APPLY — nothing was measured\n' | tee -a "$LOG"
    NOT_APPLIED=$((NOT_APPLIED + 1))
    restore
    continue
  fi
  php -l "$($MUT file "$i")" >/dev/null 2>&1 || printf 'note: the mutated file does not parse; the failure below is that\n' | tee -a "$LOG"
  broke=0
  for spec in ${SUITES[$((i - 1))]}; do
    out="$(run_suite "$spec")"
    printf '%s\n' "$out" | tee -a "$LOG"
    printf '%s' "$out" | grep -qE 'FAILURES!|ERRORS!|Fatal error|Parse error' && broke=1
  done
  if [ "$broke" -eq 0 ]; then
    printf 'SURVIVED — this guard has no test that rejects it\n' | tee -a "$LOG"
    SURVIVED=$((SURVIVED + 1))
  fi
  restore
done

printf '\n=== RESTORED BYTE FOR BYTE ===\n' | tee -a "$LOG"
for f in "${FILES[@]}"; do
  if ! cmp -s "$SNAP/$f" "$f"; then
    printf 'NOT RESTORED: %s\n' "$f" | tee -a "$LOG"
    exit 1
  fi
done
printf 'all %d files byte-identical to the snapshot\n' "${#FILES[@]}" | tee -a "$LOG"

printf '\nmutations: %d · failed to apply: %d · survived: %d\n' \
  "$COUNT" "$NOT_APPLIED" "$SURVIVED" | tee -a "$LOG"
[ "$NOT_APPLIED" -eq 0 ] && [ "$SURVIVED" -eq 0 ]
