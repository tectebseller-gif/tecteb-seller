#!/usr/bin/env bash
# Reviews and ratings on a real WooCommerce site: who may write one, who may
# only answer, who decides what the public sees — and the charts.
#
#   tools/review-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/reviews}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-64s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-64s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
rv() { wp eval-file review-state.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }

cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

# This script SPENDS what it touches: a purchase can be rated once and a review
# answered once, so yesterday's rows make today's run fail for reasons that have
# nothing to do with the code. Cleared first, on the disposable site only, and
# only for products this marketplace owns.
wp eval-file review-state.php reset

{
echo "=== reviews and ratings, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version
wp option get tmc_schema_version
rv gateway
} | tee "$EV/00-header.txt"

# Two marketplace products and one that is NOT the marketplace's, so every
# «ours only» rule has something to be measured against.
read -r TMC_A TMC_B _REST <<< "$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
echo implode(" ", array_map(fn($x) => (int) $x->wcProductId, $p));')"
SHOP_PRODUCT="$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$r = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);
foreach (wc_get_products(["limit" => -1, "return" => "ids"]) as $id) {
  if ($r->findByWcProduct((int) $id) === null) { echo (int) $id; return; }
}
echo 0;')"
echo "    marketplace products: ${TMC_A} ${TMC_B} · not ours: ${SHOP_PRODUCT}"
[ -n "$TMC_A" ] && [ "$TMC_A" != "0" ] || { echo "no projected marketplace product" >&2; exit 2; }

# A FRESH buyer every run. `wc_customer_bought_product()` reads WooCommerce's
# whole order history, and this script buys product B further down — so a buyer
# reused from yesterday has already bought B and «buying A does not entitle you
# to review B» fails for a reason that is not the code's. Orders are left alone
# rather than deleted: other evidence in this repo reads them.
BUYER="$(rv buyer "buyer-reviews-$(date +%s)@example.test" | field id)"
STRANGER="$(rv buyer stranger-reviews@example.test | field id)"
echo "    buyer=${BUYER} stranger=${STRANGER}"

echo
echo "--- 1. «نظر فقط برای خریدار واقعی است» ---"
check "a stranger may not review a marketplace product"   false \
  "$(rv may-review "$STRANGER" "$TMC_A" | field allowed)"
PURCHASE="$(rv buy "$TMC_A" "$BUYER" 1 | tee "$EV/01-purchase.txt")"
echo "    $PURCHASE"
ITEM="$(echo "$PURCHASE" | field item)"
VENDOR="$(echo "$PURCHASE" | field vendor)"
check "…and after buying it, the same person may"         true \
  "$(rv may-review "$BUYER" "$TMC_A" | field allowed)"
check "buying A does not entitle anyone to review B"      false \
  "$(rv may-review "$BUYER" "$TMC_B" | field allowed)"
# The rule is OURS to apply, and only to our products. A shop's own product
# keeps whatever rules the shop has — this plugin refuses to answer for it.
if [ "$SHOP_PRODUCT" != "0" ]; then
  check "a product that is not the marketplace's gets no ruling" false \
    "$(rv may-review "$BUYER" "$SHOP_PRODUCT" | field allowed)"
  check "…and its reviews are not in any vendor's list"   0 \
    "$(rv post-review "$SHOP_PRODUCT" "$STRANGER" 5 'نظر روی کالای فروشگاه' > /dev/null; \
       rv reviews "$VENDOR" | grep -c "product=${SHOP_PRODUCT} " || true)"
else
  check "…(no non-marketplace product on this fixture)"   skipped skipped
fi

echo
echo "--- 2. the shop may answer, and may do nothing else ---"
REVIEW="$(rv post-review "$TMC_A" "$BUYER" 4 'بسته‌بندی خوب بود' | tee "$EV/02-review.txt")"
REVIEW_ID="$(echo "$REVIEW" | field id)"
echo "    $REVIEW"
check "the review is the shop's to see"                   1 \
  "$(rv reviews "$VENDOR" | grep -c "review=${REVIEW_ID} " || true)"
check "…and it starts unapproved, so shoppers do not see it" false \
  "$(rv reviews "$VENDOR" | grep "review=${REVIEW_ID} " | field approved)"
REPLY="$(rv reply-review "$REVIEW_ID" "$VENDOR" "$VENDOR" 'ممنون از خریدتان' | tee "$EV/03-reply.txt")"
echo "    $REPLY"
check "the shop may answer it"                            true "$(echo "$REPLY" | field ok)"
check "…once, and not twice"                              reply_already_given \
  "$(rv reply-review "$REVIEW_ID" "$VENDOR" "$VENDOR" 'دوباره' | field code)"
check "…and the answer is a real comment on the product"  1 \
  "$(rv comment-count "$TMC_A" | field vendor_replies)"
