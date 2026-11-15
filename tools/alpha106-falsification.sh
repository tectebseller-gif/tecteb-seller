#!/usr/bin/env bash
# The falsification loop for `0.1.0-alpha.106`.
#
# One mutation at a time, on a QUIET tree: nothing else runs, nothing else is
# edited, and the file is restored BYTE FOR BYTE with a sha256 comparison
# before the next one starts (`alpha.100`, `alpha.74`).
#
# A mutation that survives is reported as `SURVIVED` and is a finding about
# the TESTS, not a clean bill of health. A mutation that cannot be applied is
# also a failure: «nothing was measured» reads exactly like «this cannot be
# broken» (`alpha.77`).
set -u

REPO="${REPO:-/home/user/tecteb-seller}"
OUT="${1:-docs/evidence/alpha106-falsification}"
MUT="${MUT:-$REPO/tools/alpha106-mutations.py}"

cd "$REPO" || exit 1
mkdir -p "$OUT"
LOG="$OUT/falsification.txt"
: > "$LOG"

set -a; . ./.env.testing; set +a

say() { printf '%s\n' "$*" | tee -a "$LOG"; }

# ⛔ Nothing else may be running: a second runner on the same database answers
# about neither tree (`alpha.40`), and `pgrep` without `-f` finds nothing
# because the process is named `php`.
if pgrep -f 'phpunit --testsuite' >/dev/null; then
    say "REFUSED: a phpunit run is already in flight"
    exit 1
fi

run_suite() {
    case "$1" in
        unit)         vendor/bin/phpunit --testsuite unit 2>&1 ;;
        architecture) vendor/bin/phpunit --testsuite architecture 2>&1 ;;
        contract)     vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php 2>&1 ;;
        database)     vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php 2>&1 ;;
    esac
}

# Failures AND errors AND warnings: a run that could not report is not green,
# and `grep ':[1-9]'` on a `?` reads as a pass (`alpha.82`).
count_failures() {
    local out="$1" n
    n=$(printf '%s' "$out" | grep -Eo 'Failures: [0-9]+|Errors: [0-9]+|Warnings: [0-9]+' | grep -Eo '[0-9]+' | paste -sd+ - | bc 2>/dev/null)
    [ -z "$n" ] && n=0
    if ! printf '%s' "$out" | grep -qE '^(OK|OK, but|FAILURES|ERRORS|WARNINGS)'; then
        printf '%s' 'NO_VERDICT'
        return
    fi
    printf '%s' "$n"
}

TOTAL=$(python3 -I "$MUT" count)
say "mutations: $TOTAL"
say "tree: $(git rev-parse --short HEAD) (+ uncommitted work)"
say ""

problems=0
for i in $(seq 1 "$TOTAL"); do
    name=$(python3 -I "$MUT" name "$i")
    file=$(python3 -I "$MUT" file "$i")
    expect=$(python3 -I "$MUT" expect "$i")
    before=$(sha256sum "$file" | cut -d' ' -f1)
    cp "$file" "$OUT/.restore"

    say "--- $i/$TOTAL  $name"
    say "    file: $file"
    if ! python3 -I "$MUT" apply "$i" 2>>"$LOG"; then
        say "    MUTATION DID NOT APPLY — this is a failure, not a strong guard"
        problems=$((problems + 1))
        cp "$OUT/.restore" "$file"
        continue
    fi

    verdict=""
    for suite in ${expect//,/ }; do
        out=$(run_suite "$suite")
        n=$(count_failures "$out")
        verdict="$verdict $suite:$n"
    done
    say "    expected red in: $expect"
    say "    measured:$verdict"

    # Restore FIRST, then judge: a judgement that exits early must not leave a
    # mutated tree behind (`alpha.82`).
    cp "$OUT/.restore" "$file"
    after=$(sha256sum "$file" | cut -d' ' -f1)
    if [ "$before" != "$after" ]; then
        say "    RESTORE FAILED — $before != $after"
        problems=$((problems + 1))
        continue
    fi

    if printf '%s' "$verdict" | grep -q 'NO_VERDICT'; then
        say "    NO VERDICT PRINTED — the run could not be measured"
        problems=$((problems + 1))
    elif printf '%s' "$verdict" | grep -qE ':[1-9]'; then
        say "    falsified"
    else
        say "    SURVIVED — a hole in the tests, not a guard that cannot break"
        problems=$((problems + 1))
    fi
    say ""
done

rm -f "$OUT/.restore"
say "problems: $problems"
[ "$problems" -eq 0 ]
