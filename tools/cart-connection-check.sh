#!/usr/bin/env bash
# Do a vendor's coupon and a wholesale ladder actually change what a shopper
# pays — in a real WooCommerce cart, not in a service test?
#
# The previous delivery said «ساخته‌شده» about both and the owner asked for the
# connection to be reported apart from the service existing. This measures the
# connection: real carts, real totals, real session.
#
#   tools/cart-connection-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/cart-connection}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
SCRATCH="${SCRATCH:-/tmp/claude-0/cart}"
mkdir -p "$EV" "$SCRATCH"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
market() { wp eval-file marketplace-state.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }
# The total WooCommerce actually shows, read the way a person reads it: strip
# the markup first. A regex over the raw HTML finds nothing, because the
# currency symbol sits inside the number's own markup — the first version of
# this script reported an empty total while the discount line was plainly on
# the page.
cart_total() { # <jar>
  curl -s -c "$1" -b "$1" -L "$SITE/cart/" -o "$SCRATCH/total.html"
  python3 "$(dirname "$0")/cart-total.py" "$SCRATCH/total.html" order-total
}

{
echo "=== do a coupon and a wholesale ladder reach a real cart? ==="
wp plugin list --fields=name,status,version
} | tee "$EV/00-header.txt"

wp eval-file purchase-block-state.php resume > /dev/null
read -r TMC_A _REST <<< "$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
echo implode(" ", array_map(fn($x) => (int) $x->wcProductId, $p));')"
SHOP="$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} p WHERE p.post_type = \"product\" AND p.post_status = \"publish\" AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = \"_tmc_product_id\") AND p.post_author = 1 ORDER BY p.ID ASC LIMIT 1");')"
SEED="$(market seed "$TMC_A")"
echo "    $SEED"
VENDOR="$(echo "$SEED" | field vendor)"
CODE="$(echo "$SEED" | field code)"
TMC_ROW="$(echo "$SEED" | field product)"
echo "    marketplace product ${TMC_A} (row ${TMC_ROW}, vendor ${VENDOR}) · shop product ${SHOP} · code ${CODE}"

echo
echo "--- 1. a guest basket, no code ---"
JAR="$SCRATCH/plain.txt"; rm -f "$JAR"
curl -s -c "$JAR" -b "$JAR" -L "$SITE/?add-to-cart=${TMC_A}&quantity=2" -o /dev/null
PLAIN="$(cart_total "$JAR")"
echo "    total without a code: ${PLAIN}"
check "the basket has a total to start from"              yes \
  "$(case "$PLAIN" in ''|0) echo no;; *) echo yes;; esac)"

echo
echo "--- 2. the vendor's own code, through WooCommerce's own coupon box ---"
curl -s -c "$JAR" -b "$JAR" -L "$SITE/cart/" -o "$SCRATCH/cart.html"
NONCE="$(grep -oE 'name="woocommerce-cart-nonce" value="[^"]+"' "$SCRATCH/cart.html" | grep -oE 'value="[^"]+"' | cut -d'"' -f2 | head -1)"
curl -s -c "$JAR" -b "$JAR" -L "$SITE/cart/" \
  --data-urlencode "coupon_code=${CODE}" \
  --data-urlencode "apply_coupon=اعمال" \
  --data-urlencode "woocommerce-cart-nonce=${NONCE}" -o "$SCRATCH/applied.html"
DISCOUNTED="$(cart_total "$JAR")"
echo "    total with the code:    ${DISCOUNTED}"
check "the code actually reduced the total"               yes \
  "$([ -n "$DISCOUNTED" ] && [ -n "$PLAIN" ] && [ "$DISCOUNTED" -lt "$PLAIN" ] && echo yes || echo no)"
check "…and WooCommerce carries it as a coupon, not a fee"  yes \
  "$(grep -qi "coupon-${CODE}" "$SCRATCH/applied.html" && echo yes || echo no)"
check "…so the shopper is offered WooCommerce's own remove link" yes \
  "$(grep -qi "remove_coupon=${CODE}" "$SCRATCH/applied.html" && echo yes || echo no)"
cp "$SCRATCH/applied.html" "$EV/01-cart-with-coupon.html"

echo
echo "--- 3. the same code does nothing to another shop's goods ---"
JAR2="$SCRATCH/other.txt"; rm -f "$JAR2"
curl -s -c "$JAR2" -b "$JAR2" -L "$SITE/?add-to-cart=${SHOP}&quantity=1" -o /dev/null
SHOP_PLAIN="$(cart_total "$JAR2")"
curl -s -c "$JAR2" -b "$JAR2" -L "$SITE/cart/" -o "$SCRATCH/cart2.html"
NONCE2="$(grep -oE 'name="woocommerce-cart-nonce" value="[^"]+"' "$SCRATCH/cart2.html" | grep -oE 'value="[^"]+"' | cut -d'"' -f2 | head -1)"
curl -s -c "$JAR2" -b "$JAR2" -L "$SITE/cart/" \
  --data-urlencode "coupon_code=${CODE}" \
  --data-urlencode "apply_coupon=اعمال" \
  --data-urlencode "woocommerce-cart-nonce=${NONCE2}" -o "$SCRATCH/applied2.html"
