#!/usr/bin/env bash
# Homepage 0.3.6 → 0.3.7 و بازگشت، از خودِ دو ZIP، روی بستر Elementor ِ واقعی (tth_perf، 127.0.0.1:8092) — نه staging،
# نه سایت اصلی. آنچه این ارتقا عوض می‌کند فقط اینجا سنجیدنی است: CSS و دارایی‌های Elementor ِ برگهٔ نخستی که جایگزین
# شده. صفحهٔ چاپ‌شده (<main>) باید بایت‌به‌بایت همان بماند (فقط ?ver=)، و option بایت‌به‌بایت.
#
#   bash perf/tests/upgrade-home-elementor.sh plugin/dist/tecteb-homepage-0.3.6.zip plugin/dist/tecteb-homepage-0.3.7.zip
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OLD="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"; NEW="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"
WPP=/home/user/wp2/core; W="wp --allow-root --path=$WPP"; SITE=http://127.0.0.1:8092
PASS=0; FAIL=0
check() { if [[ "$2" == "0" ]]; then PASS=$((PASS+1)); echo "PASS $1"; else FAIL=$((FAIL+1)); echo "FAIL $1${3:+  — $3}"; fi; }
q() { $W "$@" 2>/dev/null; }
[[ "$(q eval 'echo DB_NAME;')" == "tth_perf" ]] || { echo "refused: not the tth_perf testbed" >&2; exit 1; }
heads() { python3 -c 'import re,sys; h=sys.stdin.read().partition("</head>")[0]; print(" ".join(sorted(re.sub(r"-css$","",m) for m in re.findall(r"<link[^>]*rel=.stylesheet.[^>]*id=.([^\x27\x22]+)",h))))'; }
main() { python3 -c 'import re,sys; h=sys.stdin.read(); m=re.search(r"<main[^>]*>.*?</main>",h,re.S); print(re.sub(r"([?&]ver=)[0-9.]+",r"\1X",m.group(0)) if m else "none")'; }
FRONT="$(q option get page_on_front)"
FRONT_ONLY=(swiper e-swiper widget-image-carousel widget-testimonial widget-counter e-animation-grow "elementor-post-$FRONT")
PLUG="$WPP/wp-content/plugins/tecteb-homepage"
q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"; q plugin install "$OLD" --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.6" ]]; check "0.3.6 installed and active from its ZIP" $?
OPT="$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')"
h="$(curl -sL "$SITE/")"; HB="$(heads <<<"$h")"; MB="$(main <<<"$h")"
[[ "$MB" != "none" ]] && grep -q 'tecteb-home-front' <<<"$MB"; check "0.3.6: front page replaced by the Tecteb homepage" $?
python3 -c 'import sys; h=set(sys.argv[1].split()); sys.exit(0 if all(x in h for x in sys.argv[2:]) else 1)' "$HB" "${FRONT_ONLY[@]}"; check "0.3.6: the replaced Elementor front page's post-$FRONT.css, swiper, carousel, testimonial, counter and grow still load in <head> (the cost 0.3.7 removes)" $? "$HB"
out="$(q plugin install "$NEW" --force)"; check "0.3.7 installed over 0.3.6 from its ZIP (--force)" $? "$out"
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.7" && "$(q plugin get tecteb-homepage --field=status)" == "active" ]]; check "still active, version 0.3.7" $?
diff -rq --exclude=.gitkeep --exclude=languages "$HERE/plugin/tecteb-homepage" "$PLUG" >/dev/null; check "installed files are byte-identical to the source" $?
h="$(curl -sL "$SITE/")"; HA="$(heads <<<"$h")"
[[ "$(main <<<"$h")" == "$MB" ]]; check "the printed front page (<main>) is byte-identical to 0.3.6 apart from ?ver=" $? "$(diff <(echo "$MB") <(main <<<"$h") | head -4 | tr '\n' ' ')"
python3 -c 'import sys; h=set(sys.argv[1].split()); sys.exit(0 if not any(x in h for x in sys.argv[2:]) else 1)' "$HA" "${FRONT_ONLY[@]}"; check "0.3.7: none of the replaced front page's assets load" $? "$HA"
python3 -c 'import sys; a=set(sys.argv[1].split()); b=set(sys.argv[2].split()); sys.exit(0 if b <= a and len(b) < len(a) else 1)' "$HB" "$HA"; check "0.3.7: <head> CSS is a strict subset of 0.3.6's ($(wc -w <<<"$HB") → $(wc -w <<<"$HA"))" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT" ]]; check "settings unchanged byte for byte (no migration)" $?
for p in '' 'shop/' 'cart/'; do c="$(curl -sL -o /dev/null -w '%{http_code}' "$SITE/$p")"; [[ "$c" == "200" ]]; check "/$p answers 200 with 0.3.7" $? "$c"; done
rm -rf "$PLUG"; q plugin install "$OLD" --force --activate >/dev/null
[[ "$(q plugin get tecteb-homepage --field=version)" == "0.3.6" ]]; check "rollback: 0.3.6 reinstalled from its ZIP" $?
h="$(curl -sL "$SITE/")"
[[ "$(main <<<"$h")" == "$MB" && "$(heads <<<"$h")" == "$HB" ]]; check "rollback: front page and <head> CSS exactly as 0.3.6 again" $?
[[ "$(q eval 'echo md5(serialize(get_option("tecteb_home_settings")));')" == "$OPT" ]]; check "rollback: settings untouched" $?
q plugin deactivate tecteb-homepage >/dev/null; rm -rf "$PLUG"; ln -s "$HERE/plugin/tecteb-homepage" "$PLUG"; q plugin activate tecteb-homepage >/dev/null
echo; echo "$PASS passed, $FAIL failed"; exit $(( FAIL > 0 ? 1 : 0 ))
