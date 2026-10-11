#!/usr/bin/env bash
#
# Every named defect of this round, measured on the `alpha.42` BYTES and then
# on the fix — ON A REAL WooCommerce.
#
# ### What is new about this script
#
# Forty rounds of this repository reported WooCommerce as `Not Run`, because
# wordpress.org answers 403 from this environment. This round the BUILT plugin
# was obtained from the project's own GitHub release
# (`woocommerce/woocommerce` tag `11.0.1`, `woocommerce.zip`, sha256 recorded
# in the delivery note) and installed on the disposable WordPress. So the
# numbers below come from `WC_Product`, its data store and its post meta.
#
# That matters more here than in any previous round, because §1's defect IS
# WooCommerce's behaviour:
#
#     $attributes[ sanitize_title( $attribute->get_name() ) ] = $attribute;
#       — woocommerce 11.0.1, includes/abstracts/abstract-wc-product.php:1231
#
# A stub that stored the array it was handed cannot disagree with the plugin,
# so the test confirmed the plugin's own assumption. Here the keys come back
# from the shop.
#
# ### How this avoids the `alpha.32` trap
#
# The measurement is a probe (`tests/Support/alpha42-probe.php`) that asks
# only questions BOTH builds can answer, and every run is checked three ways:
# the probe must exit 0, it must print `probe=ran`, and the CONTROLS must give
# the same answer on both trees. A control that moves means the revert broke
# the tree, so the «defect» lines would be about a broken checkout.
#
# ### How the revert is done and undone
#
# The whole of `src/` goes back to the delivered `alpha.42` commit, so the old
# tree is COHERENT, and the installed plugin is re-synced from it each time —
# otherwise the probe would measure one build while WordPress served another.
# The restore is checked against a hash list taken BEFORE anything was
# touched (`alpha.32`'s rule: not through `git diff`, which answers correctly
# right up until the fix is committed), runs from a `trap` with absolute
# paths, and uses `git restore --worktree` rather than
# `git checkout <commit> -- src`, which writes the index too (`alpha.39`).
#
# ### Both trees have the same tables
#
# THIS ROUND ADDS NO MIGRATION. Schema stays 23, so every difference below is
# behaviour rather than schema, and none of it can be explained away as «the
# old tree could not store it».
#
# Usage: bash tools/alpha43-reproduction.sh [out-dir]
# Requires: .env.testing, a disposable WordPress with real WooCommerce, WP-CLI.

set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha43-reproduction}"
BASE_COMMIT="${TMC_BASE_COMMIT:-3070781}"
PHPBIN="${PHPBIN:-php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
INSTALL="${TMC_INSTALL:-$WP_PATH/wp-content/plugins/tecteb-marketplace-core}"
PROBE="$REPO/tests/Support/alpha42-probe.php"
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

WP() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }

# ---------------------------------------------------------------- environment

if [ ! -f "$REPO/.env.testing" ]; then
    note "alpha43-reproduction: .env.testing is missing; the disposable database is required."
    exit 2
fi
set -a
# shellcheck disable=SC1091
. "$REPO/.env.testing"
set +a
case "${TMC_TEST_DB_DSN:-}" in
    *tmc_test*) ;;
    *) note "alpha43-reproduction: refuses any DSN that is not the disposable tmc_test."; exit 2 ;;
esac
if [ ! -d "$INSTALL" ]; then
    note "alpha43-reproduction: no installed plugin at $INSTALL."
    exit 2
fi
if ! WP core version >/dev/null 2>&1; then
    note "alpha43-reproduction: WP-CLI cannot reach the WordPress at $WP_PATH."
    exit 2
fi
if ! WP plugin list --status=active --field=name 2>/dev/null | grep -qx woocommerce; then
    note "alpha43-reproduction: WooCommerce is not ACTIVE on the disposable site."
    note "  This script exists to measure real WooCommerce; it will not pretend with a stub."
    exit 2
fi
WC_VERSION="$(WP plugin get woocommerce --field=version 2>/dev/null)"
note "woocommerce: $WC_VERSION on WordPress $(WP core version 2>/dev/null)"
if [ "$WC_VERSION" != "11.0.1" ]; then
    note "alpha43-reproduction: WooCommerce $WC_VERSION is not the target 11.0.1."
    note "  A document about another version is not evidence about the target one."
    exit 2
fi
if ! git -C "$REPO" rev-parse --verify "$BASE_COMMIT" >/dev/null 2>&1; then
    note "alpha43-reproduction: base commit $BASE_COMMIT is not in this clone."
    exit 2
fi
if ! git -C "$REPO" diff --quiet -- src || ! git -C "$REPO" diff --cached --quiet -- src; then
    note "alpha43-reproduction: src/ has uncommitted changes. Commit or stash them first."
    exit 2
fi

# ------------------------------------------------- snapshot, and restore trap

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

# ------------------------------------------------------------------ the probe

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

reset_fixture() { WP eval-file "$PROBE" reset >/dev/null 2>&1 || true; }

