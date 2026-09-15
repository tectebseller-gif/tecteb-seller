#!/usr/bin/env bash
# Returns and refunds on a real WooCommerce site: the quantity, the stock, the
# money, and the second refund that never happens.
#
#   tools/return-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/returns}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
ret() { wp eval-file return-state.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }

{
echo "=== returns and refunds, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version
} | tee "$EV/00-header.txt"

# A FRESH line every run, captured through the marketplace's own path. This
# script SPENDS what it touches — it returns units and refunds them, and
# neither can happen twice — so reusing yesterday's line made sixteen checks
# fail for a reason that had nothing to do with the code.
read -r TMC_A _REST <<< "$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
echo implode(" ", array_map(fn($x) => (int) $x->wcProductId, $p));')"
SEEDED="$(ret seed-line "$TMC_A" 3)"
echo "    $SEEDED"
ITEM="$(echo "$SEEDED" | field item)"
if [ -z "$ITEM" ] || [ "$ITEM" = "0" ]; then
  echo "could not capture a fresh order line; is the order gate open (trial mode)?" >&2
  exit 2
fi
LINE="$(ret show "$ITEM")"
echo "    $LINE"
QTY="$(echo "$LINE" | field quantity)"
WC_PRODUCT="$(echo "$LINE" | field wc_product)"
STOCK_BEFORE="$(wp eval "\$p = wc_get_product($WC_PRODUCT); echo \$p && \$p->get_manage_stock() ? (int) \$p->get_stock_quantity() : 'unmanaged';")"
echo "    line ${ITEM}: quantity=${QTY} wc_product=${WC_PRODUCT} stock=${STOCK_BEFORE}"

echo
echo "--- 1. a return is opened, and decides nothing by itself ---"
OPENED="$(ret open "$ITEM" 1 'کالای معیوب' | tee "$EV/01-open.txt")"
echo "    $OPENED"
RETURN_ID="$(echo "$OPENED" | field return)"
check "the vendor may open a return on their own line"    true "$(echo "$OPENED" | field ok)"
check "…and it starts as merely requested"                requested "$(ret show-return "$RETURN_ID" | field status)"
check "…with the undecided terms named, not guessed"      yes \
  "$(ret terms | grep -q 'return_window_undecided' && echo yes || echo no)"
check "nothing beyond the line's quantity may be returned" quantity_exceeds_returnable \
  "$(ret open "$ITEM" "$((QTY + 5))" 'بیش از حد' | field code)"

echo
echo "--- 2. the goods come back, and the shelf is corrected ---"
check "the manager approves"                              true "$(ret decide "$RETURN_ID" approved | field ok)"
RECEIVED="$(ret decide "$RETURN_ID" received 1 | tee "$EV/02-received.txt")"
echo "    $RECEIVED"
check "…the goods are received and restocked"             true "$(echo "$RECEIVED" | field ok)"
STOCK_AFTER="$(wp eval "\$p = wc_get_product($WC_PRODUCT); echo \$p && \$p->get_manage_stock() ? (int) \$p->get_stock_quantity() : 'unmanaged';")"
if [ "$STOCK_BEFORE" = "unmanaged" ]; then
  check "…(this product is not stock-managed, so nothing to correct)" unmanaged "$STOCK_AFTER"
else
  check "…WooCommerce's stock went up by exactly one"     "$((STOCK_BEFORE + 1))" "$STOCK_AFTER"
fi

echo
echo "--- 3. the money is reversed once, and the ledger keeps its history ---"
LINES_BEFORE="$(ret ledger-count | field lines)"
REFUND="$(ret refund "$RETURN_ID" | tee "$EV/03-refund.txt")"
echo "    $REFUND"
check "the refund is recorded"                            true "$(echo "$REFUND" | field ok)"
check "…and reverses a real amount"                       yes \
  "$(case "$(echo "$REFUND" | field refund_minor)" in 0|'') echo no;; *) echo yes;; esac)"
check "…the ledger reversal is this plugin's own work"   true "$(echo "$REFUND" | field did_ledger)"
check "…and it does NOT claim the money moved"           false "$(echo "$REFUND" | field did_money)"
check "…nor that a WooCommerce refund was created"       false "$(echo "$REFUND" | field did_wc_refund)"
check "the scope says plainly there is no gateway"       false "$(ret scope | field can_transfer_money)"
AGAIN="$(ret refund "$RETURN_ID")"
echo "    $AGAIN"
check "a SECOND refund on the same return is refused"     already_refunded "$(echo "$AGAIN" | field code)"
LINES_AFTER="$(ret ledger-count | field lines)"
check "reversing lines were ADDED, not edited"            yes \
  "$([ "$LINES_AFTER" -gt "$LINES_BEFORE" ] && echo yes || echo no)"
