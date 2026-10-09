#!/usr/bin/env bash
#
# Every named defect of this round, measured on the `alpha.39` BYTES and then
# on the fix — with controls that must answer identically on both.
#
# ### How this avoids the `alpha.32` trap
#
# A reproduction script that reverts code and then runs the NEW round's tests
# proves nothing: the tests name classes the old tree does not have, PHP fatals
# at load, zero tests execute, and every «reproduced» line is a report about
# nothing having run. So the measurement is a probe
# (`tests/Support/alpha39-probe.php`) that asks only questions both builds can
# answer, and every run is checked three ways:
#
#   1. the probe must exit 0;
#   2. its output must contain `probe=ran` — a verb that printed nothing did
#      not execute;
#   3. the CONTROLS must give the same answers on both trees. A control that
#      changes means the revert broke the tree, so the «defect» lines are
#      about a broken checkout rather than about `alpha.39`.
#
# ### How the revert is done and undone
#
# The whole of `src/` goes back to the delivered `alpha.39` commit, so the old
# tree is COHERENT — no file from one build calling a signature from the other.
# The restore is checked against a hash list taken BEFORE anything was
# touched: «فایل برگشت را با هشِ پیش از دست‌زدن بسنجید، نه با git diff»
# (`alpha.32`), because the same check read through `git diff` answers
# correctly right up until the fix is committed. The restore also runs from a
# `trap`, with absolute paths, so an interrupt or a failed assertion still
# leaves the tree as it was found (`alpha.37`).
#
# Both halves use `git restore --worktree`, NOT `git checkout <commit> -- src`:
# `git checkout <commit> -- <path>` writes the INDEX as well, so the plain
# `git checkout -- src` meant to undo it restores from an index that now holds
# the OLD bytes and hands the revert back (`alpha.39`'s own fourth rule).
#
# ### What the `alpha.39` tree does NOT have, and what that means here
#
# Schema 22 arrives with this round, so on the reverted tree
# `Bootstrap::migrations()` stops at 21 and `tmc_vendor_money_unit` does not
# exist. That is not a limitation of the measurement — it IS the state §4 is
# about, and the probe builds the registry only when the constructor has room
# for it.
#
# Usage: bash tools/alpha39-reproduction.sh [out-dir]
# Requires: .env.testing pointing at the disposable tmc_test database.

set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha39-reproduction}"
BASE_COMMIT="${TMC_BASE_COMMIT:-54228e5}"
PHPBIN="${PHPBIN:-php}"
PROBE="$REPO/tests/Support/alpha39-probe.php"
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
    note "alpha39-reproduction: .env.testing is missing; the disposable database is required."
    exit 2
fi
set -a
# shellcheck disable=SC1091
. "$REPO/.env.testing"
set +a
case "${TMC_TEST_DB_DSN:-}" in
    *tmc_test*) ;;
    *) note "alpha39-reproduction: refuses any DSN that is not the disposable tmc_test."; exit 2 ;;
esac

if ! git -C "$REPO" rev-parse --verify "$BASE_COMMIT" >/dev/null 2>&1; then
    note "alpha39-reproduction: base commit $BASE_COMMIT is not in this clone."
    exit 2
fi
if ! git -C "$REPO" restore --help >/dev/null 2>&1; then
    note "alpha39-reproduction: this git has no 'restore'; it is required so the index is never written."
    exit 2
fi
if ! git -C "$REPO" diff --quiet -- src || ! git -C "$REPO" diff --cached --quiet -- src; then
    note "alpha39-reproduction: src/ has uncommitted changes. Commit or stash them first:"
    note "  a restore would silently discard them, and this script is not allowed to."
    exit 2
fi

