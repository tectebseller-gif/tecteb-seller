#!/usr/bin/env bash
# Everything the owner asked to be closed in this delivery, asked of a running
# shop rather than of the code — on a DISPOSABLE WordPress with WooCommerce and
# a REALLY ACTIVE Dokan.
#
# Four doors into a purchase, and one door out of one:
#   1. the classic add-to-cart form
#   2. a basket that was already full when selling stopped
#   3. the Store API, which is what the block cart and checkout speak
#   4. the pay link of an order that was never paid
# …and in every one of them, the shop's own product and Dokan's vendor's
# product must behave exactly as if this plugin were not installed.
#
#   tools/safe-stop-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/safe-stop}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
# The ids are DISCOVERED, not hard-coded: every re-seed makes new WooCommerce
# posts, and a stale constant here reports a guard failure that is really a
# fixture that moved.
wp0() { (cd "${WPROOT:-/home/user/wp-disposable}" && "${PHPBIN:-/opt/php81/bin/php}" "${WPCLI:-/usr/local/bin/wp}" --allow-root "$@" 2>/dev/null); }
discover() { wp0 eval "\$c = Tecteb\\Marketplace\\Infrastructure\\WordPress\\Bootstrap::container();
\$p = \$c->get(Tecteb\\Marketplace\\Modules\\Product\\Application\\ProductRepositoryInterface::class)->projected();
echo implode(' ', array_map(fn(\$x) => (int) \$x->wcProductId, \$p));"; }
read -r FOUND_A FOUND_B _REST <<< "$(discover)"
TMC_A="${TMC_A:-${FOUND_A}}"
TMC_B="${TMC_B:-${FOUND_B}}"
SHOP="${SHOP:-$(wp0 eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} p WHERE p.post_type = \"product\" AND p.post_status = \"publish\" AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = \"_tmc_product_id\") AND p.post_author = 1 ORDER BY p.ID ASC LIMIT 1");')}"
DOKAN="${DOKAN:-$(wp0 eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = \"_dokan_seeded\" LIMIT 1");')}"
SCRATCH="${SCRATCH:-/tmp/claude-0/safe-stop}"
PRODUCT_COUNT="${PRODUCT_COUNT:-5}"   # 3 marketplace + 1 shop's own + 1 Dokan vendor's

mkdir -p "$EV" "$SCRATCH"
EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { # <name> <expected> <actual>
  if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi
}
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
state() { wp eval-file purchase-block-state.php "$@"; }

# --- classic: can a guest put this product in an empty basket? --------------
classic_add() {
  local id="$1" jar="$SCRATCH/classic-$1-$2.txt"
  rm -f "$jar"
  curl -s -c "$jar" -b "$jar" -L "$SITE/?add-to-cart=${id}" -o /dev/null
  curl -s -c "$jar" -b "$jar" -L "$SITE/cart/" \
    | grep -qE 'woocommerce-cart-form__cart-item|wc-block-cart-items__row' && echo yes || echo no
}

# --- Store API: the path the block cart and checkout actually use -----------
store_api_add() {
  local id="$1" jar="$SCRATCH/api-$1-$2.txt" nonce
  rm -f "$jar"
  nonce="$(curl -s -c "$jar" -b "$jar" -D - "$SITE/wp-json/wc/store/v1/cart" -o /dev/null \
    | grep -i '^nonce:' | awk '{print $2}' | tr -d '\r')"
  curl -s -c "$jar" -b "$jar" -H "Nonce: ${nonce}" -H 'Content-Type: application/json' \
    -X POST "$SITE/wp-json/wc/store/v1/cart/add-item" -d "{\"id\":${id},\"quantity\":1}" \
    > "$SCRATCH/api-response-$1-$2.json"
  python3 - "$SCRATCH/api-response-$1-$2.json" <<'PY'
import json, sys
with open(sys.argv[1], encoding='utf-8') as fh:
    body = json.load(fh)
# A successful add answers with the cart; a refusal answers with a code.
print('yes' if 'items' in body else 'no:' + str(body.get('code', 'unknown')))
PY
}

# --- a basket that was full BEFORE selling stopped --------------------------
prefilled_cart_survives() {  # fills, then stops, then looks
  local jar="$SCRATCH/prefilled.txt"
  rm -f "$jar"
  curl -s -c "$jar" -b "$jar" -L "$SITE/?add-to-cart=${TMC_A}" -o /dev/null
  curl -s -c "$jar" -b "$jar" -L "$SITE/?add-to-cart=${SHOP}" -o /dev/null
  local before
  before="$(curl -s -c "$jar" -b "$jar" -L "$SITE/cart/" | grep -cE 'woocommerce-cart-form__cart-item')"
  echo "rows_before=${before}"
  state stop 'manager_stopped' > "$EV/02-stop.txt"
  local after
  after="$(curl -s -c "$jar" -b "$jar" -L "$SITE/cart/" | grep -cE 'woocommerce-cart-form__cart-item')"
  echo "rows_after=${after}"
}

{
echo "=== safe stop, carts, Store API and the pay link ==="
echo "site: $(wp plugin list --fields=name,status,version | tr '\n' ' ')"
echo

echo "--- 0. selling is on: everything sells ---"
state resume > /dev/null
} | tee "$EV/00-header.txt"

