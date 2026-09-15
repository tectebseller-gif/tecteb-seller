#!/usr/bin/env bash
# Can a customer still pay for an unpaid order after the marketplace stops —
# and after this plugin is switched off entirely?
#
# The old evidence opened the link that had been e-mailed, and rotating the
# order key kills that one. It never asked what a customer actually does: open
# «حساب من ← سفارش‌ها» and press «پرداخت», which hands them a FRESH link built
# from the order's CURRENT key. The owner named that gap — «تغییر order_key
# به‌تنهایی اثبات توقف پرداخت نیست» — and measuring it showed the payment form.
#
# So this script walks through the customer's own account page, four times:
# selling on, selling stopped, plugin DEACTIVATED, and after a release that
# does NOT reopen the shop.
#
#   tools/pay-stop-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/pay-stop}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
BROWSER="${BROWSER:-/home/user/tecteb-seller/tools/browser}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-64s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-64s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
state() { wp eval-file purchase-block-state.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }
# One phase of the browser probe: signs in as the customer, takes whatever pay
# link the account page offers RIGHT NOW, and follows it.
probe() { # <phase> <expect> <order>
  (cd "$BROWSER" && SITE="$SITE" TMC_ORDER="$3" TMC_PHASE="$1" TMC_EXPECT="$2" TMC_OUT="$EV" \
    node check-pay-after-deactivation.mjs 2>&1) | tee "$EV/probe-$1.txt" | grep -E '^(ok|FAIL|    )'
  grep -q '0 failures' "$EV/probe-$1.txt" && echo pass || echo fail
}

{
echo "=== can a customer still pay after the marketplace stops, and after we are gone? ==="
wp plugin list --fields=name,status,version
} | tee "$EV/00-header.txt"

state resume > /dev/null
CUSTOMER="$(wp eval-file safe-stop-order.php customer | field customer)"
read -r TMC_A _REST <<< "$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
echo implode(" ", array_map(fn($x) => (int) $x->wcProductId, $p));')"
CREATED="$(wp eval-file safe-stop-order.php create-for "$CUSTOMER" "$TMC_A" | tail -1)"
ORDER="$(echo "$CREATED" | grep -oP 'order=\K[0-9]+')"
STOCK_START="$(wp eval "echo (int) wc_get_product($TMC_A)->get_stock_quantity();")"
echo "    customer ${CUSTOMER} · order ${ORDER} · product ${TMC_A} · stock ${STOCK_START}"
echo "$CREATED" > "$EV/01-order.txt"

echo
echo "--- 1. selling is on: the account page pays ---"
check "the fresh link from «حساب من» takes money"            pass "$(probe before-stop payable "$ORDER" | tail -1)"

echo
echo "--- 2. the marketplace stops ---"
STOPPED="$(state stop | tee "$EV/02-stop.txt")"
echo "    $STOPPED"
ORDER_STATE="$(state order-status "$ORDER")"
echo "    $ORDER_STATE"
check "the order is held, not cancelled"                     on-hold "$(echo "$ORDER_STATE" | field status)"
check "…and remembers the status to go back to"              pending "$(echo "$ORDER_STATE" | field held_from)"
check "…keeping its items"                                   1 "$(echo "$ORDER_STATE" | field items)"
check "…and its total"                                       1200000 "$(echo "$ORDER_STATE" | field total)"
check "the fresh link is refused while we run"               pass "$(probe after-stop refused "$ORDER" | tail -1)"
STOCK_HELD="$(wp eval "echo (int) wc_get_product($TMC_A)->get_stock_quantity();")"
echo "    stock while held: ${STOCK_HELD} (was ${STOCK_START})"

echo
echo "--- 3. …and with this plugin DEACTIVATED, which is the whole question ---"
wp plugin deactivate tecteb-marketplace-core > /dev/null
check "the fresh link is STILL refused with no code of ours" pass "$(probe after-deactivation refused "$ORDER" | tail -1)"
check "…because WooCommerce itself says so"                  false \
  "$(wp eval "echo wc_get_order($ORDER)->needs_payment() ? 'true' : 'false';")"
check "the order still exists, with its items"               1 \
  "$(wp eval "echo count(wc_get_order($ORDER)->get_items());")"
check "…and its total is untouched"                          1200000 \
  "$(wp eval "echo (int) round((float) wc_get_order($ORDER)->get_total());")"
wp plugin activate tecteb-marketplace-core > /dev/null

echo
echo "--- 4. the release is its own act: the shop stays SHUT ---"
RELEASED="$(state release-orders | tee "$EV/03-release.txt")"
echo "    $RELEASED"
check "the release reports success"                          true "$(echo "$RELEASED" | field ok)"
check "…and the marketplace is still stopped"                true "$(echo "$RELEASED" | field stopped)"
check "the product is still off the shelf"                   draft "$(wp post get "$TMC_A" --field=post_status)"
AFTER="$(state order-status "$ORDER")"
echo "    $AFTER"
check "the order went back to the status it had"             pending "$(echo "$AFTER" | field status)"
check "…and the hold note is gone"                           - "$(echo "$AFTER" | field held_from)"
STOCK_BACK="$(wp eval "echo (int) wc_get_product($TMC_A)->get_stock_quantity();")"
check "the stock WooCommerce moved for the hold came back"   "$STOCK_START" "$STOCK_BACK"

echo
echo "--- 5. and only then, if the manager wants, selling reopens ---"
state resume > "$EV/04-resume.txt"
check "resuming is a separate decision"                      publish "$(wp post get "$TMC_A" --field=post_status)"
check "…and the customer can pay again"                      pass "$(probe after-resume payable "$ORDER" | tail -1)"

{
echo "=== stock movement caused by holding an order ==="
echo "before the hold: ${STOCK_START}"
echo "while held:      ${STOCK_HELD}"
echo "after release:   ${STOCK_BACK}"
echo
echo "WooCommerce reduces stock on the way into on-hold and increases it on the"
echo "way out (wc_maybe_reduce_stock_levels is hooked to"
echo "woocommerce_order_status_on-hold). That is WooCommerce acting on its own"
echo "rules in response to a status, not this plugin writing stock — and the"
echo "release puts it back."
} > "$EV/05-stock.txt"

echo
printf 'payment stop after deactivation — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
