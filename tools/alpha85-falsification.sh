#!/usr/bin/env bash
# Put each of this round's defects back, one at a time, and prove a suite
# turns red for it.
#
# Three rules this script exists to obey:
#
#   * ONE MUTATION AT A TIME, on a quiet tree. Mutating code while a suite is
#     running is the same shape as two runners on one database, and the answer
#     is about neither tree (`alpha.74`).
#   * RESTORE IN A `trap`. A loop stopped half way must not leave a mutation on
#     the tree — one that `git status` would show, but a later run would
#     silently answer a different question with (`alpha.76`).
#   * A MUTATION THAT DOES NOT APPLY IS A FAILURE, never an unbreakable guard
#     (`alpha.77`). The python helper exits non-zero and this script counts it.
#
# Usage: bash tools/alpha85-falsification.sh <out-dir>
set -uo pipefail
cd "$(dirname "$0")/.."
REPO="$PWD"
OUT="${1:-docs/evidence/alpha85-falsification}"
mkdir -p "$OUT"
LOG="$OUT/falsification.txt"
: > "$LOG"

if [ -f .env.testing ]; then
  set -a; . ./.env.testing; set +a
fi

SNAPSHOT="$(mktemp -d)"
TOUCHED=""
restore() {
  for f in $TOUCHED; do
    if [ -f "$SNAPSHOT/$(echo "$f" | tr / _)" ]; then
      cp "$SNAPSHOT/$(echo "$f" | tr / _)" "$REPO/$f"
    fi
  done
  TOUCHED=""
}
trap 'restore; rm -rf "$SNAPSHOT"' EXIT INT TERM

say() { echo "$*" | tee -a "$LOG"; }

run_suite() {
  # $1 suite, $2 optional --filter class. Returns the failure+error count.
  local suite="$1" filter="${2:-}" extra=""
  case "$suite" in
    database) extra="--bootstrap tests/bootstrap-database.php" ;;
    contract) extra="--bootstrap tests/bootstrap-contract.php" ;;
  esac
  [ -n "$filter" ] && extra="$extra --filter $filter"
  local out
  out="$(vendor/bin/phpunit --testsuite "$suite" $extra 2>&1 || true)"
  # ⛔ Warnings and «no summary printed» are counted too: a run that could not
  # be measured must read RED, not green (`alpha.82`).
  local line
  line="$(printf '%s\n' "$out" | grep -E '^(OK|OK, but|Tests:|FAILURES|ERRORS)' | tail -1)"
  if [ -z "$line" ]; then
    echo "UNMEASURED"
    return
  fi
  if printf '%s' "$line" | grep -q '^OK ('; then
    echo 0
    return
  fi
  printf '%s' "$line" | grep -oE '(Failures|Errors|Warnings): [0-9]+' \
    | grep -oE '[0-9]+' | paste -sd+ - | bc
}

COUNT="$(python3 -I tools/alpha85-mutations.py count)"
say "alpha.85 falsification — $COUNT mutations"
say "tree: $(git rev-parse --short HEAD) $(git status --porcelain | wc -l) modified paths"
say ""

# The healthy baseline, so a red suite is not blamed on a mutation.
for pair in "architecture:" "database:CategoryLinkFlowTest"; do
  n="$(run_suite "${pair%%:*}" "${pair##*:}")"
  say "baseline ${pair}: $n"
  [ "$n" = "0" ] || { say "FATAL: the baseline is not clean; measure nothing until it is"; exit 1; }
done
say ""

FAIL=0
for i in $(seq 1 "$COUNT"); do
  name="$(python3 -I tools/alpha85-mutations.py name "$i")"
  file="$(python3 -I tools/alpha85-mutations.py file "$i")"
  expect="$(python3 -I tools/alpha85-mutations.py expect "$i")"
  suite="${expect%%:*}"
  want="${expect##*:}"
  filter="$(printf '%s' "$expect" | cut -d: -f2)"

  key="$(echo "$file" | tr / _)"
  cp "$REPO/$file" "$SNAPSHOT/$key"
  before="$(sha256sum "$REPO/$file" | cut -d' ' -f1)"
  TOUCHED="$file"

  if ! python3 -I tools/alpha85-mutations.py apply "$i" 2>>"$LOG"; then
    say "$i. $name — MUTATION DID NOT APPLY (counted as a failure)"
    FAIL=$((FAIL + 1))
    restore
    continue
  fi

  got="$(run_suite "$suite" "$filter")"
  restore
  after="$(sha256sum "$REPO/$file" | cut -d' ' -f1)"

  verdict="red"
  [ "$got" = "0" ] && verdict="SURVIVED"
  [ "$got" = "UNMEASURED" ] && verdict="UNMEASURED"
  say "$i. $name"
  say "    $suite failures: $got (wanted at least $want) — $verdict"
  if [ "$before" = "$after" ]; then
    say "    restored byte for byte: yes"
  else
    say "    restored byte for byte: NO — $file differs from its snapshot"
    FAIL=$((FAIL + 1))
  fi
  if [ "$verdict" != "red" ]; then
    FAIL=$((FAIL + 1))
  fi
done

say ""
# And the tree is healthy again at the end, not just between mutations.
for pair in "architecture:" "database:CategoryLinkFlowTest"; do
  n="$(run_suite "${pair%%:*}" "${pair##*:}")"
  say "final ${pair}: $n"
  [ "$n" = "0" ] || FAIL=$((FAIL + 1))
done
say ""
say "mutations: $COUNT · problems: $FAIL"
[ "$FAIL" = "0" ] || exit 1