SHOP_AFTER="$(cart_total "$JAR2")"
echo "    the shop's own product: ${SHOP_PLAIN} → ${SHOP_AFTER}"
check "a marketplace code leaves the shop's own goods alone" "$SHOP_PLAIN" "$SHOP_AFTER"
check "…and says why, instead of silently doing nothing"   yes \
  "$(grep -qE 'متعلق به فروشگاه دیگری|چنین کدی وجود ندارد' "$SCRATCH/applied2.html" && echo yes || echo no)"

echo
echo "--- 4. the wholesale ladder, for an approved buyer only ---"
TIERS="$(market tiers "$TMC_ROW")"
echo "    $TIERS"
BUYER="$(market wholesale-buyer | field user)"
market wholesale-status "$BUYER" requested > /dev/null
check "a buyer who only asked pays the ordinary price"    "-" "$(market price-for "$BUYER" "$TMC_ROW" 5 | field price)"
market wholesale-status "$BUYER" approved > /dev/null
check "…and an approved one gets the ladder"              yes \
  "$(case "$(market price-for "$BUYER" "$TMC_ROW" 5 | field price)" in -|'') echo no;; *) echo yes;; esac)"
LADDER_PRICE="$(market price-for "$BUYER" "$TMC_ROW" 5 | field price)"
CART_PRICE="$(market cart-price "$BUYER" "$TMC_A" 5 | field unit)"
echo "    ladder says ${LADDER_PRICE}; a real cart of 5 charges ${CART_PRICE} each"
check "the real cart charges the ladder price"            "$LADDER_PRICE" "$CART_PRICE"
check "…and a cart of one still pays the ordinary price"  "$(market cart-price "$BUYER" "$TMC_A" 1 | field retail)" \
  "$(market cart-price "$BUYER" "$TMC_A" 1 | field unit)"

echo
echo "--- 5. a mixed basket: only the code's own vendor is discounted ---"
# The whole point of scoping. If the code were a cart-wide discount the shop's
# own 450,000 would be cut too, and the marketplace would be spending a
# shopkeeper's money on a vendor's promotion.
JAR3="$SCRATCH/mixed.txt"; rm -f "$JAR3"
curl -s -c "$JAR3" -b "$JAR3" -L "$SITE/?add-to-cart=${TMC_A}&quantity=2" -o /dev/null
curl -s -c "$JAR3" -b "$JAR3" -L "$SITE/?add-to-cart=${SHOP}&quantity=1" -o /dev/null
MIXED_PLAIN="$(cart_total "$JAR3")"
curl -s -c "$JAR3" -b "$JAR3" -L "$SITE/cart/" -o "$SCRATCH/cart3.html"
NONCE3="$(grep -oE 'name="woocommerce-cart-nonce" value="[^"]+"' "$SCRATCH/cart3.html" | grep -oE 'value="[^"]+"' | cut -d'"' -f2 | head -1)"
curl -s -c "$JAR3" -b "$JAR3" -L "$SITE/cart/" \
  --data-urlencode "coupon_code=${CODE}" \
  --data-urlencode "apply_coupon=اعمال" \
  --data-urlencode "woocommerce-cart-nonce=${NONCE3}" -o "$SCRATCH/applied3.html"
MIXED_AFTER="$(cart_total "$JAR3")"
MIXED_CUT=$((MIXED_PLAIN - MIXED_AFTER))
VENDOR_CUT=$((PLAIN - DISCOUNTED))
echo "    mixed basket: ${MIXED_PLAIN} → ${MIXED_AFTER} (cut ${MIXED_CUT}; the vendor's own cut was ${VENDOR_CUT})"
check "the basket really held both shops' goods"          "$((PLAIN + SHOP_PLAIN))" "$MIXED_PLAIN"
check "…and exactly the vendor's own discount came off"   "$VENDOR_CUT" "$MIXED_CUT"
cp "$SCRATCH/applied3.html" "$EV/03-mixed-cart.html"

{
echo "=== what was measured ==="
echo "plain cart:      ${PLAIN}"
echo "with the code:   ${DISCOUNTED}"
echo "shop's own cart: ${SHOP_PLAIN} → ${SHOP_AFTER} (unchanged)"
echo "mixed basket:    ${MIXED_PLAIN} → ${MIXED_AFTER} (only the vendor's lines)"
market tiers "$TMC_ROW"
} > "$EV/02-summary.txt"

echo
printf 'cart connection — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
