#!/usr/bin/env bash
# Is B2B a screen a buyer can actually use, or a service with no way in?
#
# The owner asked for the interface to be reported apart from the service
# existing — «وضعیت رابط و اتصال به مسیر واقعی خرید را جدا از وجود سرویس‌ها
# گزارش کن». Before this delivery `ManageWholesale::apply()` had no form
# anywhere and `tiersFor()` was never shown to the person whose price it is.
# This walks the whole thing as a real logged-in customer on a real shop:
# ask, wait, be approved, and only then see the ladder.
#
#   tools/wholesale-ui-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/wholesale-ui}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
SCRATCH="${SCRATCH:-/tmp/claude-0/wholesale-ui}"
PASSWORD="${PASSWORD:-TmcWholesale!2026}"
mkdir -p "$EV" "$SCRATCH"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
market() { wp eval-file marketplace-state.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }
has() { grep -qF "$2" "$1" && echo yes || echo no; }
# Every POST below is written as `curl -L --data-urlencode …` and NEVER as
# `curl -L -X POST …`. `-X` forces the method for every request in the chain,
# so curl re-POSTs the redirect target instead of GETting it, and a
# post/redirect/get page answers that with another redirect until curl gives up
# at fifty. Measured: the save really happened, and the evidence saw no page
# at all because curl had exited 47 before writing one.
# Does the page SHOW this number, however it is spelled? A raw grep for
# «960000» never matches «۹۶۰٬۰۰۰», so every negative price check written that
# way would pass without measuring anything.
shows() { python3 "$(dirname "$0")/cart-total.py" "$1" --contains-number "$2"; }

# Logs a WordPress user in and keeps the cookies. Every page below is fetched
# as a PERSON, because "the service returns the right value" was already true
# and is not what was missing.
login() { # <jar> <login>
  rm -f "$1"
  curl -s -c "$1" -b "$1" -L "$SITE/wp-login.php" -o /dev/null
  curl -s -c "$1" -b "$1" -L "$SITE/wp-login.php" \
    --data-urlencode "log=$2" --data-urlencode "pwd=${PASSWORD}" \
    --data-urlencode "wp-submit=ورود" \
    --data-urlencode "redirect_to=${SITE}/my-account/" -o /dev/null
  curl -s -c "$1" -b "$1" -L "$SITE/my-account/" | grep -qE 'customer-logout|خروج' && echo yes || echo no
}

{
echo "=== the wholesale interface, walked as a real customer ==="
wp plugin list --fields=name,status,version
} | tee "$EV/00-header.txt"

wp eval-file purchase-block-state.php resume > /dev/null
read -r TMC_A _REST <<< "$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
echo implode(" ", array_map(fn($x) => (int) $x->wcProductId, $p));')"
SEED="$(market seed "$TMC_A")"
TMC_ROW="$(echo "$SEED" | field product)"
VENDOR="$(echo "$SEED" | field vendor)"
TIERS="$(market tiers "$TMC_ROW")"
LADDER_PRICE="$(echo "$TIERS" | field steps | cut -d, -f1 | cut -d: -f2)"
PRODUCT_URL="$(wp eval "echo get_permalink($TMC_A);")"
echo "    product ${TMC_A} (row ${TMC_ROW}, vendor ${VENDOR}) · ${TIERS}"
echo "    ${PRODUCT_URL}"

BUYER="$(market fresh-buyer | field user)"
echo "    a customer who has never asked: user ${BUYER}"

echo
echo "--- 1. a guest, and a customer who never asked, see no wholesale prices ---"
curl -s -L "$PRODUCT_URL" -o "$SCRATCH/guest-product.html"
check "a guest is told the product HAS a wholesale price"  yes \
  "$(has "$SCRATCH/guest-product.html" 'این محصول قیمت عمده دارد')"
check "…but is shown none of the step prices"              no \
  "$(shows "$SCRATCH/guest-product.html" "$LADDER_PRICE")"

JAR="$SCRATCH/buyer.txt"
check "the customer can log in"                            yes "$(login "$JAR" tmcfreshbuyer)"
curl -s -c "$JAR" -b "$JAR" -L "$SITE/my-account/" -o "$SCRATCH/account-before.html"
cp "$SCRATCH/account-before.html" "$EV/01-account-before.html"
check "«حساب من» offers the wholesale application form"    yes \
  "$(has "$SCRATCH/account-before.html" 'ثبت درخواست خرید عمده')"
check "…and names the terms nobody has decided yet"        yes \
  "$(has "$SCRATCH/account-before.html" 'تعرفه')"

