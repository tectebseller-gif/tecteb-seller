#!/usr/bin/env bash
#
# This round's falsification: each mutation applied to the QUIET tree, the
# named suite run, the file restored BYTE FOR BYTE, and the hash checked after.
#
# ⛔ THE RESTORE IS IN A `trap` (`alpha.106`): the environment's time limit
# arrived mid-mutation one round and left a mutated file on disk with a
# clean-looking `git status`, because the restore came after the suite.
#
# ⛔ AND THE DATABASE SUITE RUNS FILTERED. Twelve minutes per mutation buys
# nothing; `expect` names the test class that is supposed to notice, and a
# filter that matches nothing is reported as a problem rather than as a pass.
#
#   bash tools/alpha108-falsification.sh <out-dir> [start] [end]
#
# `end` exists because a rewritten mutation has to be re-measured on its own:
# re-running the twelve that already fell buys nothing and costs an hour.
set -uo pipefail
cd "$(dirname "$0")/.."

OUT=${1:-docs/evidence/alpha108-falsification}
START=${2:-1}
END=${3:-}
mkdir -p "$OUT"
LOG="$OUT/falsification.txt"
MUT=tools/alpha108-mutations.py

set -a; . ./.env.testing; set +a

problems=0
RESTORE=""
TARGET=""

restore() {
  if [ -n "$RESTORE" ] && [ -n "$TARGET" ]; then
    cp "$RESTORE" "$TARGET"
    RESTORE=""
  fi
}
trap restore EXIT INT TERM

note() { echo "$*" | tee -a "$LOG"; }

count=$(python3 -I "$MUT" count)
[ -n "$END" ] && count=$END
note "=== falsification: mutations $START..$count of $(python3 -I "$MUT" count) ==="
note "php: $(php -r 'echo PHP_VERSION;')"

for n in $(seq "$START" "$count"); do
  name=$(python3 -I "$MUT" name "$n")
  TARGET=$(python3 -I "$MUT" file "$n")
  expect=$(python3 -I "$MUT" expect "$n")
  note ""
  note "--- M$n: $name"
  note "    file: $TARGET   expect: $expect"

  before=$(sha256sum "$TARGET" | cut -d' ' -f1)
  RESTORE=$(mktemp)
  cp "$TARGET" "$RESTORE"

  if ! python3 -I "$MUT" apply "$n" >>"$LOG" 2>&1; then
    note "    NOT APPLIED — this is a FAILURE, not an unbreakable guard"
    problems=$((problems + 1))
    restore
    continue
  fi

  fails=0
  case "$expect" in
    *unit*)
      r=$(vendor/bin/phpunit --testsuite unit 2>&1 | tail -3 | tr '\n' ' ')
      note "    unit: $r"
      echo "$r" | grep -qE 'Failures|Errors' && fails=$((fails + 1))
      ;;
  esac
  case "$expect" in
    *architecture*)
      r=$(vendor/bin/phpunit --testsuite architecture 2>&1 | tail -3 | tr '\n' ' ')
      note "    architecture: $r"
      echo "$r" | grep -qE 'Failures|Errors' && fails=$((fails + 1))
      ;;
  esac
  case "$expect" in
    *contract*)
      r=$(vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php 2>&1 | tail -3 | tr '\n' ' ')
      note "    contract: $r"
      echo "$r" | grep -qE 'Failures|Errors' && fails=$((fails + 1))
      ;;
  esac
  case "$expect" in
    *database:*)
      filter=${expect#*database:}
      filter=${filter%% *}
      r=$(vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
            --filter "$filter" 2>&1 | tail -4 | tr '\n' ' ')
      note "    database[$filter]: $r"
      # ⛔ A filter that matched nothing is NOT a pass: «no tests executed» is
      # byte-for-byte like «this guard is unbreakable» (`alpha.88`).
      if echo "$r" | grep -qiE 'No tests executed'; then
        note "    FILTER MATCHED NOTHING — a problem, not a result"
        problems=$((problems + 1))
      elif echo "$r" | grep -qE 'Failures|Errors'; then
        fails=$((fails + 1))
      fi
      ;;
  esac

  if [ "$fails" -gt 0 ]; then
    note "    FALSIFIED"
  else
    note "    SURVIVED — a hole in the tests, or a mutation that measures nothing"
    problems=$((problems + 1))
  fi

  restore
  after=$(sha256sum "$TARGET" | cut -d' ' -f1)
  if [ "$before" = "$after" ]; then
    note "    restored byte-for-byte: yes"
  else
    note "    restored byte-for-byte: NO ($before -> $after)"
    problems=$((problems + 1))
  fi
done

note ""
note "problems: $problems"
exit $([ "$problems" -eq 0 ] && echo 0 || echo 1)
