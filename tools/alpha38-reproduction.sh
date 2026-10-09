#!/usr/bin/env bash
#
# Every named defect of this round, measured on the `alpha.38` BYTES and then
# on the fix — with controls that must answer identically on both.
#
# ### How this avoids the `alpha.32` trap
#
# A reproduction script that reverts code and then runs the NEW round's tests
# proves nothing: the tests name classes the old tree does not have, PHP fatals
# at load, zero tests execute, and every «reproduced» line is a report about
# nothing having run. So the measurement is a probe
# (`tests/Support/alpha38-probe.php`) that asks only questions both builds can
# answer, and every run is checked three ways:
#
#   1. the probe must exit 0;
#   2. its output must contain `probe=ran` — a verb that printed nothing did
#      not execute;
#   3. the CONTROLS must give the same answers on both trees. A control that
#      changes means the revert broke the tree, so the «defect» lines are
#      about a broken checkout rather than about `alpha.38`.
#
# ### How the revert is done and undone
#
# The whole of `src/` goes back to the delivered `alpha.38` commit, so the old
# tree is COHERENT — no file from one build calling a signature from the other.
# The restore is checked against a hash list taken BEFORE anything was
# touched: «فایل برگشت را با هشِ پیش از دست‌زدن بسنجید، نه با git diff»
# (`alpha.32`), because the same check read through `git diff` answers
# correctly right up until the fix is committed. The restore also runs from a
# `trap`, with absolute paths, so an interrupt or a failed assertion still
# leaves the tree as it was found (`alpha.37`).
#
# Both halves use `git restore --worktree`, NOT `git checkout <commit> -- src`,
# and that distinction is the whole reason the hash list exists. `git checkout
# <commit> -- <path>` writes the INDEX as well as the working tree, so the
# plain `git checkout -- src` that undoes it restores from an index that now
# holds the OLD bytes — the restore hands back the revert. The first run of
# this script did exactly that and left `alpha.38`'s source on disk; the hash
# comparison is what said so.
#
# Usage: bash tools/alpha38-reproduction.sh [out-dir]
# Requires: .env.testing pointing at the disposable tmc_test database.

set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha38-reproduction}"
BASE_COMMIT="${TMC_BASE_COMMIT:-29eccbd}"
PHPBIN="${PHPBIN:-php}"
PROBE="$REPO/tests/Support/alpha38-probe.php"
SNAPSHOT="$(mktemp -d)"
STATE_FILE="$SNAPSHOT/src-before.sha256"

mkdir -p "$OUT"
LOG="$OUT/run.txt"
: > "$LOG"

pass=0
fail=0

note()  { printf '%s\n' "$*" | tee -a "$LOG"; }
ok()    { pass=$((pass + 1)); printf 'PASS  %s\n' "$*" | tee -a "$LOG"; }
bad()   { fail=$((fail + 1)); printf 'FAIL  %s\n' "$*" | tee -a "$LOG"; }

# ---------------------------------------------------------------- environment

if [ ! -f "$REPO/.env.testing" ]; then
    note "alpha38-reproduction: .env.testing is missing; the disposable database is required."
    exit 2
fi
set -a
# shellcheck disable=SC1091
. "$REPO/.env.testing"
set +a
case "${TMC_TEST_DB_DSN:-}" in
    *tmc_test*) ;;
    *) note "alpha38-reproduction: refuses any DSN that is not the disposable tmc_test."; exit 2 ;;
esac

if ! git -C "$REPO" rev-parse --verify "$BASE_COMMIT" >/dev/null 2>&1; then
    note "alpha38-reproduction: base commit $BASE_COMMIT is not in this clone."
    exit 2
fi
if ! git -C "$REPO" restore --help >/dev/null 2>&1; then
    note "alpha38-reproduction: this git has no 'restore'; it is required so the index is never written."
    exit 2
fi

# The tree must be clean for src/, or the restore would hand back something
# that was never there.
if ! git -C "$REPO" diff --quiet -- src || ! git -C "$REPO" diff --cached --quiet -- src; then
    note "alpha38-reproduction: src/ has uncommitted changes. Commit or stash them first:"
    note "  a restore would silently discard them, and this script is not allowed to."
    exit 2
fi

# ------------------------------------------------- snapshot, and restore trap

# Absolute paths, taken before anything is touched. `alpha.37`'s rule: a
# relative path wrote the archive inside the directory being moved, the restore
# could not find it, and the hash check compared one empty string with another
# and PASSED.
( cd "$REPO" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum ) > "$STATE_FILE"
note "snapshot: $(wc -l < "$STATE_FILE") files under src/ hashed before any change"