selling_on_tmc="$(classic_add "$TMC_A" a)"
selling_on_shop="$(classic_add "$SHOP" a)"
selling_on_dokan="$(classic_add "$DOKAN" a)"
api_on_tmc="$(store_api_add "$TMC_A" a)"
api_on_dokan="$(store_api_add "$DOKAN" a)"
check "classic: a marketplace product sells"            yes "$selling_on_tmc"
check "classic: the shop's own product sells"           yes "$selling_on_shop"
check "classic: the Dokan vendor's product sells"       yes "$selling_on_dokan"
check "store api: a marketplace product sells"          yes "$api_on_tmc"
check "store api: the Dokan vendor's product sells"     yes "$api_on_dokan"

echo
echo "--- 1. a basket filled before the stop ---"
prefilled="$(prefilled_cart_survives)"
rows_before="$(echo "$prefilled" | grep rows_before | cut -d= -f2)"
rows_after="$(echo "$prefilled" | grep rows_after | cut -d= -f2)"
check "the basket held both products before the stop"   2 "$rows_before"
check "…and the marketplace one is gone after it"       1 "$rows_after"

echo
echo "--- 2. with selling stopped, every door is shut for us and open for them ---"
stopped_tmc="$(classic_add "$TMC_A" b)"
stopped_shop="$(classic_add "$SHOP" b)"
stopped_dokan="$(classic_add "$DOKAN" b)"
stopped_api_tmc="$(store_api_add "$TMC_A" b)"
stopped_api_shop="$(store_api_add "$SHOP" b)"
stopped_api_dokan="$(store_api_add "$DOKAN" b)"
check "classic: the marketplace product is refused"     no  "$stopped_tmc"
check "classic: the shop's own product still sells"     yes "$stopped_shop"
check "classic: the Dokan vendor's product still sells" yes "$stopped_dokan"
check "store api: the marketplace product is refused"   "no:woocommerce_rest_product_not_purchasable" "$stopped_api_tmc"
check "store api: the shop's own product still sells"   yes "$stopped_api_shop"
check "store api: the Dokan vendor's still sells"       yes "$stopped_api_dokan"
api_message="$(python3 -c "
import json
with open('$SCRATCH/api-response-${TMC_A}-b.json', encoding='utf-8') as fh:
    print(json.load(fh).get('message', ''))
")"
echo "    store api said: ${api_message}"
check "store api: …in the marketplace's own short words" yes \
  "$(case "$api_message" in *'برای فروش در دسترس نیست'*) echo yes;; *) echo no;; esac)"
check "store api: …and says nothing about the rate or the decisions" yes \
  "$(case "$api_message" in *DEC-*|*کمیسیون*|*دفترکل*) echo no;; *) echo yes;; esac)"

echo
echo "--- 3. nothing was deleted ---"
state state > "$EV/03-after-stop.txt"
still_there="$(wp post list --post_type=product --post_status=any --format=count)"
tmc_rows="$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_products");')"
check "every product post still exists"                 "$PRODUCT_COUNT" "$still_there"
check "every marketplace row still exists"              3 "$tmc_rows"
check "and the marketplace remembers it is stopped"     true \
  "$(wp option get tmc_storefront_stop --format=json >/dev/null 2>&1 && echo true || echo false)"

echo
echo "--- 4. resuming brings back only what qualifies ---"
state resume > "$EV/04-resume.txt"
resumed_tmc="$(classic_add "$TMC_A" c)"
check "the marketplace product is back on sale"         yes "$resumed_tmc"
cat "$EV/04-resume.txt"

echo
echo "--- 5. the pay link of an order that was never paid ---"
# An unpaid order holding a marketplace line, then the marketplace stops.
PAY="$(wp eval-file safe-stop-order.php create "$TMC_A" "$SHOP" | tail -1)"
ORDER_ID="$(echo "$PAY" | grep -oP 'order=\K[0-9]+')"
PAY_URL="$(echo "$PAY" | grep -oP 'pay_url=\K\S+')"
echo "    unpaid order ${ORDER_ID}"
payable_before="$(wp eval-file safe-stop-order.php payable "$ORDER_ID" | tail -1)"
check "before the stop the order can be paid"           needs_payment=true "$payable_before"
state stop 'manager_stopped' > "$EV/05-stop-again.txt"
payable_after="$(wp eval-file safe-stop-order.php payable "$ORDER_ID" | tail -1)"
check "…and after it the pay link refuses"              needs_payment=false "$payable_after"
pay_page="$(curl -s -L "$PAY_URL" | grep -c 'پرداخت این سفارش فعلاً ممکن نیست' || true)"
check "…and the page says so in one plain sentence"     1 "$pay_page"

# An order of the shop's own goods only must stay payable throughout.
SHOP_ONLY="$(wp eval-file safe-stop-order.php create "$SHOP" "$DOKAN" | tail -1)"
SHOP_ORDER="$(echo "$SHOP_ONLY" | grep -oP 'order=\K[0-9]+')"
shop_payable="$(wp eval-file safe-stop-order.php payable "$SHOP_ORDER" | tail -1)"
check "an order with no marketplace item is untouched"  needs_payment=true "$shop_payable"
state resume > /dev/null

echo
printf 'safe stop — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
