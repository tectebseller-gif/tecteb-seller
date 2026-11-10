#!/usr/bin/env bash
# Re-measure named mutations after closing the holes they exposed.
#
# ⛔ WHY A SECOND RUN AND NOT A RERUN OF EVERYTHING. Three of the eight
# mutations survived the first loop. Two were test holes and one was a branch
# that cannot be reached with today's catalog; each got the answer its own kind
# needs — a behavioural test, a reachable public rule, or a source assertion
# plus the measurement that makes it unreachable (`alpha.77`, `alpha.95`). A
# claim that they are closed is only worth the run that shows the same mutation
# now going red, so this re-runs exactly those and writes into the SAME log.
#
# Usage: bash tools/alpha103-remeasure.sh <out-dir> <n> [n...]
set -u
cd "$(dirname "$0")/.."
OUT="${1:?usage: alpha103-remeasure.sh <out-dir> <n> [n...]}"
shift
LOG="$OUT/falsification.txt"
mkdir -p "$OUT"

if pgrep -f 'phpunit --testsuite' >/dev/null 2>&1; then
  echo "REFUSING: phpunit is already running." | tee -a "$LOG"
  exit 1
fi

set -a; . ./.env.testing; set +a
say() { echo "$*" | tee -a "$LOG"; }

suites=(unit architecture contract database)
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
  echo '?'
}

run_stop() {
  local label="$1" line=""
  for s in "${suites[@]}"; do
    local boot_var="boot_$s"
    local tmp="$OUT/.remeasure-$label-$s.txt"
    # shellcheck disable=SC2086
    vendor/bin/phpunit --testsuite "$s" ${!boot_var} > "$tmp" 2>&1
    local n; n=$(count_failures "$tmp")
    line="$line $s:$n"
    if [ "$n" != 0 ]; then echo "$line (stopped: this suite noticed)"; return; fi
  done
  echo "$line"
}

say ""
say "=== SECOND RUN: the three that survived, after the holes were closed ==="
problems=0
for i in "$@"; do
  name=$(python3 -I tools/alpha103-mutations.py name "$i")
  file=$(python3 -I tools/alpha103-mutations.py file "$i")
  before=$(sha256sum "$file" | cut -d' ' -f1)
  cp "$file" "$file.snapshot"

  if ! python3 -I tools/alpha103-mutations.py apply "$i" 2>>"$LOG"; then
    say "MUTATION $i $name: NOT APPLIED — FAILURE"
    rm -f "$file.snapshot"; problems=$((problems+1)); continue
  fi
  if ! php -l "$file" >/dev/null 2>&1; then
    say "MUTATION $i $name: does not parse — the red would be a syntax error"
    cp "$file.snapshot" "$file"; rm -f "$file.snapshot"; problems=$((problems+1)); continue
  fi

  result=$(run_stop "m$i")
  say "MUTATION $i $name (second run)"
  say "  suites:$result"
  cp "$file.snapshot" "$file"; rm -f "$file.snapshot"
  restored=$(sha256sum "$file" | cut -d' ' -f1)
  if [ "$before" != "$restored" ]; then
    say "  RESTORE FAILED"; problems=$((problems+1)); continue
  fi
  say "  restored byte-for-byte: $restored"
  if ! echo "$result" | grep -qE ':[1-9]'; then
    say "  ⛔ STILL SURVIVES — the hole is not closed. Say so; do not report it as falsified."
    problems=$((problems+1))
  fi
  say ""
done

say "=== baseline after the second run ==="
say "baseline:$(run_stop final2)"
say "second run: $# mutations   problems: $problems"
rm -f "$OUT"/.remeasure-*.txt
exit $(( problems > 0 ? 1 : 0 ))
