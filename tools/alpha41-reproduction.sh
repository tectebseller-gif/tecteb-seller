#!/usr/bin/env bash
#
# Every named defect of this round, measured on the `alpha.40` BYTES and then
# on the fix — with controls that must answer identically on both.
#
# ### How this avoids the `alpha.32` trap
#
# A reproduction script that reverts code and then runs the NEW round's tests
# proves nothing: the tests name classes the old tree does not have, PHP fatals
# at load, zero tests execute, and every «reproduced» line is a report about
# nothing having run. So the measurement is a probe
# (`tests/Support/alpha40-probe.php`) that asks only questions both builds can
# answer, and every run is checked three ways:
#
#   1. the probe must exit 0;
#   2. its output must contain `probe=ran` — a verb that printed nothing did
#      not execute;
#   3. the CONTROLS must give the same answers on both trees. A control that
#      changes means the revert broke the tree, so the «defect» lines are
#      about a broken checkout rather than about `alpha.40`.
#
# ### How the revert is done and undone
#
# The whole of `src/` goes back to the delivered `alpha.40` commit, so the old
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
# ### What the `alpha.40` tree does NOT have, and what that means here
#
# Schema 23 arrives with this round, so on the reverted tree
# `Bootstrap::migrations()` stops at 22 and `tmc_products` has neither
# `description` nor `create_token`. That is not a limitation of the
# measurement — it IS the state §2 and §4 are about, and the probe asks for
# the field by `property_exists()` rather than naming it.
#
# ### What this script does NOT measure
#
# §3 (the false «identical» claim) is a rendering question: the review card's
# `render()` is the only place the sentence exists, and on the reverted tree
# the interface it reads has no `comparisonState()` at all — so the honest
# measurement is the one in `ProductReviewCardTest`, which falsifies against
# the same bytes through the contract suite. §5's theme question cannot be
# measured anywhere in this environment: WooCommerce is not installable and the
# owner's theme is not here. What IS measured for §5 is which source names the
# shop, which is a source-level fact.
#
# Usage: bash tools/alpha41-reproduction.sh [out-dir]
# Requires: .env.testing pointing at the disposable tmc_test database.

set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha41-reproduction}"
BASE_COMMIT="${TMC_BASE_COMMIT:-3aa4677}"
PHPBIN="${PHPBIN:-php}"
PROBE="$REPO/tests/Support/alpha40-probe.php"
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
    note "alpha41-reproduction: .env.testing is missing; the disposable database is required."
    exit 2
fi
set -a
# shellcheck disable=SC1091
. "$REPO/.env.testing"
set +a
case "${TMC_TEST_DB_DSN:-}" in
    *tmc_test*) ;;
    *) note "alpha41-reproduction: refuses any DSN that is not the disposable tmc_test."; exit 2 ;;
esac

if ! git -C "$REPO" rev-parse --verify "$BASE_COMMIT" >/dev/null 2>&1; then
    note "alpha41-reproduction: base commit $BASE_COMMIT is not in this clone."
    exit 2
fi
if ! git -C "$REPO" restore --help >/dev/null 2>&1; then
    note "alpha41-reproduction: this git has no 'restore'; it is required so the index is never written."
    exit 2
fi
if ! git -C "$REPO" diff --quiet -- src || ! git -C "$REPO" diff --cached --quiet -- src; then
    note "alpha41-reproduction: src/ has uncommitted changes. Commit or stash them first:"
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

DEFECT_VERBS="tab-legacy-input tab-named tab-unknown create-replay half-written-draft two-descriptions csv-markup csv-legacy-file shop-name"
CONTROL_VERBS="control-rename-is-not-a-save control-another-shop-is-refused control-two-names-are-two-products control-duplicate-sku-is-refused control-csv-never-publishes"

run_all() {
    local tree="$1"
    local verb
    for verb in $DEFECT_VERBS $CONTROL_VERBS; do
        run_probe "$tree" "$verb"
    done
}

# ------------------------------------------------------- 1. the alpha.40 tree

note ""
note "=== 1. src/ reverted to $BASE_COMMIT (the delivered alpha.40) ==========="
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

run_all alpha40

