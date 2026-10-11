#!/usr/bin/env bash
#
# The reset gap: on the `alpha.46` BYTES, then on the fix, with REAL
# WooCommerce 11.0.1.
#
# `alpha.46` gave the WooCommerce panel a named «back to automatic» control
# and made it DELETE the three quantity rows, leaving the restoring to the
# next projection. Neither half was wrong alone — `ProjectedQuantity::
# ruleFrom()` is right to read an absent row as WooCommerce's defaults,
# because a product this plugin never projected has no rule — and that is
# why the two together survived a round.
#
# THE WINDOW IS THE SUBJECT, so it is measured in its own `wp` invocation.
# A verb that arranges the reset and then asks about the page in the same PHP
# process proves nothing about a visitor's request: `PurchasePolicy` memoises
# per request, the sale gate memoises per request, and `publish()` has already
# warmed both. A verb that measures after a projection measures the state the
# defect never reached. So the arranging verbs and the measuring verbs are
# separate processes, and nothing between them saves a product or projects
# anything.
#
# Checked three ways, like every reproduction script here: the probe must exit
# 0, it must print `probe=ran` (a verb that printed nothing executed nothing —
# the `alpha.32` trap), and the CONTROLS must answer identically on both
# trees, so a difference cannot be the revert having broken the checkout.
#
# The revert is `git restore --worktree` (never `git checkout <commit> --`,
# which writes the index too — `alpha.39`), verified against a hash list taken
# BEFORE anything was touched (`alpha.32`), and undone from a `trap` with
# absolute paths (`alpha.37`). The installed plugin is re-synced on every
# switch, or the probe would measure one build while WordPress served another.
#
# NO MIGRATION THIS ROUND. Schema stays 24, so every difference below is
# behaviour.
#
#   WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#     bash tools/alpha47-reproduction.sh docs/evidence/alpha47-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha47-reproduction}"
BASE_COMMIT="${TMC_BASE_COMMIT:-2676ae3}"
PHPBIN="${PHPBIN:-php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
INSTALL="${TMC_INSTALL:-$WP_PATH/wp-content/plugins/tecteb-marketplace-core}"
PROBE="$REPO/tests/Support/alpha46-probe.php"
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
    note "alpha47-reproduction: no installed plugin at $INSTALL."; exit 2
fi
if ! WP core version >/dev/null 2>&1; then
    note "alpha47-reproduction: WP-CLI cannot reach the WordPress at $WP_PATH."; exit 2
fi
if ! WP plugin list --status=active --field=name 2>/dev/null | grep -qx woocommerce; then
    note "alpha47-reproduction: WooCommerce is not ACTIVE on the disposable site."
    note "  This script exists to measure real WooCommerce; it will not pretend with a stub."
    exit 2
fi
WC_VERSION="$(WP plugin get woocommerce --field=version 2>/dev/null)"
note "woocommerce: $WC_VERSION on WordPress $(WP core version 2>/dev/null)"
if [ "$WC_VERSION" != "11.0.1" ]; then
    note "alpha47-reproduction: WooCommerce $WC_VERSION is not the target 11.0.1."; exit 2
fi
if ! git -C "$REPO" rev-parse --verify "$BASE_COMMIT" >/dev/null 2>&1; then
    note "alpha47-reproduction: base commit $BASE_COMMIT is not in this clone."; exit 2
fi
if ! git -C "$REPO" diff --quiet -- src || ! git -C "$REPO" diff --cached --quiet -- src; then
    note "alpha47-reproduction: src/ has uncommitted changes. Commit or stash them first."; exit 2
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
#  - `control-no-change` is `alpha.46`'s own §2 fix — a save that moves no
#    rule must still move nothing, ownership record included;
#  - `control-manager-edit` is the other half of it: a real manager edit takes
#    ONLY the rule it changed, and the vendor's other changes still land.
#
# Both must print the same bytes on both trees. A reset that stopped deleting
# must not have disturbed the saving path beside it.
CONTROLS="control-no-change control-manager-edit"

# The arranging verb for each measurement, and the fresh-request verbs that
# follow it. Each pair is run as SEPARATE processes, in this order.
run_scenario() {
    local tree="$1"; shift
    for verb in "$@"; do
        run_probe "$tree" "$verb" || return 1
    done
    return 0
}

