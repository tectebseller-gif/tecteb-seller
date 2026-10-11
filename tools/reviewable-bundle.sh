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

# --- 2. THE WHOLE SOURCE, not a hand-picked part of it ----------------------
#
# «آرشیو ناقص با عنوان «سورس کامل» تحویل داده نشود» — and until this round
# that is what happened: section 2 copied five chosen directories, so the
# archive held 49 PHP files out of 505 and the one stub the report leaned on
# (`tools/wp-stubs/woocommerce-product.php`) was not in it at all. A reviewer
# could not run a single test.
#
# So: all of `src/`, the plugin's entry points, and everything a test run
# needs to BOOT — `composer.json` and the lockfile that pins PHPUnit,
# `phpunit.xml.dist` with its suite definitions, and the three bootstraps.
# `tests/` is copied whole for the same reason it always was: a reviewer who
# wants to check that the concurrency test is not the only test needs the rest
# of it to look at.
cp -r src "${BUNDLE}/src"
cp -r tests "${BUNDLE}/tests"
cp tecteb-marketplace-core.php uninstall.php composer.json phpunit.xml.dist "${BUNDLE}/"
[ -f composer.lock ] && cp composer.lock "${BUNDLE}/"
[ -f package.json ] && cp package.json "${BUNDLE}/"
[ -f package-lock.json ] && cp package-lock.json "${BUNDLE}/"
[ -f readme.txt ] && cp readme.txt "${BUNDLE}/"
[ -d assets ] && cp -r assets "${BUNDLE}/assets"
[ -d languages ] && cp -r languages "${BUNDLE}/languages"

# The disposable database's configuration is DESCRIBED, never copied. Even a
# throwaway password has no business travelling in an archive, and the file
# is what every suite reads, so an example is what a reviewer needs.
cat > "${BUNDLE}/.env.testing.example" <<'ENVEOF'
# Copy to .env.testing. A DISPOSABLE database with synthetic data only —
# the database suite refuses to run unless the name is tmc_test.
export TMC_TEST_DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=tmc_test;charset=utf8mb4"
export TMC_TEST_DB_USER="tmc_test"
export TMC_TEST_DB_PASS="<choose one>"
ENVEOF

# --- 3. the scripts that produced every file in section 1 -------------------
#
# The whole tree, so `tools/wp-stubs/` comes with it — a flat `tools/*.php`
# does not recurse, which is how the WordPress stubs went missing. Only
# `node_modules` is left out: 22 MB of third-party code that `package.json`
# names and `npm ci` restores.
mkdir -p "${BUNDLE}/tools"
( cd tools && find . -type f -not -path './browser/node_modules/*' -print0 \
    | while IFS= read -r -d '' f; do
        mkdir -p "${BUNDLE}/tools/$(dirname "$f")"
        cp "$f" "${BUNDLE}/tools/$f"
      done )

# --- 4. the documents a reviewer needs next to the transcripts --------------
mkdir -p "${BUNDLE}/docs"
# The delivery note for THIS release travels with it: a reader holding the
# transcripts should not have to go and find what they were supposed to show.
# `upgrade-and-rollback.md` is here IN FULL for the same reason — the previous
# bundle referred to it and did not carry it.
LATEST_NOTE="$(ls -1 docs/phase-*.md | sed 's/.*phase-\([0-9]*\)-.*/\1 &/' | sort -n | tail -1 | cut -d' ' -f2)"
LATEST_CHECKLIST="$(ls -1 docs/staging-checklist-*.md 2>/dev/null | sort -V | tail -1)"
for d in docs/evidence-manifest.txt docs/owner-guide-fa.md docs/acceptance-walkthrough-fa.md \
         docs/feature-inventory.md docs/open-decisions.md docs/upgrade-and-rollback.md \
         docs/compatibility-matrix.md docs/decision-log.md docs/staging-test-checklist-fa.md \
         "${LATEST_NOTE}" "${LATEST_CHECKLIST}"; do
  [ -f "$d" ] && cp "$d" "${BUNDLE}/docs/"
done
[ -d docs/adr ] && cp -r docs/adr "${BUNDLE}/docs/adr"
[ -f CLAUDE.md ] && cp CLAUDE.md "${BUNDLE}/"