# The one that matters: there is no vendor path to a decision at all. Not a
# guarded one — the service has no such method to call.
check "no vendor-facing moderation method exists at all"  0 \
  "$(grep -cE 'public function (moderate|approve|reject|delete)[A-Za-z]*\(int \$actorId, int \$vendorUserId' \
     src/Modules/Marketplace/Application/ManageReviews.php || true)"

echo
echo "--- 3. moderation is the manager's, and decides visibility only ---"
check "the manager may show it"                           true "$(rv moderate-review "$REVIEW_ID" approve | field ok)"
check "…and it is then shown"                             true \
  "$(rv reviews "$VENDOR" | grep "review=${REVIEW_ID} " | field approved)"
check "…and may hold it again"                            true "$(rv moderate-review "$REVIEW_ID" hold | field ok)"
check "…without the review ceasing to exist"              1 \
  "$(rv reviews "$VENDOR" | grep -c "review=${REVIEW_ID} " || true)"
rv moderate-review "$REVIEW_ID" approve > /dev/null

echo
echo "--- 4. the shop's own rating: one per purchase, hung off the purchase ---"
BEFORE="$(rv standing "$VENDOR" | tee "$EV/04-standing-before.txt")"
echo "    $BEFORE"
RATED="$(rv rate "$ITEM" "$BUYER" 5 'سریع فرستادند' | tee "$EV/05-rate.txt")"
echo "    $RATED"
check "the buyer may rate the shop they bought from"      true "$(echo "$RATED" | field ok)"
check "…and is told it waits for the manager"             true "$(echo "$RATED" | field pending)"
check "a stranger may not rate that purchase"             rating_not_your_purchase \
  "$(rv rate "$ITEM" "$STRANGER" 5 | field code)"
check "…and the same purchase is not rated twice"         rating_already_given \
  "$(rv rate "$ITEM" "$BUYER" 3 | field code)"
check "…nor with a star count that does not exist"        rating_stars_out_of_range \
  "$(PURCHASE2="$(rv buy "$TMC_A" "$BUYER" 1)"; rv rate "$(echo "$PURCHASE2" | field item)" "$BUYER" 9 | field code)"

RATING_ID="$(rv ratings "$VENDOR" | grep 'rating=' | head -1 | field rating)"
DURING="$(rv standing "$VENDOR")"
echo "    $DURING"
check "a pending rating counts for nothing in public"     "$(echo "$BEFORE" | field vendor_count)" \
  "$(echo "$DURING" | field vendor_count)"
check "…and the shop can see it all the same"             1 \
  "$(rv ratings "$VENDOR" | grep -c "rating=${RATING_ID} " || true)"

echo
echo "--- 5. the manager decides, and a rejection is explained ---"
check "rejecting without a reason is refused"             moderation_reason_required \
  "$(rv moderate "$RATING_ID" reject | field code)"
check "the manager may approve"                           true "$(rv moderate "$RATING_ID" approve | field ok)"
AFTER="$(rv standing "$VENDOR" | tee "$EV/06-standing-after.txt")"
echo "    $AFTER"
check "…and only then does it count"                      1 \
  "$(( $(echo "$AFTER" | field vendor_count) - $(echo "$BEFORE" | field vendor_count) ))"
check "…at the stars that were given"                     500 "$(echo "$AFTER" | field vendor_avg)"
check "«بررسی‌نشده» is not a decision to go back to"       moderation_cannot_undecide \
  "$(rv moderate "$RATING_ID" pending | field code)"

REJECT_ITEM="$(rv buy "$TMC_B" "$BUYER" 1 | field item)"
REJECT_VENDOR="$(rv buy "$TMC_B" "$BUYER" 1 | field vendor)"
rv rate "$REJECT_ITEM" "$BUYER" 1 'متن رد شده' > /dev/null
REJECT_ID="$(rv ratings "$REJECT_VENDOR" | grep 'rating=' | head -1 | field rating)"
rv moderate "$REJECT_ID" reject 'خارج از موضوع' > /dev/null
REJECTED="$(rv ratings "$REJECT_VENDOR" | grep "rating=${REJECT_ID} " | tee "$EV/07-rejected.txt")"
echo "    $REJECTED"
# `grep -q` rather than `cut -c`: cut counts BYTES, and every Persian letter is
# two of them, so a byte slice of «متن رد شده» is half a character and never
# equal to anything.
check "a rejected rating keeps its text"                  yes \
  "$(echo "$REJECTED" | grep -q 'body=متن' && echo yes || echo no)"
check "…and carries the manager's reason to the shop"     yes \
  "$(echo "$REJECTED" | grep -q 'reason=خارج' && echo yes || echo no)"
check "…and is counted nowhere"                           0 \
  "$(rv standing "$REJECT_VENDOR" | field vendor_count)"