echo
echo "--- 2. the customer asks, through that form ---"
NONCE="$(grep -oE 'name="tmc_wholesale_nonce" value="[^"]+"' "$SCRATCH/account-before.html" \
  | grep -oE 'value="[^"]+"' | cut -d'"' -f2 | head -1)"
check "the form carries a nonce"                           yes \
  "$(case "$NONCE" in '') echo no;; *) echo yes;; esac)"
curl -s -c "$JAR" -b "$JAR" -L "$SITE/my-account/" \
  --data-urlencode "tmc_action=tmc_wholesale_apply" \
  --data-urlencode "tmc_wholesale_nonce=${NONCE}" \
  --data-urlencode "wholesale_company=داروخانه آزمایشی تک‌طب" \
  --data-urlencode "wholesale_registration=1234567890" \
  --data-urlencode "wholesale_note=درخواست آزمایشی" -o "$SCRATCH/account-applied.html"
cp "$SCRATCH/account-applied.html" "$EV/02-account-applied.html"
ACCOUNT="$(market wholesale-account "$BUYER")"
echo "    $ACCOUNT"
check "the application was really recorded"                requested "$(echo "$ACCOUNT" | field status)"
check "…with the company the buyer typed"                  'داروخانه' \
  "$(echo "$ACCOUNT" | field company | cut -d' ' -f1)"
check "…and the page says it is waiting for the manager"   yes \
  "$(has "$SCRATCH/account-applied.html" 'در انتظار بررسی مدیر')"

echo
echo "--- 3. a form with no nonce writes nothing ---"
market fresh-buyer > /dev/null
curl -s -c "$JAR" -b "$JAR" -L "$SITE/my-account/" \
  --data-urlencode "tmc_action=tmc_wholesale_apply" \
  --data-urlencode "wholesale_company=بدون نانس" -o /dev/null
check "a submission without a nonce is ignored"            none \
  "$(market wholesale-account "$BUYER" | field status)"

echo
echo "--- 4. still asking is still not approved ---"
curl -s -c "$JAR" -b "$JAR" -L "$SITE/my-account/" \
  --data-urlencode "tmc_action=tmc_wholesale_apply" \
  --data-urlencode "tmc_wholesale_nonce=${NONCE}" \
  --data-urlencode "wholesale_company=داروخانه آزمایشی تک‌طب" \
  --data-urlencode "wholesale_registration=1234567890" -o /dev/null
curl -s -c "$JAR" -b "$JAR" -L "$PRODUCT_URL" -o "$SCRATCH/requested-product.html"
check "a waiting buyer still sees no step price"           no \
  "$(shows "$SCRATCH/requested-product.html" "$LADDER_PRICE")"
check "…and the ladder table is not on the page"           no \
  "$(has "$SCRATCH/requested-product.html" 'قیمت عمده برای شما')"

echo
echo "--- 5. the manager approves, and only then is the ladder visible ---"
market wholesale-status "$BUYER" approved > /dev/null
curl -s -c "$JAR" -b "$JAR" -L "$PRODUCT_URL" -o "$SCRATCH/approved-product.html"
cp "$SCRATCH/approved-product.html" "$EV/03-product-ladder.html"
check "the approved buyer is shown the ladder"             yes \
  "$(has "$SCRATCH/approved-product.html" 'قیمت عمده برای شما')"
check "…with the first step's real price on it"            yes \
  "$(shows "$SCRATCH/approved-product.html" "$LADDER_PRICE")"
curl -s -c "$JAR" -b "$JAR" -L "$SITE/my-account/" -o "$SCRATCH/account-approved.html"
check "…and «حساب من» says the approval plainly"           yes \
  "$(has "$SCRATCH/account-approved.html" 'تأیید شده است')"

echo
echo "--- 6. the same page, on a product that is not the marketplace's ---"
SHOP="$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} p WHERE p.post_type = \"product\" AND p.post_status = \"publish\" AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = \"_tmc_product_id\") AND p.post_author = 1 ORDER BY p.ID ASC LIMIT 1");')"
SHOP_URL="$(wp eval "echo get_permalink($SHOP);")"
curl -s -c "$JAR" -b "$JAR" -L "$SHOP_URL" -o "$SCRATCH/shop-product.html"
check "the shop's own product says nothing about wholesale" no \
  "$(has "$SCRATCH/shop-product.html" 'قیمت عمده')"
check "…and nothing about applying either"                 no \
  "$(has "$SCRATCH/shop-product.html" 'خرید عمده')"

