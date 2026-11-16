#!/usr/bin/env bash
#
# Re-runs named §8 mutations and APPENDS the verdicts to the same log.
#
# ⛔ A MISSING VERDICT IS `NO_VERDICT`, not a pass. A re-measurement that
# cannot tell whether a suite went red says so (`alpha.106`).
#
#   bash tools/alpha107-remeasure.sh <out-dir> <n> [n...]
set -uo pipefail
cd "$(dirname "$0")/.."

OUT=${1:-docs/evidence/alpha107-falsification}
shift || true
mkdir -p "$OUT"
LOG="$OUT/falsification.txt"
MUT=tools/alpha107-mutations.py
HDR_BASE=${TMC_HEADER_BASE:-http://127.0.0.1:8099}

set -a; . ./.env.testing; set +a

problems=0
RESTORE=""
TARGET=""
restore() { if [ -n "$RESTORE" ] && [ -n "$TARGET" ]; then cp "$RESTORE" "$TARGET"; RESTORE=""; fi; }
trap restore EXIT INT TERM
note() { echo "$*" | tee -a "$LOG"; }

note ""
note "=== second pass: $* ==="

for n in "$@"; do
  name=$(python3 -I "$MUT" name "$n")
  TARGET=$(python3 -I "$MUT" file "$n")
  expect=$(python3 -I "$MUT" expect "$n")
  note ""
  note "--- M$n (again): $name"
  note "    file: $TARGET   expect: $expect"

  before=$(sha256sum "$TARGET" | cut -d' ' -f1)
  RESTORE=$(mktemp)
  cp "$TARGET" "$RESTORE"

  if ! python3 -I "$MUT" apply "$n" >>"$LOG" 2>&1; then
    note "    NOT APPLIED — a FAILURE, not an unbreakable guard"
    problems=$((problems + 1)); restore; continue
  fi

  verdict=NO_VERDICT
  case "$expect" in
    *browser*)
      TMC_HEADER_BASE="$HDR_BASE" php tools/render-vendor-header.php >/dev/null 2>&1
      r=$( (cd tools/browser && TMC_HEADER_DIR="$PWD/../../docs/evidence/alpha107-header" node alpha107-header.mjs 2>&1 | tail -1) )
      note "    browser: $r"
      if echo "$r" | grep -qE 'failures: [1-9]'; then verdict=FALSIFIED
      elif echo "$r" | grep -qE 'failures: 0'; then verdict=SURVIVED; fi
      ;;
    *contract*)
      r=$(vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php 2>&1 | tail -3 | tr '\n' ' ')
      note "    contract: $r"
      if echo "$r" | grep -qE 'Failures|Errors'; then verdict=FALSIFIED
      elif echo "$r" | grep -q 'OK ('; then verdict=SURVIVED; fi
      ;;
    *architecture*)
      r=$(vendor/bin/phpunit --testsuite architecture 2>&1 | tail -3 | tr '\n' ' ')
      note "    architecture: $r"
      if echo "$r" | grep -qE 'Failures|Errors'; then verdict=FALSIFIED
      elif echo "$r" | grep -q 'OK ('; then verdict=SURVIVED; fi
      ;;
  esac

  note "    $verdict"
  [ "$verdict" = FALSIFIED ] || problems=$((problems + 1))

  restore
  after=$(sha256sum "$TARGET" | cut -d' ' -f1)
  if [ "$before" = "$after" ]; then note "    restored byte-for-byte: yes"
  else note "    restored byte-for-byte: NO ($before -> $after)"; problems=$((problems + 1)); fi
done

TMC_HEADER_BASE="$HDR_BASE" php tools/render-vendor-header.php >/dev/null 2>&1
note ""
note "second-pass problems: $problems"
exit $([ "$problems" -eq 0 ] && echo 0 || echo 1)
