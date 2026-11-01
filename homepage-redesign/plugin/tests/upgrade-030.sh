#!/usr/bin/env bash
# ارتقای 0.3.0 → 0.3.1 به همان شکل Staging، روی وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/upgrade-030.sh dist/tecteb-homepage-0.3.0.zip dist/tecteb-homepage-0.3.1.zip
#
# 0.3.0 نصب و فعال؛ محصولات منتخب و دسته‌ها ذخیره‌شده؛ «تنظیمات ← خواندن» یک برگهٔ عادی. بعد ZIP ِ 0.3.1
# روی همان نصب (`plugin install --force`). باید: option بایت‌به‌بایت همان بماند، صفحهٔ نخست همان برگه بماند
# تا وقتی مدیر صریحاً روشنش کند، روشن‌کردن Concept C را بیاورد، و خاموش‌کردن یا غیرفعال‌سازی فوراً برگرداند.
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
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.0" ]]; check "0.3.0 installed and active from its ZIP" $?
q eval-file tests/fixtures/state.php full >/dev/null
PLAIN="$(q eval 'echo get_page_by_path("plain-page")->ID;')"
q option update show_on_front page >/dev/null; q option update page_on_front "$PLAIN" >/dev/null
OPT_BEFORE="$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')"
PAGE_BEFORE="$(q eval "echo md5(get_post($PLAIN)->post_content . serialize(get_post_meta($PLAIN)));")"
PAGES_BEFORE="$(q eval 'echo wp_count_posts("page")->publish;')"
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "0.3.0 + Reading page: the front page shows the page itself (what the owner saw)" $?

out="$(q plugin install "$NEW" --force)"; check "0.3.1 installed over 0.3.0 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-homepage --field=status)" == "active" && "$(q plugin get tecteb-homepage --field=version)" == "0.3.1" ]]; check "still active, version 0.3.1" $?
diff -rq --exclude=.gitkeep --exclude=languages "$HERE/tecteb-homepage" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT_BEFORE" ]]; check "saved settings unchanged byte for byte (no migration)" $?
[[ "$(q eval 'echo \Tecteb\Homepage\Settings::get()["front_page"] ? "on" : "off";')" == "off" ]]; check "after the update the switch reads off (no visible change on update)" $?
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "after the update: front page unchanged until the admin switches it on" $?

q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "1")));' >/dev/null
h="$(page '')"; grep -q 'th-hero-title' <<<"$h" && ! grep -q 'متن عادی.' <<<"$h" && [[ "$(grep -o 'class="th-product__title"' <<<"$h" | wc -l)" -ge 1 ]]; check "switch on: Concept C with the products saved under 0.3.0" $?
[[ "$(q eval 'echo wp_json_encode(array(count(\Tecteb\Homepage\Settings::get()["products"]), count(\Tecteb\Homepage\Settings::get()["categories"])));')" == "$(q eval 'echo wp_json_encode(array(count(get_option("tecteb_home_settings")["products"]), count(get_option("tecteb_home_settings")["categories"])));')" ]]; check "switch on: product and category selection intact" $?

q plugin deactivate tecteb-homepage >/dev/null
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "rollback by deactivation: the page itself again, immediately" $?
q plugin activate tecteb-homepage >/dev/null
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "")));' >/dev/null
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "rollback by unticking: the page itself again" $?
[[ "$(q eval "echo md5(get_post($PLAIN)->post_content . serialize(get_post_meta($PLAIN)));")" == "$PAGE_BEFORE" && "$(q eval 'echo (int) get_option("page_on_front");')" == "$PLAIN" && "$(q eval 'echo wp_count_posts("page")->publish;')" == "$PAGES_BEFORE" ]]; check "the Reading page, its Elementor data and Reading settings never changed; no new page" $?

q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"
q plugin install "$OLD" --force --activate >/dev/null
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "1")));' >/dev/null
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "downgrade to 0.3.0 with the switch left on: the extra key is ignored, the page itself shows" $?

q option update show_on_front "$PREV_SHOW" >/dev/null
if [[ "$PREV_FRONT" -gt 0 ]]; then q option update page_on_front "$PREV_FRONT" >/dev/null; else q option delete page_on_front >/dev/null; fi
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "")));' >/dev/null
echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
