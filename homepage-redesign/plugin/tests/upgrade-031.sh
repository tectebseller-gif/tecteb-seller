#!/usr/bin/env bash
# ارتقای 0.3.1 → 0.3.2 به همان شکل Staging، روی وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/upgrade-031.sh dist/tecteb-homepage-0.3.1.zip dist/tecteb-homepage-0.3.2.zip
#
# 0.3.1 نصب و فعال؛ محصولات (با همهٔ شکل‌های قیمت) و دسته‌ها ذخیره‌شده؛ گزینهٔ «نمایش روی صفحهٔ نخست» روشن؛
# «تنظیمات ← خواندن» یک برگهٔ عادی. بعد ZIP ِ 0.3.2 روی همان نصب. option باید بایت‌به‌بایت بماند، گزینه روشن
# بماند، صفحهٔ نخست همان Concept C با قیمت درست و جست‌وجوی بی منوی دسته باشد، و بازگشت به 0.3.1 و خاموش‌کردن
# گزینه هر دو کار کنند. پیش‌نیاز: header seed + configure on، tests/fixtures/seed.php seed.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OLD="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
NEW="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"
WPP="${WP_PATH:-/home/user/wp/core}"
W="wp --allow-root --path=$WPP"
SITE="${SITE:-http://127.0.0.1:8090}"
PASS=0; FAIL=0
check() { if [[ "$2" == "0" ]]; then PASS=$((PASS+1)); echo "PASS $1"; else FAIL=$((FAIL+1)); echo "FAIL $1${3:+  — $3}"; fi; }
q() { $W "$@" 2>/dev/null; }
page() { curl -s "$SITE/$1"; }
cd "$HERE"
[[ "$(q eval 'echo DB_NAME;')" == "tth_disposable" ]] || { echo "refused: not the disposable site" >&2; exit 1; }

PLUG="$WPP/wp-content/plugins/tecteb-homepage"
PREV_SHOW="$(q option get show_on_front)"; PREV_FRONT="$(q eval 'echo (int) get_option("page_on_front");')"
q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"
q plugin install "$OLD" --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.1" ]]; check "0.3.1 installed and active from its ZIP" $?
q eval-file tests/fixtures/state.php full >/dev/null
q eval-file tests/fixtures/prices.php seed >/dev/null
PLAIN="$(q eval 'echo get_page_by_path("plain-page")->ID;')"
q option update show_on_front page >/dev/null; q option update page_on_front "$PLAIN" >/dev/null
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => true)));' >/dev/null
OPT_BEFORE="$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')"
h="$(page '')"; grep -q 'th-hero-title' <<<"$h" && grep -q 'Price range' <<<"$h" && grep -q 'name="product_cat"' <<<"$h"; check "0.3.1 on the front page: Concept C with the staging defects (English price text, category select)" $?

out="$(q plugin install "$NEW" --force)"; check "0.3.2 installed over 0.3.1 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-homepage --field=status)" == "active" && "$(q plugin get tecteb-homepage --field=version)" == "0.3.2" ]]; check "still active, version 0.3.2" $?
diff -rq --exclude=.gitkeep --exclude=languages "$HERE/tecteb-homepage" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT_BEFORE" ]]; check "saved settings unchanged byte for byte (products, order, categories, links, switch)" $?
h="$(page '')"
grep -q 'th-hero-title' <<<"$h"; check "switch still on: Concept C on the front page after the update" $?
! grep -qE 'Price range|through|Original price|Current price' <<<"$h"; check "no English screen-reader price text on the page" $?
! grep -q '<select' <<<"$h" && ! grep -q 'name="product_cat"' <<<"$h"; check "Hero search without the category select" $?
[[ "$(grep -o 'class="th-product__title"' <<<"$h" | wc -l)" -eq 10 ]]; check "all ten selected products still shown" $?
grep -q 'th-price--range' <<<"$h" && grep -q 'th-price--sale' <<<"$h" && grep -q '<del class="th-price__was">' <<<"$h"; check "range and sale prices in the new markup" $?
s="$(curl -s -o /dev/null -w '%{http_code}' "$SITE/?s=%D9%BE%D8%A7%D9%84%D8%B3&post_type=product")"; [[ "$s" == "200" || "$s" == "302" ]]; check "WooCommerce product search answers ($s)" $?

q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "")));' >/dev/null
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "rollback by unticking: the Reading page itself again" $?
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => true)));' >/dev/null
q plugin deactivate tecteb-homepage >/dev/null
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "rollback by deactivation: the Reading page itself again" $?
q plugin activate tecteb-homepage >/dev/null

rm -rf "$PLUG"; q plugin install "$OLD" --force --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.1" ]]; check "downgrade: 0.3.1 reinstalled from its ZIP" $?
h="$(page '')"; grep -q 'th-hero-title' <<<"$h" && grep -q 'name="product_cat"' <<<"$h"; check "downgrade: 0.3.1 behaves exactly as before (settings understood, switch on)" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT_BEFORE" ]]; check "downgrade: settings still byte-identical" $?

q eval-file tests/fixtures/prices.php reset >/dev/null
q option update show_on_front "$PREV_SHOW" >/dev/null
if [[ "$PREV_FRONT" -gt 0 ]]; then q option update page_on_front "$PREV_FRONT" >/dev/null; else q option delete page_on_front >/dev/null; fi
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "")));' >/dev/null
echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