restore() {
    git -C "$REPO" restore --worktree --source=HEAD -- src 2>/dev/null || true
    local after="$SNAPSHOT/src-after.sha256"
    ( cd "$REPO" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum ) > "$after"
    if cmp -s "$STATE_FILE" "$after"; then
        printf 'PASS  src/ restored byte for byte (%s files)\n' "$(wc -l < "$after")" | tee -a "$LOG"
    else
        printf 'FAIL  src/ did NOT come back as it was — compare %s with %s\n' "$STATE_FILE" "$after" | tee -a "$LOG"
        diff "$STATE_FILE" "$after" | head -20 | tee -a "$LOG"
    fi
}
trap restore EXIT INT TERM

# ------------------------------------------------------------------ the probe

# Runs one verb and writes its output. Returns non-zero when the probe did not
# execute, which is NEVER treated as a result.
run_probe() {
    local tree="$1" verb="$2"
    local file="$OUT/$tree-$verb.txt"
    if ! ( cd "$REPO" && "$PHPBIN" "$PROBE" "$verb" ) > "$file" 2>"$file.err"; then
        bad "[$tree] probe '$verb' did not run (exit non-zero) — this is a broken measurement, not a finding"
        sed -n 1,5p "$file.err" | tee -a "$LOG"
        return 1
    fi
    if ! grep -q '^probe=ran$' "$file"; then
        bad "[$tree] probe '$verb' printed no probe=ran — nothing executed"
        return 1
    fi
    return 0
}

expect() {
    local tree="$1" verb="$2" line="$3" why="$4"
    if grep -qx -- "$line" "$OUT/$tree-$verb.txt"; then
        ok "[$tree] $verb: $line — $why"
    else
        bad "[$tree] $verb: expected '$line' ($why); got:"
        sed 's/^/        /' "$OUT/$tree-$verb.txt" | tee -a "$LOG"
    fi
}

DEFECT_VERBS="refund-amount reserve-claim-failure release-failure stale-cancel payment-pair capture-retry half-written-line"
CONTROL_VERBS="control-zero-is-success control-settlement-rules control-unpaid-item-share control-manager-gate"

run_all() {
    local tree="$1"
    local verb
    for verb in $DEFECT_VERBS $CONTROL_VERBS; do
        run_probe "$tree" "$verb"
    done
}

# ------------------------------------------------------- 1. the alpha.38 tree

note ""
note "=== 1. src/ reverted to $BASE_COMMIT (the delivered alpha.38) ==========="
git -C "$REPO" restore --worktree --source="$BASE_COMMIT" -- src
reverted="$(cd "$REPO" && git diff --name-only -- src | wc -l)"
if [ "$reverted" -gt 0 ]; then
    ok "the revert changed $reverted files under src/ — the old tree is really on disk"
else
    bad "the revert changed NOTHING under src/: either the base commit is wrong or this round touched no source"
fi
# And it has to be a tree PHP can load at all.
if ( cd "$REPO" && find src -name '*.php' -print0 | xargs -0 -n1 "$PHPBIN" -l >/dev/null 2>&1 ); then
    ok "the reverted tree passes php -l, so a fatal below would be a real answer"
else
    bad "the reverted tree does not even lint — every measurement after this is void"
fi

run_all alpha38

expect alpha38 refund-amount           'recorded_amount=1100'            'the hundredth: 110000 minor units divided by 100'
expect alpha38 reserve-claim-failure   'request_exists=yes'              'a request with no lines behind it'
expect alpha38 reserve-claim-failure   'claimed_lines=0'                 'and it holds nothing'
expect alpha38 reserve-claim-failure   'eligible_after=900000'           'while the money still counts as withdrawable'
expect alpha38 release-failure         'reject_ok=yes'                   'a rejection reported as successful'
expect alpha38 release-failure         'reserve_lines=0'                 'its reserve lines deleted'
expect alpha38 release-failure         'claimed_items=1'                 'while the order item is still claimed'
expect alpha38 release-failure         'eligible_after=0'                'so the money is stranded with no record of who holds it'
expect alpha38 stale-cancel            'cancel_ok=yes'                   'the stale cancel won'
expect alpha38 stale-cancel            'status_after=cancelled'          'and overwrote the manager status'
expect alpha38 stale-cancel            'claimed_lines=0'                 'releasing money mid-transfer'
expect alpha38 payment-pair            'document_lines=2'                'a payout on the books'
expect alpha38 payment-pair            'status_after=payment_in_progress' 'against a request that is payable again'
expect alpha38 capture-retry           'first_captured=1'                'a storage failure counted as a captured sale'
expect alpha38 capture-retry           'share=null'                      'and the retry stored no share'
expect alpha38 capture-retry           'event=empty'                     'with no reference to the money that IS recorded'
expect alpha38 half-written-line       'repaired=absent'                 'nothing looked at the half-written row'
expect alpha38 half-written-line       'share=null'                      'so it stays unknown for ever'

# ------------------------------------------------------- 2. back to alpha.39

