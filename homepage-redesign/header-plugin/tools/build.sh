#!/usr/bin/env bash
# ZIP نصب افزونهٔ tecteb-header، تکرارپذیر: یک نسخه = همان بایت‌ها.
#
#   bash homepage-redesign/header-plugin/tools/build.sh
#
# پیش از بسته‌بندی: سه دارایی تأییدشده باید بایت‌به‌بایت همان نمونهٔ طرح B باشند، و هر
# فایل PHP روی PHP ِ موجود lint شود. مهر زمانی فایل‌ها از شمارهٔ نسخه مشتق می‌شود.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$HERE/tecteb-header"
PROTO="$HERE/../header"
DIST="${TTHH_DIST:-$HERE/dist}"
VERSION="$(sed -n 's/^ \* Version: *//p' "$SRC/tecteb-header.php")"
[[ "$VERSION" == "$(sed -n "s/^define( 'TECTEB_HEADER_VERSION', '\(.*\)' );/\1/p" "$SRC/tecteb-header.php")" ]] || { echo "version header and constant differ" >&2; exit 1; }
[[ "$VERSION" == "$(sed -n 's/^Stable tag: *//p' "$SRC/readme.txt")" ]] || { echo "readme Stable tag differs" >&2; exit 1; }

cmp -s "$SRC/assets/css/header-base.css" "$PROTO/shared/header-base.css" || { echo "header-base.css differs from approved prototype" >&2; exit 1; }
cmp -s "$SRC/assets/css/header-b.css"    "$PROTO/b/header-b.css"         || { echo "header-b.css differs from approved prototype" >&2; exit 1; }
cmp -s "$SRC/assets/js/header.js"        "$PROTO/shared/header.js"       || { echo "header.js differs from approved prototype" >&2; exit 1; }

while IFS= read -r f; do php -l "$f" >/dev/null || { echo "lint: $f" >&2; exit 1; }; done < <(find "$SRC" -name '*.php')

IFS=. read -r MA MI PA <<<"${VERSION%%-*}"
STAMP="$(date -u -d "2026-01-01 00:00 UTC + $(( MA*10000 + MI*100 + PA )) minutes" +%Y%m%d%H%M)"

mkdir -p "$DIST"
OUT="$DIST/tecteb-header-$VERSION.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
cp -rL "$SRC" "$TMP/tecteb-header"
find "$TMP/tecteb-header" -name '.gitkeep' -delete
find "$TMP" -exec touch -h -t "$STAMP" {} +
rm -f "$OUT"
(cd "$TMP" && find tecteb-header -print | LC_ALL=C sort | TZ=UTC zip -X -q -D "$OUT" -@)
(cd "$DIST" && sha256sum tecteb-header-*.zip > SHA256SUMS)
echo "$OUT"
cat "$DIST/SHA256SUMS"
