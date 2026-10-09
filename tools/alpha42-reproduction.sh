#!/usr/bin/env bash
#
# Every named defect of this round, measured on the `alpha.41` BYTES and then
# on the fix — with controls that must answer identically on both.
#
# ### How this avoids the `alpha.32` trap
#
# A reproduction script that reverts code and then runs the NEW round's tests
# proves nothing: the tests name classes the old tree does not have, PHP fatals
# at load, zero tests execute, and every «reproduced» line is a report about
# nothing having run. So the measurement is a probe
# (`tests/Support/alpha41-probe.php`) that asks only questions both builds can
# answer, and every run is checked three ways:
#
#   1. the probe must exit 0;
#   2. its output must contain `probe=ran` — a verb that printed nothing did
#      not execute;
#   3. the CONTROLS must give the same answers on both trees. A control that
#      changes means the revert broke the tree, so the «defect» lines are
#      about a broken checkout rather than about `alpha.41`.
#
# ### How the revert is done and undone
#
# The whole of `src/` goes back to the delivered `alpha.41` commit, so the old
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
# ### Both trees have the same tables
#
# THIS ROUND ADDS NO MIGRATION. Schema stays 23, so the reverted tree builds
# exactly the same tables and every difference measured below is behaviour
# rather than schema — which also means a difference cannot be explained away
# as «the old tree could not store it».
#
# ### What this script measures structurally, and why
#
# §4 and §5 are measured by the SHAPE of the code, not by running it, and that
# is stated rather than dressed up. Driving `project()` needs WooCommerce
# product classes that this round's own stubs introduce, so they do not exist
# on the reverted tree at all — a probe that tried would be reporting that its
# fixtures are missing. The behaviour is measured by `ProjectedFactsTest` and
# `StorePublicSurfaceTest`, which falsify against the same bytes through the
# contract suite; here the question is only whether the facts block and the
# once-per-request guard EXIST.
#
# Real WooCommerce and the owner's theme remain `Not Run` throughout.
#
# Usage: bash tools/alpha42-reproduction.sh [out-dir]
# Requires: .env.testing pointing at the disposable tmc_test database.

set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha42-reproduction}"
BASE_COMMIT="${TMC_BASE_COMMIT:-457ea88}"
PHPBIN="${PHPBIN:-php}"
PROBE="$REPO/tests/Support/alpha41-probe.php"
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
    note "alpha42-reproduction: .env.testing is missing; the disposable database is required."
    exit 2
fi
set -a
# shellcheck disable=SC1091
. "$REPO/.env.testing"
set +a
case "${TMC_TEST_DB_DSN:-}" in
    *tmc_test*) ;;
    *) note "alpha42-reproduction: refuses any DSN that is not the disposable tmc_test."; exit 2 ;;
esac

if ! git -C "$REPO" rev-parse --verify "$BASE_COMMIT" >/dev/null 2>&1; then
    note "alpha42-reproduction: base commit $BASE_COMMIT is not in this clone."
    exit 2
fi
if ! git -C "$REPO" restore --help >/dev/null 2>&1; then
    note "alpha42-reproduction: this git has no 'restore'; it is required so the index is never written."
    exit 2
fi
if ! git -C "$REPO" diff --quiet -- src || ! git -C "$REPO" diff --cached --quiet -- src; then
    note "alpha42-reproduction: src/ has uncommitted changes. Commit or stash them first:"
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

DEFECT_VERBS="legacy-title-save legacy-explicit-clear create-redirect replay-after-failure simultaneous-overwrite facts-block one-box"
CONTROL_VERBS="control-replay-after-edit control-another-shop-is-refused control-stale-revision-is-refused control-two-names-are-two-products control-duplicate-sku-is-refused control-csv-never-publishes"

run_all() {
    local tree="$1"
    local verb
    for verb in $DEFECT_VERBS $CONTROL_VERBS; do
        run_probe "$tree" "$verb"
    done
}

# ------------------------------------------------------- 1. the alpha.41 tree

note ""
note "=== 1. src/ reverted to $BASE_COMMIT (the delivered alpha.41) ==========="
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

run_all alpha41

