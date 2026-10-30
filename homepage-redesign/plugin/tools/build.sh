#!/usr/bin/env bash
# ZIP نصب افزونهٔ tecteb-homepage، تکرارپذیر: یک نسخه = همان بایت‌ها.
#
#   bash homepage-redesign/plugin/tools/build.sh
#
# پیش از بسته‌بندی: دارایی‌ها باید همان خروجی port-assets.py باشند (کسی CSS
# ِ ارسالی را دستی ویرایش نکرده)، و هر فایل PHP روی PHP موجود lint شود. مهر
# زمانی همهٔ فایل‌ها از شمارهٔ نسخه مشتق می‌شود، نه از ساعت (opcache با mtime
# می‌سنجد؛ مهر ثابت برای همهٔ نسخه‌ها ارتقا را باطل‌نشدنی می‌کند).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$HERE/tecteb-homepage"
DIST="${TTH_DIST:-$HERE/dist}"
VERSION="$(sed -n 's/^ \* Version: *//p' "$SRC/tecteb-homepage.php")"
[[ "$VERSION" == "$(sed -n "s/^define( 'TECTEB_HOME_VERSION', '\(.*\)' );/\1/p" "$SRC/tecteb-homepage.php")" ]] || { echo "version header and constant differ" >&2; exit 1; }
[[ "$VERSION" == "$(sed -n 's/^Stable tag: *//p' "$SRC/readme.txt")" ]] || { echo "readme Stable tag differs" >&2; exit 1; }

before="$(cd "$SRC" && sha256sum assets/css/*.css assets/js/*.js templates/sprite.svg)"
python3 "$HERE/tools/port-assets.py" >/dev/null
after="$(cd "$SRC" && sha256sum assets/css/*.css assets/js/*.js templates/sprite.svg)"
[[ "$before" == "$after" ]] || { echo "shipped assets were not the port-assets.py output; re-run it and review the diff" >&2; exit 1; }

while IFS= read -r f; do php -l "$f" >/dev/null || { echo "lint: $f" >&2; exit 1; }; done < <(find "$SRC" -name '*.php')

# مهر زمانی از نسخه: 0.1.0 ⇒ 2026-01-01 + (0*10000+1*100+0) دقیقه
IFS=. read -r MA MI PA <<<"${VERSION%%-*}"
STAMP="$(date -u -d "2026-01-01 00:00 UTC + $(( MA*10000 + MI*100 + PA )) minutes" +%Y%m%d%H%M)"

mkdir -p "$DIST"
OUT="$DIST/tecteb-homepage-$VERSION.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
cp -r "$SRC" "$TMP/tecteb-homepage"
find "$TMP/tecteb-homepage" -name '.gitkeep' -delete
find "$TMP" -exec touch -h -t "$STAMP" {} +
rm -f "$OUT"
(cd "$TMP" && find tecteb-homepage -print | LC_ALL=C sort | TZ=UTC zip -X -q -D "$OUT" -@)
(cd "$DIST" && sha256sum tecteb-homepage-*.zip > SHA256SUMS)
echo "$OUT"
cat "$DIST/SHA256SUMS"
