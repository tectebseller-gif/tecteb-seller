#!/usr/bin/env bash
# The evidence a reviewer can actually be handed.
#
# «ادعای وجود فایل در مخزن جای تحویل قابل‌دسترسی را نمی‌گیرد» — naming a
# repository path is not a delivery. Someone who has the ZIP and the delivery
# note still has to clone a 1.4 GB repository to read a single transcript.
#
# The evidence archive is ~77 MB because of screenshots and a walkthrough
# video, and that is what makes it awkward to send. But the part a reviewer
# actually reads — the transcripts, the check lists, the JSON measurements,
# the hashes, and the sources that produced them — is text, and text
# compresses. So this builds a SECOND archive out of exactly that part:
# everything under docs/evidence that is not an image or a video, plus the
# test suite and the scripts that wrote those files.
#
# It is not a replacement for the evidence archive. Images prove layout, and
# nothing here does. It is the half that can travel in a message.
#
#   bash tools/reviewable-bundle.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

SLUG='tecteb-marketplace-core'
VERSION="$(grep -m1 '^ \* Version:' tecteb-marketplace-core.php | awk '{print $3}')"
# TMC_DIST, honoured — because `build.sh` is run against a throwaway dist by
# the packaging test that asks «are two builds of the same source identical?».
# This script ignored it, so that check wrote its rebuilt bundle over the REAL
# `dist/` while its own SHA256SUMS went to the temp directory and was deleted.
# The delivered archive and the delivered checksum file then disagreed, and
# the owner found it. A check must not mutate the thing it is checking — the
# test's own docblock says so, and this is the file that got around it.
DIST="${TMC_DIST:-dist}"
OUT="${DIST}/${SLUG}-reviewable-${VERSION}.tar.gz"
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT
BUNDLE="${STAGE}/${SLUG}-reviewable-${VERSION}"
mkdir -p "${BUNDLE}"