# =========================================================================
note ""
note "=== the alpha.46 bytes ($BASE_COMMIT), on WooCommerce $WC_VERSION ==="
git -C "$REPO" restore --worktree --source="$BASE_COMMIT" -- src
sync_install

if run_scenario old gap-arrange gap-page gap-cart; then
    expect old gap-arrange 'rows_after_reset=__absent__/__absent__/__absent__' \
        "DEFECT: the reset removed the three rows and left the restoring to a later save"
    expect old gap-arrange 'outcome=handed_back' \
        "and reported plain success for it"
    expect old gap-page 'rows_read_as_unrestricted=YES' \
        "DEFECT: in a FRESH request the absent rows read as «no minimum, no maximum, no step»"
    expect old gap-page 'input_args=1/40/1' \
        "so WooCommerce's own numbers lost the rule — the ceiling is stock, not the vendor's six"
    expect old gap-page 'enforced_matches_marketplace=NO' \
        "while the marketplace row still said 2/6/2"
    expect old gap-cart 'classic_qty_1=allowed' \
        "DEFECT: one unit, under a minimum of two"
    expect old gap-cart 'classic_qty_3=allowed' \
        "DEFECT: three units, off a step of two"
    expect old gap-cart 'classic_qty_8=allowed' \
        "DEFECT: eight units, over a ceiling of six — the reset RAISED what could be bought"
    expect old gap-cart 'store_api_qty_8=allowed' \
        "and the block cart's own validator agreed"
fi

if run_scenario old gap-variable-arrange gap-variable-page gap-variable-cart; then
    expect old gap-variable-arrange 'rows_after_reset=__absent__/__absent__/__absent__' \
        "DEFECT: the same on a variable product's parent"
    expect old gap-variable-page 'variation_input_args=1/7/1' \
        "DEFECT: so the variation lost it too — its ceiling is its own stock"
    expect old gap-variable-page 'variation_enforced_matches_marketplace=NO' \
        "while the marketplace row said 2/6/2"
    expect old gap-variable-cart 'classic_qty_8=allowed' \
        "DEFECT: eight of a variation whose rule says at most six"
    expect old gap-variable-cart 'store_api_qty_8=allowed' \
        "through the Store API as well"
fi

if run_probe old write-failure; then
    expect old write-failure 'rows_after_refused_reset=__absent__/__absent__/__absent__' \
        "DEFECT: a REFUSED write still left no rows — nothing was written, so nothing could fail"
    expect old write-failure 'ceiling_kept=NO' \
        "DEFECT: the manager's ceiling went anyway"
    expect old write-failure 'outcome=handed_back' \
        "DEFECT: and success was reported with a write refused"
fi

if run_probe old read-failure; then
    expect old read-failure 'rows_after_unreadable_reset=__absent__/__absent__/__absent__' \
        "DEFECT: the rule could not be read AND the rows went anyway"
    expect old read-failure 'restriction_kept=NO' \
        "DEFECT: so an unreadable rule produced no rule at all"
    expect old read-failure 'outcome=handed_back' \
        "DEFECT: reported as a success"
fi

for verb in $CONTROLS; do
    run_probe old "$verb" || true
done

# =========================================================================
note ""
note "=== the fix (HEAD) ==="
git -C "$REPO" restore --worktree --source=HEAD -- src
sync_install

if run_scenario new gap-arrange gap-page gap-cart; then
    expect new gap-arrange 'rows_after_reset=2/6/2' \
        "the approved marketplace rule is written in the SAME request"
    expect new gap-arrange 'record_after_reset=max,min,purchase_step' \
        "and all three rows are the marketplace's again, so the vendor's changes apply"
    expect new gap-arrange 'outcome=handed_back' \
        "with the outcome reported to the manager who clicked it"
    expect new gap-page 'rows_read_as_unrestricted=no' \
        "in a FRESH request there is no window: the rows are there"
    expect new gap-page 'input_args=2/6/2' \
        "WooCommerce's own numbers carry the rule — the classic page AND the blocks"
    expect new gap-page 'enforced_matches_marketplace=yes' \
        "which is what «back to automatic» has to mean"
    expect new gap-cart 'classic_qty_1=refused' \
        "one unit is under the minimum"
    expect new gap-cart 'classic_qty_3=refused' \
        "three is off the step"
    expect new gap-cart 'classic_qty_8=refused' \
        "eight is over the ceiling"
    expect new gap-cart 'classic_qty_4=allowed' \
        "and four, which the rule allows, still goes through"
    expect new gap-cart 'store_api_qty_8=refused' \
        "the Store API refuses it too"
    expect new gap-cart 'store_api_qty_4=allowed' \
        "and allows what the rule allows"
