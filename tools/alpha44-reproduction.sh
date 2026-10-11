#!/usr/bin/env bash
#
# «ثبت کلید به‌تنهایی نشان نمی‌دهد مدیر آن ویژگی را تغییر نداده است» — on the
# `alpha.43` BYTES and then on the fix, with REAL WooCommerce 11.0.1.
#
# Narrow on purpose. This round changes one rule in one class, so the script
# measures that rule and the things next to it that must not have moved. The
# previous round's own script (`alpha43-reproduction.sh`) is re-run separately
# as the regression; nothing here duplicates it.
#
# Every verb follows one shape, and the shape is the whole argument:
#
#     project  →  the MANAGER edits it in WooCommerce  →  project again
#
# A test that stopped at the first projection would pass on both trees.
#
# Checked three ways, like every reproduction script here: the probe must exit
# 0, it must print `probe=ran` (a verb that printed nothing executed nothing —
# the `alpha.32` trap), and the CONTROLS must answer identically on both
# trees, so a difference cannot be the revert having broken the checkout.
#
# The revert is `git restore --worktree` (never `git checkout <commit> --`,
# which writes the index too — `alpha.39`), verified against a hash list taken
# BEFORE anything was touched (`alpha.32`: not through `git diff`, which
# answers correctly only until the fix is committed), and undone from a `trap`
# with absolute paths (`alpha.37`). The installed plugin is re-synced on every
# switch, or the probe would measure one build while WordPress served another.
#
# NO MIGRATION THIS ROUND. Schema stays 23, so every difference below is
# behaviour, and none of it can be explained away as «the old tree could not
# store it». The record lives in post meta and the meta KEY is unchanged, so
# the two builds read each other's records — which is what makes the legacy
# verbs meaningful rather than hypothetical.
#
# Usage: bash tools/alpha44-reproduction.sh [out-dir]

set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha44-reproduction}"
BASE_COMMIT="${TMC_BASE_COMMIT:-06e63d1}"
PHPBIN="${PHPBIN:-php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
INSTALL="${TMC_INSTALL:-$WP_PATH/wp-content/plugins/tecteb-marketplace-core}"
PROBE="$REPO/tests/Support/alpha43-probe.php"
SNAPSHOT="$(mktemp -d)"
STATE_FILE="$SNAPSHOT/src-before.sha256"

mkdir -p "$OUT"
LOG="$OUT/run.txt"
: > "$LOG"
pass=0
fail=0
note() { printf '%s\n' "$*" | tee -a "$LOG"; }
ok()   { pass=$((pass + 1)); printf 'PASS  %s\n' "$*" | tee -a "$LOG"; }
bad()  { fail=$((fail + 1)); printf 'FAIL  %s\n' "$*" | tee -a "$LOG"; }
WP()   { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }

# ---------------------------------------------------------------- environment

if [ ! -d "$INSTALL" ]; then
    note "alpha44-reproduction: no installed plugin at $INSTALL."; exit 2
fi
if ! WP core version >/dev/null 2>&1; then
    note "alpha44-reproduction: WP-CLI cannot reach the WordPress at $WP_PATH."; exit 2
fi
if ! WP plugin list --status=active --field=name 2>/dev/null | grep -qx woocommerce; then
    note "alpha44-reproduction: WooCommerce is not ACTIVE on the disposable site."
    note "  This script exists to measure real WooCommerce; it will not pretend with a stub."
    exit 2
fi
WC_VERSION="$(WP plugin get woocommerce --field=version 2>/dev/null)"
note "woocommerce: $WC_VERSION on WordPress $(WP core version 2>/dev/null)"
if [ "$WC_VERSION" != "11.0.1" ]; then
    note "alpha44-reproduction: WooCommerce $WC_VERSION is not the target 11.0.1."; exit 2
fi
if ! git -C "$REPO" rev-parse --verify "$BASE_COMMIT" >/dev/null 2>&1; then
    note "alpha44-reproduction: base commit $BASE_COMMIT is not in this clone."; exit 2
fi
if ! git -C "$REPO" diff --quiet -- src || ! git -C "$REPO" diff --cached --quiet -- src; then
    note "alpha44-reproduction: src/ has uncommitted changes. Commit or stash them first."; exit 2
fi

( cd "$REPO" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum ) > "$STATE_FILE"
note "snapshot: $(wc -l < "$STATE_FILE") files under src/ hashed before any change"

