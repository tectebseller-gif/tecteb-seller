#!/usr/bin/env bash
# One mutation at a time, on a QUIET tree, restored byte for byte.
#
# ⛔ The tree must be quiet: mutating code while a suite runs is the same
# shape as two runners on one database (`alpha.74`), and the answer is about
# neither tree. The restore is in a `trap` so an interrupted loop cannot leave
# a mutation on disk (`alpha.83`).
set -uo pipefail

OUT="${1:?usage: alpha98-falsification.sh <out-dir>}"
mkdir -p "$OUT"
LOG="$OUT/falsification.txt"
: > "$LOG"

cd "$(dirname "$0")/.."
REPO="$PWD"
set -a; . ./.env.testing; set +a

TOTAL="$(python3 -I tools/alpha98-mutations.py count)"
BACKUP="$(mktemp -d)"
CURRENT=""

restore() {
  if [ -n "$CURRENT" ] && [ -f "$BACKUP/live" ]; then
    cp "$BACKUP/live" "$REPO/$CURRENT"
    CURRENT=""
  fi
}
trap restore EXIT INT TERM

# ⛔ The `database` suite is FILTERED inside the loop, and the filter is
# printed in the log.
#
# The whole suite is ten minutes; twelve mutations would be two hours
# of it. The filter names every class that covers this round, and the
# direction of the error it can make is the SAFE one: a mutation caught only
# by a class outside the filter reads as SURVIVED — a false alarm, never a
# false pass. And the closing baseline below runs the suite UNFILTERED, so
# nothing is declared green on a filtered run.
DB_FILTER='OrderFlowTest|ShipmentAndReturnFlowTest|AdminPagesRenderTest|SettlementFlowTest'

suite() {
  case "$1" in
    unit)         vendor/bin/phpunit --testsuite unit 2>&1 ;;
    architecture) vendor/bin/phpunit --testsuite architecture 2>&1 ;;
    contract)     vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php 2>&1 ;;
    database)     vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
                      --filter "$DB_FILTER" 2>&1 ;;
    database-all) vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php 2>&1 ;;
    # ⛔ Built into a THROWAWAY dist, never over `dist/`.
    #
    # One mutation this round edits `build.sh` itself, so the only way to
    # measure the guard is to build with the mutated script — and building
    # over `dist/` would overwrite a DELIVERED package, which `build.sh`
    # refuses and which this repository has paid for once already
    # (`alpha.16`). `PackagingTest` honours `TMC_DIST` from this round for
    # exactly this reason.
    packaging)
        TMP_DIST="$(mktemp -d)"
        if TMC_DIST="$TMP_DIST" bash tools/build.sh >/dev/null 2>&1; then
            TMC_DIST="$TMP_DIST" vendor/bin/phpunit --testsuite packaging 2>&1
        else
            echo "FAILURES! Tests: 1, Assertions: 0, Failures: 1 (the build itself refused)"
        fi
        rm -rf "$TMP_DIST"
        ;;
  esac
}

# Count failures AND errors AND warnings: «I could not measure» must be red
# and must be red by name (`alpha.82`).
count_failures() {
  local text="$1"
  python3 -I - "$text" <<'PY'
import re, sys
text = sys.argv[1]
m = re.search(r'^(OK|OK \(|FAILURES!|ERRORS!|WARNINGS!|Tests:)', text, re.M)
nums = dict(re.findall(r'(Failures|Errors|Warnings): (\d+)', text))
total = sum(int(v) for v in nums.values())
if total == 0 and 'OK (' not in text:
    print('?')          # no summary printed at all — NOT a pass
else:
    print(total)
PY
}

echo "alpha.98 falsification — $TOTAL mutations" | tee -a "$LOG"
echo "database filter inside the loop: $DB_FILTER" | tee -a "$LOG"
echo "the closing baseline runs the database suite UNFILTERED" | tee -a "$LOG"
echo "tree: $(git rev-parse --short HEAD) (plus the uncommitted round)" | tee -a "$LOG"
echo "" | tee -a "$LOG"

PROBLEMS=0
for n in $(seq 1 "$TOTAL"); do
  NAME="$(python3 -I tools/alpha98-mutations.py name "$n")"
  FILE="$(python3 -I tools/alpha98-mutations.py file "$n")"
  EXPECT="$(python3 -I tools/alpha98-mutations.py expect "$n")"
  echo "=== $n/$TOTAL $NAME" | tee -a "$LOG"
  echo "    file:   $FILE" | tee -a "$LOG"
  echo "    expect: $EXPECT" | tee -a "$LOG"

  BEFORE="$(sha256sum "$FILE" | cut -d' ' -f1)"
  cp "$FILE" "$BACKUP/live"
  CURRENT="$FILE"
  if ! python3 -I tools/alpha98-mutations.py apply "$n" >>"$LOG" 2>&1; then
    echo "    RESULT: NOT APPLIED — this is a FAILURE, not an unbreakable guard" | tee -a "$LOG"
    PROBLEMS=$((PROBLEMS + 1))
    restore
    echo "" | tee -a "$LOG"
    continue
  fi

  RED=0
  for s in $(echo "$EXPECT" | tr ',' ' '); do
    TEXT="$(suite "$s")"
    FAILS="$(count_failures "$TEXT")"
    echo "    $s: $FAILS" | tee -a "$LOG"
    if [ "$FAILS" = "?" ]; then
      echo "    RESULT: $s printed no summary — NOT measured" | tee -a "$LOG"
      PROBLEMS=$((PROBLEMS + 1))
    elif [ "$FAILS" -gt 0 ]; then
      RED=1
    fi
  done
  if [ "$RED" = "1" ]; then
    echo "    RESULT: falsified" | tee -a "$LOG"
  else
    echo "    RESULT: SURVIVED — measure why before calling it a guard" | tee -a "$LOG"
    PROBLEMS=$((PROBLEMS + 1))
  fi

  restore
  AFTER="$(sha256sum "$FILE" | cut -d' ' -f1)"
  if [ "$BEFORE" = "$AFTER" ]; then
    echo "    restored byte for byte: yes" | tee -a "$LOG"
  else
    echo "    restored byte for byte: NO — $BEFORE -> $AFTER" | tee -a "$LOG"
    PROBLEMS=$((PROBLEMS + 1))
  fi
  echo "" | tee -a "$LOG"
done

echo "=== the quiet tree, afterwards" | tee -a "$LOG"
for s in unit architecture contract database-all; do
  TEXT="$(suite "$s")"
  echo "    $s: $(count_failures "$TEXT")" | tee -a "$LOG"
done
echo "" | tee -a "$LOG"
echo "problems: $PROBLEMS" | tee -a "$LOG"
rm -rf "$BACKUP"
[ "$PROBLEMS" = "0" ]
