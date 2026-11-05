#!/usr/bin/env bash
# ارتقای 0.2.3 → 0.2.4 و بازگشت، از خودِ دو ZIP، روی وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/upgrade-023.sh dist/tecteb-header-0.2.3.zip dist/tecteb-header-0.2.4.zip
#
# 0.2.3 از ZIP نصب و فعال؛ تنظیمات مثل آزمون‌های مرورگری (سیزده دسته، منوی وردپرس، جایگزینی روشن). بعد 0.2.4 روی
# همان نصب: option باید بایت‌به‌بایت بماند، HTML ِ هدر (جز ?ver= ِ CSS) همان، CSS ِ تازه با ?ver=0.2.4 (پس کش مرورگر
# باطل می‌شود)، و فایل‌ها برابر سورس. سپس بازگشت به 0.2.3 از ZIP ِ خودش: همان HTML و همان option.
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
block() { python3 -c 'import re,sys; h=sys.stdin.read(); m=re.search(r"<div id=\"tth-root\".*?</dialog>\s*</div>",h,re.S); print(re.sub(r"([?&]ver=)[0-9.]+",r"\1X",m.group(0)) if m else "none")'; }
cssver() { python3 -c 'import re,sys; m=re.search(r"assets/css/header\.css\?ver=([0-9.]+)",sys.stdin.read()); print(m.group(1) if m else "none")'; }
cd "$HERE"
[[ "$(q eval 'echo DB_NAME;')" == "tth_disposable" ]] || { echo "refused: not the disposable site" >&2; exit 1; }
PLUG="$WPP/wp-content/plugins/tecteb-header"

q plugin deactivate tecteb-header >/dev/null; rm -rf "$PLUG"
q plugin install "$OLD" --activate >/dev/null
[[ "$(q plugin get tecteb-header --field=version)" == "0.2.3" ]]; check "0.2.3 installed and active from its ZIP" $?
q eval-file tests/fixtures/seed.php seed >/dev/null
q eval-file tests/fixtures/seed.php configure on >/dev/null
OPT_BEFORE="$(q eval 'echo md5(serialize(get_option("tecteb_header_settings")));')"
h="$(curl -s "$SITE/tthh-about/")"; B_BEFORE="$(block <<<"$h")"
[[ "$B_BEFORE" != "none" && "$(cssver <<<"$h")" == "0.2.3" ]]; check "0.2.3 prints Header B with header.css?ver=0.2.3" $? "$(cssver <<<"$h")"
grep -q 'class="tth-seller"' <<<"$B_BEFORE"; check "0.2.3: «همکاری تأمین‌کنندگان» in the menu row" $?

out="$(q plugin install "$NEW" --force)"; check "0.2.4 installed over 0.2.3 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-header --field=status)" == "active" && "$(q plugin get tecteb-header --field=version)" == "0.2.4" ]]; check "still active, version 0.2.4" $?
diff -rq --exclude=.gitkeep "$HERE/tecteb-header" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
h="$(curl -s "$SITE/tthh-about/")"
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_header_settings")));')" == "$OPT_BEFORE" ]]; check "saved settings unchanged byte for byte (no migration, no write on read)" $?
[[ "$(block <<<"$h")" == "$B_BEFORE" ]]; check "header HTML identical to 0.2.3 (only the stylesheet changed): logo, search, account, cart, categories + mega menu, menu, «همکاری تأمین‌کنندگان», drawer" $?
[[ "$(cssver <<<"$h")" == "0.2.4" ]]; check "header.css?ver=0.2.4 — browsers and page caches fetch the new stylesheet" $? "$(cssver <<<"$h")"
curl -s "$SITE/wp-content/plugins/tecteb-header/assets/css/header.css?ver=0.2.4" | grep -q 'grid-template-columns: subgrid'; check "the served header.css carries the one-row sticky rule" $?
for p in '' 'shop/' 'cart/'; do c="$(curl -s -o /dev/null -w '%{http_code}' "$SITE/$p")"; [[ "$c" == "200" ]]; check "page /$p answers 200 with 0.2.4" $? "$c"; done

rm -rf "$PLUG"; q plugin install "$OLD" --force --activate >/dev/null
[[ "$(q plugin get tecteb-header --field=version)" == "0.2.3" ]]; check "rollback: 0.2.3 reinstalled from its ZIP" $?
h="$(curl -s "$SITE/tthh-about/")"
[[ "$(block <<<"$h")" == "$B_BEFORE" && "$(cssver <<<"$h")" == "0.2.3" ]]; check "rollback: same header HTML, header.css?ver=0.2.3" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_header_settings")));')" == "$OPT_BEFORE" ]]; check "rollback: settings untouched" $?

# راه بازگشت فوری در 0.2.4 هم: مدیر با ?tth_original=1 هدر قدیمی Elementor را می‌بیند.
q plugin install "$NEW" --force >/dev/null
JAR="$(mktemp)"; curl -s -c "$JAR" -b "$JAR" -o /dev/null "$SITE/wp-login.php"; curl -s -c "$JAR" -b "$JAR" -o /dev/null --data-urlencode "log=tthadmin" --data-urlencode "pwd=local-only-2026" --data-urlencode "testcookie=1" "$SITE/wp-login.php"
ha="$(curl -s -b "$JAR" "$SITE/tthh-about/?tth_original=1")"; rm -f "$JAR"
grep -q 'data-elementor-type="header"' <<<"$ha" && ! grep -q 'data-tth-header' <<<"$ha"; check "0.2.4: ?tth_original=1 (admin) shows the old Elementor header — instant way back kept" $?

# بازگرداندن نصب به سورس این مخزن (پیوند نمادین).
q plugin deactivate tecteb-header >/dev/null; rm -rf "$PLUG"; ln -s "$HERE/tecteb-header" "$PLUG"; q plugin activate tecteb-header >/dev/null
q eval-file tests/fixtures/seed.php seed >/dev/null
echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
