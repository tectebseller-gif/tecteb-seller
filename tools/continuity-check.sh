#!/usr/bin/env bash
# Does the SHOP still work after the cutover?
#
# `cutover-check.sh` proves Dokan can be switched off without losing anything.
# This proves the harder and more useful thing: that the shop which moved can
# still sell the product that moved, to a real customer, and be paid for it.
#
# Eighteen stages in ONE PHP pass (`tools/continuity-state.php`), because the
# stages are not independent — the purchase needs the transfer, the shipment
# needs the purchase, the financial share needs the lines the purchase wrote.
#
#   tools/continuity-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/continuity}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-58s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-58s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
stage() { grep -E "^stage=$1 " "$EV/01-run.txt" | head -1; }
ok_of() { stage "$1" | grep -oE ' ok=[a-z]+' | head -1 | cut -d= -f2; }
field() { stage "$1" | grep -oE " $2=[^ ]*" | head -1 | cut -d= -f2-; }

cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

{
echo "=== business continuity across the cutover, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version | grep -E 'tecteb|dokan|woocommerce'
} | tee "$EV/00-header.txt"

# A fixture that spends something must be able to run again. Both resets, every
# time — the first run of this suite passed and the second measured its own
# leftovers, which is the failure mode the reset exists to prevent.
wp eval-file continuity-state.php reset > "$EV/00-reset.txt" 2>&1
wp eval-file dokan-migration.php reset >> "$EV/00-reset.txt" 2>&1
wp eval-file continuity-state.php run > "$EV/01-run.txt" 2>&1
sed -n 's/^stage=/  /p' "$EV/01-run.txt"
echo

echo "--- 1. what the migration cannot decide, it does not decide ---"
check "unmapped source categories are named, with counts"  true "$(ok_of unmapped_categories_are_named)"
check "…and the owner's mapping is what resolves them"   true "$(ok_of owner_maps_the_category)"
check "nothing is left unmapped afterwards"                0 "$(field owner_maps_the_category unmapped_groups_after)"

echo
echo "--- 2. arriving is not the same as being allowed to trade ---"
check "the catalogue arrives as OBSERVED"                  true "$(ok_of imported_as_observed)"
check "…carrying the product's own picture"              1 "$(field imported_as_observed images_carried)"
check "an observed row cannot be bought"                   true "$(ok_of observed_is_not_sellable)"
check "ownership is taken deliberately"                    true "$(ok_of ownership_taken)"
# The heart of «هیچ دسترسی نامشخصی خودکار اعطا نشود»: the catalogue moved and
# the shop still cannot sell, because this marketplace's register is separate.
check "the imported shop still may NOT trade"              true "$(ok_of imported_shop_may_not_trade_yet)"
check "…and says so as vendor_stopped"                   vendor_stopped "$(field imported_shop_may_not_trade_yet decision)"
check "a manager admits it, and only then can it sell"     true "$(ok_of shop_admitted_by_a_manager)"
check "…which is the profile switch, not the paperwork"  true "$(field shop_admitted_by_a_manager can_sell)"
check "…and direct publishing is NOT granted with it"    false "$(field shop_admitted_by_a_manager can_publish_directly)"

echo
echo "--- 3. the product becomes sellable the same way any product does ---"
check "the specs — the one gap — are filled by a person"   true "$(ok_of specs_are_the_one_gap_migration_cannot_fill)"
check "it goes through submit and review, no shortcut"     true "$(ok_of migrated_product_published_through_review)"
check "…and ends published"                              published "$(field migrated_product_published_through_review status)"
# An oversold Dokan product arrives at zero, not at minus two.
check "the oversold source stock was clamped"              -2 "$(field shop_restocks_after_the_clamp source_stock)"
check "…to zero"                                         0 "$(field shop_restocks_after_the_clamp imported_as)"
check "…and the shop sets its own number"                true "$(ok_of shop_restocks_after_the_clamp)"
check "NOW it is sellable"                                 true "$(ok_of owned_is_sellable)"

echo
echo "--- 4. a real sale, on the other side of the cutover ---"
check "a customer buys it and the stock falls"             true "$(ok_of bought_and_stock_fell)"
check "the sale is captured for the shop that moved"       true "$(ok_of captured_for_the_moved_shop)"
check "the vendor sees their own sale"                     true "$(ok_of vendor_sees_own_sale_only)"
check "…and another shop sees none of it"                0 "$(field vendor_sees_own_sale_only lines_of_ours_they_can_see)"
check "access is derived from the register"                true "$(ok_of staff_access_is_derived_not_assumed)"
check "…so a stranger is not an owner"                   false "$(field staff_access_is_derived_not_assumed stranger_is_owner)"
check "it ships"                                           true "$(ok_of shipped)"

echo
echo "--- 5. one financial engine per order (FIN-02) ---"
check "the sale wrote ledger lines"                        true "$(ok_of one_financial_engine)"
check "…and the Dokan history wrote none for it"         0 "$(field one_financial_engine dokan_history_rows)"

echo
echo "--- 6. rollback, with a real order already against the product ---"
# The hard case: undoing the import must not take away a product the
# marketplace now runs and has already sold, or a real order is orphaned.
check "the rollback keeps what was sold"                   true "$(ok_of rollback_keeps_what_was_sold)"
check "…and says exactly that"                           rollback_kept_transferred "$(field rollback_keeps_what_was_sold code)"
check "the product is still there"                         true "$(field rollback_keeps_what_was_sold product_still_there)"
check "…still owned by the marketplace"                  marketplace "$(field rollback_keeps_what_was_sold ownership_after)"
check "the order line survived"                            1 "$(field rollback_keeps_what_was_sold order_lines_after)"
check "the ledger lines survived"                          3 "$(field rollback_keeps_what_was_sold ledger_lines_after)"

echo
echo "--- 7. the trial settings stay out of the package ---"
# The category map is one marketplace's commercial decision. It lives in this
# site's options and must never travel in a plugin.
check "the category map is an option, not a shipped file"  0 \
  "$(grep -rc 'tmc_dokan_category_map' "$(dirname "$0")/../dist/" 2>/dev/null | grep -v ':0$' | wc -l | tr -d ' ')"

echo
echo "=== pass=${pass} fail=${fail} ==="
echo "pass=${pass} fail=${fail}" > "$EV/99-summary.txt"
[ "$fail" -eq 0 ]
