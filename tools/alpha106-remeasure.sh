#!/usr/bin/env bash
# Re-measure named mutations after closing the holes they exposed.
#
# ⛔ WHY A SECOND RUN RATHER THAN A RERUN OF ALL TWENTY-ONE. A mutation that
# survived says one of three things, and each needs its own answer: a hole in
# the tests (write the behavioural test), a guard no fixture can reach
# (`alpha.95` — assert it in source and move the mutation there), or a
# mutation whose name does not match its work (`alpha.82` — rewrite it). A
# claim that a hole is closed is worth exactly the run that shows the SAME
# mutation now going red, so this re-runs those and appends to the SAME log.
#
# ⛔ AND IT CHECKS THE EXPECTED SUITE, not merely «something went red»:
# falsification by an unrelated suite is not falsification (`alpha.97`).
#
# Usage: bash tools/alpha106-remeasure.sh <out-dir> <n> [n...]
set -u
cd "$(dirname "$0")/.."
OUT="${1:?usage: alpha106-remeasure.sh <out-dir> <n> [n...]}"
shift
LOG="$OUT/falsification.txt"
mkdir -p "$OUT"

# One is not zero (`alpha.105`): `pgrep -f` finds the loop itself, so this
# asks only about phpunit — and asks at all, because a second runner on the
# same database answers about neither tree (`alpha.40`).
if pgrep -f 'phpunit --testsuite' >/dev/null 2>&1; then
  echo "REFUSING: phpunit is already running." | tee -a "$LOG"
  exit 1
fi

set -a; . ./.env.testing; set +a
say() { echo "$*" | tee -a "$LOG"; }

boot_unit=""
boot_architecture=""
boot_contract="--bootstrap tests/bootstrap-contract.php"
boot_database="--bootstrap tests/bootstrap-database.php"

count_failures() {
  local file="$1"
  if grep -q '^OK (' "$file"; then echo 0; return; fi
  if grep -qE '^(FAILURES|ERRORS|WARNINGS|OK, but)' "$file"; then
    local f e w
    f=$(grep -oE 'Failures: [0-9]+' "$file" | grep -oE '[0-9]+' | head -1)
    e=$(grep -oE 'Errors: [0-9]+' "$file" | grep -oE '[0-9]+' | head -1)
    w=$(grep -oE 'Warnings: [0-9]+' "$file" | grep -oE '[0-9]+' | head -1)
    echo $(( ${f:-0} + ${e:-0} + ${w:-0} ))
    return
  fi
  # No verdict line at all means the run died before judging anything, and
  # «?» must never read as green.
  echo 'NO_VERDICT'
}

# Runs the suites given, in order, stopping at the first that notices.
run_suites() {
  local label="$1"; shift
  local line=""
  for s in "$@"; do
    local boot_var="boot_$s"
    local tmp="$OUT/.remeasure-$label-$s.txt"
    # shellcheck disable=SC2086
    vendor/bin/phpunit --testsuite "$s" ${!boot_var} > "$tmp" 2>&1
    local n; n=$(count_failures "$tmp")
    line="$line $s:$n"
    if [ "$n" != 0 ]; then echo "$line"; return; fi
  done
  echo "$line"
}

say ""
say "=== SECOND RUN: the mutations that survived, after the holes were closed ==="
problems=0
for i in "$@"; do
  name=$(python3 -I tools/alpha106-mutations.py name "$i")
  file=$(python3 -I tools/alpha106-mutations.py file "$i")
  expect=$(python3 -I tools/alpha106-mutations.py expect "$i")
  before=$(sha256sum "$file" | cut -d' ' -f1)
  cp "$file" "$file.snapshot"

  if ! python3 -I tools/alpha106-mutations.py apply "$i" 2>>"$LOG"; then
    say "MUTATION $i $name: DID NOT APPLY — FAILURE, not an unbreakable guard"
    rm -f "$file.snapshot"; problems=$((problems+1)); continue
  fi
  if ! php -l "$file" >/dev/null 2>&1; then
    say "MUTATION $i $name: does not parse — the red would be a syntax error"
    cp "$file.snapshot" "$file"; rm -f "$file.snapshot"; problems=$((problems+1)); continue
  fi

  # shellcheck disable=SC2086
  result=$(run_suites "m$i" ${expect//,/ })
  say "MUTATION $i $name (second run)"
  say "    expected red in: $expect"
  say "    measured:$result"

  # Restore BEFORE judging, so a judgement that exits early still leaves the
  # tree as it found it.
  cp "$file.snapshot" "$file"; rm -f "$file.snapshot"
  restored=$(sha256sum "$file" | cut -d' ' -f1)
  if [ "$before" != "$restored" ]; then
    say "    RESTORE FAILED — stop and look at $file"
    problems=$((problems+1)); continue
  fi
  say "    restored byte-for-byte: $restored"

  if echo "$result" | grep -q 'NO_VERDICT'; then
    say "    NO_VERDICT — a run died before judging; this measures nothing"
    problems=$((problems+1))
  elif echo "$result" | grep -qE ':[1-9]'; then
    say "    falsified"
  else
    say "    ⛔ STILL SURVIVES — the hole is not closed. Report it as surviving."
    problems=$((problems+1))
  fi
  say ""
done

say "=== baseline after the second run (unfiltered) ==="
say "baseline:$(run_suites final2 unit architecture contract database)"
say "second run: $# mutations   problems: $problems"
rm -f "$OUT"/.remeasure-*.txt
exit $(( problems > 0 ? 1 : 0 ))
