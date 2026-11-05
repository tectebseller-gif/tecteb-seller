#!/usr/bin/env bash
# ارتقای 0.3.4 → 0.3.5 و بازگشت، از خودِ دو ZIP، روی وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/upgrade-034.sh dist/tecteb-homepage-0.3.4.zip dist/tecteb-homepage-0.3.5.zip
#
# 0.3.4 نصب و فعال؛ «نمایش روی صفحهٔ نخست» روشن؛ دسته‌ها، محصولات منتخب، برندها، برچسب‌های Hero و قالب قیمت مثل
# آزمون ارتقای قبلی. بعد 0.3.5 روی همان نصب: option بایت‌به‌بایت؛ Hero، دسته‌ها، محصولات، قیمت‌ها، برندها و پیوند
# «ورود به فروشگاه» همان؛ فقط ردیف دکمه‌های دسته در «محصولات منتخب» نیست. سپس بازگشت به 0.3.4 از ZIP ِ خودش.
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
# آنچه بازدیدکننده می‌بیند، جز بخش محصولات: برچسب‌های Hero، کارت‌های دسته، برندها، قیمت‌ها.
floats() { python3 -c 'import re,sys,html; h=sys.stdin.read(); m=re.search(r"<ul class=\"th-floats\".*?</ul>",h,re.S); print("\n".join("|".join([html.unescape(re.sub(r"<[^>]+>","",a[2])).strip(),a[1],a[0]]) for a in re.findall(r"<a class=\"(th-float[^\"]*)\" href=\"([^\"]*)\">(.*?)</a>",m.group(0),re.S)) if m else "")'; }
cats() { python3 -c 'import re,sys,html; h=sys.stdin.read(); print("\n".join(html.unescape(a) for a in re.findall(r"<a class=\"th-cat[^\"]*\" href=\"([^\"]*)\"",h)))'; }
brands() { python3 -c 'import re,sys,html; h=sys.stdin.read(); m=re.search(r"<section class=\"th-section th-section--white th-section--brands\".*?</section>",h,re.S); print("\n".join(html.unescape(a[0])+"|"+a[1] for a in re.findall(r"<(?:a class=\"th-brand\" href=\"([^\"]*)\"|span class=\"th-brand\")>.*?alt=\"([^\"]*)\"",m.group(0),re.S)) if m else "none")'; }
prices() { python3 -c 'import re,sys,html; h=sys.stdin.read(); print("\n".join(re.sub(r"\s+"," ",html.unescape(re.sub(r"<[^>]+>"," ",p))).strip() for p in re.findall(r"<(?:p|div|span) class=\"th-product__price[^\"]*\"[^>]*>(.*?)</(?:p|div)>",h,re.S)))'; }
# بخش محصولات: عنوان، پیوند «ورود به فروشگاه»، محصولات (عنوان|پیوند)، و شمار ردیف دکمه‌های دسته.
products() { python3 -c 'import re,sys,html; h=sys.stdin.read(); m=re.search(r"<section[^>]*aria-labelledby=\"th-products-title\".*?</section>",h,re.S); s=m.group(0) if m else ""; t=re.search(r"id=\"th-products-title\">(.*?)<",s); more=re.search(r"<a class=\"th-more\" href=\"([^\"]*)\">(.*?)<",s); items=re.findall(r"<h3 class=\"th-product__title\"><a href=\"([^\"]*)\">(.*?)</a>",s); print("title="+(html.unescape(t.group(1)) if t else "")); print("more="+(html.unescape(more.group(1))+"|"+html.unescape(more.group(2)) if more else "")); print("eyebrow="+str(len(re.findall(r"<p class=\"th-eyebrow\">فروشگاه</p>",s)))); print("filter="+str(len(re.findall(r"th-filter|th-chip",s)))); [print("item="+html.unescape(b)+"|"+a) for a,b in items]'; }
cssver() { python3 -c 'import re,sys; m=re.search(r"assets/css/tecteb-home\.css\?ver=([0-9.]+)",sys.stdin.read()); print(m.group(1) if m else "none")'; }
cd "$HERE"
[[ "$(q eval 'echo DB_NAME;')" == "tth_disposable" ]] || { echo "refused: not the disposable site" >&2; exit 1; }

PLUG="$WPP/wp-content/plugins/tecteb-homepage"
PREV_SHOW="$(q option get show_on_front)"; PREV_FRONT="$(q eval 'echo (int) get_option("page_on_front");')"
q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"
q plugin install "$OLD" --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.4" ]]; check "0.3.4 installed and active from its ZIP" $?
q eval-file tests/fixtures/state.php full >/dev/null
q eval-file tests/fixtures/prices.php seed >/dev/null
q eval-file tests/fixtures/hero-brands.php reset >/dev/null
q eval-file tests/fixtures/hero-brands.php brands 4 >/dev/null
q eval-file tests/fixtures/hero-brands.php labels >/dev/null
q eval-file tests/fixtures/prices.php format staging >/dev/null
q eval '$s = get_option("tecteb_home_settings"); $s["front_page"] = true; update_option("tecteb_home_settings", $s);' >/dev/null
PLAIN="$(q eval 'echo get_page_by_path("plain-page")->ID;')"
q option update show_on_front page >/dev/null; q option update page_on_front "$PLAIN" >/dev/null
OPT_BEFORE="$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')"
h="$(page '')"
F_BEFORE="$(floats <<<"$h")"; C_BEFORE="$(cats <<<"$h")"; B_BEFORE="$(brands <<<"$h")"; P_BEFORE="$(prices <<<"$h")"; S_BEFORE="$(products <<<"$h")"
grep -q '^filter=[1-9]' <<<"$S_BEFORE" && grep -q '^eyebrow=1$' <<<"$S_BEFORE"; check "0.3.4 on the front page: products section with the category filter row and the «فروشگاه» eyebrow" $? "$(head -4 <<<"$S_BEFORE" | tr '\n' ' ')"
[[ "$(grep -c '^item=' <<<"$S_BEFORE")" -ge 3 && -n "$C_BEFORE" && "$B_BEFORE" != "none" ]]; check "0.3.4: selected products, category cards and brands shown" $?
[[ "$(cssver <<<"$h")" == "0.3.4" ]]; check "0.3.4: tecteb-home.css?ver=0.3.4" $? "$(cssver <<<"$h")"