echo
echo "--- 6. «امتیاز محصول و فروشنده جداست» ---"
SEPARATE="$(rv standing "$VENDOR" | tee "$EV/08-separate.txt")"
echo "    $SEPARATE"
check "the product average is read from WooCommerce"      true "$(echo "$SEPARATE" | field product_available)"
check "…and is its own number, not the shop's"            yes \
  "$([ "$(echo "$SEPARATE" | field product_avg)" != "$(echo "$SEPARATE" | field vendor_avg)" ] && echo yes || echo no)"
check "…each with its own count"                          yes \
  "$([ -n "$(echo "$SEPARATE" | field product_count)" ] && [ -n "$(echo "$SEPARATE" | field vendor_count)" ] && echo yes || echo no)"
check "no combined figure is produced anywhere"           0 \
  "$(grep -c "'overall'" src/Modules/Marketplace/Application/ManageReviews.php || true)"

echo
echo "--- 7. one shop cannot answer for another ---"
OTHER_VENDOR="$(rv buy "$TMC_B" "$BUYER" 1 | field vendor)"
if [ "$OTHER_VENDOR" != "$VENDOR" ] && [ "$OTHER_VENDOR" != "0" ]; then
  check "a different shop may not answer this review"     not_ours \
    "$(rv reply-review "$REVIEW_ID" "$OTHER_VENDOR" "$OTHER_VENDOR" 'نه مال من' | field code)"
  check "…nor this rating"                                not_ours \
    "$(rv reply-rating "$RATING_ID" "$OTHER_VENDOR" "$OTHER_VENDOR" 'نه مال من' | field code)"
else
  check "…(both products belong to one shop on this fixture)" skipped skipped
fi

echo
echo "--- 8. the charts: drawn here, by us, out of the same numbers ---"
rv chart "$VENDOR" > "$EV/09-chart.html"
CHART="$(cat "$EV/09-chart.html")"
check "a chart is really drawn"                           1 "$(echo "$CHART" | grep -c 'tv-chart__rows' || true)"
# Not SVG, and that is the point. The first version WAS an SVG, and the a11y
# suite measured its category labels at ~9px on a 320px viewport — an SVG
# scales its own text with the drawing. Here the bar is a span with a
# percentage width and every word is HTML text that wraps and zooms.
check "…as text and CSS, not as a picture with text in it"   0 "$(echo "$CHART" | grep -c '<svg' || true)"
check "every row prints its label as real text"           1 \
  "$(echo "$CHART" | grep -c 'tv-chart__label">منتشرشده<' || true)"
check "…and its value as real text beside it"             1 \
  "$(echo "$CHART" | grep -c 'tv-chart__value">۳<' || true)"
check "the bar itself is hidden from screen readers"      1 \
  "$(echo "$CHART" | grep -c 'aria-hidden="true"' || true)"
check "…because the text next to it already says it"      0 \
  "$(echo "$CHART" | grep -c 'aria-label' || true)"
# The fixture has exactly two zeros («نیازمند اصلاح» and «تعلیق‌شده») and both
# must draw nothing. A non-zero value is guaranteed at least 2% instead, so
# «۱ از ۲۰۰» stays visible — the two rules are opposite and both are measured.
check "both zero rows are drawn with no bar at all"       2 \
  "$(echo "$CHART" | grep -oE 'inline-size:[0-9]+%' | grep -c '^inline-size:0%$' || true)"
check "…while every non-zero row keeps a visible bar"     0 \
  "$(echo "$CHART" | grep -oE 'inline-size:[0-9]+%' | grep -cE '^inline-size:1%$' || true)"
check "no script anywhere in it"                          0 "$(echo "$CHART" | grep -c '<script' || true)"
check "no request to anywhere outside this site"          0 \
  "$(echo "$CHART" | grep -oE 'https?://[^\"'"'"' ]+' | wc -l | tr -d ' ')"
check "a chart of nothing says so instead of drawing zero" 1 \
  "$(wp eval 'echo (int) str_contains(
      Tecteb\Marketplace\Modules\Marketplace\Presentation\Charts::bars([["label" => "الف", "value" => 0]]),
      "هنوز عددی"
  );')"
check "money is never charted"                            0 \
  "$(wp eval 'echo count(Tecteb\Marketplace\Modules\Marketplace\Presentation\NoticeMessages::chartRows(
      Tecteb\Marketplace\Modules\Marketplace\Application\Reports::FINANCE,
      ["available" => 1, "earned_minor" => 5000000, "paid_minor" => 100]
  ));')"

{
echo "=== final state ==="
rv standing "$VENDOR"
rv ratings "$VENDOR"
rv reviews "$VENDOR"
rv comment-count "$TMC_A"
} > "$EV/10-final-state.txt"

echo
printf 'reviews, ratings and charts — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
