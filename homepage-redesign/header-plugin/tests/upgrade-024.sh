#!/usr/bin/env bash
# ارتقای 0.2.4 → 0.2.5 و بازگشت، از خودِ دو ZIP، روی وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/upgrade-024.sh dist/tecteb-header-0.2.4.zip dist/tecteb-header-0.2.5.zip
#
# 0.2.5 فقط کارایی است. HTML ِ هدر باید همان 0.2.4 باشد جز سه چیز که با norm برگردانده می‌شوند: اسکریپت درون‌خطی
# حالت JS، لوگوی WebP ِ بی‌اتلاف به‌جای PNG، و fetchpriority="high" روی لوگوی نوار. tecteb-header.css به‌جای
# فایل جدا درون‌خطی چاپ می‌شود؛ header.css?ver=0.2.5 بی توضیح. option بایت‌به‌بایت. سپس بازگشت به 0.2.4.
# در پایان نصب به پیوند نمادینِ سورس این مخزن برمی‌گردد.
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
# بلوک هدر از صفحه (از <div id="tth-root" تا </dialog></div>)، با ?ver= برداشته؛ و نسخهٔ CSS ِ پایه.
block() { python3 -c 'import re,sys; h=sys.stdin.read(); m=re.search(r"<div id=\"tth-root\".*?</dialog>\s*</div>",h,re.S); s=re.sub(r"([?&]ver=)[0-9.]+",r"\1X",m.group(0)) if m else "none"
if sys.argv[1]=="1": s=re.sub(r"\n?<script data-no-optimize=\"1\"[^>]*nowprocket>.*?</script>","",s,count=1,flags=re.S).replace("tecteb-logo-mark-96.webp","tecteb-logo-mark-96.png").replace(" fetchpriority=\"high\"","")
s="\n".join(l for l in s.split("\n") if l.strip())  # PHP-comment blocks leave whitespace-only lines; they render nothing
print(s)' "${1:-0}"; }
cssver() { python3 -c 'import re,sys; m=re.search(r"assets/css/header\.css\?ver=([0-9.]+)",sys.stdin.read()); print(m.group(1) if m else "none")'; }
cd "$HERE"
[[ "$(q eval 'echo DB_NAME;')" == "tth_disposable" ]] || { echo "refused: not the disposable site" >&2; exit 1; }
PLUG="$WPP/wp-content/plugins/tecteb-header"

q plugin deactivate tecteb-header >/dev/null; rm -rf "$PLUG"
q plugin install "$OLD" --activate >/dev/null
[[ "$(q plugin get tecteb-header --field=version)" == "0.2.4" ]]; check "0.2.4 installed and active from its ZIP" $?
q eval-file tests/fixtures/seed.php seed >/dev/null
q eval-file tests/fixtures/seed.php configure on >/dev/null
OPT_BEFORE="$(q eval 'echo md5(serialize(get_option("tecteb_header_settings")));')"
h="$(curl -s "$SITE/tthh-about/")"; B_BEFORE="$(block 0 <<<"$h")"
[[ "$B_BEFORE" != "none" && "$(cssver <<<"$h")" == "0.2.4" ]]; check "0.2.4 prints Header B with header.css?ver=0.2.4" $? "$(cssver <<<"$h")"
grep -q 'assets/css/tecteb-header.css?ver=0.2.4' <<<"$h" && ! grep -q 'data-no-optimize="1"' <<<"$B_BEFORE"; check "0.2.4: tecteb-header.css as a file, no inline JS-mode script" $?
CSS_OLD="$(curl -s "$SITE/wp-content/plugins/tecteb-header/assets/css/header.css?ver=0.2.4" | wc -c)"

out="$(q plugin install "$NEW" --force)"; check "0.2.5 installed over 0.2.4 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-header --field=status)" == "active" && "$(q plugin get tecteb-header --field=version)" == "0.2.5" ]]; check "still active, version 0.2.5" $?
diff -rq --exclude=.gitkeep "$HERE/tecteb-header" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
h="$(curl -s "$SITE/tthh-about/")"; B_AFTER="$(block 0 <<<"$h")"
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_header_settings")));')" == "$OPT_BEFORE" ]]; check "saved settings unchanged byte for byte (no migration, no write on read)" $?
[[ "$(block 1 <<<"$h")" == "$B_BEFORE" ]]; check "header HTML identical to 0.2.4 apart from the inline script, the WebP logo and fetchpriority: logo, search, account, cart, categories + mega menu, menu, «همکاری تأمین‌کنندگان», drawer" $? "$(diff <(echo "$B_BEFORE") <(block 1 <<<"$h") | head -4 | tr '\n' ' ')"
python3 -c 'import sys,re; sys.exit(0 if re.search(r"<div id=\"tth-root\"[^>]*>\s*<script data-no-optimize=\"1\"", sys.stdin.read()) else 1)' <<<"$B_AFTER"; check "0.2.5: the inline script is the first child of #tth-root" $?
[[ "$(grep -o 'fetchpriority="high"' <<<"$B_AFTER" | wc -l)" == "1" ]]; check "0.2.5: fetchpriority=high on exactly one image (the bar logo; the drawer copy has none)" $?
c="$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$SITE/wp-content/plugins/tecteb-header/assets/img/tecteb-logo-mark-96.webp")"; [[ "$c" == "200 image/webp" ]]; check "the WebP logo is served (200 image/webp)" $? "$c"
[[ "$(cssver <<<"$h")" == "0.2.5" ]]; check "header.css?ver=0.2.5 — browsers and page caches fetch the new stylesheet" $? "$(cssver <<<"$h")"
! grep -q 'assets/css/tecteb-header.css' <<<"$h" && grep -q '<style id="tecteb-header-inline-css">' <<<"$h"; check "tecteb-header.css printed inline (one request fewer), not linked" $?
css="$(curl -s "$SITE/wp-content/plugins/tecteb-header/assets/css/header.css?ver=0.2.5")"
[[ ${#css} -lt $CSS_OLD ]] && grep -q 'grid-template-columns: subgrid' <<<"$css"; check "the served header.css is smaller (${CSS_OLD} → ${#css} bytes) and still carries the one-row sticky rule" $?
for p in '' 'shop/' 'cart/'; do c="$(curl -s -o /dev/null -w '%{http_code}' "$SITE/$p")"; [[ "$c" == "200" ]]; check "page /$p answers 200 with 0.2.5" $? "$c"; done

rm -rf "$PLUG"; q plugin install "$OLD" --force --activate >/dev/null
[[ "$(q plugin get tecteb-header --field=version)" == "0.2.4" ]]; check "rollback: 0.2.4 reinstalled from its ZIP" $?
h="$(curl -s "$SITE/tthh-about/")"
[[ "$(block 0 <<<"$h")" == "$B_BEFORE" && "$(cssver <<<"$h")" == "0.2.4" ]]; check "rollback: same header HTML as 0.2.4 (no inline script, PNG logo), header.css?ver=0.2.4" $?
grep -q 'assets/css/tecteb-header.css?ver=0.2.4' <<<"$h"; check "rollback: tecteb-header.css linked again" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_header_settings")));')" == "$OPT_BEFORE" ]]; check "rollback: settings untouched" $?

# راه بازگشت فوری در 0.2.5 هم: مدیر با ?tth_original=1 هدر قدیمی Elementor را می‌بیند.
q plugin install "$NEW" --force >/dev/null
JAR="$(mktemp)"; curl -s -c "$JAR" -b "$JAR" -o /dev/null "$SITE/wp-login.php"; curl -s -c "$JAR" -b "$JAR" -o /dev/null --data-urlencode "log=tthadmin" --data-urlencode "pwd=local-only-2026" --data-urlencode "testcookie=1" "$SITE/wp-login.php"
ha="$(curl -s -b "$JAR" "$SITE/tthh-about/?tth_original=1")"; rm -f "$JAR"
grep -q 'data-elementor-type="header"' <<<"$ha" && ! grep -q 'data-tth-header' <<<"$ha"; check "0.2.5: ?tth_original=1 (admin) shows the old Elementor header — instant way back kept" $?

# بازگرداندن نصب به سورس این مخزن (پیوند نمادین).
q plugin deactivate tecteb-header >/dev/null; rm -rf "$PLUG"; ln -s "$HERE/tecteb-header" "$PLUG"; q plugin activate tecteb-header >/dev/null
q eval-file tests/fixtures/seed.php seed >/dev/null
echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
