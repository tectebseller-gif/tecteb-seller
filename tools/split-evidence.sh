#!/usr/bin/env bash
# The image-and-video archive, cut into pieces small enough to send.
#
# The owner asked for the evidence archive as a Release attachment. This
# environment has no tool that can create a GitHub Release or upload an asset
# to one — only read-only release tools — so the archive is cut into parts
# that fit in a message instead, with the one command that puts them back and
# the hash of the whole to check the result against.
#
# Nothing is deleted: the archive stays where it was, and the parts are extra
# files beside it.
#
#   bash tools/split-evidence.sh [archive] [part-size]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

SLUG='tecteb-marketplace-core'
VERSION="$(grep -m1 '^ \* Version:' tecteb-marketplace-core.php | awk '{print $3}')"
ARCHIVE="${1:-dist/${SLUG}-evidence-${VERSION}.tar.gz}"
SIZE="${2:-9m}"
OUTDIR="dist/evidence-parts"

[ -f "${ARCHIVE}" ] || { echo "no archive at ${ARCHIVE}" >&2; exit 2; }

rm -rf "${OUTDIR}"
mkdir -p "${OUTDIR}"
BASE="$(basename "${ARCHIVE}")"
split -b "${SIZE}" -d -a 2 "${ARCHIVE}" "${OUTDIR}/${BASE}.part"

WHOLE="$(sha256sum "${ARCHIVE}" | cut -c1-64)"
BYTES="$(stat -c%s "${ARCHIVE}")"
( cd "${OUTDIR}" && sha256sum ./*.part?? > PARTS.sha256 )

cat > "${OUTDIR}/JOIN-fa.txt" <<TXT
پیوستن تکه‌ها به هم
===================

همهٔ فایل‌های ${BASE}.part00 تا آخرین تکه را در یک پوشه بگذارید، بعد:

    cat ${BASE}.part* > ${BASE}
    sha256sum ${BASE}

باید دقیقاً این را بدهد:

    ${WHOLE}

اندازهٔ فایل کامل: ${BYTES} بایت

روی ویندوز (PowerShell):

    cmd /c copy /b "${BASE}.part*" "${BASE}"
    Get-FileHash "${BASE}" -Algorithm SHA256

اگر هش یکی نبود، یک تکه ناقص رسیده است؛ PARTS.sha256 هش هر تکه را جداگانه
دارد، پس می‌شود فهمید کدام یکی.

باز کردن:

    tar -tzf ${BASE} | head        # فهرست، بدون استخراج
    tar -xzf ${BASE}               # استخراج

داخلش ۳۲۹ فایل تصویر و ویدئوی شواهد است. فهرست نام، اندازه و SHA-256 هرکدام
در خودِ مخزن است: docs/evidence-manifest.txt — پس بدون اعتماد به این آرشیو
هم می‌شود هر فایل را سنجید.
TXT

COUNT=$(find "${OUTDIR}" -name '*.part*' | wc -l)
printf 'split: %s parts of %s → %s\n' "${COUNT}" "${SIZE}" "${OUTDIR}"
printf 'whole: %s  %s bytes\n' "${WHOLE}" "${BYTES}"
