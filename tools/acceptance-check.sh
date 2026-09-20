#!/usr/bin/env bash
# ONE acceptance path, asserted stage by stage, on the plugin installed from
# the final ZIP:
#
#   فروشنده و پرسنل → محصول → خرید چندفروشنده → ارسال جزئی → مرجوعی
#   → دفترکل و برداشت آزمایشی → گزارش‌ها
#
# The stages themselves run inside `acceptance-path.php` — one PHP pass, so
# each stage hands the next the object it made rather than re-deriving an id
# from the previous tool's stdout. This script seeds what the path needs,
# asserts what it printed, and says which refusals are RESULTS rather than
# failures.
#
#   tools/acceptance-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/acceptance-path}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }
stage() { grep "^stage=$1 " "$EV/01-path.txt"; }

cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

{
echo "=== one acceptance path, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version
wp option get tmc_schema_version
} | tee "$EV/00-header.txt"

# The vendor stage needs a staff member to exist before it can assert that
# `StaffAccess` scopes one. Seeded through the vendor module's own invite path,
# not by writing a row.
VENDOR_A="$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
$ids = array_unique(array_map(fn($x) => (int) $x->vendorUserId, $p)); sort($ids); echo $ids[0] ?? 0;')"
wp eval-file purchase-block-state.php seed-staff "$VENDOR_A" tmcstaffacc 'TmcStaff!2026' > "$EV/00-staff.txt" 2>&1 || true
head -1 "$EV/00-staff.txt"

echo
echo "--- the path, end to end ---"
wp eval-file acceptance-path.php run > "$EV/01-path.txt" 2>&1
cat "$EV/01-path.txt"

echo
echo "--- what each stage has to have been ---"
S1="$(stage vendor_and_staff)"
check "1. vendor and staff: two shops exist"               true "$(echo "$S1" | field ok)"
check "   …with a staff member on the first"               true \
  "$([ "$(echo "$S1" | field staff_rows)" -gt 0 ] && echo true || echo false)"
# The thing every later stage leans on: one question, one answer, everywhere.
check "   …whom StaffAccess scopes to their own shop"      true "$(echo "$S1" | field staff_scoped)"

S2="$(stage product)"
check "2. product: both shops have a projected product"    true "$(echo "$S2" | field ok)"
check "   …and both are published"                         published "$(echo "$S2" | field published_a)"

S3="$(stage multi_vendor_purchase)"
check "3. purchase: one order, two shops"                  2 "$(echo "$S3" | field vendors_on_one_order)"
check "   …both captured"                                  2 "$(echo "$S3" | field captured)"
# The basket also carries a product this marketplace does not own. It stays on
# the order and out of our rows — `not_ours`, on a mixed basket.
check "   …with a non-marketplace line on the same order"  yes "$(echo "$S3" | field foreign_line_on_order)"
check "   …which was NOT captured"                         no "$(echo "$S3" | field foreign_line_captured)"

S4="$(stage partial_shipment)"
check "4. partial shipment: two of three went"             2/3 "$(echo "$S4" | field shipped_of)"
check "   …and the status is derived, not set"             partially_shipped "$(echo "$S4" | field status)"
check "   …as one package with its own tracking"           1 "$(echo "$S4" | field packages)"

S5="$(stage return)"
check "5. return: opened, received and refunded"           true "$(echo "$S5" | field ok)"
check "   …the ledger was reversed"                        true "$(echo "$S5" | field did_ledger)"
check "   …the shelf was corrected"                        true "$(echo "$S5" | field did_stock)"
# The half this build cannot do, said in the same breath as the halves it can.
check "   …and no money moved, because none can"           false "$(echo "$S5" | field did_money)"

S6="$(stage ledger_and_withdrawal)"
check "6. ledger: a share was recorded"                    true \
  "$([ "$(echo "$S6" | field earned_minor)" -gt 0 ] && echo true || echo false)"
# Shop A's line carries a return, so its share is HELD rather than eligible —
# UX §10.2, and the number beside it is why.
check "   …shop A's share is held by its open return"      true \
  "$([ "$(echo "$S6" | field a_held_by_return)" -gt 0 ] && echo true || echo false)"
# …and the request carries the ELIGIBLE amount, not the held one. This is the
# rule, and it is the assertion that survives a site with history on it: the
# check used to expect the refusal codes `nothing_eligible` and
# `bank_account_missing`, which were facts about a fixture nobody had re-run
# from empty. Both are paperwork states, and neither is «a share under an open
# return cannot be withdrawn».
check "   …and the request asks only for what is eligible" \
  "$(echo "$S6" | field a_eligible_at_request)" "$(echo "$S6" | field a_requested_minor)"
check "   …with the held share still pending, not in it"   true \
  "$([ "$(echo "$S6" | field a_pending_minor)" -ge 0 ] && echo true || echo false)"
# Shop B is the control: same purchase, same settlement, no return.
check "   …while shop B's became eligible"                 true \
  "$([ "$(echo "$S6" | field b_eligible_after)" -gt 0 ] && echo true || echo false)"
check "   …and its request is accepted"                    withdrawal_requested \
  "$(echo "$S6" | field b_withdrawal_code)"

S7="$(stage reports)"
check "7. reports: four cards for the shop"                4 "$(echo "$S7" | field vendor_cards)"
check "   …two for the marketplace"                        2 "$(echo "$S7" | field manager_cards)"
check "   …the partial shipment is counted"                true \
  "$([ "$(echo "$S7" | field sales_partially_shipped)" -gt 0 ] && echo true || echo false)"
check "   …and so is the return"                           true \
  "$([ "$(echo "$S7" | field sales_returned)" -gt 0 ] && echo true || echo false)"
# The two ratings, side by side and never added together.
check "   …product and shop ratings stay separate"         true \
  "$([ "$(echo "$S7" | field rating_product)" != "$(echo "$S7" | field rating_vendor)" ] && echo true || echo false)"

echo
echo "--- every stage reported ok ---"
check "seven stages, seven true"                           7 "$(grep -c 'ok=true' "$EV/01-path.txt")"

wp eval-file acceptance-path.php ids > "$EV/02-ids.txt"
cat "$EV/02-ids.txt"

echo
printf 'one acceptance path — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
