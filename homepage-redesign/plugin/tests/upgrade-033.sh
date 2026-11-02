#!/usr/bin/env bash
# ارتقای 0.3.3 → 0.3.4 به همان شکل Staging، روی وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/upgrade-033.sh dist/tecteb-homepage-0.3.3.zip dist/tecteb-homepage-0.3.4.zip
#
# 0.3.3 نصب و فعال؛ «نمایش روی صفحهٔ نخست» روشن؛ برندهای همکار با لوگو ثبت‌شده؛ برچسب‌های Hero مثل Staging (سه
# برچسب با محصول، «جراحی» بی محصول قابل‌نمایش ⇒ پنهان)؛ قالب قیمت ووکامرس مثل Staging. بعد ZIP ِ 0.3.4 روی همان
# نصب: option باید بایت‌به‌بایت بماند (هیچ نوشتنی در خواندن)، Hero همان برچسب‌ها و پیوندها، برندها همان نام‌ها و
# نشانی‌ها، قیمت‌ها همان متن. سپس ذخیرهٔ پنل در 0.3.4 (همان مسیر sanitize) و بازگشت به 0.3.3 از ZIP ِ خودش.
# پیش‌نیاز: header seed + configure on، tests/fixtures/seed.php seed.
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
# برچسب‌های Hero (متن|پیوند|کلاس)، برندها (alt|پیوند)، و متن قیمت‌های صفحهٔ اصلی — آنچه بازدیدکننده می‌بیند.
floats() { python3 -c 'import re,sys,html; h=sys.stdin.read(); m=re.search(r"<ul class=\"th-floats\".*?</ul>",h,re.S); print("\n".join("|".join([html.unescape(re.sub(r"<[^>]+>","",a[2])).strip(),a[1],a[0]]) for a in re.findall(r"<a class=\"(th-float[^\"]*)\" href=\"([^\"]*)\">(.*?)</a>",m.group(0),re.S)) if m else "")'; }
brands() { python3 -c 'import re,sys,html; h=sys.stdin.read(); m=re.search(r"<section class=\"th-section th-section--white th-section--brands\".*?</section>",h,re.S); print("\n".join(html.unescape(a[0])+"|"+a[1] for a in re.findall(r"<(?:a class=\"th-brand\" href=\"([^\"]*)\"|span class=\"th-brand\")>.*?alt=\"([^\"]*)\"",m.group(0),re.S)) if m else "none")'; }
prices() { python3 -c 'import re,sys,html; h=sys.stdin.read(); print("\n".join(re.sub(r"\s+"," ",html.unescape(re.sub(r"<[^>]+>"," ",p))).strip() for p in re.findall(r"<(?:p|div|span) class=\"th-product__price[^\"]*\"[^>]*>(.*?)</(?:p|div)>",h,re.S)))'; }
cd "$HERE"
[[ "$(q eval 'echo DB_NAME;')" == "tth_disposable" ]] || { echo "refused: not the disposable site" >&2; exit 1; }

PLUG="$WPP/wp-content/plugins/tecteb-homepage"
PREV_SHOW="$(q option get show_on_front)"; PREV_FRONT="$(q eval 'echo (int) get_option("page_on_front");')"
q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"
q plugin install "$OLD" --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.3" ]]; check "0.3.3 installed and active from its ZIP" $?
q eval-file tests/fixtures/state.php full >/dev/null
q eval-file tests/fixtures/prices.php seed >/dev/null
q eval-file tests/fixtures/hero-brands.php reset >/dev/null
q eval-file tests/fixtures/hero-brands.php brands 4 >/dev/null
q eval-file tests/fixtures/prices.php format staging >/dev/null
# مثل Staging: «پزشکی جنرال»، «بیهوشی و تنفسی» و «آزمایشگاه» محصول دارند؛ «جراحی» محصول قابل‌نمایش ندارد. «جراحی»
# ِ ریشه در این وردپرس محصول دارد، پس 0.3.3 با شناسهٔ دستی به یک دستهٔ بی‌محصول سنجاق می‌شود (همان نتیجه: پنهان).
q eval-file tests/fixtures/hero-brands.php labels >/dev/null
EMPTY="$(q eval '$t = wp_insert_term("جراحی (ارتقا، بی‌محصول)", "product_cat"); echo is_wp_error($t) ? 0 : $t["term_id"];')"
q eval "\$s = get_option('tecteb_home_settings'); unset(\$s['hero_items']); \$s['hero_labels'] = array(0,0,0,$EMPTY); \$s['front_page'] = true; update_option('tecteb_home_settings', \$s);" >/dev/null
PLAIN="$(q eval 'echo get_page_by_path("plain-page")->ID;')"
q option update show_on_front page >/dev/null; q option update page_on_front "$PLAIN" >/dev/null
q eval 'echo array_key_exists("hero_items", get_option("tecteb_home_settings")) ? "has" : "no";' | grep -q '^no$'; check "the saved option is a 0.3.3 option (no hero_items key)" $?
OPT_BEFORE="$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')"
h="$(page '')"; F_BEFORE="$(floats <<<"$h")"; B_BEFORE="$(brands <<<"$h")"; P_BEFORE="$(prices <<<"$h")"
[[ "$(wc -l <<<"$F_BEFORE")" -eq 3 && "$F_BEFORE" != *"جراحی"* ]]; check "0.3.3 on the front page: three Hero labels, «جراحی» hidden (no displayable products) — the staging state" $? "$F_BEFORE"
[[ "$(wc -l <<<"$B_BEFORE")" -eq 3 ]] && grep -q 'th-section--brands' <<<"$h"; check "0.3.3: brands section with the registered logos (3 active of 4)" $? "$B_BEFORE"
grep -q '45,000,000' <<<"$P_BEFORE"; check "0.3.3: homepage prices formatted (45,000,000 تومان)" $? "$(head -3 <<<"$P_BEFORE")"

