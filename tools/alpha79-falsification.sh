#!/usr/bin/env bash
# Falsify each of alpha.79's four fixes by putting the defect back.
#
# ⛔ On a QUIET tree. Mutating code while a suite is running is the same shape
# as two runners on one database (`alpha.40`), and the answer is about neither
# tree. Nothing else may be running when this starts.
#
# Each round: hash the file, apply the mutation from `alpha79-mutations.py`,
# run the filter that must FAIL, restore from the pre-mutation copy, and prove
# the restore with `cmp` — byte for byte, not `git diff`, which is satisfied
# by an uncommitted change of its own (`alpha.71`).
set -uo pipefail
cd "$(dirname "$0")/.."
OUT="${1:-docs/evidence/alpha79-falsification}"
mkdir -p "$OUT"
LOG="$OUT/falsification.txt"
: > "$LOG"

say() { printf '%s\n' "$*" | tee -a "$LOG"; }

# shellcheck disable=SC1091
set -a; . ./.env.testing; set +a

N=$(python3 tools/alpha79-mutations.py count)
say "alpha.79 falsification — $N mutations, $(date -u +%Y-%m-%dT%H:%M:%SZ)"
say ""

FAILURES=0
for i in $(seq 0 $((N - 1))); do
  NAME=$(python3 tools/alpha79-mutations.py name "$i")
  FILE=$(python3 tools/alpha79-mutations.py file "$i")
  EXPECT=$(python3 tools/alpha79-mutations.py expect "$i")
  say "## $i — $NAME"
  say "   file:   $FILE"
  say "   filter: $EXPECT"

  BEFORE="$OUT/.before-$i"
  cp "$FILE" "$BEFORE"
  SUM=$(sha256sum < "$FILE" | cut -d' ' -f1)

  if ! python3 tools/alpha79-mutations.py apply "$i" >>"$LOG" 2>&1; then
    say "   RESULT: MUTATION DID NOT APPLY — counted as a failure of this script,"
    say "           because «nothing was measured» looks exactly like «this guard"
    say "           cannot be broken»."
    FAILURES=$((FAILURES + 1))
    cp "$BEFORE" "$FILE"; rm -f "$BEFORE"
    say ""
    continue
  fi

  ARCH=0; DB=0
  vendor/bin/phpunit --testsuite architecture --filter "$EXPECT" \
    > "$OUT/run-$i-architecture.txt" 2>&1 || ARCH=1
  vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
    --filter "$EXPECT" > "$OUT/run-$i-database.txt" 2>&1 || DB=1
  say "   architecture: $(tail -3 "$OUT/run-$i-architecture.txt" | tr '\n' ' ')"
  say "   database:     $(tail -3 "$OUT/run-$i-database.txt" | tr '\n' ' ')"

  if [ "$ARCH" -eq 1 ] || [ "$DB" -eq 1 ]; then
    say "   RESULT: the defect is caught. ✓"
  else
    say "   RESULT: SURVIVED — the mutation passed every test, so what was"
    say "           added is not measured by anything."
    FAILURES=$((FAILURES + 1))
  fi

  cp "$BEFORE" "$FILE"
  if ! cmp -s "$BEFORE" "$FILE"; then
    say "   RESTORE: FAILED"
    FAILURES=$((FAILURES + 1))
  else
    AFTER=$(sha256sum < "$FILE" | cut -d' ' -f1)
    if [ "$SUM" = "$AFTER" ]; then
      say "   restored byte for byte: $AFTER"
    else
      say "   RESTORE: hash moved ($SUM -> $AFTER)"
      FAILURES=$((FAILURES + 1))
    fi
  fi
  rm -f "$BEFORE"
  say ""
done

say "failures: $FAILURES"
[ "$FAILURES" -eq 0 ]
