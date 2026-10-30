#!/usr/bin/env bash
# چرخهٔ کامل روی ZIP ِ ساخته‌شده، در وردپرس یکبارمصرف محلی — نه staging، نه سایت اصلی.
#
#   bash tests/lifecycle.sh dist/tecteb-homepage-0.1.0.zip
#
# نصب از ZIP · برابری بایت‌به‌بایت با سورس · سه سوییت روی نسخهٔ نصب‌شده ·
# تمرین بازگشت (صفحهٔ اصلی قبلی ← غیرفعال‌سازی ← حذف) · و اینکه هیچ ردیف
# وردپرس/ووکامرس جز option ِ خودِ افزونه تکان نخورد.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
WPP="${WP_PATH:-/home/user/wp/core}"
W="wp --allow-root --path=$WPP"
SITE="${SITE:-http://127.0.0.1:8090}"
PASS=0; FAIL=0
check() { if [[ "$2" == "0" ]]; then PASS=$((PASS+1)); echo "PASS $1"; else FAIL=$((FAIL+1)); echo "FAIL $1${3:+  — $3}"; fi; }
q() { $W "$@" 2>/dev/null; }
cd "$HERE"

[[ "$(q eval 'echo DB_NAME;')" == "tth_disposable" ]] || { echo "refused: not the disposable site" >&2; exit 1; }

# --- ۱. نصب از ZIP
PLUG="$WPP/wp-content/plugins/tecteb-homepage"
q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"
out="$(q plugin install "$ZIP" --activate)"; check "install: from the ZIP and activate" $? "$out"
diff -rq --exclude=.gitkeep --exclude=languages "$HERE/tecteb-homepage" "$PLUG" >/dev/null; check "install: installed files are byte-identical to the source" $?
[[ "$(q plugin get tecteb-homepage --field=version)" == "$(sed -n 's/^ \* Version: *//p' "$HERE/tecteb-homepage/tecteb-homepage.php")" ]]; check "install: version reported by WordPress matches" $?

# --- ۲. سه سوییت روی نسخهٔ نصب‌شده
python3 tests/static/check.py > /tmp/tth-static.txt; check "static suite (source = installed)" $? "$(tail -1 /tmp/tth-static.txt)"
q eval-file tests/fixtures/seed.php seed >/dev/null
q eval-file tests/integration/run.php > /tmp/tth-int.txt; check "integration suite on the installed ZIP" $? "$(tail -1 /tmp/tth-int.txt)"
if [[ -n "${AXE:-}" ]]; then
	q eval-file tests/fixtures/seed.php seed >/dev/null
	NODE_PATH=/opt/node-tools/node_modules node tests/browser/run.mjs > /tmp/tth-browser.txt 2>&1; check "browser suite on the installed ZIP" $? "$(tail -1 /tmp/tth-browser.txt)"
fi
tail -1 /tmp/tth-int.txt; [[ -n "${AXE:-}" ]] && tail -1 /tmp/tth-browser.txt

# --- ۳. تمرین بازگشت
q eval-file tests/fixtures/seed.php seed >/dev/null
q eval-file tests/fixtures/state.php shipped >/dev/null
OTHER="$(q eval 'echo get_option("tth_fixture_ids")["other"];')"
PAGE="$(q eval 'echo get_option("tth_fixture_ids")["page"];')"
q option update show_on_front page >/dev/null; q option update page_on_front "$OTHER" >/dev/null   # «صفحهٔ اصلی فعلی»
before="$(curl -s "$SITE/" | grep -c 'tecteb-home')"
q option update page_on_front "$PAGE" >/dev/null                                                # تعویض
on="$(curl -s "$SITE/")"
grep -q 'th-hero-title' <<<"$on"; check "switch: the new front page shows the design" $?
grep -q 'id="site-header"' <<<"$on" && grep -q 'id="site-footer"' <<<"$on"; check "switch: site header and footer kept" $?
digest() { q db query "SELECT (SELECT CONCAT(COUNT(*),':',MAX(ID)) FROM wp_posts),(SELECT COUNT(*) FROM wp_postmeta),(SELECT COUNT(*) FROM wp_terms),(SELECT COUNT(*) FROM wp_termmeta),(SELECT COUNT(*) FROM wp_term_relationships)" --skip-column-names; }
d0="$(digest)"
start=$(date +%s)
q option update page_on_front "$OTHER" >/dev/null                                                # گام ۱ بازگشت
back="$(curl -s "$SITE/")"
[[ "$(grep -c 'tecteb-home' <<<"$back")" == "$before" ]] && ! grep -q 'th-hero-title' <<<"$back"; check "rollback step 1: front page back to the previous page, nothing of ours on it" $?
q plugin deactivate tecteb-homepage >/dev/null                                                    # گام ۲
check "rollback step 2: deactivate" $?
curl -s "$SITE/" | grep -q 'tecteb-home'; [[ $? -ne 0 ]]; check "rollback step 2: front page clean after deactivation" $?
[[ "$(digest)" == "$d0" ]]; check "rollback: no WordPress/WooCommerce row changed by deactivation" $?
raw="$(curl -s "$SITE/tecteb-home-new/")"
grep -q '\[tecteb_home\]' <<<"$raw"; check "rollback note: the new page prints the bare shortcode once the plugin is off (keep it draft/private)" $?
q post update "$PAGE" --post_status=private >/dev/null
[[ "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/tecteb-home-new/")" == "404" ]]; check "rollback note: a private page is not reachable by visitors" $?
echo "rollback took $(( $(date +%s) - start ))s (two settings + deactivate, scripted)"
q plugin activate tecteb-homepage >/dev/null; q post update "$PAGE" --post_status=publish >/dev/null
[[ -n "$(q option get tecteb_home_settings --format=json)" ]]; check "reactivation: settings survive a deactivate/activate cycle" $?
d1="$(digest)"
q plugin deactivate tecteb-homepage >/dev/null
q plugin uninstall tecteb-homepage >/dev/null                                                     # گام ۳ (فقط اگر لازم)
[[ -z "$(q option get tecteb_home_settings 2>/dev/null)" ]] && [[ ! -d "$PLUG" ]]; check "uninstall: plugin files and its single option removed" $?
[[ "$(digest)" == "$d1" ]]; check "uninstall: no other row changed" $?

# --- بازنشانی برای توسعه
q option update show_on_front posts >/dev/null
q plugin install "$ZIP" --activate >/dev/null
echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ))