note ""
note "=== 2. src/ restored to HEAD (alpha.39) ================================"
git -C "$REPO" restore --worktree --source=HEAD -- src
after_restore="$SNAPSHOT/src-mid.sha256"
( cd "$REPO" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum ) > "$after_restore"
if cmp -s "$STATE_FILE" "$after_restore"; then
    ok "src/ is byte-identical to the hashes taken before anything was touched"
else
    bad "src/ is NOT what it was before the revert — every measurement below is void"
fi

run_all alpha39

expect alpha39 refund-amount           'recorded_amount=110000'          'the recorded amount, in the order own unit'
expect alpha39 reserve-claim-failure   'request_ok=reservation_lost'     'named, not silently half-done'
expect alpha39 reserve-claim-failure   'request_exists=no'               'nothing was created'
expect alpha39 reserve-claim-failure   'claimed_lines=0'                 'and nothing was claimed'
expect alpha39 reserve-claim-failure   'eligible_after=900000'           'the vendor can still ask for the same money'
expect alpha39 release-failure         'reject_ok=release_failed'        'the caller is told the lines are still held'
expect alpha39 release-failure         'reserve_lines=1'                 'the request still records what it holds'
expect alpha39 release-failure         'claimed_items=1'                 'so a later release can find the money'
expect alpha39 release-failure         'eligible_after=0'                'and nothing pretends it is available yet'
expect alpha39 stale-cancel            'interference_fired=yes'          'the interleaving really happened'
expect alpha39 stale-cancel            'cancel_ok=withdrawal_moved_on'   'the stale cancel is refused'
# MEASURED CHANGE, from `alpha.40` — and the reason is worth more than the line.
#
# `alpha.39` closed the status and released the lines as two separate writes, so
# the manager's scheduled `startPayment()` was a committed fact by the time the
# cancel's guarded `UPDATE` ran, and the status this verb read afterwards was
# the manager's: `payment_in_progress`.
#
# From `alpha.40` the cancel is ONE transaction. The interference is scheduled
# on the SAME CONNECTION, so the manager's write now lands INSIDE that
# transaction and the rollback takes it away with everything else — «a third
# party on your own connection is not a third party». The status therefore
# reads `approved`, which is the state before either actor touched it: nothing
# moved, which is the right outcome and a WEAKER measurement of the race.
#
# So this verb now measures the refusal and the rollback, and the race itself
# is measured where it can be: two OS processes on two connections, in
# `WithdrawalIntegrityTest` via `tests/Support/concurrent-withdrawal.php`.
expect alpha39 stale-cancel            'status_after=approved'           'the transaction rolled the interference back with itself'
expect alpha39 stale-cancel            'claimed_lines=1'                 'and the money stays reserved'
expect alpha39 payment-pair            'payment_ok=withdrawal_moved_on'  'the pair is one unit of work'
expect alpha39 payment-pair            'document_lines=0'                'so no payout exists without its status'
expect alpha39 payment-pair            'status_after=payment_in_progress' 'and the request can still be paid properly'
expect alpha39 capture-retry           'first_captured=0'                'a line that was not stored is not a captured sale'
expect alpha39 capture-retry           'first_failed=1'                  'and the count says which way it went'
expect alpha39 capture-retry           'share=450000'                    'the retry recovered the recorded figures'
expect alpha39 capture-retry           'rate_bp=1000'                    'at the rate recorded at the time'
expect alpha39 capture-retry           'event=set'                       'and the line points at its own event'
expect alpha39 half-written-line       'repaired=1'                      'the existing half-written row was finished'
expect alpha39 half-written-line       'share=450000'                    'from its own ledger event'

# ---------------------------------------------------------- 3. the controls

note ""
note "=== 3. controls: the same answer on both trees =========================="
control_fail=0
for verb in $CONTROL_VERBS; do
    a="$OUT/alpha38-$verb.txt"
    b="$OUT/alpha39-$verb.txt"
    if [ ! -s "$a" ] || [ ! -s "$b" ]; then
        bad "control $verb is missing an output file on one of the trees"
        control_fail=1
        continue
    fi
    if cmp -s "$a" "$b"; then
        ok "control $verb answers identically on alpha.38 and alpha.39"
    else
        bad "control $verb DIFFERS between the trees — the revert changed behaviour it should not have:"
        diff "$a" "$b" | sed 's/^/        /' | tee -a "$LOG"
        control_fail=1
    fi
done
if [ "$control_fail" -eq 0 ]; then
    ok "every control held, so the defect lines above are about alpha.38 and not about a broken checkout"
fi

# ------------------------------------------------------------------- verdict

note ""
note "checks passed: $pass"
note "checks failed: $fail"
note "evidence: $OUT"
if [ "$fail" -ne 0 ]; then
    exit 1
fi
exit 0