out="$(q plugin install "$NEW" --force)"; check "0.3.4 installed over 0.3.3 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-homepage --field=status)" == "active" && "$(q plugin get tecteb-homepage --field=version)" == "0.3.4" ]]; check "still active, version 0.3.4" $?
diff -rq --exclude=.gitkeep --exclude=languages "$HERE/tecteb-homepage" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
h="$(page '')"
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT_BEFORE" ]]; check "after the update and a page view: saved settings unchanged byte for byte (no migration, no write on read)" $?
[[ "$(floats <<<"$h" | cut -d'|' -f1,2)" == "$(cut -d'|' -f1,2 <<<"$F_BEFORE")" ]]; check "Hero labels: the same three titles, the same archives, the same order as 0.3.3" $? "$(floats <<<"$h")"
[[ "$(floats <<<"$h" | cut -d'|' -f3 | tr '\n' ' ')" == "th-float th-float--one th-float th-float--two th-float th-float--three " ]]; check "Hero labels: the same three positions (one, two, three)" $? "$(floats <<<"$h" | cut -d'|' -f3)"
[[ "$(brands <<<"$h")" == "$B_BEFORE" ]]; check "brands: the same names, logos and links, in the same order" $? "$(brands <<<"$h")"
[[ "$(prices <<<"$h")" == "$P_BEFORE" ]]; check "prices: the same text on the homepage, letter for letter" $?
q eval '\Tecteb\Homepage\Settings::forget(); $s=\Tecteb\Homepage\Settings::get(); echo wp_json_encode(array(array_column($s["hero_items"],"title"), array_column($s["hero_items"],"term"), array_column($s["hero_items"],"order"), array_column($s["hero_items"],"active"), count($s["brands"]), $s["front_page"]));' > /tmp/tth-up033.txt
[[ "$(cat /tmp/tth-up033.txt)" == "[[\"\\u067e\\u0632\\u0634\\u06a9\\u06cc \\u062c\\u0646\\u0631\\u0627\\u0644\",\"\\u0628\\u06cc\\u0647\\u0648\\u0634\\u06cc \\u0648 \\u062a\\u0646\\u0641\\u0633\\u06cc\",\"\\u0622\\u0632\\u0645\\u0627\\u06cc\\u0634\\u06af\\u0627\\u0647\",\"\\u062c\\u0631\\u0627\\u062d\\u06cc (\\u0627\\u0631\\u062a\\u0642\\u0627\\u060c \\u0628\\u06cc\\u200c\\u0645\\u062d\\u0635\\u0648\\u0644)\"],[0,0,0,$EMPTY],[1,2,3,4],[true,true,true,true],4,true]" ]]; check "the 0.3.3 option reads in 0.3.4 as: default titles, its pinned category kept (title = that category's name, as 0.3.3 showed), order 1–4, all active, 4 brands, switch on" $? "$(cat /tmp/tth-up033.txt)"
# پیش‌نمایش فقط برای مدیر است (بازدیدکننده آن را نمی‌بیند): ورود با کوکی، گزینهٔ صفحهٔ نخست خاموش، و پیش‌نمایش.
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "")));' >/dev/null
JAR="$(mktemp)"; curl -s -c "$JAR" -b "$JAR" -o /dev/null "$SITE/wp-login.php"; curl -s -c "$JAR" -b "$JAR" -o /dev/null --data-urlencode "log=tthadmin" --data-urlencode "pwd=local-only-2026" --data-urlencode "testcookie=1" "$SITE/wp-login.php"
# (صفحه اول در متغیر: با pipefail، «curl | grep -q» وقتی grep زود تمام کند به‌خاطر SIGPIPE ِ curl شکست می‌خورد.)
ha="$(curl -s -b "$JAR" "$SITE/?tecteb_home_preview=1")"; hv="$(curl -s "$SITE/?tecteb_home_preview=1")"
grep -q 'th-hero-title' <<<"$ha" && ! grep -q 'th-hero-title' <<<"$hv"; check "preview (?tecteb_home_preview=1) still works for the admin with the switch off, and not for visitors" $?
rm -f "$JAR"
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => true)));' >/dev/null
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT_BEFORE" ]]; check "switch off and on again: settings back to the 0.3.3 bytes" $?