# §1 — opening the form and saving the title asked for the description to go.
expect alpha41 legacy-title-save      'before=null'                  'a product from before the field, as the owner has on staging'
expect alpha41 legacy-title-save      'code=product_saved'           'the save succeeded'
expect alpha41 legacy-title-save      'title_after=عنوان تازه'       'and the title landed, which is why it looked right'
expect alpha41 legacy-title-save      'after='                       'while the long description became an EMPTY STRING — «delete the shop text»'
expect alpha41 legacy-explicit-clear  'after='                       'the explicit clear reached the same place, so the two were indistinguishable'
# §2 — the create went back to the step it came from.
expect alpha41 create-redirect        'code=product_created'         'the create itself always worked'
expect alpha41 create-redirect        'lands_on_step=1'              'and «ذخیره و ادامه» sent the vendor back to «معرفی»'
# §3 — a replay claimed a create, and the losing half of two could overwrite.
#
# The SEQUENTIAL replay is a control, not a defect, and it got that label by
# failing here as a defect line: `alpha.41` short-circuited it before any
# write, so both trees answer the same. The overwrite needed both requests to
# get past that read.
expect alpha41 replay-after-failure   'first_code=product_created_incomplete' 'a create whose gallery failed says so'
expect alpha41 replay-after-failure   'replay_code=product_created'  'and its replay reported a plain, complete create'
expect alpha41 replay-after-failure   'claims_created=yes'           'about a product that is half written'
expect alpha41 simultaneous-overwrite 'interference_fired=yes'       'the interleaving really happened'
expect alpha41 simultaneous-overwrite 'loser_code=product_created'   'the loser reported a create it had not made'
expect alpha41 simultaneous-overwrite 'gallery=4303'                 "and wrote its own gallery over the winner's row"
# §4 and §5 — structural: neither existed.
expect alpha41 facts-block            'has_facts_block=no'           'nothing put the brand and the specification on the product page'
expect alpha41 facts-block            'reads_templates=no'           'the projector had stopped reading the template at all'
expect alpha41 one-box                'has_once_guard=no'            'the hook and the shortcode could both print'

# ------------------------------------------------------- 2. back to alpha.42

note ""
note "=== 2. src/ restored to HEAD (alpha.42) ================================"
git -C "$REPO" restore --worktree --source=HEAD -- src
after_restore="$SNAPSHOT/src-mid.sha256"
( cd "$REPO" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum ) > "$after_restore"
if cmp -s "$STATE_FILE" "$after_restore"; then
    ok "src/ is byte-identical to the hashes taken before anything was touched"
else
    bad "src/ is NOT what it was before the revert — every measurement below is void"
fi

run_all alpha42

expect alpha42 legacy-title-save      'code=product_saved'           'the save still succeeds'
expect alpha42 legacy-title-save      'title_after=عنوان تازه'       'the title still lands'
expect alpha42 legacy-title-save      'after=null'                   'and the long description is untouched: nobody asked for anything'
expect alpha42 legacy-explicit-clear  'code=product_saved'           'and the explicit clear is a different submission'
expect alpha42 legacy-explicit-clear  'after='                       'which really clears — the half that makes the half above mean something'
expect alpha42 create-redirect        'code=product_created'         'the create succeeds'
expect alpha42 create-redirect        'lands_on_step=2'              'and «ذخیره و ادامه» continues'
expect alpha42 replay-after-failure   'replay_code=product_created_replayed' 'a replay has a code of its own'
expect alpha42 replay-after-failure   'claims_created=no'            'and never claims a create it did not make'
expect alpha42 simultaneous-overwrite 'interference_fired=yes'       'the same interleaving'
expect alpha42 simultaneous-overwrite 'loser_code=product_created_replayed' 'the loser says it created nothing'
expect alpha42 simultaneous-overwrite 'gallery=4301'                 "and the winner's gallery stands untouched"
expect alpha42 facts-block            'has_facts_block=yes'          'the brand and the specification have a block of their own'
expect alpha42 facts-block            'merges_attributes=yes'        "merged, so a manager's own attribute survives"
expect alpha42 facts-block            'reads_templates=yes'          'and the labels and units come from the template again'
expect alpha42 one-box                'has_once_guard=yes'           'one block, however many placements ask for it'

# ---------------------------------------------------------- 3. the controls

note ""
note "=== 3. controls: the same answer on both trees =========================="
control_fail=0
for verb in $CONTROL_VERBS; do
    a="$OUT/alpha41-$verb.txt"
    b="$OUT/alpha42-$verb.txt"
    if [ ! -s "$a" ] || [ ! -s "$b" ]; then
        bad "control $verb is missing an output file on one of the trees"
        control_fail=1
        continue
    fi
    if cmp -s "$a" "$b"; then
        ok "control $verb answers identically on alpha.41 and alpha.42"
    else
        bad "control $verb DIFFERS between the trees — the revert changed behaviour it should not have:"
        diff "$a" "$b" | sed 's/^/        /' | tee -a "$LOG"
        control_fail=1
    fi
done
if [ "$control_fail" -eq 0 ]; then
    ok "every control held, so the defect lines above are about alpha.41 and not about a broken checkout"
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