check "…and the reversal balances to zero"                0 "$(ret reversal-sum "$ITEM" "$RETURN_ID" | field sum)"
check "the original accrual is still there, unchanged"    true "$(ret accrual-intact "$ITEM" | field intact)"

echo
echo "--- 3b. two refunds at the same instant: exactly one writes ---"
# Two separate OS processes, started together and each pausing the same amount
# before the write, so they meet inside the refund rather than queueing. What
# decides the winner is the unique index, not the order they were launched in.
RACE_RETURN="$(ret open "$ITEM" 1 'رقابت هم‌زمان' | field return)"
ret decide "$RACE_RETURN" approved > /dev/null
ret decide "$RACE_RETURN" received > /dev/null
( ret refund "$RACE_RETURN" 1.0 > "$EV/race-a.txt" 2>&1 ) &
( ret refund "$RACE_RETURN" 1.0 > "$EV/race-b.txt" 2>&1 ) &
wait
echo "    A: $(cat "$EV/race-a.txt" | tr -d '\n')"
echo "    B: $(cat "$EV/race-b.txt" | tr -d '\n')"
WINNERS="$(cat "$EV/race-a.txt" "$EV/race-b.txt" | grep -c 'ok=true' || true)"
LOSERS="$(cat "$EV/race-a.txt" "$EV/race-b.txt" | grep -c 'code=already_refunded' || true)"
check "exactly one of the two wrote the refund"           1 "$WINNERS"
check "…and the other was told it was already done"       1 "$LOSERS"
check "one set of reversing lines, not two"               0 "$(ret reversal-sum "$ITEM" "$RACE_RETURN" | field sum)"
check "…and the return is refunded once"                  refunded "$(ret show-return "$RACE_RETURN" | field status)"

echo
echo "--- 4. an open return holds its share out of what may be withdrawn ---"
# On a line that is ELIGIBLE, not one already paid out: a paid share is
# reported as paid whatever happens next, so it could not show this rule.
# (The line refunded above went to `vendor_debt` for exactly that reason,
# which is the other half of the same design.)
FRESH="$(ret eligible-line | field item)"
if [ -z "$FRESH" ] || [ "$FRESH" = "0" ]; then
  check "…(no unpaid line on this fixture to demonstrate it)"        skipped skipped
else
  FRESH_VENDOR="$(ret show "$FRESH" | field vendor)"
  wp eval-file purchase-block-state.php settle "$FRESH" 1 > /dev/null
  wp eval '$s = get_option("tmc_settings"); $s["values"]["settlement_delay_days"] = 0; update_option("tmc_settings", $s);' > /dev/null
  BEFORE_RETURN="$(wp eval-file purchase-block-state.php balance "$FRESH_VENDOR")"
  echo "    $BEFORE_RETURN"
  check "the share is askable for before any return"       yes \
    "$([ "$(echo "$BEFORE_RETURN" | field eligible)" -gt 0 ] && echo yes || echo no)"

  SECOND_RETURN="$(ret open "$FRESH" 1 'مورد دوم' | field return)"
  DURING="$(wp eval-file purchase-block-state.php balance "$FRESH_VENDOR")"
  echo "    $DURING"
  check "…and is held back while a return is undecided"    yes \
    "$([ "$(echo "$DURING" | field awaiting_return)" -gt 0 ] && echo yes || echo no)"
  check "…without being lost: it is pending, not gone"     yes \
    "$([ "$(echo "$DURING" | field pending)" -gt 0 ] && echo yes || echo no)"

  ret decide "$SECOND_RETURN" rejected > /dev/null
  AFTER="$(wp eval-file purchase-block-state.php balance "$FRESH_VENDOR")"
  echo "    $AFTER"
  check "a rejected return gives the share straight back"  0 "$(echo "$AFTER" | field awaiting_return)"
  check "…and it is askable for again"                     yes \
    "$([ "$(echo "$AFTER" | field eligible)" -gt 0 ] && echo yes || echo no)"
fi

{
echo "=== final state ==="
ret show "$ITEM"
ret list "$ITEM"
} > "$EV/04-final-state.txt"

echo
printf 'returns and refunds — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