sync_install() {
    rsync -a --delete "$REPO/src/" "$INSTALL/src/"
    cp "$REPO/tecteb-marketplace-core.php" "$REPO/uninstall.php" "$INSTALL/"
}

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
    sync_install 2>/dev/null || true
}
trap restore EXIT INT TERM

run_probe() {
    local tree="$1" verb="$2"
    local file="$OUT/$tree-$verb.txt"
    if ! WP eval-file "$PROBE" "$verb" > "$file" 2>"$file.err"; then
        bad "[$tree] probe '$verb' did not run (exit non-zero) — a broken measurement, not a finding"
        sed -n 1,6p "$file.err" | tee -a "$LOG"
        return 1
    fi
    if ! grep -qx 'probe=ran' "$file"; then
        bad "[$tree] probe '$verb' printed no probe=ran — nothing executed"
        sed -n 1,6p "$file.err" | tee -a "$LOG"
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

CONTROLS="control-vendor-change control-retire-untouched control-manager-independent control-not-ours"

# =========================================================================
# PART 1 — the alpha.43 bytes
# =========================================================================
note ""
note "=== the alpha.43 bytes ($BASE_COMMIT), on WooCommerce $WC_VERSION ==="
git -C "$REPO" restore --worktree --source="$BASE_COMMIT" -- src
sync_install
WP eval-file "$PROBE" reset >/dev/null 2>&1 || true

if run_probe old manager-edit; then
    expect old manager-edit 'brand_after_manager=برندی_که_مدیر_نوشته' \
        "the fixture really did change the attribute — without this the next line proves nothing"
    expect old manager-edit 'brand_after_projection=آریا_طب' \
        "DEFECT: an ordinary projection put the plugin's value back over the manager's"
    expect old manager-edit 'conflicts=' 'DEFECT: and said nothing about it'
    expect old manager-edit 'record_shape=list' 'the alpha.43 record is a list of keys, with no values'
fi
if run_probe old manager-edit-then-vendor-change; then
    expect old manager-edit-then-vendor-change 'material_after=نیتریل' \
        "DEFECT: the vendor's value won over an edit the manager had made"
fi
if run_probe old manager-edit-then-retire; then
    expect old manager-edit-then-retire 'size_after_manager=اندازه‌ای_که_مدیر_نوشته' 'the manager edited it'
    expect old manager-edit-then-retire 'size_present=no' \
        'DEFECT: retiring the specification DELETED the row the manager had written'
fi
if run_probe old manager-edit-then-rename; then
    expect old manager-edit-then-rename 'old_label_present=no' \
        'DEFECT: renaming the label deleted the manager-edited row with the old key'
fi
if run_probe old legacy-record; then
    expect old legacy-record 'brand_after_projection=آریا_طب' \
        "DEFECT: a key list was read as a licence over whatever is in the key"
fi
for verb in $CONTROLS; do
    run_probe old "$verb" || true
done

# =========================================================================
# PART 2 — the fix
# =========================================================================
note ""
note "=== the fix (HEAD), on WooCommerce $WC_VERSION ==="
git -C "$REPO" restore --worktree --source=HEAD -- src
sync_install
WP eval-file "$PROBE" reset >/dev/null 2>&1 || true

if run_probe new manager-edit; then
    expect new manager-edit 'record_shape=map' 'the record is key => fingerprint now'
    expect new manager-edit 'brand_after_manager=برندی_که_مدیر_نوشته' 'the manager edited it'
    expect new manager-edit 'brand_after_projection=برندی_که_مدیر_نوشته' \
        "and the projection left it alone"
    expect new manager-edit 'conflicts=%d8%a8%d8%b1%d9%86%d8%af' 'and reported the disagreement'
fi
if run_probe new manager-edit-then-vendor-change; then
    expect new manager-edit-then-vendor-change 'material_after=جنسی_که_مدیر_نوشته' \
        "the vendor's own change is no licence either"
    expect new manager-edit-then-vendor-change 'size_after=7_اینچ' \
        'and the specification beside it is unaffected by the disagreement'
fi
if run_probe new manager-edit-then-retire; then
    expect new manager-edit-then-retire 'size_present=yes' \
        'a retired row the manager edited is not ours to delete'
    expect new manager-edit-then-retire 'size_after_retire=اندازه‌ای_که_مدیر_نوشته' 'with their value'
fi
if run_probe new manager-edit-then-rename; then
    expect new manager-edit-then-rename 'old_label_present=yes' \
        'a renamed label does not delete the manager-edited row'
    expect new manager-edit-then-rename 'old_label_value=جنسی_که_مدیر_نوشته' 'with their value'
    expect new manager-edit-then-rename 'new_label_value=لاتکس' 'and the new label is written'
fi
if run_probe new legacy-record; then
    expect new legacy-record 'brand_after_projection=برندی_که_مدیر_نوشته' \
        'an alpha.43 key list says nothing about what is in the key'
    expect new legacy-record 'conflicts=%d8%a8%d8%b1%d9%86%d8%af' 'and the key is reported'
fi
if run_probe new legacy-record-upgrades; then
    expect new legacy-record-upgrades 'shape_before=list' 'the alpha.43 shape'
    expect new legacy-record-upgrades 'shape_after=map' \
        'a product nobody touched upgrades its own record on the first projection'
    expect new legacy-record-upgrades 'conflicts_after_upgrade=' 'with nothing held'
    expect new legacy-record-upgrades 'material_after_vendor_edit=نیتریل' \
        "and from then on the vendor's edit lands normally"
fi
if run_probe new manager-deleted-ours; then
    expect new manager-deleted-ours 'size_after_delete=absent' 'the manager deleted one of ours'
    expect new manager-deleted-ours 'size_after_projection=present' \
        'DECISION, not a surprise: an absent attribute has no value to preserve, so it is written again'
fi

# §4 — the variable product, both directions.
if run_probe new variable-manager-edit; then
    expect new variable-manager-edit 'colour_after_manager=آبی/زرد' 'the manager edited the axis'
    expect new variable-manager-edit 'colour_after_projection=آبی/زرد' 'and the projection kept it'
    expect new variable-manager-edit 'theirs_after=ایران' "the manager's own attribute survives"
    expect new variable-manager-edit 'variation_blue_same=yes' 'the variation keeps its id'
    expect new variable-manager-edit 'blue_price=210000' 'its price'
    expect new variable-manager-edit 'blue_stock=3' 'its stock'
    expect new variable-manager-edit 'blue_sku=V44-BLUE' 'and its SKU'
    expect new variable-manager-edit 'conflicts=%d8%b1%d9%86%da%af' 'with the axis reported'
fi
if run_probe new variable-vendor-change; then
    expect new variable-vendor-change 'colour_after_projection=آبی/قرمز' \
        "CONTROL: with nobody interfering the vendor's new axis is written"
    expect new variable-vendor-change 'theirs_after=ایران' "and the manager's own attribute survives"
    expect new variable-vendor-change 'variation_blue_same=yes' 'the variation keeps its id'
    expect new variable-vendor-change 'blue_sku=V44-BLUE' 'and its SKU'
    expect new variable-vendor-change 'conflicts=' 'with nothing held'
fi
for verb in $CONTROLS; do
    run_probe new "$verb" || true
done

# =========================================================================
# PART 3 — the controls must answer the same on both trees
# =========================================================================
note ""
note "=== controls: the same answer on both trees ==="
for verb in $CONTROLS; do
    if [ ! -s "$OUT/old-$verb.txt" ] || [ ! -s "$OUT/new-$verb.txt" ]; then
        bad "control $verb did not produce output on both trees"
        continue
    fi
    # `wc` and the product id move with the fixture, so they are not part of
    # the comparison; everything the control is about is.
    if diff <(grep -vE '^(wc|product|wc_variation_blue)=' "$OUT/old-$verb.txt") \
            <(grep -vE '^(wc|product|wc_variation_blue)=' "$OUT/new-$verb.txt") >/dev/null; then
        ok "control $verb answers identically on alpha.43 and alpha.44"
    else
        bad "control $verb CHANGED — the revert broke the tree, or this round changed something it should not:"
        diff "$OUT/old-$verb.txt" "$OUT/new-$verb.txt" | sed 's/^/        /' | tee -a "$LOG"
    fi
done

WP eval-file "$PROBE" reset >/dev/null 2>&1 || true
note ""
note "checks: $((pass + fail))   pass: $pass   fail: $fail"
[ "$fail" -eq 0 ]