echo
echo "--- 7. the vendor's own ladder editor, on the vendor's own page ---"
# `setTiers()` had no screen at all before this delivery: the only way to set a
# ladder was `wp eval-file`, which is not an interface. This is the vendor
# doing it themselves, on /vendor/support/.
VJAR="$SCRATCH/vendor.txt"; rm -f "$VJAR"
curl -s -c "$VJAR" -b "$VJAR" -L "$SITE/wp-login.php" \
  --data-urlencode "log=${TMC_VENDOR_USER:-tmcvendor}" \
  --data-urlencode "pwd=${TMC_VENDOR_PASS:-TmcVendor!2026}" \
  --data-urlencode "wp-submit=ورود" -o /dev/null
curl -s -c "$VJAR" -b "$VJAR" -L "$SITE/vendor/support/?tier_product=${TMC_ROW}" -o "$SCRATCH/vendor-support.html"
cp "$SCRATCH/vendor-support.html" "$EV/05-vendor-tier-editor.html"
check "the vendor page carries a ladder editor"            yes \
  "$(has "$SCRATCH/vendor-support.html" 'ذخیرهٔ پلکان قیمت')"
check "…already filled in with the current steps"          yes \
  "$(shows "$SCRATCH/vendor-support.html" "$LADDER_PRICE")"
check "…and names the product it will write to"            yes \
  "$(has "$SCRATCH/vendor-support.html" 'name="tier_product"')"

VNONCE="$(grep -oE 'name="tmc_vendor_nonce" value="[^"]+"' "$SCRATCH/vendor-support.html" \
  | grep -oE 'value="[^"]+"' | cut -d'"' -f2 | head -1)"
NEW_PRICE=$((LADDER_PRICE - 10000))
curl -s -c "$VJAR" -b "$VJAR" -L "$SITE/vendor/support/" \
  --data-urlencode "tmc_vendor_nonce=${VNONCE}" \
  --data-urlencode "tmc_vendor_action=set_tiers" \
  --data-urlencode "tier_product=${TMC_ROW}" \
  --data-urlencode "tier_qty[0]=5"  --data-urlencode "tier_price[0]=${NEW_PRICE}" \
  --data-urlencode "tier_qty[1]=20" --data-urlencode "tier_price[1]=700000" \
  --data-urlencode "tier_qty[2]="   --data-urlencode "tier_price[2]=" \
  -o "$SCRATCH/vendor-saved.html"
AFTER_SAVE="$(market tiers "$TMC_ROW" | field steps)"
echo "    after the vendor saved: ${AFTER_SAVE}"
check "the vendor's own edit really replaced the ladder"   "5:${NEW_PRICE},20:700000" "$AFTER_SAVE"
check "…and the empty third row was not read as a step"    2 \
  "$(echo "$AFTER_SAVE" | awk -F, '{print NF}')"
check "…with the page coming back to the same product"     yes \
  "$(has "$SCRATCH/vendor-saved.html" 'ذخیرهٔ پلکان قیمت')"

# An impossible ladder is refused in words, not saved half way.
curl -s -c "$VJAR" -b "$VJAR" -L "$SITE/vendor/support/" \
  --data-urlencode "tmc_vendor_nonce=${VNONCE}" \
  --data-urlencode "tmc_vendor_action=set_tiers" \
  --data-urlencode "tier_product=${TMC_ROW}" \
  --data-urlencode "tier_qty[0]=5"  --data-urlencode "tier_price[0]=500000" \
  --data-urlencode "tier_qty[1]=20" --data-urlencode "tier_price[1]=900000" \
  -o "$SCRATCH/vendor-refused.html"
check "a ladder that rises with quantity is refused"       yes \
  "$(has "$SCRATCH/vendor-refused.html" 'قیمت هر واحد باید کمتر شود')"
check "…and the ladder on record is untouched"             "5:${NEW_PRICE},20:700000" \
  "$(market tiers "$TMC_ROW" | field steps)"

# …and the ladder the vendor typed is what a wholesale cart charges.
CART_UNIT="$(market cart-price "$BUYER" "$TMC_A" 5 | field unit)"
check "a real cart of five charges the vendor's new price" "$NEW_PRICE" "$CART_UNIT"

{
echo "=== what was measured ==="
echo "product:  ${PRODUCT_URL}"
market tiers "$TMC_ROW"
market wholesale-account "$BUYER"
} > "$EV/04-summary.txt"

echo
printf 'wholesale interface — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
