#!/usr/bin/env bash
# Header 0.2.6 روی Elementor ِ واقعی، با یک مرجع دقیق به‌جای فهرست نام‌ها: وقتی هدر ۲۶۵۲ جایگزین می‌شود، <head> باید
# دقیقاً همان CSS و JS ای را داشته باشد که Elementor برای همین صفحه **اگر قالب هدر اصلاً منتشر نشده بود** بارگذاری
# می‌کرد — نه کمتر (فوتر، Kit، صفحه چیزی از دست نمی‌دهند) و نه بیشتر — به‌اضافهٔ CSS و JS ِ خودِ هدر تک‌طب.
# مرجع با پیش‌نویس‌کردن موقت ۲۶۵۲ ساخته می‌شود (در پایان دوباره منتشر می‌شود).
#
# در سه حالت Elementor: پیش‌فرض (فایل CSS، آیکون SVG ِ درون‌خطی)؛ «Internal Embedding» (CSS ِ هر سند به‌صورت <style>،
# حالتی که فهرست PageSpeed ِ Production نشان می‌دهد)؛ و «Inline Font Icons» خاموش (Font Awesome و eicons به‌صورت فونت،
# حالتی که fontawesome/solid/regular/brands ِ فهرست Production نشان می‌دهد).
#
#   bash perf/tests/elementor-modes.sh
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE=http://127.0.0.1:8092; W="wp --allow-root --path=/home/user/wp2/core"
OUT="${OUT:-$HERE/perf/evidence/elementor-real/modes-tests.txt}"
PASS=0; FAIL=0; LOG=()
check() { if [[ "$2" == "0" ]]; then PASS=$((PASS+1)); LOG+=("PASS $1"); else FAIL=$((FAIL+1)); LOG+=("FAIL $1${3:+  — $3}"); fi; }
q() { $W "$@" 2>/dev/null; }
[[ "$(q eval 'echo DB_NAME;')" == "tth_perf" ]] || { echo "refused: not the tth_perf testbed" >&2; exit 1; }
HDR=2652
# همهٔ دارایی‌های صفحه: handle ِ CSS (فایل) و id ِ <style> ِ درون‌خطیِ post-*، و handle ِ JS؛ هر کدام با head/body.
assets() { curl -sL "$SITE${1:-/}" | python3 -c '
import re,sys; h=sys.stdin.read(); head,_,body=h.partition("</head>"); out=[]
for part,where in ((head,"head"),(body,"body")):
    out+= [where+":css:"+re.sub(r"-css$","",m) for m in re.findall(r"<link[^>]*rel=.stylesheet.[^>]*id=.([^\x27\x22]+)",part)]
    out+= [where+":inline:"+m for m in re.findall(r"<style[^>]*id=.(elementor-post-\d+)",part)]
    out+= [where+":js:"+re.sub(r"-js$","",m) for m in re.findall(r"<script[^>]*id=.([^\x27\x22]+)[^>]*\bsrc=",part)]
    # «Internal Embedding»: CSS ِ هر سند به یک <style> ِ مشترک (elementor-frontend-inline-css) افزوده می‌شود، پس سند از
    # روی قواعدش شناخته می‌شود: هر .elementor-<ID> که در یک <style> ِ این بخش آمده.
    out+= [where+":rules:elementor-"+m for st in re.findall(r"<style[^>]*>(.*?)</style>",part,re.S) for m in set(re.findall(r"\.elementor-(\d+)\b",st))]
print("\n".join(sorted(set(out))))'; }
OURS='^(head|body):(css|js):tecteb-header'
PRINT_BEFORE="$(q option get elementor_css_print_method)"; ICONS_BEFORE="$(q option get elementor_experiment-e_font_icon_svg)"
SAVED_HDR="$(q option get tecteb_header_settings --format=json)"
restore() { [[ -n "$SAVED_HDR" ]] && q option update tecteb_header_settings "$SAVED_HDR" --format=json >/dev/null; q option update elementor_css_print_method "${PRINT_BEFORE:-external}" >/dev/null; if [[ -n "$ICONS_BEFORE" ]]; then q option update elementor_experiment-e_font_icon_svg "$ICONS_BEFORE" >/dev/null; else q option delete elementor_experiment-e_font_icon_svg >/dev/null; fi; q post update $HDR --post_status=publish >/dev/null; q elementor flush-css >/dev/null; }
trap restore EXIT
for mode in default internal font-icons; do
  case $mode in
    default)    q option update elementor_css_print_method external >/dev/null; q option update elementor_experiment-e_font_icon_svg active >/dev/null ;;
    internal)   q option update elementor_css_print_method internal >/dev/null; q option update elementor_experiment-e_font_icon_svg active >/dev/null ;;
    font-icons) q option update elementor_css_print_method external >/dev/null; q option update elementor_experiment-e_font_icon_svg inactive >/dev/null ;;
  esac
  q elementor flush-css >/dev/null; curl -s -o /dev/null "$SITE/"; curl -s -o /dev/null "$SITE/shop/"
  for path in / /shop/; do
    WITH="$(assets "$path")"
    q post update $HDR --post_status=draft >/dev/null; q elementor flush-css >/dev/null; curl -s -o /dev/null "$SITE$path"
    REF="$(assets "$path")"
    q post update $HDR --post_status=publish >/dev/null; q elementor flush-css >/dev/null; curl -s -o /dev/null "$SITE$path"
    AGAIN="$(assets "$path")"
    LOG+=("[$mode $path] with replaced header: $(wc -l <<<"$WITH") assets · reference (header unpublished): $(wc -l <<<"$REF")")
    D="$(diff <(grep -Ev "$OURS" <<<"$WITH") <(grep -Ev "$OURS" <<<"$REF"))"
    [[ -z "$D" ]]; check "[$mode] $path: with header $HDR replaced, every CSS/JS (files and inline post CSS) is exactly what Elementor loads when that header does not exist — nothing missing, nothing extra" $? "$(tr '\n' ' ' <<<"$D")"
    [[ "$AGAIN" == "$WITH" ]]; check "[$mode] $path: same result after republishing (the reference step left no trace)" $?
    grep -Eq "$OURS" <<<"$WITH"; check "[$mode] $path: Tecteb header CSS and JS present" $?
  done
  # و برای اینکه مرجع چیزی بسنجد: جایگزینی خاموش ⇒ دارایی‌های ۲۶۵۲ واقعاً آنجا هستند.
  q eval '$s = get_option("tecteb_header_settings"); $s["replace"] = false; update_option("tecteb_header_settings", $s);' >/dev/null
  OFF="$(assets /)"
  q eval '$s = get_option("tecteb_header_settings"); $s["replace"] = true; update_option("tecteb_header_settings", $s);' >/dev/null
  EXTRA="$(comm -23 <(grep -Ev "$OURS" <<<"$OFF") <(grep -Ev "$OURS" <<<"$(assets /)") | tr '\n' ' ')"
  [[ -n "$EXTRA" ]] && grep -Eq "(css|inline):elementor-post-$HDR|head:rules:elementor-$HDR$" <<<"$OFF"; check "[$mode] control: with replacement off, header $HDR's own assets load (and are what 0.2.6 removes): $EXTRA" $?
done
{ echo "Elementor $(q plugin get elementor --field=version) · PRO Elements $(q plugin get pro-elements --field=version) · tecteb-header $(q plugin get tecteb-header --field=version)"; echo; printf '%s\n' "${LOG[@]}"; echo; echo "$PASS passed, $FAIL failed"; } > "$OUT"
grep -E "^(PASS|FAIL|\[)" "$OUT" | cut -c1-300; echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