out="$(q plugin install "$NEW" --force)"; check "0.3.5 installed over 0.3.4 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-homepage --field=status)" == "active" && "$(q plugin get tecteb-homepage --field=version)" == "0.3.5" ]]; check "still active, version 0.3.5" $?
diff -rq --exclude=.gitkeep --exclude=languages "$HERE/tecteb-homepage" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
h="$(page '')"; S_AFTER="$(products <<<"$h")"
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT_BEFORE" ]]; check "after the update and a page view: saved settings unchanged byte for byte (no migration, no write on read)" $?
grep -q '^filter=0$' <<<"$S_AFTER"; check "0.3.5: no category filter row in the products section" $? "$(grep '^filter' <<<"$S_AFTER")"
[[ "$(grep -v '^filter=' <<<"$S_AFTER")" == "$(grep -v '^filter=' <<<"$S_BEFORE")" ]]; check "0.3.5: same title, same «ورود به فروشگاه» link, same products in the same order (eyebrow still in the HTML; hidden on mobile by CSS)" $? "$(diff <(grep -v '^filter=' <<<"$S_BEFORE") <(grep -v '^filter=' <<<"$S_AFTER") | head -4 | tr '\n' ' ')"
grep -q 'class="th-head th-head--shop th-reveal"' <<<"$h"; check "0.3.5: the products header carries th-head--shop (the mobile rule's hook)" $?
[[ "$(floats <<<"$h")" == "$F_BEFORE" ]]; check "Hero labels: same titles, archives and positions" $?
[[ "$(cats <<<"$h")" == "$C_BEFORE" ]]; check "category cards («از دسته درست شروع کنید»): same links in the same order" $?
[[ "$(brands <<<"$h")" == "$B_BEFORE" ]]; check "brands: same names, logos and links" $?
[[ "$(prices <<<"$h")" == "$P_BEFORE" ]]; check "prices: the same text, letter for letter" $?
[[ "$(cssver <<<"$h")" == "0.3.5" ]]; check "tecteb-home.css?ver=0.3.5 — browsers and page caches fetch the new stylesheet" $? "$(cssver <<<"$h")"
curl -s "$SITE/wp-content/plugins/tecteb-homepage/assets/css/tecteb-home.css?ver=0.3.5" | grep -q 'th-head--shop .th-eyebrow { display: none; }'; check "the served stylesheet carries the mobile eyebrow rule" $?
JAR="$(mktemp)"; curl -s -c "$JAR" -b "$JAR" -o /dev/null "$SITE/wp-login.php"; curl -s -c "$JAR" -b "$JAR" -o /dev/null --data-urlencode "log=tthadmin" --data-urlencode "pwd=local-only-2026" --data-urlencode "testcookie=1" "$SITE/wp-login.php"
c="$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$SITE/wp-admin/admin.php?page=tecteb-homepage")"; rm -f "$JAR"
[[ "$c" == "200" ]]; check "0.3.5: the settings page opens (200)" $? "$c"

rm -rf "$PLUG"; q plugin install "$OLD" --force --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.4" ]]; check "rollback: 0.3.4 reinstalled from its ZIP" $?
h="$(page '')"
[[ "$(products <<<"$h")" == "$S_BEFORE" ]]; check "rollback: products section exactly as 0.3.4 (filter row back)" $?
[[ "$(floats <<<"$h")" == "$F_BEFORE" && "$(cats <<<"$h")" == "$C_BEFORE" && "$(brands <<<"$h")" == "$B_BEFORE" && "$(prices <<<"$h")" == "$P_BEFORE" ]]; check "rollback: Hero labels, category cards, brands and prices as before" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT_BEFORE" ]]; check "rollback: settings untouched" $?

# بازگرداندن نصب به سورس این مخزن (پیوند نمادین).
q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"; ln -s "$HERE/tecteb-homepage" "$PLUG"; q plugin activate tecteb-homepage >/dev/null
q eval-file tests/fixtures/prices.php format local >/dev/null
q eval-file tests/fixtures/prices.php reset >/dev/null
q eval-file tests/fixtures/hero-brands.php reset >/dev/null
q option update show_on_front "$PREV_SHOW" >/dev/null
if [[ "$PREV_FRONT" -gt 0 ]]; then q option update page_on_front "$PREV_FRONT" >/dev/null; else q option delete page_on_front >/dev/null; fi
echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