# --- 4b. the exact commands, so «reproducible» is not an adjective ----------
cat > "${BUNDLE}/REPRODUCE.txt" <<REPRODUCEEOF
How to run everything in this archive
=====================================

Requires: PHP 8.1+ (8.4 is what the delivered run used), Composer, a
DISPOSABLE MariaDB/MySQL, and — for the WooCommerce evidence — a disposable
WordPress with WooCommerce 11.0.1 and WP-CLI.

  composer install
  cp .env.testing.example .env.testing      # then set a password and create
                                            # the database named tmc_test

  vendor/bin/phpunit --testsuite unit
  vendor/bin/phpunit --testsuite architecture
  vendor/bin/phpunit --testsuite contract  --bootstrap tests/bootstrap-contract.php
  set -a; . ./.env.testing; set +a
  vendor/bin/phpunit --testsuite database  --bootstrap tests/bootstrap-database.php
  bash tools/build.sh && vendor/bin/phpunit --testsuite packaging

  bash tools/lint.sh                        # php -l on every runtime file
  vendor/bin/phpcs --config-set installed_paths vendor/phpcompatibility/php-compatibility,vendor/phpcsstandards/phpcsutils
  composer compat                           # PHPCompatibility, testVersion 8.1-

This round's own evidence, on a disposable WordPress with REAL WooCommerce:

  WP_PATH=/path/to/disposable/wp WPCLI=/usr/local/bin/wp \\
    bash tools/alpha43-reproduction.sh docs/evidence/alpha43-reproduction

  TMC_BASES="/path/alpha.40.zip /path/alpha.42.zip" PHPBIN=php WPCLI=/usr/local/bin/wp \\
    bash tools/upgrade-alpha40-check.sh docs/evidence/upgrade-alpha43

The three tests that answer §1–§4 directly:

  vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php \\
    --filter 'ProjectedFactsTest|SanitizeTitleTest'
  vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \\
    --filter 'SimultaneousCreateMediaTest|ProductFormPostTest'

The raw transcript of the delivered run is in docs/evidence/.
REPRODUCEEOF

cat > "${BUNDLE}/README-fa.txt" <<'README'
بستهٔ شواهد قابل بررسی
======================

این آرشیو همان چیزی است که در گزارش تحویل به آن ارجاع داده شده — نه اشاره به
مسیری در مخزن، بلکه خودِ فایل‌ها.

چه چیزی اینجاست
---------------
  src/        **کل سورس اجرایی** همان کامیتی که بسته از آن ساخته شده — هر ۵۰۶
              فایل، نه زیرمجموعه. (در `alpha.42` این بخش فقط ۴۹ فایل داشت و
              «سورس کامل» نامیده شده بود؛ این اصلاح شد.)
  tests/      کل سوییت آزمون، به‌علاوهٔ سه bootstrap.
  tools/      همهٔ اسکریپت‌ها، شامل `tools/wp-stubs/` (بدل‌های وردپرس و ووکامرس
              که گزارش به آن‌ها تکیه دارد) — فقط `browser/node_modules` نیامده،
              چون `package.json` آن را نام می‌برد و `npm ci` برمی‌گرداندش.
  composer.json · composer.lock · phpunit.xml.dist
              هر چیزی که برای **اجرا شدن** آزمون‌ها لازم است.
  REPRODUCE.txt  فرمان دقیق هر اجرا، از نصب تا شواهد این دور.
  .env.testing.example  پیکربندی دیتابیس یکبارمصرف — **توصیف‌شده، نه کپی‌شده**.
              هیچ کلید، رمز، دیتابیس سایت یا دادهٔ شخصی در این آرشیو نیست.
  docs/evidence/  هر فایل متنیِ docs/evidence: رونوشت خام PHPUnit، فهرست بررسی‌ها،
              اندازه‌گیری‌های JSON، لاگ‌ها و هش‌ها.
  docs/       راهنمای مالک، **سند کامل ارتقا و بازگشت**، چک‌لیست staging،
              گزارش تحویل این دور، ADRها، فهرست امکانات، تصمیم‌های باز، و
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
  REPRODUCE.txt                                     فرمان دقیق هر اجرا
  docs/evidence/alpha43-reproduction/run.txt        ۴۸ بررسی این دور، روی
                                                    ووکامرس ۱۱٫۰٫۱ واقعی
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
