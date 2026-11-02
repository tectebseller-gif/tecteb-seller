#!/usr/bin/env bash
# ارتقای 0.3.2 → 0.3.3 به همان شکل Staging، روی وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/upgrade-032.sh dist/tecteb-homepage-0.3.2.zip dist/tecteb-homepage-0.3.3.zip
#
# 0.3.2 نصب و فعال؛ محصولات و دسته‌ها ذخیره‌شده؛ «نمایش روی صفحهٔ نخست» روشن؛ «تنظیمات ← خواندن» یک برگهٔ
# عادی؛ قالب قیمت ووکامرس مثل Staging (بی جداکننده، نماد بی فاصله). بعد ZIP ِ 0.3.3 روی همان نصب. option
# باید بایت‌به‌بایت بماند، گزینه روشن بماند، بخش برندها چاپ نشود تا مدیر برندی ثبت کند، و بازگشت به 0.3.2
# و خاموش‌کردن گزینه هر دو کار کنند. پیش‌نیاز: header seed + configure on، tests/fixtures/seed.php seed.
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
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.2" ]]; check "0.3.2 installed and active from its ZIP" $?
q eval-file tests/fixtures/state.php full >/dev/null
q eval-file tests/fixtures/prices.php seed >/dev/null
q eval-file tests/fixtures/hero-brands.php labels >/dev/null
q eval-file tests/fixtures/prices.php format staging >/dev/null
PLAIN="$(q eval 'echo get_page_by_path("plain-page")->ID;')"
q option update show_on_front page >/dev/null; q option update page_on_front "$PLAIN" >/dev/null
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => true)));' >/dev/null
OPT_BEFORE="$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')"
h="$(page '')"; grep -q 'th-hero-title' <<<"$h" && grep -q 'بازار B2B' <<<"$h" && grep -q '45000000' <<<"$(sed 's/<[^>]*>//g' <<<"$h")"; check "0.3.2 on the front page: Concept C with the B2B label and the unformatted price (what staging shows)" $?

out="$(q plugin install "$NEW" --force)"; check "0.3.3 installed over 0.3.2 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-homepage --field=status)" == "active" && "$(q plugin get tecteb-homepage --field=version)" == "0.3.3" ]]; check "still active, version 0.3.3" $?
diff -rq --exclude=.gitkeep --exclude=languages "$HERE/tecteb-homepage" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT_BEFORE" ]]; check "saved settings unchanged byte for byte (no migration)" $?
[[ "$(q eval '\Tecteb\Homepage\Settings::forget(); $s=\Tecteb\Homepage\Settings::get(); echo wp_json_encode(array($s["front_page"], $s["brands"], $s["hero_labels"]));')" == '[true,[],[0,0,0,0]]' ]]; check "0.3.2 option reads as: switch on, no brands, labels by name" $?
h="$(page '')"; t="$(sed 's/<[^>]*>/ /g' <<<"$h")"
grep -q 'th-hero-title' <<<"$h"; check "switch still on: Concept C on the front page after the update" $?
! grep -q 'بازار B2B' <<<"$h" && ! grep -q 'th-pill' <<<"$h"; check "the B2B label is gone" $?
[[ "$(grep -o 'class="th-float th-float--' <<<"$h" | wc -l)" -eq 4 ]]; check "four Hero category labels" $?
grep -q '45,000,000' <<<"$t" && ! grep -q '45000000' <<<"$t"; check "prices formatted on the homepage (45,000,000)" $?
! grep -q 'th-section--brands' <<<"$h"; check "no brand registered ⇒ no brands section" $?
pid="$(q eval 'echo get_option("tth_price_fixture_ids")["simple"];')"
grep -q '45000000' <<<"$(curl -sL "$SITE/?p=$pid" | sed 's/<[^>]*>//g')"; check "product page keeps the site's own price format (homepage-only change)" $?

q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "")));' >/dev/null
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "rollback by unticking: the Reading page itself again" $?
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => true)));' >/dev/null
q eval-file tests/fixtures/hero-brands.php brands 3 >/dev/null
SAVED="$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')"
rm -rf "$PLUG"; q plugin install "$OLD" --force --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.2" ]]; check "downgrade: 0.3.2 reinstalled from its ZIP" $?
h="$(page '')"; grep -q 'th-hero-title' <<<"$h" && grep -q 'بازار B2B' <<<"$h" && ! grep -q 'th-section--brands' <<<"$h"; check "downgrade: 0.3.2 behaves as before; the extra brands/labels keys are ignored" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$SAVED" ]]; check "downgrade: settings (including the brands saved under 0.3.3) untouched" $?
q plugin deactivate tecteb-homepage >/dev/null
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "rollback by deactivation: the Reading page itself again" $?
q plugin activate tecteb-homepage >/dev/null

q eval-file tests/fixtures/prices.php format local >/dev/null
q eval-file tests/fixtures/prices.php reset >/dev/null
q eval-file tests/fixtures/hero-brands.php reset >/dev/null
q option update show_on_front "$PREV_SHOW" >/dev/null
if [[ "$PREV_FRONT" -gt 0 ]]; then q option update page_on_front "$PREV_FRONT" >/dev/null; else q option delete page_on_front >/dev/null; fi
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "")));' >/dev/null
echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
