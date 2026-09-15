#!/usr/bin/env bash
# What a stop that did NOT finish leaves behind — and what survives the plugin
# being switched off. On a DISPOSABLE WordPress with WooCommerce and a really
# active Dokan.
#
# The owner's instruction for this round, in three parts:
#
#   «اگر حتی یک محصول از فروش خارج نشد، عملیات مدیر نباید موفق گزارش شود»
#   «ایمنی پس از غیرفعال‌سازی و بازگشت را با ایجاد عمدی خطای ذخیره، سبد قبلی و
#    لینک پرداخت سفارش پرداخت‌نشده بررسی کن»
#   «پرچمی که فقط افزونهٔ فعال می‌خواند کافی نیست»
#
# So the storage failure is injected from OUTSIDE the plugin (an mu-plugin that
# refuses one product's status change, tools/stop-failure-probe.php), and every
# measurement that matters is taken with the plugin DEACTIVATED — because that
# is the moment a flag of ours is worth nothing.
#
#   tools/stop-failure-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/stop-failure}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
SCRATCH="${SCRATCH:-/tmp/claude-0/stop-failure}"
PROBE="$WPROOT/wp-content/mu-plugins/tmc-stop-failure-probe.php"

mkdir -p "$EV" "$SCRATCH" "$WPROOT/wp-content/mu-plugins"
EV="$(cd "$EV" && pwd)"
PROBE_SRC="$(cd "$(dirname "$0")" && pwd)/stop-failure-probe.php"
cp "$PROBE_SRC" "$PROBE"

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
state() { wp eval-file purchase-block-state.php "$@"; }
status_of() { wp post get "$1" --field=post_status; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }

# Ids are discovered, never hard-coded: a re-seed makes new posts and a stale
# constant here reports a guard failure that is really a fixture that moved.
read -r TMC_A TMC_B TMC_C <<< "$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
echo implode(" ", array_map(fn($x) => (int) $x->wcProductId, $p));')"
read -r TMC_ROW_B <<< "$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
echo (int) $p[1]->id;')"
SHOP="$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} p WHERE p.post_type = \"product\" AND p.post_status = \"publish\" AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = \"_tmc_product_id\") AND p.post_author = 1 ORDER BY p.ID ASC LIMIT 1");')"
DOKAN="$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = \"_dokan_seeded\" LIMIT 1");')"

sells() {  # <product> <tag> — a guest, an empty basket, the classic form
  local id="$1" jar="$SCRATCH/jar-$1-$2.txt"; rm -f "$jar"
  curl -s -c "$jar" -b "$jar" -L "$SITE/?add-to-cart=${id}" -o /dev/null
  curl -s -c "$jar" -b "$jar" -L "$SITE/cart/" | grep -qE 'woocommerce-cart-form__cart-item' && echo yes || echo no
}
pay_page_takes_money() {  # <pay-url> — is the payment FORM there?
  curl -s -L "$1" | grep -qE 'id="order_review"|name="woocommerce_pay"' && echo yes || echo no
}
arm()    { wp option update tmc_probe_block_product "$1" > /dev/null; }
disarm() { wp option delete tmc_probe_block_product   > /dev/null; }

{
echo "=== a stop that did not finish, and what survives deactivation ==="
wp plugin list --fields=name,status,version
echo "marketplace products: $TMC_A $TMC_B $TMC_C · shop: $SHOP · dokan: $DOKAN"
echo "the injected failure blocks the status write of product $TMC_B (row $TMC_ROW_B)"
} | tee "$EV/00-header.txt"

echo
echo "--- 0. selling is on ---"
disarm
state resume > "$EV/01-resume.txt"
check "the marketplace product sells"                     yes "$(sells "$TMC_A" a)"
check "…and so does the one about to be blocked"          yes "$(sells "$TMC_B" a)"

echo
echo "--- 1. one product will not leave the shop ---"
arm "$TMC_B"
STOP_OUT="$(state stop manager_stopped | tee "$EV/02-stop-with-failure.txt")"
echo "    $STOP_OUT"
check "the manager is told the stop FAILED"               false "$(echo "$STOP_OUT" | field ok)"
check "…with a code that names the incompleteness"        storefront_stop_incomplete "$(echo "$STOP_OUT" | field code)"
check "…and the product that is still on sale, by id"     "$TMC_ROW_B" "$(echo "$STOP_OUT" | field stuck)"
check "the two that could leave, left"                    2 "$(echo "$STOP_OUT" | field withdrawn)"
check "the blocked product is still published"            publish "$(status_of "$TMC_B")"
check "…and the others are drafts"                        draft "$(status_of "$TMC_A")"
check "the marketplace still records that it is stopped"  true "$(echo "$STOP_OUT" | field stopped)"
STUCK_OUT="$(state stuck)"
check "…and writes down what it could not close"          "$TMC_ROW_B" "$(echo "$STUCK_OUT" | field products)"

echo
echo "--- 2. with the plugin ACTIVE the guard still covers the stuck product ---"
check "the stuck product cannot be bought while we run"   no "$(sells "$TMC_B" b)"