# §1 — one tab's save took the whole form with it.
expect alpha40 tab-legacy-input   'save_code=store_saved'   'the save the vendor route posted for ONE tab succeeded'
expect alpha40 tab-legacy-input   'city_after=empty'        'and emptied the city nobody edited'
expect alpha40 tab-legacy-input   'intro_after=empty'       'and the introduction with it'
expect alpha40 tab-legacy-input   'days_after=9'            'the field that WAS edited landing correctly, which is why it looked like a save'
expect alpha40 tab-named          'save_code=store_saved'   'the honest per-tab post also succeeded'
expect alpha40 tab-named          'carriers_after=tipax'    'its own field written'
expect alpha40 tab-named          'social_after=empty'      'and the social links — whose ABSENCE is their answer — emptied'
expect alpha40 tab-unknown        'save_code=store_saved'   'a tab the page does not have was accepted'
expect alpha40 tab-unknown        'city_after=شیراز'        'and wrote a field that form never showed'
# §2 — a replayed create made a second product.
expect alpha40 create-replay      'products_after=2'        'one form posted twice made two products — the SKU is on step 2, so a step-1 post has no identity to be unique about'
expect alpha40 create-replay      'second_replayed=no'      'with nothing saying the second was a replay'
expect alpha40 half-written-draft 'code=product_created'    'a create whose gallery was refused reported plain success'
# §4 — there was no second description field.
expect alpha40 two-descriptions   'field_exists=no'         'the vendor had no long-description field at all'
expect alpha40 two-descriptions   'stored_long=no-field'    'so nothing of theirs could be stored'
expect alpha40 csv-markup         'created=1'               'a CSV row was imported'
expect alpha40 csv-legacy-file    'before=no-field'         'and an old file had nothing to preserve'
# §5 — the shop box named the person, not the shop.
expect alpha40 shop-name          'shop_name=رضا الف'       'the WordPress account name, beside a shop called «داروخانهٔ probe»'
expect alpha40 shop-name          'reads_account_name=yes'  'because the method never asked the store settings row'

# ------------------------------------------------------- 2. back to alpha.41

note ""
note "=== 2. src/ restored to HEAD (alpha.41) ================================"
git -C "$REPO" restore --worktree --source=HEAD -- src
after_restore="$SNAPSHOT/src-mid.sha256"
( cd "$REPO" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum ) > "$after_restore"
if cmp -s "$STATE_FILE" "$after_restore"; then
    ok "src/ is byte-identical to the hashes taken before anything was touched"
else
    bad "src/ is NOT what it was before the revert — every measurement below is void"
fi

run_all alpha41

expect alpha41 tab-legacy-input   'save_code=bad_tab'         'a save that names no tab is refused rather than guessed at'
expect alpha41 tab-legacy-input   'city_after=تهران'          'and nothing is written'
expect alpha41 tab-legacy-input   'days_after=4'              'not even the field it did fill'
expect alpha41 tab-named          'save_code=store_saved'     'the per-tab save succeeds'
expect alpha41 tab-named          'days_after=9'              'its own fields written'
expect alpha41 tab-named          'carriers_after=tipax'      'including the one whose absence is an answer, ON its tab'
expect alpha41 tab-named          'social_after=kept'         'while another tab the same field class belongs to is untouched'
expect alpha41 tab-named          'city_after=تهران'          'and the general tab stands'
expect alpha41 tab-unknown        'save_code=bad_tab'         'an unknown tab is refused by name'
expect alpha41 tab-unknown        'city_after=تهران'          'and injects nothing'
expect alpha41 create-replay      'products_after=1'          'one form makes one product however many times it is posted'
expect alpha41 create-replay      'second_replayed=yes'       'and the replay says so rather than claiming a new product'
expect alpha41 half-written-draft 'code=product_created_incomplete' 'a half-written draft is not announced as a success'
expect alpha41 half-written-draft 'names_draft=yes'           'and the draft it DID make is named, so the vendor can carry on from it'
expect alpha41 two-descriptions   'field_exists=yes'          'the long description is a field of its own'
expect alpha41 two-descriptions   'stored_long=<p>متن کامل</p>' 'and what the vendor wrote is what is stored'
expect alpha41 csv-markup         'has_script=no'             'a CSV row cannot get past the filter the form applies'
expect alpha41 csv-markup         'keeps_markup=yes'          'while the markup the field exists for survives'
expect alpha41 csv-legacy-file    'before=<p>متن کامل</p>'    'a product with a long description'
expect alpha41 csv-legacy-file    'after=<p>متن کامل</p>'     'keeps it when an old file with no such column is imported'
expect alpha41 shop-name          'shop_name=داروخانهٔ probe'  'the shop is named by its shop name'
expect alpha41 shop-name          'reads_account_name=no'     'read from the settings row rather than from the account'

# ---------------------------------------------------------- 3. the controls

note ""
note "=== 3. controls: the same answer on both trees =========================="
control_fail=0
for verb in $CONTROL_VERBS; do
    a="$OUT/alpha40-$verb.txt"
    b="$OUT/alpha41-$verb.txt"
    if [ ! -s "$a" ] || [ ! -s "$b" ]; then
        bad "control $verb is missing an output file on one of the trees"
        control_fail=1
        continue
    fi
    if cmp -s "$a" "$b"; then
        ok "control $verb answers identically on alpha.40 and alpha.41"
    else
        bad "control $verb DIFFERS between the trees — the revert changed behaviour it should not have:"
        diff "$a" "$b" | sed 's/^/        /' | tee -a "$LOG"
        control_fail=1
    fi
done
if [ "$control_fail" -eq 0 ]; then
    ok "every control held, so the defect lines above are about alpha.40 and not about a broken checkout"
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