fi

if run_scenario new gap-variable-arrange gap-variable-page gap-variable-cart; then
    expect new gap-variable-arrange 'rows_after_reset=2/6/2' \
        "the same on a variable product's parent"
    expect new gap-variable-page 'variation_has_own_rows=no' \
        "the variation holds no rows of its own — the rule is the product's"
    expect new gap-variable-page 'variation_input_args=2/6/2' \
        "and it is governed by it in a FRESH request"
    expect new gap-variable-page 'variation_enforced_matches_marketplace=yes' \
        "matching the marketplace row"
    expect new gap-variable-cart 'classic_qty_8=refused' \
        "eight of the variation is refused"
    expect new gap-variable-cart 'classic_qty_4=allowed' \
        "four is allowed"
    expect new gap-variable-cart 'store_api_qty_8=refused' \
        "and the Store API agrees"
fi

if run_probe new write-failure; then
    expect new write-failure 'rows_after_refused_reset=2/4/2' \
        "a refused write leaves the row exactly as it was"
    expect new write-failure 'ceiling_kept=yes' \
        "so the manager's ceiling of four is still in force"
    expect new write-failure 'max_row_absent=no' \
        "and no row became absent — there is no unlimited middle state"
    expect new write-failure 'outcome=hand_back_failed' \
        "the manager is told it did not finish"
    expect new write-failure 'named_the_key=yes' \
        "and which rule it was"
fi

if run_probe new write-failure-page; then
    expect new write-failure-page 'rows_read_as_unrestricted=no' \
        "and a FRESH request after the refused restore still has a rule"
    expect new write-failure-page 'input_args=2/4/2' \
        "the restriction that was already there"
fi

if run_probe new read-failure; then
    expect new read-failure 'wc_get_product_now=false' \
        "the shop product really cannot be resolved — injected at WooCommerce's own filter"
    expect new read-failure 'rows_after_unreadable_reset=2/4/2' \
        "nothing is written and nothing is DELETED"
    expect new read-failure 'restriction_kept=yes' \
        "so the restriction the shop had is still there"
    expect new read-failure 'any_row_absent=no' \
        "and no row became absent"
    expect new read-failure 'record_after=min,purchase_step' \
        "the ownership record is untouched too"
    expect new read-failure 'outcome=hand_back_unavailable' \
        "and the manager is told why, by name"
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
    if grep -q '^measurement=not_run$' "$OUT/old-$verb.txt" \
        || grep -q '^measurement=not_run$' "$OUT/new-$verb.txt"; then
        bad "control $verb declined to measure (measurement=not_run) — its precondition did not hold"
        continue
    fi
    if diff -q "$OUT/old-$verb.txt" "$OUT/new-$verb.txt" >/dev/null; then
        ok "control $verb answers identically on alpha.46 and the fix"
    else
        bad "control $verb DIFFERS between the trees:"
        diff "$OUT/old-$verb.txt" "$OUT/new-$verb.txt" | sed 's/^/        /' | tee -a "$LOG"
    fi
done

# …and said out loud, because «identical» alone does not say WHAT they agreed on.
for tree in old new; do
    [ -s "$OUT/$tree-control-no-change.txt" ] || continue
    expect "$tree" control-no-change 'rows_after=2/6/2' \
        "a save that moves no rule moves no row"
    expect "$tree" control-no-change 'record_after=max,min,purchase_step' \
        "and leaves the ownership record whole"
    expect "$tree" control-manager-edit 'manager_max_survived=yes' \
        "a real manager edit survives the next projection"
    expect "$tree" control-manager-edit 'vendor_min_applied=yes' \
        "and the two rules they did not touch still track the vendor"
done

note ""
note "checks: $((pass + fail))   pass: $pass   fail: $fail"
[ "$fail" -eq 0 ] || exit 1
