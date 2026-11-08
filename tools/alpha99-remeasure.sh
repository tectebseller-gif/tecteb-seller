#!/usr/bin/env bash
# Re-measures NAMED mutations after their gaps were closed.
#
# ⛔ The five survivors of the first run were all holes in MY tests, not
# unbreakable guards. Each one got a test (or, for the measured-unreachable
# double guard, a source assertion) and is measured again here — on a quiet
# tree, restored byte for byte, appended to the SAME log so the first run's
# answer stays visible beside the second.
set -uo pipefail

OUT="${1:?usage: alpha99-remeasure.sh <out-dir> <n> [n...]}"; shift
LOG="$OUT/falsification.txt"
cd "$(dirname "$0")/.."
REPO="$PWD"
set -a; . ./.env.testing; set +a

DB_FILTER='OrderFlowTest|ShipmentAndReturnFlowTest|OrderLedgerReportTest|AuditTrailReadabilityTest|AdminPagesRenderTest|SettlementFlowTest'
BACKUP="$(mktemp -d)"; CURRENT=""
restore() { if [ -n "$CURRENT" ] && [ -f "$BACKUP/live" ]; then cp "$BACKUP/live" "$REPO/$CURRENT"; CURRENT=""; fi; }
trap restore EXIT INT TERM

suite() {
  case "$1" in
    unit)         vendor/bin/phpunit --testsuite unit 2>&1 ;;
    architecture) vendor/bin/phpunit --testsuite architecture 2>&1 ;;
    contract)     vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php 2>&1 ;;
    database)     vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php --filter "$DB_FILTER" 2>&1 ;;
  esac
}
count_failures() {
  python3 -I - "$1" <<'PY'
import re, sys
text = sys.argv[1]
nums = dict(re.findall(r'(Failures|Errors|Warnings): (\d+)', text))
total = sum(int(v) for v in nums.values())
print(total if (total or 'OK (' in text) else '?')
PY
}

{
  echo ""
  echo "=== SECOND RUN — the five that survived, after their gaps were closed"
  echo "    (same harness, quiet tree, byte-for-byte restore; the first run's"
  echo "     answers above are left exactly as they were)"
  echo ""
} | tee -a "$LOG"

PROBLEMS=0
for n in "$@"; do
  NAME="$(python3 -I tools/alpha99-mutations.py name "$n")"
  FILE="$(python3 -I tools/alpha99-mutations.py file "$n")"
  EXPECT="$(python3 -I tools/alpha99-mutations.py expect "$n")"
  echo "=== $n (second run) $NAME" | tee -a "$LOG"
  echo "    file:   $FILE" | tee -a "$LOG"
  echo "    expect: $EXPECT" | tee -a "$LOG"
  BEFORE="$(sha256sum "$FILE" | cut -d' ' -f1)"
  cp "$FILE" "$BACKUP/live"; CURRENT="$FILE"
  if ! python3 -I tools/alpha99-mutations.py apply "$n" >>"$LOG" 2>&1; then
    echo "    RESULT: NOT APPLIED — a FAILURE" | tee -a "$LOG"
    PROBLEMS=$((PROBLEMS + 1)); restore; continue
  fi
  RED=0
  for s in $(echo "$EXPECT" | tr ',' ' '); do
    FAILS="$(count_failures "$(suite "$s")")"
    echo "    $s: $FAILS" | tee -a "$LOG"
    if [ "$FAILS" = "?" ]; then
      echo "    RESULT: $s printed no summary — NOT measured" | tee -a "$LOG"; PROBLEMS=$((PROBLEMS + 1))
    elif [ "$FAILS" -gt 0 ]; then RED=1; fi
  done
  if [ "$RED" = "1" ]; then echo "    RESULT: falsified" | tee -a "$LOG"
  else echo "    RESULT: STILL SURVIVED" | tee -a "$LOG"; PROBLEMS=$((PROBLEMS + 1)); fi
  restore
  AFTER="$(sha256sum "$FILE" | cut -d' ' -f1)"
  if [ "$BEFORE" = "$AFTER" ]; then echo "    restored byte for byte: yes" | tee -a "$LOG"
  else echo "    restored byte for byte: NO" | tee -a "$LOG"; PROBLEMS=$((PROBLEMS + 1)); fi
  echo "" | tee -a "$LOG"
done

echo "=== the quiet tree, afterwards (second run)" | tee -a "$LOG"
for s in unit architecture contract; do
  echo "    $s: $(count_failures "$(suite "$s")")" | tee -a "$LOG"
done
echo "    database: $(count_failures "$(vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php 2>&1)")" | tee -a "$LOG"
echo "" | tee -a "$LOG"
echo "problems (second run): $PROBLEMS" | tee -a "$LOG"
rm -rf "$BACKUP"
[ "$PROBLEMS" = "0" ]