# ------------------------------------------- nobody else may be on this database
#
# MEASURED THIS ROUND, and it cost a run: this script and `phpunit --testsuite
# database` were started at the same time against the same disposable database.
# The suite's `TRUNCATE`/`DROP` landed in the middle of the probe's migration
# chain, a migration threw, and `run.txt` ended in a stack trace about
# `M0016VendorImportRun` — which is a report about two runners sharing a
# database, dressed as a defect in the build under test.
#
# So the question is asked of MySQL itself rather than of a lockfile this script
# keeps: a lockfile only serialises things that take it, and `phpunit` does not.
# `information_schema.PROCESSLIST` sees every connection whatever started it.
others_on_this_database() {
    "$PHPBIN" -r '
        $dsn = getenv("TMC_TEST_DB_DSN");
        try { $pdo = new PDO($dsn, getenv("TMC_TEST_DB_USER"), getenv("TMC_TEST_DB_PASS"),
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch (Throwable $e) { echo "unknown"; exit; }
        $n = $pdo->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST
            WHERE DB = DATABASE() AND ID <> CONNECTION_ID()")->fetchColumn();
        echo (string) (int) $n;
    ' 2>/dev/null
}
busy="$(others_on_this_database)"
if [ "$busy" = "unknown" ]; then
    note "$(basename "$0"): cannot reach the disposable database to ask who else is on it."
    exit 2
fi
if [ "${busy:-0}" -gt 0 ]; then
    note "$(basename "$0"): $busy other connection(s) are using the disposable database."
    note "  Refusing to start. Two runners on one database measure each other, not the build:"
    note "  a TRUNCATE from phpunit inside this script's migration chain throws, and the"
    note "  stack trace reads like a defect. Wait for the other run, then start this one."
    exit 2
fi

# ------------------------------------------------- snapshot, and restore trap

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

DEFECT_VERBS="reversal-unit reversal-unit-unknown refund-arguments cancel-atomic reject-atomic claim-cancelled-item claim-returned-item mixed-books unit-read-failure changed-line-retry figures-not-overwritten no-repointing"
CONTROL_VERBS="control-zero-is-success control-settlement-rules control-unpaid-item-share control-manager-gate control-complete-line-not-repairable"

run_all() {
    local tree="$1"
    local verb
    for verb in $DEFECT_VERBS $CONTROL_VERBS; do
        run_probe "$tree" "$verb"
    done
}

# ------------------------------------------------------- 1. the alpha.39 tree

note ""
note "=== 1. src/ reverted to $BASE_COMMIT (the delivered alpha.39) ==========="
git -C "$REPO" restore --worktree --source="$BASE_COMMIT" -- src
reverted="$(cd "$REPO" && git diff --name-only -- src | wc -l)"
if [ "$reverted" -gt 0 ]; then
    ok "the revert changed $reverted files under src/ — the old tree is really on disk"
else
    bad "the revert changed NOTHING under src/: either the base commit is wrong or this round touched no source"
fi
if ( cd "$REPO" && find src -name '*.php' -print0 | xargs -0 -n1 "$PHPBIN" -l >/dev/null 2>&1 ); then
    ok "the reverted tree passes php -l, so a fatal below would be a real answer"
else
    bad "the reverted tree does not even lint — every measurement after this is void"
fi

run_all alpha39

# §1 — the unit of the ledger reversal.
expect alpha39 reversal-unit           'sale_units=USD/2'                'the sale was recorded in the order own unit'
expect alpha39 reversal-unit           'reversal_units=IRR/0'            'and undone in a DIFFERENT kind of money'
expect alpha39 reversal-unit-unknown   'refund_ok=yes'                   'a reversal written with no unit to read'
expect alpha39 reversal-unit-unknown   'reversal_lines=4'                'four lines about money nobody can name the unit of'
# §2 — the status closed while the money stays reserved.
expect alpha39 cancel-atomic           'cancel_ok=release_failed'        'the message is true and the state is not repaired'
expect alpha39 cancel-atomic           'status_after=cancelled'          'a CLOSED request'
expect alpha39 cancel-atomic           'claimed_items=1'                 'still holding the money'
expect alpha39 reject-atomic           'reject_ok=release_failed'        'the same shape on the manager side'
expect alpha39 reject-atomic           'status_after=rejected'           'closed'
expect alpha39 reject-atomic           'claimed_items=1'                 'and still holding'
# §3 — conditions the claim never asked about.
expect alpha39 claim-cancelled-item    'interference_fired=yes'          'the interleaving really happened'
expect alpha39 claim-cancelled-item    'request_ok=yes'                  'a reservation over a CANCELLED item'
expect alpha39 claim-cancelled-item    'claimed_items=1'                 'and the item was claimed anyway'
expect alpha39 claim-returned-item     'request_ok=yes'                  'and over an item with a blocking return'
expect alpha39 claim-returned-item     'claimed_items=1'                 'likewise claimed'
# §4 — mixed books, and a failed read read as empty.
expect alpha39 mixed-books             'captured=1'                      'a capture against books holding two units'
expect alpha39 mixed-books             'refused_unit=0'                  'refused nothing'
expect alpha39 unit-read-failure       'captured=1'                      'and a BROKEN unit read'
expect alpha39 unit-read-failure       'refused_unit=0'                  'was indistinguishable from a first sale'
# §5 — the retry that stored a line disagreeing with its ledger.
expect alpha39 changed-line-retry      'retry_captured=1'                'the retry reported a captured sale'
expect alpha39 changed-line-retry      'line_stored=yes'                 'and stored a line'
expect alpha39 changed-line-retry      'stored_base=2000000'             'with the EDITED amount'
expect alpha39 changed-line-retry      'stored_share=900000'             'beside the share of the amount that was recorded'
expect alpha39 figures-not-overwritten 'repair_ok=yes'                   'a repair accepted over recorded figures'
expect alpha39 figures-not-overwritten 'share_after=900000'              'rewriting a share somebody will have to explain'
expect alpha39 no-repointing           'repair_ok=yes'                   'and a line repointed'
expect alpha39 no-repointing           'event_after=order:999999:item:1' 'at a document that is not its own'

# ------------------------------------------------------- 2. back to alpha.40

note ""
note "=== 2. src/ restored to HEAD (alpha.40) ================================"
git -C "$REPO" restore --worktree --source=HEAD -- src
after_restore="$SNAPSHOT/src-mid.sha256"
( cd "$REPO" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum ) > "$after_restore"
if cmp -s "$STATE_FILE" "$after_restore"; then
    ok "src/ is byte-identical to the hashes taken before anything was touched"
else
    bad "src/ is NOT what it was before the revert — every measurement below is void"
fi

run_all alpha40

expect alpha40 reversal-unit           'sale_units=USD/2'                'the sale, unchanged'
expect alpha40 reversal-unit           'reversal_units=USD/2'            'and the reversal in the unit of the sale it undoes'
expect alpha40 reversal-unit-unknown   'refund_ok=refund_unit_unknown'   'refused BY NAME when no unit can be read'
expect alpha40 reversal-unit-unknown   'reversal_lines=0'                'and not one line written'
expect alpha40 refund-arguments        'passed_amount=105.50'            'the amount as a string that carries its own scale'
expect alpha40 cancel-atomic           'cancel_ok=release_failed'        'the caller is still told'
expect alpha40 cancel-atomic           'status_after=requested'          'and the request is NOT closed'
expect alpha40 cancel-atomic           'reserve_lines=1'                 'its reserve lines are intact'
expect alpha40 cancel-atomic           'claimed_items=1'                 'the money is still its own'
expect alpha40 reject-atomic           'reject_ok=release_failed'        'the same on the manager side'
expect alpha40 reject-atomic           'status_after=reviewing'          'the status rolled back'
expect alpha40 reject-atomic           'reserve_lines=1'                 'and nothing is stranded'
expect alpha40 claim-cancelled-item    'interference_fired=yes'          'the interleaving really happened'
expect alpha40 claim-cancelled-item    'request_ok=reservation_lost'     'a condition that changed is refused'
expect alpha40 claim-cancelled-item    'claimed_items=0'                 'nothing was claimed'
expect alpha40 claim-cancelled-item    'open_requests=0'                 'and no half-made request exists'
expect alpha40 claim-returned-item     'request_ok=reservation_lost'     'the same for a blocking return'
expect alpha40 claim-returned-item     'claimed_items=0'                 'nothing claimed'
expect alpha40 claim-returned-item     'open_requests=0'                 'nothing created'
expect alpha40 mixed-books             'captured=0'                      'mixed books stop a new financial record'
expect alpha40 mixed-books             'reasons=books_mixed'             'detectably, with its own name'
expect alpha40 mixed-books             'line_stored=no'                  'and no line is stored against them'
expect alpha40 unit-read-failure       'captured=0'                      'a failed read is not an empty ledger'
expect alpha40 unit-read-failure       'reasons=unit_unreadable'         'and says which it was'
expect alpha40 changed-line-retry      'retry_captured=0'                'an order edited in between is not captured'
expect alpha40 changed-line-retry      'reasons=line_mismatch'           'it is named'
expect alpha40 changed-line-retry      'line_stored=no'                  'and nothing inconsistent is stored'
expect alpha40 figures-not-overwritten 'repair_ok=no'                    'a repair over recorded figures is refused'
expect alpha40 figures-not-overwritten 'share_after=440000'              'the stored figure stays, to be found'
expect alpha40 figures-not-overwritten 'event_after=empty'               'and the link is not written either'
expect alpha40 no-repointing           'repair_ok=no'                    'and no line is repointed'
expect alpha40 no-repointing           'share_after=null'                'with no figures written on the way'

# ---------------------------------------------------------- 3. the controls

note ""
note "=== 3. controls: the same answer on both trees =========================="
control_fail=0
for verb in $CONTROL_VERBS; do
    a="$OUT/alpha39-$verb.txt"
    b="$OUT/alpha40-$verb.txt"
    if [ ! -s "$a" ] || [ ! -s "$b" ]; then
        bad "control $verb is missing an output file on one of the trees"
        control_fail=1
        continue
    fi
    if cmp -s "$a" "$b"; then
        ok "control $verb answers identically on alpha.39 and alpha.40"
    else
        bad "control $verb DIFFERS between the trees — the revert changed behaviour it should not have:"
        diff "$a" "$b" | sed 's/^/        /' | tee -a "$LOG"
        control_fail=1
    fi
done
if [ "$control_fail" -eq 0 ]; then
    ok "every control held, so the defect lines above are about alpha.39 and not about a broken checkout"
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