# ذخیرهٔ پنل در 0.3.4 (همان مسیر sanitize که فرم می‌رود) — هیچ برند یا تنظیم دیگری از دست نمی‌رود.
# مقایسه روی شکلِ خوانده‌شده (normalize)، چون فیکسچرِ محلی دسته‌ها را بی کلید خالی «note» نوشته است.
q eval '$S = \Tecteb\Homepage\Settings::class; $s = get_option("tecteb_home_settings"); $o = $S::normalize($s, false); $n = $S::sanitize($s); update_option("tecteb_home_settings", $n); $keep = array_diff_key($o, array("hero_items" => 1, "hero_labels" => 1)); echo ($keep === array_diff_key($n, array("hero_items" => 1, "hero_labels" => 1)) && $o["hero_items"] === $n["hero_items"] && array_key_exists("hero_items", get_option("tecteb_home_settings"))) ? "kept" : "lost";' | grep -q '^kept$'; check "first save in 0.3.4: brands, links, categories, products and every other setting kept; the rows saved as they were read" $?
h="$(page '')"
[[ "$(floats <<<"$h" | cut -d'|' -f1,2)" == "$(cut -d'|' -f1,2 <<<"$F_BEFORE")" && "$(brands <<<"$h")" == "$B_BEFORE" ]]; check "after that save: the same labels and brands on the page" $?
# مدیر یک عنوان را عوض می‌کند و یکی را غیرفعال؛ بعد بازگشت به 0.3.3.
q eval '$s = get_option("tecteb_home_settings"); $s["hero_items"][0]["title"] = "تجهیزات عمومی"; $s["hero_items"][1]["active"] = false; update_option("tecteb_home_settings", \Tecteb\Homepage\Settings::sanitize($s));' >/dev/null
h="$(page '')"; floats <<<"$h" | cut -d'|' -f1 | tr '\n' ',' | grep -q '^تجهیزات عمومی,آزمایشگاه,$'; check "0.3.4 panel change shows: new title, one label off" $? "$(floats <<<"$h" | cut -d'|' -f1 | tr '\n' ',')"
SAVED="$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')"

rm -rf "$PLUG"; q plugin install "$OLD" --force --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.3" ]]; check "rollback: 0.3.3 reinstalled from its ZIP" $?
h="$(page '')"
[[ "$(floats <<<"$h" | cut -d'|' -f1,2)" == "$(cut -d'|' -f1,2 <<<"$F_BEFORE")" ]]; check "rollback: 0.3.3 shows its own three labels again (same titles, archives; the 0.3.4 title/on-off are ignored)" $? "$(floats <<<"$h")"
[[ "$(brands <<<"$h")" == "$B_BEFORE" ]]; check "rollback: brands as before" $?
[[ "$(prices <<<"$h")" == "$P_BEFORE" ]]; check "rollback: prices as before" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$SAVED" ]]; check "rollback: settings (including the 0.3.4 rows) untouched by reading them in 0.3.3" $?
q plugin deactivate tecteb-homepage >/dev/null
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "rollback by deactivation: the Reading page itself again" $?
q plugin activate tecteb-homepage >/dev/null
q eval 'update_option("tecteb_home_settings", array_merge(get_option("tecteb_home_settings"), array("front_page" => "")));' >/dev/null
h="$(page '')"; grep -q 'متن عادی.' <<<"$h" && ! grep -q 'th-hero-title' <<<"$h"; check "rollback by unticking «نمایش روی صفحهٔ نخست»: the Reading page itself again" $?

# بازگرداندن نصب به سورس این مخزن (پیوند نمادین)، پیش از فیکسچرها که API ِ 0.3.4 را صدا می‌زنند.
q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"; ln -s "$HERE/tecteb-homepage" "$PLUG"; q plugin activate tecteb-homepage >/dev/null
q eval "wp_delete_term($EMPTY, 'product_cat');" >/dev/null
q eval-file tests/fixtures/prices.php format local >/dev/null
q eval-file tests/fixtures/prices.php reset >/dev/null
q eval-file tests/fixtures/hero-brands.php reset >/dev/null
q option update show_on_front "$PREV_SHOW" >/dev/null
if [[ "$PREV_FRONT" -gt 0 ]]; then q option update page_on_front "$PREV_FRONT" >/dev/null; else q option delete page_on_front >/dev/null; fi
echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
