#!/usr/bin/env bash
# Falsify every guard this round added: break it, watch the suites go red,
# put the byte back.
#
# ⛔ RUN THIS ON A QUIET TREE. Mutating code while a suite runs is the same
# shape as two runners on one database, and the answer is about neither tree
# (`alpha.74`, `alpha.82`). The loop refuses to start if phpunit is already up.
#
# ⛔ AND THE DATABASE SUITE RUNS ALONE. The contract suite's wpdb stub is a
# real PDO on the same database (`alpha.40`, `alpha.98`).
#
# Mutations live in tools/alpha103-mutations.py, as data — not in a heredoc
# here, where every `$variable` in a PHP snippet would be eaten (`alpha.77`).
set -u
cd "$(dirname "$0")/.."
OUT="${1:-docs/evidence/alpha103-falsification}"
mkdir -p "$OUT"
LOG="$OUT/falsification.txt"
: > "$LOG"

say() { echo "$*" | tee -a "$LOG"; }

if pgrep -f 'phpunit --testsuite' >/dev/null 2>&1; then
  say "REFUSING: phpunit is already running. A mutation now measures neither tree."
  exit 1
fi

set -a; . ./.env.testing; set +a

# One suite at a time, and `database` never beside another.
suites=(unit architecture contract database)
boot_unit=""
boot_architecture=""
boot_contract="--bootstrap tests/bootstrap-contract.php"
boot_database="--bootstrap tests/bootstrap-database.php"

count_failures() {
  # Failures, errors AND warnings. A run that printed no summary at all is a
  # measurement that did not happen, and it is reported as `?` rather than 0
  # (`alpha.82`: «نتوانستم اندازه بگیرم» must be red and must have a name).
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

# `all` runs every suite; `stop` stops at the first one that notices.
#
# ⛔ Stopping early is not thrift, it is the question. «Did anything notice?»
# is answered by the first red, and the database suite takes ten minutes —
# running it nine more times to collect numbers nobody reads would turn a
# measurement into a vigil. A mutation that SURVIVES still gets every suite,
# because there the answer is «nothing noticed» and that claim needs all four.
run_suites() {
  local label="$1" mode="$2" line=""
  for s in "${suites[@]}"; do
    local boot_var="boot_$s"
    local tmp="$OUT/.run-$label-$s.txt"
    # shellcheck disable=SC2086
    vendor/bin/phpunit --testsuite "$s" ${!boot_var} > "$tmp" 2>&1
    local n; n=$(count_failures "$tmp")
    line="$line $s:$n"
    if [ "$mode" = stop ] && [ "$n" != 0 ]; then
      echo "$line (stopped: this suite noticed)"
      return
    fi
  done
  echo "$line"
}

run_all() { run_suites "$1" all; }

say "=== healthy baseline, before any mutation ==="
say "baseline:$(run_all baseline)"
say ""

total=$(python3 -I tools/alpha103-mutations.py count)
problems=0
for i in $(seq 1 "$total"); do
  name=$(python3 -I tools/alpha103-mutations.py name "$i")
  file=$(python3 -I tools/alpha103-mutations.py file "$i")
  why=$(python3 -I tools/alpha103-mutations.py why "$i")
  before=$(sha256sum "$file" | cut -d' ' -f1)
  # ⛔ Snapshot, not a reverse needle. `WHERE id = %d` is not unique and a
  # revert that silently matches elsewhere leaves the tree mutated while
  # claiming it restored it — which is how the dry run of this very script
  # left two files broken.
  cp "$file" "$file.snapshot"

  if ! python3 -I tools/alpha103-mutations.py apply "$i" 2>>"$LOG"; then
    say "MUTATION $i $name: NOT APPLIED — this is a FAILURE, not an unbreakable guard"
    rm -f "$file.snapshot"
    problems=$((problems+1))
    continue
  fi
  after=$(sha256sum "$file" | cut -d' ' -f1)
  if [ "$before" = "$after" ]; then
    say "MUTATION $i $name: file unchanged — FAILURE"
    cp "$file.snapshot" "$file"; rm -f "$file.snapshot"
    problems=$((problems+1))
    continue
  fi
  # A mutation that does not even parse makes every suite red for the wrong
  # reason, and «red» would then prove nothing about the guard.
  if ! php -l "$file" >/dev/null 2>&1; then
    say "MUTATION $i $name: does not parse — the red would be a syntax error, not a guard"
    cp "$file.snapshot" "$file"; rm -f "$file.snapshot"
    problems=$((problems+1))
    continue
  fi

  result=$(run_suites "m$i" stop)
  say "MUTATION $i $name"
  say "  breaks: $why"
  say "  suites:$result"

  cp "$file.snapshot" "$file"; rm -f "$file.snapshot"
  restored=$(sha256sum "$file" | cut -d' ' -f1)
  if [ "$before" != "$restored" ]; then
    say "  RESTORE FAILED — tree is dirty, stop here"
    problems=$((problems+1))
    continue
  fi
  say "  restored byte-for-byte: $restored"

  # Survivor? Every number zero means nothing noticed.
  if ! echo "$result" | grep -qE ':[1-9]'; then
    say "  ⛔ SURVIVED — no suite noticed. Either a hole in the tests or unreachable; measure which."
    problems=$((problems+1))
  fi
  say ""
done

say "=== final baseline, after every restore ==="
say "baseline:$(run_all final)"
say ""
say "mutations: $total   problems: $problems"
rm -f "$OUT"/.run-*.txt
exit $(( problems > 0 ? 1 : 0 ))
