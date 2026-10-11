#!/usr/bin/env bash
#
# The five findings against `alpha.45`, on the `alpha.45` BYTES and then on the
# fix, with REAL WooCommerce 11.0.1.
#
# Narrow on purpose: this round changes one domain rule and one admin panel,
# plus the four callers that must now ask the same question. The previous
# round's own scripts are re-run separately as the regression; nothing here
# duplicates them.
#
# Three of the five findings are about code `alpha.45` itself wrote, and two of
# those were asserted as CORRECT by its own tests — `12/12` for a stated
# ceiling of eleven was a unit-test expectation. So the shape that matters here
# is not «does the new code work» but «did the old code really do the wrong
# thing», measured on its bytes, with controls that answer identically on both
# trees.
#
# The panel verbs fire `woocommerce_process_product_meta` with a `$_POST` taken
# out of the panel's OWN rendered markup — field names and nonce scraped from
# the HTML — with WooCommerce's own listener detached, because that listener
# rewrites the whole product from `$_POST` and would blank price and stock as a
# side effect of the harness. Said plainly: this is the server half of the real
# form, not a browser.
#
# Checked three ways, like every reproduction script here: the probe must exit
# 0, it must print `probe=ran` (a verb that printed nothing executed nothing —
# the `alpha.32` trap), and the CONTROLS must answer identically on both trees,
# so a difference cannot be the revert having broken the checkout.
#
# The revert is `git restore --worktree` (never `git checkout <commit> --`,
# which writes the index too — `alpha.39`), verified against a hash list taken
# BEFORE anything was touched (`alpha.32`), and undone from a `trap` with
# absolute paths (`alpha.37`). The installed plugin is re-synced on every
# switch, or the probe would measure one build while WordPress served another.
#
# NO MIGRATION THIS ROUND. Schema stays 24, so every difference below is
# behaviour, and none of it can be explained away as «the old tree could not
# read the column».
#
#   WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#     bash tools/alpha46-reproduction.sh docs/evidence/alpha46-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha46-reproduction}"
BASE_COMMIT="${TMC_BASE_COMMIT:-bee5f17}"
PHPBIN="${PHPBIN:-php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
INSTALL="${TMC_INSTALL:-$WP_PATH/wp-content/plugins/tecteb-marketplace-core}"
PROBE="$REPO/tests/Support/alpha45-probe.php"
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
# WP_ADMIN, because the panel is registered only in wp-admin and WP-CLI is not.
# Without it the panel never loads, the scrape finds no fields, the POST
# carries no nonce and every panel verb reports «nothing changed» about a
# listener that never ran — measured, and the first run of this script said so.
# WP_ENVIRONMENT_TYPE, because the basket verb needs the plugin's own
# local-only trial switch; the sale gate itself is not touched.
WP()   { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
           --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' "$@"; }

# ---------------------------------------------------------------- environment

if [ ! -d "$INSTALL" ]; then
    note "alpha46-reproduction: no installed plugin at $INSTALL."; exit 2
fi
if ! WP core version >/dev/null 2>&1; then
    note "alpha46-reproduction: WP-CLI cannot reach the WordPress at $WP_PATH."; exit 2
fi
if ! WP plugin list --status=active --field=name 2>/dev/null | grep -qx woocommerce; then
    note "alpha46-reproduction: WooCommerce is not ACTIVE on the disposable site."
    note "  This script exists to measure real WooCommerce; it will not pretend with a stub."
    exit 2
fi
WC_VERSION="$(WP plugin get woocommerce --field=version 2>/dev/null)"
note "woocommerce: $WC_VERSION on WordPress $(WP core version 2>/dev/null)"
if [ "$WC_VERSION" != "11.0.1" ]; then
    note "alpha46-reproduction: WooCommerce $WC_VERSION is not the target 11.0.1."; exit 2
fi
if ! git -C "$REPO" rev-parse --verify "$BASE_COMMIT" >/dev/null 2>&1; then
    note "alpha46-reproduction: base commit $BASE_COMMIT is not in this clone."; exit 2
fi
if ! git -C "$REPO" diff --quiet -- src || ! git -C "$REPO" diff --cached --quiet -- src; then
    note "alpha46-reproduction: src/ has uncommitted changes. Commit or stash them first."; exit 2
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

# Controls: answers this round must NOT have changed.
#
#  - `brand-persists` is last round's whole §3, here because the form path was
#    touched again;
#  - `workable-cart` is the other half of §3's demand: `min 3, max 10, step 3`
#    must still accept three, six and nine, in the REAL basket, through the
#    filter WooCommerce actually fires. It is a control rather than a finding
#    precisely because `alpha.45` got this case right — the refusal added this
#    round must narrow nothing that was working. Both trees must print the
#    same bytes, down to `input_args=3/9/3`.
#
# Both run after every verb above, so the product exists; `workable-cart`
# writes its own three rows and projects nothing (the sale gate memoises per
# request, so a verb that publishes cannot also measure a basket).
CONTROLS="brand-persists workable-cart"

# =========================================================================
note ""
note "=== the alpha.45 bytes ($BASE_COMMIT), on WooCommerce $WC_VERSION ==="
git -C "$REPO" restore --worktree --source="$BASE_COMMIT" -- src
sync_install

if run_probe old rule-ceiling; then
    expect old rule-ceiling 'stated_10_11_4=effective=12/12/4_raised_ceiling=YES_satisfiable=n/a' \
        "DEFECT: a stated ceiling of eleven came out twelve"
    expect old rule-ceiling 'stated_3_10_3=effective=3/9/3_raised_ceiling=no_satisfiable=n/a' \
        "and a workable window was already right, so the defect is narrow"
fi

if run_probe old rule-invalid-max; then
    expect old rule-invalid-max 'stated_5_2_1=max=UNLIMITED_allows_9999=YES_satisfiable=n/a' \
        "DEFECT: «at most two» became no maximum at all"
    expect old rule-invalid-max 'stated_3_0_1=max=UNLIMITED_allows_9999=YES_satisfiable=n/a' \
        "DEFECT: and so did a maximum of zero"
fi

if run_probe old rule-one-answer; then
    expect old rule-one-answer 'service_10_11_4=inventory_saved' \
        "DEFECT: the service accepted an impossible window"
    expect old rule-one-answer 'csv_10_11_4=csv_row_ok' \
        "DEFECT: so did the CSV importer"
    expect old rule-one-answer 'panel_10_11_4_rows_after=12/12/4' \
        "DEFECT: and the panel wrote the raised ceiling onto the shop"
fi

if run_probe old panel-invalid; then
    expect old panel-invalid 'rows_after=6/empty/2' \
        "DEFECT: «min 5, max 2» rewrote all three rows and removed the ceiling"
    expect old panel-invalid 'record_after=__none__' \
        "DEFECT: and deleted the ownership record"
fi

if run_probe old panel-title-only; then
    expect old panel-title-only 'record_after=__none__' \
        "DEFECT: saving only the title deleted the ownership record"
    expect old panel-title-only 'vendor_change_applied=NO' \
        "DEFECT: so the vendor's legitimate change stopped applying"
    expect old panel-title-only 'conflicts=_tmc_max_purchase' \
        "and was reported as a manager conflict nobody had made"
fi

if run_probe old panel-one-rule; then
    expect old panel-one-rule 'record_keeps_min_and_step=NO' \
        "DEFECT: changing the maximum froze the minimum and the step too"
    expect old panel-one-rule 'vendor_min_applied=NO' \
        "DEFECT: so a rule the manager never touched stopped tracking the vendor"
fi

if run_probe old panel-return-auto; then
    expect old panel-return-auto 'panel_offers_a_reset_control=no' \
        "DEFECT: the panel promised «leave the fields empty» and offered no control"
    expect old panel-return-auto 'rows_after_reset=1/empty/1' \
        "DEFECT: and emptying them wrote «no minimum, NO MAXIMUM, no step»"
    expect old panel-return-auto 'back_to_automatic=NO' \
        "DEFECT: so the product stayed manager-owned, at an unlimited ceiling"
fi

for verb in $CONTROLS; do
    run_probe old "$verb" || true
done

# =========================================================================
note ""
note "=== the fix (HEAD) ==="
git -C "$REPO" restore --worktree --source=HEAD -- src
sync_install

if run_probe new rule-ceiling; then
    expect new rule-ceiling 'stated_10_11_4=effective=12/none/4_raised_ceiling=no_satisfiable=no' \
        "the window is impossible, so it is refused rather than widened"
    expect new rule-ceiling 'stated_3_10_3=effective=3/9/3_raised_ceiling=no_satisfiable=yes' \
        "and a workable window still works, floored not raised"
    expect new rule-ceiling 'stated_5_7_5=effective=5/5/5_raised_ceiling=no_satisfiable=yes' \
        "the boundary case: flooring to exactly the minimum is fine"
fi

if run_probe new rule-invalid-max; then
    expect new rule-invalid-max 'stated_5_2_1=max=2_allows_9999=no_satisfiable=no' \
        "an invalid maximum is kept for the message and allows nothing"
    expect new rule-invalid-max 'stated_3_0_1=max=0_allows_9999=no_satisfiable=no' \
        "a maximum of zero is not «unlimited» either"
fi

if run_probe new rule-one-answer; then
    expect new rule-one-answer 'service_10_11_4=bad_quantity_unreachable' \
        "the service refuses it, by name"
    expect new rule-one-answer 'service_3_10_3=inventory_saved' \
        "and still accepts a workable one"
    expect new rule-one-answer 'csv_10_11_4=bad_quantity_unreachable' \
        "the importer gives the SAME code — one rule, four doors"
    expect new rule-one-answer 'csv_3_10_3=csv_row_ok' \
        "and still accepts a workable one"
    expect new rule-one-answer 'panel_10_11_4_rows_after=2/6/2' \
        "and the panel wrote nothing at all"
fi

if run_probe new panel-invalid; then
    expect new panel-invalid 'rows_after=2/6/2' \
        "«min 5, max 2» changed not one of the three rules"
    expect new panel-invalid 'record_after=max,min,purchase_step' \
        "and the ownership record is intact"
    expect new panel-invalid 'other_product_data_unchanged=yes' \
        "nor did it touch the title, the price, the stock or the SKU"
    expect new panel-invalid 'rows_after_unreachable=2/6/2' \
        "and the impossible window is refused from this door too"
    expect new panel-invalid 'record_after_unreachable=max,min,purchase_step' \
        "with the record still intact"
fi

if run_probe new panel-title-only; then
    expect new panel-title-only 'record_after=max,min,purchase_step' \
        "a title-only save leaves the ownership record alone"
    expect new panel-title-only 'vendor_change_applied=yes' \
        "so the vendor's legitimate change still applies"
    expect new panel-title-only 'conflicts=' \
        "and nothing is reported as a conflict nobody made"
fi

if run_probe new panel-one-rule; then
    expect new panel-one-rule 'record_after=min,purchase_step' \
        "only the rule the manager changed leaves the record"
    expect new panel-one-rule 'record_keeps_min_and_step=yes' \
        "the other two keep tracking the vendor"
    expect new panel-one-rule 'manager_max_survived=yes' \
        "the manager's maximum survives the next projection"
    expect new panel-one-rule 'vendor_min_applied=yes' \
        "and the vendor's minimum still lands"
fi

if run_probe new panel-return-auto; then
    expect new panel-return-auto 'panel_offers_a_reset_control=yes' \
        "the promise has a named control now"
    expect new panel-return-auto 'rows_after_reset=__absent__/__absent__/__absent__' \
        "which removes the rows"
    expect new panel-return-auto 'record_after_reset=__none__' \
        "and the ownership record with them"
    expect new panel-return-auto 'rows_after_reprojection=2/6/2' \
        "so the next synchronisation writes the marketplace's values again"
    expect new panel-return-auto 'back_to_automatic=yes' \
        "which is what «back to automatic» has to mean"
fi

if run_probe new unsatisfiable-stored; then
    expect new unsatisfiable-stored 'stored_rows=10/11/4' \
        "a row that is already inconsistent, as only a pre-alpha.46 write could leave it"
    expect new unsatisfiable-stored 'args_not_widened=yes' \
        "the product page's own numbers are NOT widened by it"
    expect new unsatisfiable-stored 'contradiction_recorded=unsatisfiable' \
        "it is recorded rather than reinterpreted"
    expect new unsatisfiable-stored 'panel_warns=yes' \
        "the panel says so"
    expect new unsatisfiable-stored 'panel_shows_stored_max=yes' \
        "and shows the STORED eleven rather than a coerced twelve"
fi

if run_probe new unsatisfiable-cart; then
    expect new unsatisfiable-cart 'qty_1=refused' "nothing may be bought under it"
    expect new unsatisfiable-cart 'qty_11=refused' "not the stated maximum"
    expect new unsatisfiable-cart 'qty_12=refused' "and certainly not above it"
    expect new unsatisfiable-cart 'shopper_told=named' \
        "and the shopper is told what is wrong, not given a quantity to retry"
fi

for verb in $CONTROLS; do
    run_probe new "$verb" || true
done

# ---- the controls must answer identically on BOTH trees -----------------
note ""
note "=== controls: the same answer on both trees, or the revert broke something ==="
for verb in $CONTROLS; do
    if [ ! -s "$OUT/old-$verb.txt" ] || [ ! -s "$OUT/new-$verb.txt" ]; then
        bad "control $verb did not produce output on both trees"
        continue
    fi
    if diff -q "$OUT/old-$verb.txt" "$OUT/new-$verb.txt" >/dev/null; then
        ok "control $verb answers identically on alpha.45 and the fix"
    else
        bad "control $verb DIFFERS between the trees:"
        diff "$OUT/old-$verb.txt" "$OUT/new-$verb.txt" | sed 's/^/        /' | tee -a "$LOG"
    fi
done

note ""
note "checks: $((pass + fail))   pass: $pass   fail: $fail"
[ "$fail" -eq 0 ] || exit 1
