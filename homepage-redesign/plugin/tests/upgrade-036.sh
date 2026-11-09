#!/usr/bin/env bash
# ارتقای 0.3.6 → 0.3.7 و بازگشت، از خودِ دو ZIP، روی وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/upgrade-036.sh dist/tecteb-homepage-0.3.6.zip dist/tecteb-homepage-0.3.7.zip
#
# 0.3.7 هیچ HTML ای را عوض نمی‌کند: Hero، دسته‌ها، محصولات، قیمت‌ها، برندها و بخش تأمین‌کنندگان بایت‌به‌بایت همان
# 0.3.6 (فقط ?ver=)، option بایت‌به‌بایت، CSS همان فایل. تنها تفاوت: وقتی صفحهٔ نخست جایگزین می‌شود، CSS و
# دارایی‌های Elementor ِ همان برگه (که چاپ نمی‌شود) بارگذاری نمی‌شوند — آن بخش در perf/tests/elementor-assets.sh و
# perf/tests/upgrade-elementor.sh روی Elementor ِ واقعی سنجیده می‌شود، چون این بستر شبیه‌ساز است.
# سپس بازگشت به 0.3.6 از ZIP ِ خودش.
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
# بخش «همکاری تأمین‌کنندگان» (norm=1 فقط برای ارتقای 0.3.5 → 0.3.6 بود؛ اینجا همیشه 0، یعنی برابری کامل).
supplier() { python3 -c 'import re,sys; h=sys.stdin.read(); m=re.search(r"<section[^>]*aria-labelledby=\"th-supplier-title\".*?</section>",h,re.S); s=m.group(0) if m else "none"; s=re.sub(r"([?&]ver=)[0-9.]+",r"\1X",s)
if sys.argv[1]=="1": s=s.replace("tecteb-logo-mark-96.webp","tecteb-logo-mark-96.png").replace(" loading=\"lazy\" decoding=\"async\"","")
print(s)' "${1:-0}"; }
cssver() { python3 -c 'import re,sys; m=re.search(r"assets/css/tecteb-home\.css\?ver=([0-9.]+)",sys.stdin.read()); print(m.group(1) if m else "none")'; }
cd "$HERE"
[[ "$(q eval 'echo DB_NAME;')" == "tth_disposable" ]] || { echo "refused: not the disposable site" >&2; exit 1; }

PLUG="$WPP/wp-content/plugins/tecteb-homepage"
PREV_SHOW="$(q option get show_on_front)"; PREV_FRONT="$(q eval 'echo (int) get_option("page_on_front");')"
q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"
q plugin install "$OLD" --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.6" ]]; check "0.3.6 installed and active from its ZIP" $?
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
F_BEFORE="$(floats <<<"$h")"; C_BEFORE="$(cats <<<"$h")"; B_BEFORE="$(brands <<<"$h")"; P_BEFORE="$(prices <<<"$h")"; S_BEFORE="$(products <<<"$h")"; J_BEFORE="$(supplier 0 <<<"$h")"
[[ "$(grep -c '^item=' <<<"$S_BEFORE")" -ge 3 && -n "$C_BEFORE" && "$B_BEFORE" != "none" && "$J_BEFORE" != "none" ]]; check "0.3.6 on the front page: selected products, category cards, brands and the supplier section" $?
[[ "$(cssver <<<"$h")" == "0.3.6" ]]; check "0.3.6: tecteb-home.css?ver=0.3.6" $? "$(cssver <<<"$h")"
CSS_OLD="$(curl -s "$SITE/wp-content/plugins/tecteb-homepage/assets/css/tecteb-home.css?ver=0.3.6" | wc -c)"

out="$(q plugin install "$NEW" --force)"; check "0.3.7 installed over 0.3.6 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-homepage --field=status)" == "active" && "$(q plugin get tecteb-homepage --field=version)" == "0.3.7" ]]; check "still active, version 0.3.7" $?
diff -rq --exclude=.gitkeep --exclude=languages "$HERE/tecteb-homepage" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
h="$(page '')"
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT_BEFORE" ]]; check "after the update and a page view: saved settings unchanged byte for byte (no migration, no write on read)" $?
[[ "$(products <<<"$h")" == "$S_BEFORE" ]]; check "products section: same title, link, products, order" $?
[[ "$(floats <<<"$h")" == "$F_BEFORE" ]]; check "Hero labels: same titles, archives and positions" $?
[[ "$(cats <<<"$h")" == "$C_BEFORE" ]]; check "category cards: same links in the same order" $?
[[ "$(brands <<<"$h")" == "$B_BEFORE" ]]; check "brands: same names, logos and links" $?
[[ "$(prices <<<"$h")" == "$P_BEFORE" ]]; check "prices: the same text, letter for letter" $?
[[ "$(supplier 0 <<<"$h")" == "$J_BEFORE" ]]; check "supplier section byte-identical (apart from ?ver=)" $? "$(diff <(echo "$J_BEFORE") <(supplier 0 <<<"$h") | head -4 | tr '\n' ' ')"
c="$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$SITE/wp-content/plugins/tecteb-homepage/assets/img/tecteb-logo-mark-96.webp")"; [[ "$c" == "200 image/webp" ]]; check "the WebP is served (200 image/webp)" $? "$c"
[[ "$(cssver <<<"$h")" == "0.3.7" ]]; check "tecteb-home.css?ver=0.3.7 — browsers and page caches fetch the new stylesheet" $? "$(cssver <<<"$h")"
CSS_NEW="$(curl -s "$SITE/wp-content/plugins/tecteb-homepage/assets/css/tecteb-home.css?ver=0.3.7" | wc -c)"
cmp -s <(curl -s "$SITE/wp-content/plugins/tecteb-homepage/assets/css/tecteb-home.css?ver=0.3.7") "$HERE/tecteb-homepage/assets/css/tecteb-home.css" && [[ "$CSS_NEW" -eq "$CSS_OLD" ]]; check "the served stylesheet is the shipped file byte for byte, same size as 0.3.6 (${CSS_OLD} → ${CSS_NEW} bytes; 0.3.7 changes no CSS)" $?
JAR="$(mktemp)"; curl -s -c "$JAR" -b "$JAR" -o /dev/null "$SITE/wp-login.php"; curl -s -c "$JAR" -b "$JAR" -o /dev/null --data-urlencode "log=tthadmin" --data-urlencode "pwd=local-only-2026" --data-urlencode "testcookie=1" "$SITE/wp-login.php"
c="$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$SITE/wp-admin/admin.php?page=tecteb-homepage")"; rm -f "$JAR"
[[ "$c" == "200" ]]; check "0.3.7: the settings page opens (200)" $? "$c"

rm -rf "$PLUG"; q plugin install "$OLD" --force --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.6" ]]; check "rollback: 0.3.6 reinstalled from its ZIP" $?
h="$(page '')"
[[ "$(products <<<"$h")" == "$S_BEFORE" && "$(supplier 0 <<<"$h")" == "$J_BEFORE" ]]; check "rollback: products and supplier section exactly as 0.3.6" $?
[[ "$(floats <<<"$h")" == "$F_BEFORE" && "$(cats <<<"$h")" == "$C_BEFORE" && "$(brands <<<"$h")" == "$B_BEFORE" && "$(prices <<<"$h")" == "$P_BEFORE" ]]; check "rollback: Hero labels, category cards, brands and prices as before" $?
[[ "$(cssver <<<"$h")" == "0.3.6" ]]; check "rollback: tecteb-home.css?ver=0.3.6" $?
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
