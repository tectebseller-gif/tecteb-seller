#!/usr/bin/env bash
# The shop's PUBLIC page, and — more to the point — what is NOT on it.
#
# Master §7 and A.5: «صفحه عمومی فروشگاه سئوپذیر است و اطلاعات حساس/نشانی
# خصوصی را نمایش نمی‌دهد». So this asserts both halves: the things a shopper
# must see are there, and the things they must never see are absent from the
# rendered BYTES — not hidden by CSS, not behind a check, simply not present.
#
# Also: temporary closure (A.5) must stop NEW purchases and nothing else —
# «سفارش‌های قبلی باید ادامه یابند».
#
#   tools/store-page-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/store-page}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }

cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

{
echo "=== the shop's public page, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version | grep tecteb
} | tee "$EV/00-header.txt"

VENDOR="$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
$ids = array_unique(array_map(fn($x) => (int) $x->vendorUserId, $p)); sort($ids); echo $ids[0] ?? 0;')"
echo "    vendor=${VENDOR}"

# Real values in the private fields, so their absence below means something.
# A page that omits an EMPTY warehouse proves nothing at all.
SEEDED="$(wp eval-file store-page-state.php seed "$VENDOR" | tee "$EV/01-seed.txt")"
echo "    $SEEDED"
# The shop's NAME is read back rather than assumed. `DbStoreRepository::save()`
# deliberately leaves `store_name` out of its UPDATE — a rename goes through
# the manager's change-request queue («صف تغییر نام … برای مدیر») — so a
# fixture that wrote a name and then asserted it would be testing the wrong
# thing and failing for the right reason.
STORE_NAME="$(echo "$SEEDED" | field store)"

URL="${SITE}/?tmc_store=${VENDOR}"
curl -s "$URL" > "$EV/02-page.html"
BYTES="$(wc -c < "$EV/02-page.html" | tr -d ' ')"
echo "    ${URL} → ${BYTES} bytes"

echo
echo "--- 1. it is a real, crawlable page ---"
check "the page is served"                                200 \
  "$(curl -s -o /dev/null -w '%{http_code}' "$URL")"
check "…with a title"                                     1 "$(grep -c '<title>' "$EV/02-page.html")"
check "…a description"                                    1 "$(grep -c 'name="description"' "$EV/02-page.html")"
check "…a canonical url"                                  1 "$(grep -c 'rel="canonical"' "$EV/02-page.html")"
check "…Open Graph, for a link pasted into a messenger"   1 "$(grep -c 'property="og:title"' "$EV/02-page.html")"
check "…and Schema.org Store for a crawler"               1 "$(grep -c '"@type":"Store"' "$EV/02-page.html")"
check "the document is Persian and RTL"                   1 \
  "$(grep -c 'lang="fa-IR" dir="rtl"' "$EV/02-page.html")"
# Counted plainly. An earlier version chained two `sed` substitutions —
# `s/^0$/1/;s/^[1-9].*$/0/` — and the second one re-matched what the first had
# just written, so every answer came out 0.
check "no script beyond the JSON-LD block"                0 \
  "$(grep -oE '<script[^>]*>' "$EV/02-page.html" | grep -vc 'application/ld' || true)"

echo
echo "--- 2. what a shopper must SEE ---"
check "the shop's name, whatever the queue let it be"      yes \
  "$(grep -q "$STORE_NAME" "$EV/02-page.html" && echo yes || echo no)"
check "the one badge v1 has"                              1 \
  "$(grep -c 'فروشندهٔ تأییدشده' "$EV/02-page.html")"
check "the city"                                          1 "$(grep -c 'اصفهان' "$EV/02-page.html")"
check "the introduction"                                  1 "$(grep -c 'معرفی آزمایشی فروشگاه' "$EV/02-page.html")"
check "its policies (preparation time)"                   1 \
  "$(grep -c 'زمان آماده‌سازی' "$EV/02-page.html")"