# =========================================================================
# PART 1 — the alpha.42 bytes
# =========================================================================
note ""
note "=== the alpha.42 bytes ($BASE_COMMIT), on WooCommerce $WC_VERSION ==="
git -C "$REPO" restore --worktree --source="$BASE_COMMIT" -- src
sync_install
reset_fixture

# §1 — the key the plugin writes is not the key it looks for.
if run_probe old attr-keys; then
    expect old attr-keys 'prefixed_keys=0' \
        'WooCommerce keeps NO key the plugin passed in — the `tmc-` scheme has no input on which it works'
    expect old attr-keys 'brand_key_is_sanitized_name=yes' \
        'the key is sanitize_title() of the display name, measured not argued'
fi
# §1 (b) a retired specification cannot be dropped, because it cannot be found.
if run_probe old attr-retire; then
    expect old attr-retire 'size_before=present' 'the fixture really had the attribute'
    expect old attr-retire 'size_after=present' \
        'DEFECT: a retired specification stays on the product for ever'
fi
# §1 (c) a renamed label leaves the old attribute behind.
if run_probe old attr-rename; then
    expect old attr-rename 'old_label_key=present' \
        'DEFECT: the old label is orphaned next to the new one'
    expect old attr-rename 'new_label_key=present' 'and the new one arrives, so the product shows both'
fi
# §1 (e) and a manager's own attribute under our name is overwritten.
if run_probe old attr-collision; then
    expect old attr-collision 'brand_value=آریا_طب' \
        "DEFECT: the manager's own value is replaced by ours"
fi
# §2 — `$kept + $ours` lets the stored attribute beat the new one.
if run_probe old variable-merge; then
    expect old variable-merge 'colour_before=آبی/سبز' 'the axis the vendor first offered'
    expect old variable-merge 'colour_after=آبی/سبز' \
        'DEFECT: the vendor added and changed options and the shop kept the old set'
fi
if run_probe old variable-remove; then
    expect old variable-remove 'colour_after=present' \
        'DEFECT: an axis the vendor removed survives'
fi
# Controls on the old tree.
if run_probe old attr-manager; then
    expect old attr-manager 'theirs_after=present' "CONTROL: the manager's own attribute survives on both trees"
fi
if run_probe old control-not-ours; then
    expect old control-not-ours 'foreign_brand=برند_فروشگاه' 'CONTROL: a product that is not ours is untouched'
fi
if run_probe old control-description; then
    expect old control-description 'description_has_brand=no' "CONTROL: the facts are not glued into the vendor's text"
fi

# §4 — the stub's oracle, on the old bytes.
old_stub="$("$PHPBIN" -r 'require "'"$REPO"'/tools/wp-stubs/load.php"; echo sanitize_title("برند");' 2>/dev/null)"
real_stub="$(WP eval 'echo sanitize_title("برند");' 2>/dev/null)"
note "stub sanitize_title('برند') on this tree: $old_stub"
note "real WordPress  sanitize_title('برند'): $real_stub"

# §3 — the scheduled interleaving, against the old ProductArea.
note ""
note "--- §3: the loser of two simultaneous creates, on the alpha.42 bytes ---"
if ( cd "$REPO" && vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
        --filter SimultaneousCreateMediaTest ) > "$OUT/old-simultaneous.txt" 2>&1; then
    bad '[old] SimultaneousCreateMediaTest PASSED on the alpha.42 bytes — it is not measuring the defect'
else
    if grep -q 'orphan\|discarded\|Failures: ' "$OUT/old-simultaneous.txt"; then
        ok '[old] SimultaneousCreateMediaTest fails on the alpha.42 bytes — the orphan is real'
    else
        bad '[old] the test did not run at all on the old bytes (a broken measurement)'
        sed -n 1,12p "$OUT/old-simultaneous.txt" | tee -a "$LOG"
    fi
fi

# =========================================================================
# PART 2 — the fix
# =========================================================================
note ""
note "=== the fix (HEAD), on WooCommerce $WC_VERSION ==="
git -C "$REPO" restore --worktree --source=HEAD -- src
sync_install
reset_fixture

if run_probe new attr-keys; then
    expect new attr-keys 'prefixed_keys=0' \
        'unchanged, because this is WooCommerce behaviour — the fix stops DEPENDING on it'
    expect new attr-keys 'brand_key_is_sanitized_name=yes' 'and the plugin now computes that same key'
fi
if run_probe new attr-change; then
    expect new attr-change 'material_after=نیتریل' '(a) a changed answer is written'
fi
if run_probe new attr-retire; then
    expect new attr-retire 'size_after=absent' '(b) a retired specification is removed'
    expect new attr-retire 'material_after=present' 'and the one still asked for stays'
fi
if run_probe new attr-rename; then
    expect new attr-rename 'old_label_key=absent' '(c) a renamed label leaves no orphan'
    expect new attr-rename 'new_label_key=present' 'and the new label is there'
fi
if run_probe new attr-manager; then
    expect new attr-manager 'theirs_after=present' "(d) the manager's own attribute survives"
    expect new attr-manager 'theirs_value=ایران' 'with its own value'
