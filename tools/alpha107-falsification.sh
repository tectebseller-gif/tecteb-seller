#!/usr/bin/env bash
#
# §8 falsification: each mutation applied to the QUIET tree, the named suite
# run, the file restored BYTE FOR BYTE, and the hash checked after.
#
# ⛔ THE RESTORE IS IN A `trap`. The environment's time limit arrived in the
# middle of mutation 16 one round (`alpha.106`) and left a mutated file on
# disk with a clean-looking `git status`, because the restore came after the
# suite. It comes first now, whatever happens.
#
#   bash tools/alpha107-falsification.sh <out-dir> [start]
set -uo pipefail
cd "$(dirname "$0")/.."

OUT=${1:-docs/evidence/alpha107-falsification}
START=${2:-1}
mkdir -p "$OUT"
LOG="$OUT/falsification.txt"
MUT=tools/alpha107-mutations.py
HDR_BASE=${TMC_HEADER_BASE:-http://127.0.0.1:8099}

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
note "=== §8 falsification: $count mutations, from $START ==="
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
    *contract*)
      r=$(vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php 2>&1 | tail -3 | tr '\n' ' ')
      note "    contract: $r"
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
    *contrast*)
      r=$(php tools/contrast.php 2>&1 | tail -1)
      note "    contrast: $r"
      echo "$r" | grep -qE 'failures: [1-9]' && fails=$((fails + 1))
      ;;
  esac
  case "$expect" in
    *browser*)
      TMC_HEADER_BASE="$HDR_BASE" php tools/render-vendor-header.php >/dev/null 2>&1
      r=$( (cd tools/browser && TMC_HEADER_DIR="$PWD/../../$OUT/../alpha107-header" node alpha107-header.mjs 2>&1 | tail -1) )
      note "    browser: $r"
      echo "$r" | grep -qE 'failures: [1-9]' && fails=$((fails + 1))
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

# The pages on disk were last written by whichever mutation ran last; put the
# healthy render back so the evidence folder is not a mutant.
TMC_HEADER_BASE="$HDR_BASE" php tools/render-vendor-header.php >/dev/null 2>&1

note ""
note "problems: $problems"
exit $([ "$problems" -eq 0 ] && echo 0 || echo 1)