check "…and its products, linked"                         yes \
  "$(grep -q 'tmc-store__products' "$EV/02-page.html" && echo yes || echo no)"

echo
echo "--- 3. what must NEVER be on it ---"
# Each of these is a real value written onto the shop in step 1, so a miss here
# is a leak and not an empty field.
check "NOT the dispatch warehouse (نشانی خصوصی, A.5)"     0 \
  "$(grep -c 'انبار خصوصی خیابان' "$EV/02-page.html")"
check "NOT the bank account"                              0 "$(grep -c 'IR12' "$EV/02-page.html")"
check "NOT the applicant's e-mail"                        0 "$(grep -c 'private-store@example' "$EV/02-page.html")"
check "NOT the applicant's mobile"                        0 "$(grep -c '09121112233' "$EV/02-page.html")"
check "NOT the applicant's own address"                   0 "$(grep -c 'نشانی شخصی متقاضی' "$EV/02-page.html")"
# The address that IS published is the CITY and nothing narrower.
check "the structured address is the city only"           1 \
  "$(grep -c '"addressLocality"' "$EV/02-page.html")"
check "…with no street in it"                             0 "$(grep -c '"streetAddress"' "$EV/02-page.html")"

echo
echo "--- 4. only an approved shop has a page at all ---"
check "an id that is nobody's 404s"                       404 \
  "$(curl -s -o /dev/null -w '%{http_code}' "${SITE}/?tmc_store=999999")"
SUSPENDED="$(wp eval-file store-page-state.php suspend "$VENDOR" | field ok)"
check "…a suspended shop is suspended"                    true "$SUSPENDED"
check "…and its page 404s exactly the same way"           404 \
  "$(curl -s -o /dev/null -w '%{http_code}' "$URL")"
wp eval-file store-page-state.php reinstate "$VENDOR" > /dev/null
check "…and comes back when it is reinstated"             200 \
  "$(curl -s -o /dev/null -w '%{http_code}' "$URL")"

echo
echo "--- 5. temporary closure stops NEW purchases, and nothing else ---"
BEFORE="$(wp eval-file store-page-state.php buyable "$VENDOR" | tee "$EV/03-before-closure.txt")"
echo "    $BEFORE"
check "before closing, the shop's product may be bought"  allowed "$(echo "$BEFORE" | field decision)"
wp eval-file store-page-state.php close "$VENDOR" > /dev/null
DURING="$(wp eval-file store-page-state.php buyable "$VENDOR" | tee "$EV/04-during-closure.txt")"
echo "    $DURING"
check "while closed, a NEW purchase is refused"           vendor_closed "$(echo "$DURING" | field decision)"
# The distinction the shopper reads. A closure is a shop being away; a
# suspension is the marketplace having stopped it. Two states, two sentences.
check "…with the closure sentence, not the suspension one" yes \
  "$(echo "$DURING" | grep -q 'تعطیل' && echo yes || echo no)"
check "…and the public page says so too"                  1 \
  "$(curl -s "$URL" | grep -c 'موقتاً تعطیل')"
# A.5: «سفارش‌های قبلی باید ادامه یابند». The order module is not consulted
# about closure at all — this asserts the order placed before it still moves.
ONGOING="$(wp eval-file store-page-state.php ongoing "$VENDOR" | tee "$EV/05-ongoing.txt")"
echo "    $ONGOING"
check "an order placed BEFORE the closure still ships"    true "$(echo "$ONGOING" | field shipped)"
check "…and its status moved normally"                    true "$(echo "$ONGOING" | field ok)"
wp eval-file store-page-state.php reopen "$VENDOR" > /dev/null
check "after reopening, buying works again"               allowed \
  "$(wp eval-file store-page-state.php buyable "$VENDOR" | field decision)"

curl -s "$URL" > "$EV/06-final-page.html"

echo
printf 'the shop public page and closure — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