fi
if run_probe new attr-collision; then
    expect new attr-collision 'brand_value=چیزی_که_مدیر_نوشته' \
        "(e) a name collision leaves the manager's value alone"
    expect new attr-collision 'material_still_there=yes' 'and one collision does not stop the rest of the block'
fi
if run_probe new attr-repeat; then
    expect new attr-repeat 'same_keys=yes' '(f) a second projection adds nothing'
fi
if run_probe new variable-merge; then
    expect new variable-merge 'colour_after=آبی/زرد/قرمز' '§2: the new option set wins'
    expect new variable-merge 'theirs_after=present' "and the manager's attribute comes through"
    expect new variable-merge 'variation_blue_same=yes' 'the existing variation keeps its id'
    expect new variable-merge 'blue_price=210000' 'its price'
    expect new variable-merge 'blue_stock=3' 'its stock'
    expect new variable-merge 'blue_sku=V-BLUE' 'and its SKU'
fi
if run_probe new variable-remove; then
    expect new variable-remove 'colour_after=absent' '§2: a removed axis goes away'
    expect new variable-remove 'theirs_after=present' "and the manager's attribute still does not"
fi
if run_probe new control-not-ours; then
    expect new control-not-ours 'foreign_brand=برند_فروشگاه' 'CONTROL: still untouched'
    expect new control-not-ours 'foreign_owned_meta=' 'and no ownership record is written onto it'
fi
if run_probe new control-description; then
    expect new control-description 'description_has_brand=no' "CONTROL: still not glued into the vendor's text"
fi

new_stub="$("$PHPBIN" -r 'require "'"$REPO"'/tools/wp-stubs/load.php"; echo sanitize_title("برند");' 2>/dev/null)"
if [ "$new_stub" = "$real_stub" ] && [ -n "$real_stub" ]; then
    ok "§4: the stub's sanitize_title now answers what WordPress answers ($new_stub)"
else
    bad "§4: the stub answers '$new_stub' and WordPress answers '$real_stub'"
fi
if [ "$old_stub" != "$real_stub" ]; then
    ok "§4: and on the alpha.42 bytes it did not ('$old_stub' vs '$real_stub') — the oracle was wrong"
else
    bad "§4: the old stub already matched, so it was not the reason the defect was invisible"
fi

note ""
note "--- §3: the same test on the fix ---"
if ( cd "$REPO" && vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
        --filter SimultaneousCreateMediaTest ) > "$OUT/new-simultaneous.txt" 2>&1; then
    ok '[new] SimultaneousCreateMediaTest passes: the loser discards its own upload and only its own'
else
    bad '[new] SimultaneousCreateMediaTest still fails on the fix'
    sed -n 1,20p "$OUT/new-simultaneous.txt" | tee -a "$LOG"
fi

# A REAL two-process race, reported for what it is.
note ""
note "--- §3: a real two-process race (reported, not claimed) ---"
WP eval-file "$PROBE" race-reset >/dev/null 2>&1 || true
hit=0
for i in 1 2 3 4 5 6; do
    tok="race-$(date +%s%N)-$i"
    ( WP eval-file "$PROBE" simultaneous-media "$tok" > "$OUT/race-$i-a.txt" 2>&1 & \
      WP eval-file "$PROBE" simultaneous-media "$tok" > "$OUT/race-$i-b.txt" 2>&1 & wait )
    pa="$(grep -m1 '^path=' "$OUT/race-$i-a.txt" | cut -d= -f2)"
    pb="$(grep -m1 '^path=' "$OUT/race-$i-b.txt" | cut -d= -f2)"
    note "  run $i: $pa / $pb"
    case "$pa$pb" in *simultaneous_lost_after_upload*) hit=$((hit + 1)) ;; esac
done
WP eval-file "$PROBE" race-report > "$OUT/race-report.txt" 2>&1
if grep -qx 'orphan_count=0' "$OUT/race-report.txt"; then
    ok 'six real two-process races left ZERO attachment that nothing uses'
else
    bad 'a real race left an attachment nothing uses:'
    sed 's/^/        /' "$OUT/race-report.txt" | tee -a "$LOG"
fi
if grep -qx 'product_count=6' "$OUT/race-report.txt"; then
    ok 'and exactly one product per token, six tokens, six products'
else
    bad 'the token did not give exactly one product per race:'
    sed 's/^/        /' "$OUT/race-report.txt" | tee -a "$LOG"
fi
note "  simultaneous window entered on $hit of 6 real races."
note "  STATED PLAINLY: two WP-CLI processes almost always serialise — the second"
note "  one's token read happens after the first one's insert, which is the"
note "  SEQUENTIAL replay path and uploads nothing. The simultaneous window is"
note "  demonstrated by the scheduled interleaving above, which is labelled as"
note "  such; this loop proves the property holds under a real race, not that"
note "  the race reached the window."
WP eval-file "$PROBE" race-reset >/dev/null 2>&1 || true
reset_fixture

note ""
note "checks: $((pass + fail))   pass: $pass   fail: $fail"
[ "$fail" -eq 0 ]
