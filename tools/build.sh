#!/usr/bin/env bash
#
# Reproducible build of the installable ZIP and the source archive.
#
# The install ZIP contains exactly ONE top-level directory,
# tecteb-marketplace-core/, with the main file inside it, and carries no
# vendor/, no tests, no tools, no DOCX, no .git and no dot-files.
#
# The ZIP is labelled UNVERIFIED: the staging install gate (a real
# WordPress activation) has not been executed. See docs/installation.md.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
SLUG="tecteb-marketplace-core"
DIST="${ROOT}/dist"
STAGE="${DIST}/build-stage"   # not dot-prefixed: the purge below scans for dot-files

VERSION="$(grep -oP '^\s*\*\s*Version:\s*\K[0-9A-Za-z.\-+]+' "${SLUG}.php" | head -1)"
[ -n "${VERSION}" ] || { echo "cannot read Version from ${SLUG}.php" >&2; exit 1; }

rm -rf "${DIST}"
mkdir -p "${STAGE}/${SLUG}"

# --- what ships -------------------------------------------------------------
cp "${SLUG}.php" "${STAGE}/${SLUG}/"
cp uninstall.php "${STAGE}/${SLUG}/"
cp readme.txt    "${STAGE}/${SLUG}/" 2>/dev/null || true
cp -r src        "${STAGE}/${SLUG}/"
cp -r assets     "${STAGE}/${SLUG}/"
mkdir -p "${STAGE}/${SLUG}/languages"
find languages -maxdepth 1 -type f \( -name '*.pot' -o -name '*.po' -o -name '*.mo' \) -exec cp {} "${STAGE}/${SLUG}/languages/" \; 2>/dev/null || true
cp languages/README.md "${STAGE}/${SLUG}/languages/" 2>/dev/null || true

# --- what must never ship (scoped to the payload, not the staging dir) ------
PAYLOAD="${STAGE}/${SLUG}"
find "${PAYLOAD}" -depth \( -name '.*' -o -name '*.docx' -o -name '*.zip' -o -name '*.log' \
     -o -name 'composer.*' -o -name 'package*.json' -o -name '*.dist' -o -name '*.map' \) -print -exec rm -rf {} +
find "${PAYLOAD}" -depth -type d \( -name vendor -o -name node_modules -o -name tests -o -name tools \) -print -exec rm -rf {} +

# Deterministic timestamps so two builds of the same source match byte for byte.
SOURCE_DATE="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct 2>/dev/null || echo 1757203200)}"
find "${STAGE}" -exec touch -h -d "@${SOURCE_DATE}" {} +

ZIP="${DIST}/${SLUG}.zip"
( cd "${STAGE}" && find . -type f | LC_ALL=C sort | sed 's|^\./||' | zip -q -X -9 "${ZIP}" -@ )

# --- source archive (tests, docs, lockfile, build tooling) ------------------
SRC_ARCHIVE="${DIST}/${SLUG}-source-${VERSION}.tar.gz"
# --cached --others --exclude-standard: everything tracked plus anything new
# that is not gitignored, so the archive reflects the working tree rather than
# only what happens to be committed at build time.
# dist/ is excluded: this is the SOURCE archive, and including it would ship
# the built ZIP plus a stale copy of this archive inside itself.
#
# The list is built explicitly and the build FAILS LOUDLY if it cannot be
# used. An earlier version fell back to a bare `tar .` when the git listing
# was unusable (for instance while a deleted file was still in the index),
# and that fallback silently swept tools/browser/node_modules into the
# archive — 8 MB of Playwright instead of 3.6 MB of source. A packaging
# assertion now checks for that, but the build must not paper over it either.
LIST="$(mktemp)"
LIST_OK="$(mktemp)"
trap 'rm -f "${LIST}" "${LIST_OK}"' EXIT

if git rev-parse --git-dir >/dev/null 2>&1; then
  # tracked files plus anything new that is not gitignored
  git ls-files -z --cached --others --exclude-standard | grep -zv '^dist/' > "${LIST}"
else
  find . -type f \
    -not -path './.git/*' -not -path './dist/*' -not -path './vendor/*' \
    -not -path '*/node_modules/*' -print0 | sed -z 's|^\./||' > "${LIST}"
fi

# Keep only paths that still exist: a file deleted but still listed would
# abort tar and, previously, trigger the silent fallback.
: > "${LIST_OK}"
while IFS= read -r -d '' f; do
  [ -f "${f}" ] && printf '%s\0' "${f}" >> "${LIST_OK}"
done < "${LIST}"

COUNT=$(tr -cd '\0' < "${LIST_OK}" | wc -c)
[ "${COUNT}" -gt 50 ] || { echo "source archive: file list looks wrong (${COUNT} entries)" >&2; exit 1; }

LC_ALL=C sort -zu "${LIST_OK}" -o "${LIST_OK}"
tar --null --files-from="${LIST_OK}" --owner=0 --group=0 --numeric-owner \
    --mtime="@${SOURCE_DATE}" --format=gnu -czf "${SRC_ARCHIVE}"

rm -rf "${STAGE}"

( cd "${DIST}" && sha256sum "${SLUG}.zip" "$(basename "${SRC_ARCHIVE}")" > SHA256SUMS )

cat > "${DIST}/READ-ME-BEFORE-INSTALL.txt" <<TXT
بازارگاه تک‌طب — Tecteb Marketplace Core ${VERSION}

وضعیت این بسته: تأییدنشده — نصب نشود
=====================================
گیت نصب Staging اجرا نشده است. WordPress و WooCommerce در محیط ساخت قابل
دانلود نبودند، بنابراین «نصب و فعال‌سازی واقعی»، آزمون یکپارچگی WP/WC و
حالت‌های HPOS با وضعیت Not Run باقی مانده‌اند — نه Passed.

این ZIP برای بررسی کد و اجرای آزمون‌ها در یک WordPress یکبارمصرف تحویل
می‌شود، نه برای نصب روی سایت واقعی یا Staging عملیاتی.

آنچه واقعاً آزموده شده و آنچه نشده، سطربه‌سطر در این فایل‌هاست:
  docs/phase-1-report.md
  docs/compatibility-matrix.md
  docs/installation.md

تصاویر رابط در docs/evidence/screenshots «نمونه رابط (خارج WordPress)» هستند
و اثبات کارکرد افزونه داخل wp-admin نیستند.
TXT

echo "--- dist ---"
ls -la "${DIST}"
echo
echo "--- ZIP top level (must be exactly one directory) ---"
unzip -Z1 "${ZIP}" | awk -F/ '{print $1}' | sort -u
echo
echo "--- ZIP integrity ---"
unzip -tqq "${ZIP}" && echo "unzip -t: OK"
echo "files: $(unzip -Z1 "${ZIP}" | wc -l)   size: $(stat -c%s "${ZIP}") bytes"
