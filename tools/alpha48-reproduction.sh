#!/usr/bin/env bash
#
# The purchase limits that never reached the shop, and the stock that now
# waits — on the `alpha.47` BYTES, then on the fix, with REAL WooCommerce.
#
# THE OWNER'S OWN TEST IS THE SCENARIO (§2 of the 11 October instruction).
# On `staging` they set min 1, max 20, step 1 in the vendor form and found the
# WooCommerce tab empty; they approved an unrelated brand proposal and all
# three appeared; then they changed only the maximum, 20 → 18, saved, refreshed
# WooCommerce and it still said 20.
#
# One cause for both halves: `ManageProducts::updateInventory()` wrote the
# marketplace row and called `pushStock()`, and NOTHING carried the three
# quantity rows outward. They arrived only with `project()` — a publish or an
# approval away on a live product. So the limits a shopper met were the limits
# of the last approval, and a limit nobody had asked for outlived the person
# entitled to change it.
#
# «بدون تأیید هیچ پیشنهاد دیگری» is part of the measurement: nothing is
# approved between the vendor's save and the page, because on `alpha.47` an
# approval was exactly what made the numbers appear.
#
# The SECOND subject is the owner's decision of the same day: a live product's
# stock edit becomes a proposal. Measured as a pair — the shop does not move
# before the approval, and it does move after it — plus the one conflict that
# can arise in between.
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
#   WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#     bash tools/alpha48-reproduction.sh docs/evidence/alpha48-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha48-reproduction}"
BASE_COMMIT="${TMC_BASE_COMMIT:-21b6b18}"
PHPBIN="${PHPBIN:-php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
INSTALL="${TMC_INSTALL:-$WP_PATH/wp-content/plugins/tecteb-marketplace-core}"
PROBE="$REPO/tests/Support/alpha47-probe.php"
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
# WP_ENVIRONMENT_TYPE, because the basket verb needs the plugin's own
# local-only trial switch; the sale gate itself is not touched. WP_ADMIN for
# the same reason the previous round needed it — anything registered only in
# wp-admin is otherwise absent, and WP-CLI is not wp-admin.
WP()   { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
           --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' "$@"; }

# ---------------------------------------------------------------- environment

if [ ! -d "$INSTALL" ]; then
    note "alpha48-reproduction: no installed plugin at $INSTALL."; exit 2
fi
if ! WP core version >/dev/null 2>&1; then
    note "alpha48-reproduction: WP-CLI cannot reach the WordPress at $WP_PATH."; exit 2
fi
if ! WP plugin list --status=active --field=name 2>/dev/null | grep -qx woocommerce; then
    note "alpha48-reproduction: WooCommerce is not ACTIVE on the disposable site."
    note "  This script exists to measure real WooCommerce; it will not pretend with a stub."
    exit 2
fi
WC_VERSION="$(WP plugin get woocommerce --field=version 2>/dev/null)"
note "woocommerce: $WC_VERSION on WordPress $(WP core version 2>/dev/null)"
if [ "$WC_VERSION" != "11.0.1" ]; then
    note "alpha48-reproduction: WooCommerce $WC_VERSION is not the target 11.0.1."; exit 2
fi
if ! git -C "$REPO" rev-parse --verify "$BASE_COMMIT" >/dev/null 2>&1; then
    note "alpha48-reproduction: base commit $BASE_COMMIT is not in this clone."; exit 2
fi
if ! git -C "$REPO" diff --quiet -- src || ! git -C "$REPO" diff --cached --quiet -- src; then
    note "alpha48-reproduction: src/ has uncommitted changes. Commit or stash them first."; exit 2
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
#  - `control-no-change` — a save that moves no value moves nothing, on either
#    tree, ownership record included;
#  - `control-draft` — a DRAFT's stock still applies at once and creates no
#    proposal, because the owner's decision is about LIVE products only.
#
# Both must print the same bytes on both trees.
CONTROLS="control-no-change control-draft"

run_scenario() {
    local tree="$1"; shift
    for verb in "$@"; do
        run_probe "$tree" "$verb" || return 1
    done
    return 0
}

# =========================================================================
note ""
note "=== the alpha.47 bytes ($BASE_COMMIT), on WooCommerce $WC_VERSION ==="
git -C "$REPO" restore --worktree --source="$BASE_COMMIT" -- src
sync_install

if run_scenario old limits-arrange limits-page; then
    expect old limits-arrange 'rows_at_start=1/20/1' \
        "the shop starts with the rule the last projection wrote"
    expect old limits-arrange 'row_max_after_save=18' \
        "the vendor's save DID reach the marketplace row"
    expect old limits-page 'rows_now=1/20/1' \
        "DEFECT: and never reached the shop — the owner's 20 that stayed 20"
    expect old limits-page 'input_args=1/20/1' \
        "DEFECT: so WooCommerce's own numbers still carry the old ceiling"
    expect old limits-page 'classic_qty_19=allowed' \
        "DEFECT: nineteen is bought, over a ceiling the vendor set to eighteen"
    expect old limits-page 'classic_qty_20=allowed' \
        "DEFECT: and twenty"
    expect old limits-page 'store_api_qty_19=allowed' \
        "DEFECT: the block cart's own validator agrees"
fi

if run_scenario old empty-tab-arrange empty-tab-page; then
    expect old empty-tab-arrange 'rows_before_save=__absent__/__absent__/__absent__' \
        "a product projected by a build that had no quantity rows — the staging shape"
    expect old empty-tab-page 'tab_empty=YES' \
        "DEFECT: the vendor saved 1/20/1 and the WooCommerce tab is still empty"
    # AND THE MEASUREMENT CORRECTED THE EXPECTATION. The first run of this
    # script expected the shop to enforce nothing here, and it enforced
    # 1/20/1: `alpha.47`'s own fallback — `QuantityGuard::storedRule()` asks
    # the marketplace row when ALL THREE rows are absent on a product of ours
    # — already covered this one case. So the empty tab on `alpha.47` was not
    # an enforcement hole; it was INVISIBILITY: the rule was enforced and no
    # screen in WooCommerce said what it was, so neither the manager nor the
    # vendor could see or edit it. The 20 → 18 case above is the enforcement
    # hole, because there the rows EXIST and say twenty, so the fallback never
    # runs. Both are the same missing write, met from two sides.
    expect old empty-tab-page 'input_args=1/20/1' \
        "alpha.47's absent-rows fallback did enforce the rule — so this half was invisibility, not a hole"
fi

if run_probe old manager-owned; then
    expect old manager-owned 'rows_after_manager_edit=1/7/1' \
        "the manager's own edit is on the shop row"
    expect old manager-owned 'manager_value_kept=yes' \
        "and nothing of the vendor's save touches it — because nothing was written at all"
    expect old manager-owned 'held=__none__' \
        "DEFECT: so the vendor is told nothing about a limit that did not move"
fi

if run_probe old write-failure; then
    expect old write-failure 'save_code=inventory_saved' \
        "DEFECT: a refused shop write cannot be reported, because there was no shop write"
    expect old write-failure 'rows_after_refused_write=1/20/1' \
        "the rows are untouched — for the wrong reason"
fi

if run_scenario old stock-arrange stock-page; then
    expect old stock-arrange 'save_code=inventory_saved' \
        "BASELINE: on alpha.47 a live product's stock edit applied at once"
    expect old stock-arrange 'row_stock_after_save=4' \
        "the row took the vendor's number"
    expect old stock-arrange 'pending_revision=none' \
        "and no manager was asked"
    expect old stock-page 'shop_stock_now=4' \
        "the shop moved in the same request"
fi

# The brand registry does not exist on `alpha.47`, and the probe says so
# rather than throwing — which is what keeps every other verb above
# measurable on these bytes (the `alpha.32` trap).
if run_probe old brand-list; then
    expect old brand-list 'brand_registry=absent_in_this_build' \
        "BASELINE: alpha.47 has no brand list, so free text was the only way to name a brand"
fi

for verb in $CONTROLS; do
    run_probe old "$verb" || true
done

# =========================================================================
note ""
note "=== the fix (HEAD) ==="
git -C "$REPO" restore --worktree --source=HEAD -- src
sync_install

if run_scenario new limits-arrange limits-page; then
    expect new limits-arrange 'rows_at_start=1/20/1' \
        "the same starting point"
    expect new limits-arrange 'save_code=inventory_saved' \
        "the vendor's save succeeds"
    expect new limits-arrange 'save_limits=limits_written' \
        "and says what happened to the selling rule"
    expect new limits-arrange 'row_max_after_save=18' \
        "the row holds eighteen"
    expect new limits-arrange 'pending_revision=none' \
        "with NOTHING proposed and NOTHING approved — the limits need no manager"
    expect new limits-page 'rows_now=1/18/1' \
        "and in a FRESH request the shop holds eighteen too"
    expect new limits-page 'input_args=1/18/1' \
        "WooCommerce's own numbers carry it — the classic page AND the blocks"
    expect new limits-page 'classic_qty_18=allowed' \
        "eighteen is allowed"
    expect new limits-page 'classic_qty_19=refused' \
        "nineteen is refused"
    expect new limits-page 'classic_qty_20=refused' \
        "and so is the twenty the shop used to allow"
    expect new limits-page 'store_api_qty_19=refused' \
        "the Store API refuses it as well"
fi

if run_scenario new empty-tab-arrange empty-tab-page; then
    expect new empty-tab-page 'tab_empty=no' \
        "the vendor's save fills the tab — no approval of anything else needed"
    expect new empty-tab-page 'rows_now=1/20/1' \
        "with the rule the vendor typed"
    expect new empty-tab-page 'input_args=1/20/1' \
        "and the shop enforces it"
fi

if run_probe new manager-owned; then
    expect new manager-owned 'manager_value_kept=yes' \
        "a row the manager owns is NOT overwritten (the alpha.44 rule, at a new door)"
    expect new manager-owned 'rows_after_save=1/7/1' \
        "so the shop keeps their seven"
    expect new manager-owned 'held=_tmc_max_purchase' \
        "and the vendor is told WHICH limit did not move, by name"
    expect new manager-owned 'conflicts=_tmc_max_purchase' \
        "with the key recorded for the review screen"
fi

if run_probe new write-failure; then
    expect new write-failure 'save=failed' \
        "a refused shop write is NOT reported as success"
    expect new write-failure 'save_code=inventory_saved_limits_not_written' \
        "and the code says which half failed"
    expect new write-failure 'failed=_tmc_max_purchase' \
        "naming the row"
    expect new write-failure 'ceiling_kept=yes' \
        "the previous ceiling is still in force"
    expect new write-failure 'max_row_absent=no' \
        "and no row became absent — there is no unlimited middle state (alpha.47)"
fi

if run_probe new write-failure-page; then
    expect new write-failure-page 'rows_now=1/20/1' \
        "in a FRESH request the restriction that was already there is still there"
    expect new write-failure-page 'input_args=1/20/1' \
        "and WooCommerce enforces it"
fi

if run_scenario new stock-arrange stock-page stock-approve; then
    expect new stock-arrange 'save_code=stock_revision_requested' \
        "a live product's stock edit is a proposal (the owner's decision)"
    expect new stock-arrange 'row_stock_after_save=20' \
        "the row keeps the effective number"
    expect new stock-arrange 'proposed_stock=4' \
        "the proposal carries the number the vendor wants"
    expect new stock-arrange 'baseline_stock=20' \
        "and the number it was measured against"
    expect new stock-page 'shop_stock_now=20' \
        "in a FRESH request the shop still holds what a shopper met"
    expect new stock-approve 'approve_code=revision_approved' \
        "the manager agrees"
    expect new stock-approve 'row_stock_after_approval=4' \
        "and the row takes it"
    expect new stock-approve 'shop_stock_after_approval=4' \
        "and so does WooCommerce"
fi

if run_probe new stock-conflict; then
    expect new stock-conflict 'shop_stock_before_approval=3' \
        "the shop's own number moved between proposing and approving"
    expect new stock-conflict 'approve_code=stock_moved' \
        "so the approval REFUSES rather than inventing stock"
    expect new stock-conflict 'named_numbers=20/3/12' \
        "naming all three: when proposed, now, and proposed value"
    expect new stock-conflict 'shop_stock_after_refusal=3' \
        "nothing was written"
    expect new stock-conflict 'revision_still_pending=yes' \
        "and the proposal is still there to decide"
    expect new stock-conflict 'override_code=revision_approved' \
        "the manager's explicit «apply it anyway» goes through"
    expect new stock-conflict 'shop_stock_after_override=12' \
        "and only then does the shop take the proposed number"
fi

if run_probe new keeps-proposal; then
    expect new keeps-proposal 'brand_in_proposal_after=برند_پیشنهادی_۴۸' \
        "saving the inventory tab KEEPS the pending brand proposal"
    expect new keeps-proposal 'stock_in_proposal_after=6' \
        "and adds the stock request to it rather than replacing it"
    expect new keeps-proposal 'badge_token_moved=yes' \
        "with a new proposal id, so the unseen badge comes back (alpha.36)"
fi

# ======================= §4: brands, on the real taxonomy =================
if run_probe new brand-list; then
    expect new brand-list 'taxonomy_exists=yes' \
        "the approved list is WooCommerce's OWN product_brand — measured, not read off a changelog"
    expect new brand-list 'directory_available=yes' \
        "and the directory reads it"
    expect new brand-list 'found_by_key=برند_آزمون_۴۸' \
        "a brand is found by its name"
    expect new brand-list 'found_by_variant=برند_آزمون_۴۸' \
        "and by a spelling that differs only in a space — on the real list"
fi

if run_probe new brand-free-text; then
    expect new brand-free-text 'save_code=brand_not_approved' \
        "free text cannot become an official brand"
    expect new brand-free-text 'brand_after=__empty__' \
        "and nothing of the product changed"
fi

if run_scenario new brand-request brand-approve; then
    expect new brand-request 'ask_code=brand_requested' \
        "a vendor may ASK for a brand that is not in the list"
    expect new brand-request 'brand_after_ask=__empty__' \
        "and until a manager agrees the product's brand does not move"
    expect new brand-request 'term_exists_yet=no' \
        "nor does the list"
    expect new brand-request 'in_needs_review=yes' \
        "the request is in the ONE «needs review» set (§5)"
    expect new brand-approve 'approve_code=brand_approved' \
        "the manager approves"
    expect new brand-approve 'row_brand=سینا_طب_۴۸' \
        "the brand is written on the product"
    expect new brand-approve 'shop_attribute_brand=سینا_طب_۴۸' \
        "AND on WooCommerce → Product data → Attributes → برند, the path the owner measured working"
    expect new brand-approve 'request_closed=yes' \
        "the request is closed"
    expect new brand-approve 'in_needs_review=no' \
        "and the product leaves the queue"
fi

if run_probe new brand-duplicate; then
    expect new brand-duplicate 'ask_code=brand_already_approved' \
        "a name that differs only in a space is the SAME brand"
    expect new brand-duplicate 'named_real_brand=آمبو_بگ_۴۸' \
        "and the vendor is told which brand to pick"
    expect new brand-duplicate 'no_second_term=yes' \
        "with no second term made on the real taxonomy"
fi

if run_probe new brand-register-twice; then
    expect new brand-register-twice 'same_term=yes' \
        "registering one brand twice, spelled two ways, is one term"
    expect new brand-register-twice 'term_count_for_name=1' \
        "measured on the taxonomy itself"
fi

# ---- a REAL race: two processes, two spellings, one brand ----------------
#
# `alpha.40`'s rule: a third actor on your own connection is not a third
# actor. Two calls in one request share the directory's memo, WordPress's term
# cache and the connection, so the only honest form of «two managers approve
# the same name at the same moment» is two processes.
note ""
note "=== two processes registering one brand, two spellings ==="
RACE_NAME_A='برند رقابت ۴۸'
RACE_NAME_B='برندرقابت ۴۸'
# THREE TIMES, because one pass is a coin flip. The first run of this section
# passed and the SECOND failed with `a=37 b=36` and two terms — both processes
# got past `findByKey()` before either had inserted, and `wp_insert_term()`
# refuses a duplicate NAME, which two spellings of one brand are not. That is
# what the advisory lock in `WooCommerceBrandDirectory::register()` is for, and
# a guard measured once is a guard measured by luck.
clear_race_terms() {
    WP eval 'foreach (get_terms(["taxonomy"=>"product_brand","hide_empty"=>false,"search"=>"رقابت","fields"=>"ids"]) as $id) { wp_delete_term((int) $id, "product_brand"); }' >/dev/null 2>&1
}
for attempt in 1 2 3; do
    clear_race_terms
    (
        WP eval-file "$REPO/tests/Support/concurrent-brand-register.php" "$RACE_NAME_A" > "$OUT/race-$attempt-a.txt" 2>&1 &
        WP eval-file "$REPO/tests/Support/concurrent-brand-register.php" "$RACE_NAME_B" > "$OUT/race-$attempt-b.txt" 2>&1 &
        wait
    )
    RACE_A="$(sed -n 's/^term=//p' "$OUT/race-$attempt-a.txt" | head -1)"
    RACE_B="$(sed -n 's/^term=//p' "$OUT/race-$attempt-b.txt" | head -1)"
    RACE_TERMS="$(WP eval 'echo count(get_terms(["taxonomy"=>"product_brand","hide_empty"=>false,"search"=>"رقابت","fields"=>"ids"]));' 2>/dev/null)"
    note "race $attempt: a=$RACE_A b=$RACE_B terms=$RACE_TERMS"
    if [ "$RACE_A" != "" ] && [ "$RACE_A" = "$RACE_B" ]; then
        ok "race $attempt: both processes came back with the SAME term — the loser gets the winner's brand"
    else
        bad "race $attempt: the two processes disagreed about the term (a=$RACE_A b=$RACE_B)"
    fi
    if [ "$RACE_TERMS" = "1" ]; then
        ok "race $attempt: and the taxonomy holds ONE term for the two spellings"
    else
        bad "race $attempt: the taxonomy holds $RACE_TERMS terms for one brand"
    fi
done
clear_race_terms

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
        ok "control $verb answers identically on alpha.47 and the fix"
    else
        bad "control $verb DIFFERS between the trees:"
        diff "$OUT/old-$verb.txt" "$OUT/new-$verb.txt" | sed 's/^/        /' | tee -a "$LOG"
    fi
done

# …and said out loud, because «identical» alone does not say WHAT they agreed on.
for tree in old new; do
    [ -s "$OUT/$tree-control-no-change.txt" ] || continue
    expect "$tree" control-no-change 'rows_after_save=1/20/1' \
        "a save that moves no value moves no row"
    expect "$tree" control-no-change 'record_unchanged=yes' \
        "and leaves the ownership record whole"
    expect "$tree" control-no-change 'pending_revision=none' \
        "and proposes nothing"
    expect "$tree" control-draft 'save_code=inventory_saved' \
        "a DRAFT's stock still applies at once"
    expect "$tree" control-draft 'shop_stock=7' \
        "reaching the shop in the same request"
    expect "$tree" control-draft 'pending_revision=none' \
        "and asking nobody — the owner's decision is about LIVE products"
done

note ""
note "checks: $((pass + fail))   pass: $pass   fail: $fail"
[ "$fail" -eq 0 ] || exit 1
