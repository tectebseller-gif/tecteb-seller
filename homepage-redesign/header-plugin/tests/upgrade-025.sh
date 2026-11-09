#!/usr/bin/env bash
# ارتقای 0.2.5 → 0.2.6 و بازگشت، از خودِ دو ZIP، روی وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/upgrade-025.sh dist/tecteb-header-0.2.5.zip dist/tecteb-header-0.2.6.zip
#   WP_PATH=/home/user/wp2/core SITE=http://127.0.0.1:8092 bash tests/upgrade-025.sh …   # Elementor ِ واقعی
#
# 0.2.6 هیچ HTML ای را عوض نمی‌کند: هدر بایت‌به‌بایت همان 0.2.5 است (فقط ?ver=)، option بایت‌به‌بایت. تنها تفاوت این
# است که CSS و دارایی‌های قالب هدر Elementor ای که جایگزین شده (و چاپ نمی‌شود) دیگر بارگذاری نمی‌شوند — که فقط روی
# Elementor Pro ِ واقعی سنجیدنی است؛ روی بستر شبیه‌ساز آن بخش «اجرا نشد» اعلام می‌شود، نه «قبول».
# سپس بازگشت به 0.2.5. در پایان نصب به پیوند نمادینِ سورس این مخزن برمی‌گردد.
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
DB="$(q eval 'echo DB_NAME;')"; [[ "$DB" == "tth_disposable" || "$DB" == "tth_perf" ]] || { echo "refused: not a disposable site" >&2; exit 1; }
REAL="$(q eval 'echo ( class_exists( "ElementorPro\\Plugin" ) && ! defined( "TTHH_ELEMENTOR_EMULATOR" ) ) ? "yes" : "no";')"
# handle های CSS ِ <head> (بی -css).
heads() { python3 -c 'import re,sys; h=sys.stdin.read().partition("</head>")[0]; print(" ".join(sorted(re.sub(r"-css$","",m) for m in re.findall(r"<link[^>]*rel=.stylesheet.[^>]*id=.([^\x27\x22]+)",h))))'; }
HDR_ID="$(q eval '$q = get_posts( array( "post_type" => "elementor_library", "meta_key" => "_elementor_template_type", "meta_value" => "header", "fields" => "ids", "numberposts" => 1 ) ); echo $q ? $q[0] : 0;')"
PLUG="$WPP/wp-content/plugins/tecteb-header"

q plugin deactivate tecteb-header >/dev/null; rm -rf "$PLUG"
q plugin install "$OLD" --activate >/dev/null
[[ "$(q plugin get tecteb-header --field=version)" == "0.2.5" ]]; check "0.2.5 installed and active from its ZIP" $?
q eval-file tests/fixtures/seed.php seed >/dev/null
q eval-file tests/fixtures/seed.php configure on >/dev/null
# بستر Elementor ِ واقعی: seed.php قالب ۲۶۵۲ را با محتوای آزمونی خودش بازنویسی می‌کند؛ محتوای واقعی بستر برگردد.
[[ "$DB" == "tth_perf" ]] && $W --user=tthadmin eval-file "$HERE/../perf/tools/real-elementor-testbed.php" seed >/dev/null 2>&1
OPT_BEFORE="$(q eval 'echo md5(serialize(get_option("tecteb_header_settings")));')"
h="$(curl -sL "$SITE/tthh-about/")"; B_BEFORE="$(block 0 <<<"$h")"; HEAD_BEFORE="$(heads <<<"$h")"
[[ "$B_BEFORE" != "none" && "$(cssver <<<"$h")" == "0.2.5" ]]; check "0.2.5 prints Header B with header.css?ver=0.2.5" $? "$(cssver <<<"$h")"
if [[ "$REAL" == "yes" ]]; then grep -qw "elementor-post-$HDR_ID" <<<"$HEAD_BEFORE"; check "0.2.5 on real Elementor Pro: the replaced header's post-$HDR_ID.css still loads in <head> (the cost 0.2.6 removes)" $? "$HEAD_BEFORE"; fi