# --- 1. the evidence, minus the bytes that do not compress ------------------
#
# Excluded by extension, not by folder: a text report living beside a
# screenshot is still a text report, and a folder-level exclusion would drop
# it. The manifest that ships alongside names every excluded file with its
# hash, so nothing becomes unverifiable by being left out.
find docs/evidence -type f \
  ! \( -iname '*.png' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.webp' \
       -o -iname '*.gif' -o -iname '*.pdf' -o -iname '*.webm' -o -iname '*.mp4' \) \
  -print0 | while IFS= read -r -d '' f; do
    mkdir -p "${BUNDLE}/$(dirname "$f")"
    cp "$f" "${BUNDLE}/$f"
  done

# --- 2. the sources under test, and the sources that test them --------------
#
# The whole suite, not a hand-picked subset: a reviewer who wants to check
# that the concurrency test is not the only test, or that a guard is not
# asserted somewhere weaker, needs the rest of it to look at.
cp -r tests "${BUNDLE}/tests"
mkdir -p "${BUNDLE}/src/Modules/Migration" "${BUNDLE}/src/Modules/Vendor" \
         "${BUNDLE}/src/Infrastructure/WordPress" "${BUNDLE}/src/Contracts"
cp -r src/Modules/Migration/Application     "${BUNDLE}/src/Modules/Migration/"
cp -r src/Modules/Migration/Infrastructure  "${BUNDLE}/src/Modules/Migration/"
cp -r src/Modules/Vendor/Application        "${BUNDLE}/src/Modules/Vendor/"
cp    src/Infrastructure/WordPress/WpAuditRepository.php "${BUNDLE}/src/Infrastructure/WordPress/"
cp    src/Contracts/AuditRepositoryInterface.php         "${BUNDLE}/src/Contracts/"
cp    src/Contracts/TransactionInterface.php             "${BUNDLE}/src/Contracts/"

# --- 3. the scripts that produced every file in section 1 -------------------
mkdir -p "${BUNDLE}/tools/browser"
cp tools/*.sh tools/*.php "${BUNDLE}/tools/" 2>/dev/null || true
cp tools/browser/*.mjs "${BUNDLE}/tools/browser/" 2>/dev/null || true

# --- 4. the index of what is NOT here ---------------------------------------
mkdir -p "${BUNDLE}/docs"
# The delivery note for THIS release travels with it: a reader holding the
# transcripts should not have to go and find what they were supposed to show.
LATEST_NOTE="$(ls -1 docs/phase-*.md | sed 's/.*phase-\([0-9]*\)-.*/\1 &/' | sort -n | tail -1 | cut -d' ' -f2)"
for d in docs/evidence-manifest.txt docs/owner-guide-fa.md docs/acceptance-walkthrough-fa.md \
         docs/feature-inventory.md docs/open-decisions.md "${LATEST_NOTE}"; do
  [ -f "$d" ] && cp "$d" "${BUNDLE}/docs/"
done

cat > "${BUNDLE}/README-fa.txt" <<'README'
بستهٔ شواهد قابل بررسی
======================

این آرشیو همان چیزی است که در گزارش تحویل به آن ارجاع داده شده — نه اشاره به
مسیری در مخزن، بلکه خودِ فایل‌ها.

چه چیزی اینجاست
---------------
  docs/evidence/  هر فایل متنیِ docs/evidence: رونوشت خام PHPUnit، فهرست بررسی‌ها،
              اندازه‌گیری‌های JSON، لاگ‌ها و هش‌ها.
  tests/      کل سوییت آزمون. نه یک زیرمجموعهٔ دست‌چین‌شده — کسی که می‌خواهد
              ببیند آزمون هم‌زمانی تنها آزمون نیست، باید بقیه را هم ببیند.
  src/        کدی که زیر آن آزمون‌هاست: مهاجرت، گزارش فعالیت پرسنل، مخزن
              ممیزی، و دو قراردادی که تراکنش و ممیزی را تعریف می‌کنند.
  tools/      اسکریپت‌هایی که هر فایل بخش evidence را نوشته‌اند.
  docs/       راهنمای مالک، رویهٔ شواهد، فهرست امکانات، تصمیم‌های باز، و
              فهرست هشِ چیزهایی که اینجا نیستند.

چه چیزی اینجا نیست، و چرا
-------------------------
تصویرها و ویدئوی مسیر کامل. آن‌ها ~۷۷ مگابایت‌اند و در آرشیو شواهد
(`tecteb-marketplace-core-evidence-<version>.tar.gz`) می‌آیند.

اینجا نبودنشان یعنی **هیچ ادعایی دربارهٔ چیدمان در این بسته قابل بررسی نیست**.
چیدمان را تصویر ثابت می‌کند و متن نمی‌تواند.

ولی چیزی غیرقابل‌بررسی نمی‌شود: `docs/evidence-manifest.txt` نام، اندازه و
SHA-256 هر فایلِ نیامده را دارد. آرشیو تصویرها را بگیرید و هر فایلی را با همان
فهرست بسنجید — بدون اینکه لازم باشد به آرشیو یا به این مخزن اعتماد کنید.

از کجا شروع کنید
----------------
  docs/evidence/concurrency/01-concurrency-run.txt  رونوشت خام ۹ آزمون هم‌زمانی
  docs/evidence/concurrency/02-falsification.txt    همان آزمون‌ها با گاردِ برداشته‌شده
  docs/evidence/concurrency/sources/                خودِ فایل‌ها، با SHA-256
  docs/evidence/staff-activity/                     شمارش دقیق روی >۲۰۰ رویداد،
                                                    و همان آزمون با گاردِ برداشته‌شده
                                                    (۱۵۵ به‌جای ۲۶۱)
  docs/evidence/walkthrough-roles/01-checks.txt     ۵۶ بررسی مسیر چهارنقشی
  docs/owner-guide-fa.md                            راهنمای نصب و مسیر کلیکی

SHA256SUMS هر فایل این بسته را پوشش می‌دهد.
README

# --- 5. hash everything in the bundle ---------------------------------------
( cd "${BUNDLE}" && find . -type f ! -name SHA256SUMS -print0 \
    | LC_ALL=C sort -z \
    | xargs -0 sha256sum > SHA256SUMS )

mkdir -p "${DIST}"
# Byte-reproducible, like every other archive this repository ships: fixed
# mtime, fixed ownership, sorted entries. A hash recorded for an archive that
# changes on every rebuild is a hash nobody can check.
SOURCE_DATE="${SOURCE_DATE_EPOCH:-1757203200}"
tar --owner=0 --group=0 --numeric-owner --sort=name \
    --mtime="@${SOURCE_DATE}" --format=gnu \
    -czf "${OUT}" -C "${STAGE}" "${SLUG}-reviewable-${VERSION}"

FILES=$(tar -tzf "${OUT}" | grep -cv '/$')
SIZE=$(stat -c%s "${OUT}")
stat -c%s "${OUT}" | awk -v f="${FILES}" -v o="${OUT}" '{printf "reviewable bundle: %s files, %.1f MB -> %s\n", f, $1/1048576, o}'
sha256sum "${OUT}"