echo
echo "--- 3. …and with the plugin GONE, it can. This is the hazard. ---"
wp plugin deactivate tecteb-marketplace-core > /dev/null
check "the drafted product is refused with no code of ours" no "$(sells "$TMC_A" c)"
check "the STUCK product is purchasable — measured, not assumed" yes "$(sells "$TMC_B" c)"
check "the shop's own product is untouched"               yes "$(sells "$SHOP" c)"
check "the Dokan vendor's product is untouched"           yes "$(sells "$DOKAN" c)"
wp plugin activate tecteb-marketplace-core > /dev/null
check "reactivation does not put the drafted one back"    draft "$(status_of "$TMC_A")"
check "…and the warning about the stuck one is still there" "$TMC_ROW_B" "$(state stuck | field products)"

echo
echo "--- 4. the same stop, with the failure removed, finishes ---"
disarm
RETRY="$(state stop manager_stopped | tee "$EV/03-stop-retry.txt")"
echo "    $RETRY"
check "the retry is reported as a success"                true "$(echo "$RETRY" | field ok)"
check "…and the product finally left the shop"            draft "$(status_of "$TMC_B")"
check "…and nothing is left written down as stuck"        - "$(state stuck | field products)"

echo
echo "--- 5. a basket filled before the stop, looked at AFTER deactivation ---"
JAR="$SCRATCH/prefilled.txt"; rm -f "$JAR"
state resume > /dev/null
curl -s -c "$JAR" -b "$JAR" -L "$SITE/?add-to-cart=${TMC_A}" -o /dev/null
curl -s -c "$JAR" -b "$JAR" -L "$SITE/?add-to-cart=${SHOP}" -o /dev/null
BEFORE="$(curl -s -c "$JAR" -b "$JAR" -L "$SITE/cart/" | grep -cE 'woocommerce-cart-form__cart-item')"
check "the basket held both products"                     2 "$BEFORE"
wp plugin deactivate tecteb-marketplace-core > /dev/null   # deactivation stops selling, then we are gone
AFTER="$(curl -s -c "$JAR" -b "$JAR" -L "$SITE/cart/" | grep -cE 'woocommerce-cart-form__cart-item')"
check "with the plugin gone the marketplace row dropped out" 1 "$AFTER"
wp plugin activate tecteb-marketplace-core > /dev/null
state resume > /dev/null

echo
echo "--- 6. the pay link of an unpaid order, looked at AFTER deactivation ---"
CREATED="$(wp eval-file safe-stop-order.php create "$TMC_A" | tail -1)"
ORDER_ID="$(echo "$CREATED" | grep -oP 'order=\K[0-9]+')"
PAY_URL="$(echo "$CREATED" | grep -oP 'pay_url=\K\S+')"
echo "    unpaid order ${ORDER_ID}"
echo "$CREATED" > "$EV/04-unpaid-order.txt"
# How many unpaid marketplace orders exist right now — including any left by an
# earlier run. The stop must retire exactly these, no more and no fewer.
OPEN_BEFORE="$(state payable | field count)"
check "the marketplace sees an unpaid order it must close" yes \
  "$(state payable | field orders | tr ',' '\n' | grep -qx "$ORDER_ID" && echo yes || echo no)"
check "the mailed link takes money before the stop"       yes "$(pay_page_takes_money "$PAY_URL")"
STOP2="$(state stop manager_stopped | tee -a "$EV/04-unpaid-order.txt")"
check "the stop retires every open pay link, and only those" "$OPEN_BEFORE" "$(echo "$STOP2" | field orders_held)"
check "…and none of them was left taking money"           0 "$(state payable | field count)"
check "…and the mailed link stops taking money"           no "$(pay_page_takes_money "$PAY_URL")"
wp plugin deactivate tecteb-marketplace-core > /dev/null
check "…and it STILL refuses with the plugin deactivated" no "$(pay_page_takes_money "$PAY_URL")"
SHOP_ORDER="$(wp eval-file safe-stop-order.php create "$SHOP" | tail -1)"
SHOP_PAY="$(echo "$SHOP_ORDER" | grep -oP 'pay_url=\K\S+')"
check "an order of the shop's own goods is still payable" yes "$(pay_page_takes_money "$SHOP_PAY")"
wp plugin activate tecteb-marketplace-core > /dev/null
check "reactivation alone does not hand the link back"    no "$(pay_page_takes_money "$PAY_URL")"
RESUMED="$(state resume | tee "$EV/05-resume.txt")"
check "resuming hands back exactly the links it retired"  "$OPEN_BEFORE" "$(echo "$RESUMED" | field orders_released)"
check "…and it is the SAME link, so the customer's mail works" yes "$(pay_page_takes_money "$PAY_URL")"
check "the order was never cancelled or emptied"          "pending items=1" \
  "$(wp eval "\$o = wc_get_order($ORDER_ID); echo \$o->get_status() . ' items=' . count(\$o->get_items());")"

{
echo "=== what the marketplace says afterwards ==="
state state
state stuck
state payable
} > "$EV/06-final-state.txt"

disarm
echo
printf 'stop failure and life after deactivation — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