out="$(q plugin install "$NEW" --force)"; check "0.2.6 installed over 0.2.5 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-header --field=status)" == "active" && "$(q plugin get tecteb-header --field=version)" == "0.2.6" ]]; check "still active, version 0.2.6" $?
diff -rq --exclude=.gitkeep "$HERE/tecteb-header" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
h="$(curl -sL "$SITE/tthh-about/")"; HEAD_AFTER="$(heads <<<"$h")"
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_header_settings")));')" == "$OPT_BEFORE" ]]; check "saved settings unchanged byte for byte (no migration, no write on read)" $?
[[ "$(block 0 <<<"$h")" == "$B_BEFORE" ]]; check "header HTML byte-identical to 0.2.5 (apart from ?ver=): logo, search, account, cart, categories + mega menu, menu, drawer" $? "$(diff <(echo "$B_BEFORE") <(block 0 <<<"$h") | head -4 | tr '\n' ' ')"
[[ "$(cssver <<<"$h")" == "0.2.6" ]]; check "header.css?ver=0.2.6 — page caches fetch the new stylesheet URL" $? "$(cssver <<<"$h")"
cmp -s <(curl -s "$SITE/wp-content/plugins/tecteb-header/assets/css/header.css?ver=0.2.6") "$HERE/tecteb-header/assets/css/header.css"; check "served header.css is the shipped file" $?
if [[ "$REAL" == "yes" ]]; then
  GONE="$(comm -23 <(tr ' ' '\n' <<<"$HEAD_BEFORE" | sort) <(tr ' ' '\n' <<<"$HEAD_AFTER" | sort) | tr '\n' ' ')"
  ! grep -qw "elementor-post-$HDR_ID" <<<"$HEAD_AFTER"; check "0.2.6 on real Elementor Pro: post-$HDR_ID.css no longer loads; also gone: $GONE" $? "$HEAD_AFTER"
  python3 -c 'import sys; a=set(sys.argv[1].split()); b=set(sys.argv[2].split()); sys.exit(0 if b <= a and len(b) < len(a) else 1)' "$HEAD_BEFORE" "$HEAD_AFTER"; check "0.2.6: <head> CSS is a strict subset of 0.2.5's — nothing new added ($(wc -w <<<"$HEAD_BEFORE") → $(wc -w <<<"$HEAD_AFTER"))" $?
else echo "NOT RUN  asset checks: no real Elementor Pro on this site (emulator) — run with WP_PATH=/home/user/wp2/core SITE=http://127.0.0.1:8092"; fi
for p in '' 'shop/' 'cart/'; do c="$(curl -sL -o /dev/null -w '%{http_code}' "$SITE/$p")"; [[ "$c" == "200" ]]; check "page /$p answers 200 with 0.2.6" $? "$c"; done

rm -rf "$PLUG"; q plugin install "$OLD" --force --activate >/dev/null
[[ "$(q plugin get tecteb-header --field=version)" == "0.2.5" ]]; check "rollback: 0.2.5 reinstalled from its ZIP" $?
h="$(curl -sL "$SITE/tthh-about/")"
[[ "$(block 0 <<<"$h")" == "$B_BEFORE" && "$(cssver <<<"$h")" == "0.2.5" ]]; check "rollback: same header HTML, header.css?ver=0.2.5" $?
if [[ "$REAL" == "yes" ]]; then [[ "$(heads <<<"$h")" == "$HEAD_BEFORE" ]]; check "rollback: <head> CSS exactly as 0.2.5 again" $? "$(heads <<<"$h")"; fi
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_header_settings")));')" == "$OPT_BEFORE" ]]; check "rollback: settings untouched" $?

# راه بازگشت فوری در 0.2.6 هم: مدیر با ?tth_original=1 هدر قدیمی Elementor را با همهٔ دارایی‌هایش می‌بیند.
q plugin install "$NEW" --force >/dev/null
JAR="$(mktemp)"; curl -s -c "$JAR" -b "$JAR" -o /dev/null "$SITE/wp-login.php"; curl -s -c "$JAR" -b "$JAR" -o /dev/null --data-urlencode "log=tthadmin" --data-urlencode "pwd=local-only-2026" --data-urlencode "testcookie=1" "$SITE/wp-login.php"
ha="$(curl -sL -b "$JAR" "$SITE/tthh-about/?tth_original=1")"; rm -f "$JAR"
grep -q 'data-elementor-type="header"' <<<"$ha" && ! grep -q 'data-tth-header' <<<"$ha"; check "0.2.6: ?tth_original=1 (admin) shows the old Elementor header — instant way back kept" $?
if [[ "$REAL" == "yes" ]]; then grep -qw "elementor-post-$HDR_ID" <<<"$(heads <<<"$ha")"; check "0.2.6: …and with ?tth_original=1 its post-$HDR_ID.css is loaded" $?; fi

# بازگرداندن نصب به سورس این مخزن (پیوند نمادین).
q plugin deactivate tecteb-header >/dev/null; rm -rf "$PLUG"; ln -s "$HERE/tecteb-header" "$PLUG"; q plugin activate tecteb-header >/dev/null
q eval-file tests/fixtures/seed.php seed >/dev/null
# بستر Elementor ِ واقعی همان‌طور که بود برگردد: هدر B روشن، و محتوای واقعی قالب‌ها.
[[ "$DB" == "tth_perf" ]] && { q eval-file tests/fixtures/seed.php configure on >/dev/null; $W --user=tthadmin eval-file "$HERE/../perf/tools/real-elementor-testbed.php" seed >/dev/null 2>&1; }
echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
