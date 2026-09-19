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

# The ZIP name carries the version, and previously built packages are NOT
# deleted: every delivered package must stay identifiable by name and hash
# (owner's standing delivery rule). Only this version's artefacts and the
# staging directory are replaced.
ZIP="${DIST}/${SLUG}-${VERSION}.zip"
PREVIOUS_HASH=""
if [ -f "${ZIP}" ]; then
  PREVIOUS_HASH="$(sha256sum "${ZIP}" | cut -d' ' -f1)"
fi
rm -rf "${STAGE}"
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
# Only the TOP-LEVEL development directories. Matching `-name vendor` at any
# depth is what silently dropped assets/vendor/ — the vendor area's entire
# stylesheet — out of 0.1.0-alpha.3: a directory named after Composer's,
# deleted because of its name rather than its contents. The packaging suite
# now asserts that every asset in the working tree reaches the ZIP.
for dev in vendor node_modules tests tools; do
  [ -e "${PAYLOAD}/${dev}" ] && { echo "removing ${dev}/"; rm -rf "${PAYLOAD:?}/${dev}"; }
done
# A Composer install nested deeper would still be wrong; refuse rather than
# guess, so the next surprise stops the build instead of shipping.
NESTED="$(find "${PAYLOAD}" -type f -name autoload.php -path '*/vendor/*' | head -1)"
[ -z "${NESTED}" ] || { echo "refusing to package a nested Composer vendor directory: ${NESTED}" >&2; exit 1; }

# Deterministic timestamps so two builds of the same source match byte for byte.
#
# A FIXED epoch, not the last commit's date. Deriving it from git made the
# artefact hash change on every commit, so the SHA-256 recorded in
# docs/phase-1-report.md could never survive the commit that recorded it: a
# reviewer rebuilding from the delivered source got a different hash and had
# no way to tell a timestamp difference from a content difference. Override
# with SOURCE_DATE_EPOCH when a release needs its own stamp.
SOURCE_DATE="${SOURCE_DATE_EPOCH:-1757203200}"
find "${STAGE}" -exec touch -h -d "@${SOURCE_DATE}" {} +

# Build to a temporary name first, so an existing package of this version is
# never destroyed before we know whether the content even changed.
BUILT="${DIST}/.building-${SLUG}-${VERSION}.zip"
rm -f "${BUILT}"
( cd "${STAGE}" && find . -type f | LC_ALL=C sort | sed 's|^\./||' | zip -q -X -9 "${BUILT}" -@ )

# One version, one package. A delivered ZIP must stay byte-identical for as
# long as its version number exists, otherwise "which alpha.2 do you have?"
# becomes unanswerable — which is exactly how a package the owner installed
# on staging and a later internal build ended up sharing a version once.
# Set TMC_REBUILD=1 to overwrite deliberately during local iteration.
NEW_HASH="$(sha256sum "${BUILT}" | cut -d' ' -f1)"
if [ -n "${PREVIOUS_HASH}" ] && [ "${PREVIOUS_HASH}" != "${NEW_HASH}" ] && [ "${TMC_REBUILD:-0}" != "1" ]; then
  rm -f "${BUILT}"
  echo "refusing to overwrite ${SLUG}-${VERSION}.zip: the content changed." >&2
  echo "  on disk: ${PREVIOUS_HASH}" >&2
  echo "  rebuilt: ${NEW_HASH}" >&2
  echo "Bump the Version header (decision log F-06), or set TMC_REBUILD=1 to overwrite." >&2
  exit 2
fi

# A package that was DELIVERED may never be rebuilt, full stop.
#
# This used to ask git for the version at HEAD. That was not enough, and the
# failure was instructive: the overwrite had already been committed, so HEAD
# held the WRONG bytes — and a "restore" from HEAD restored the overwrite. The
# owner noticed, from the hash they had been given, that a package with their
# version number no longer matched what they had.
#
# So the question is asked of `dist/DELIVERED.txt`, which is written by hand
# and records what was handed over. Not of git (which records what was
# committed) and not of SHA256SUMS (which is regenerated from disk, and so
# agrees with any mistake already made).
LEDGER="${ROOT}/dist/DELIVERED.txt"
if [ -f "${LEDGER}" ]; then
  DELIVERED_HASH="$(awk -v f="${SLUG}-${VERSION}.zip" '!/^#/ && $2 == f { print $1 }' "${LEDGER}" | head -1)"
  if [ -n "${DELIVERED_HASH}" ] && [ "${DELIVERED_HASH}" != "${NEW_HASH}" ]; then
    rm -f "${BUILT}"
    echo "refusing to rebuild ${SLUG}-${VERSION}.zip: that package was DELIVERED." >&2
    echo "  delivered: ${DELIVERED_HASH}" >&2
    echo "  rebuilt:   ${NEW_HASH}" >&2
    echo "Somebody has these bytes. Bump the Version header; there is no flag for this." >&2
    exit 2
  fi
fi

mv -f "${BUILT}" "${ZIP}"

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
IMG_LIST="$(mktemp)"
IMG_LIST_OK="$(mktemp)"

# Credentials are never in the source archive, even the disposable ones.
# `.env.testing` names a throwaway MariaDB with synthetic data, and it is
# STILL excluded: an archive that ships a DSN, a user and a password teaches
# whoever reads it that this is a normal thing to find in a source drop, and
# the next file with that shape will not be disposable. The database suite
# refuses to run without these variables anyway, so a reader is told what to
# set rather than handed somebody else's values.
SECRET_PATTERN='^(\.env|\.env\..*|.*\.pem|.*\.key|.*credentials.*)$'

# Evidence SCREENSHOTS travel in their own archive, and are NOT deleted.
#
# They are 49 MB of the 55 MB source drop: a reviewer downloading the source to
# read the code pays for every screenshot from every phase. Splitting them out
# is not a tidy-up — it is what keeps the source archive readable and the
# evidence complete at the same time. The text half of the evidence (every
# `.txt`, `.json`, `.log`, `.html` and `.md` under `docs/evidence/`) STAYS in
# the source archive, because that is the half a reviewer reads.
EVIDENCE_IMAGE_PATTERN='^docs/evidence/.*\.(png|jpg|jpeg|webp|gif|pdf)$'

ALL_FILES="$(mktemp)"
trap 'rm -f "${LIST}" "${LIST_OK}" "${ALL_FILES}" "${IMG_LIST}" "${IMG_LIST_OK}"' EXIT

if git rev-parse --git-dir >/dev/null 2>&1; then
  # tracked files plus anything new that is not gitignored
  git ls-files -z --cached --others --exclude-standard \
    | grep -zv '^dist/' \
    | grep -zEv "${SECRET_PATTERN}" > "${ALL_FILES}"
else
  find . -type f \
    -not -path './.git/*' -not -path './dist/*' -not -path './vendor/*' \
    -not -path '*/node_modules/*' -print0 | sed -z 's|^\./||' \
    | grep -zEv "${SECRET_PATTERN}" > "${ALL_FILES}"
fi

# Split, rather than drop: one list for the source archive, one for the
# evidence archive, and every file is in exactly one of them.
grep -zEv "${EVIDENCE_IMAGE_PATTERN}" < "${ALL_FILES}" > "${LIST}" || true
grep -zE  "${EVIDENCE_IMAGE_PATTERN}" < "${ALL_FILES}" > "${IMG_LIST}" || true

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

# --- evidence images, in their own archive ----------------------------------
: > "${IMG_LIST_OK}"
while IFS= read -r -d '' f; do
  [ -f "${f}" ] && printf '%s\0' "${f}" >> "${IMG_LIST_OK}"
done < "${IMG_LIST}"
IMG_COUNT=$(tr -cd '\0' < "${IMG_LIST_OK}" | wc -c)

EV_ARCHIVE="${DIST}/${SLUG}-evidence-${VERSION}.tar.gz"
if [ "${IMG_COUNT}" -gt 0 ]; then
  LC_ALL=C sort -zu "${IMG_LIST_OK}" -o "${IMG_LIST_OK}"
  tar --null --files-from="${IMG_LIST_OK}" --owner=0 --group=0 --numeric-owner \
      --mtime="@${SOURCE_DATE}" --format=gnu -czf "${EV_ARCHIVE}"
  echo "evidence archive: ${IMG_COUNT} images → $(basename "${EV_ARCHIVE}")"
else
  # Loudly, not silently: an empty evidence archive would look like «there
  # were no screenshots» rather than «the list was built wrong».
  echo "evidence archive: NO images matched — check EVIDENCE_IMAGE_PATTERN" >&2
  exit 1
fi

rm -rf "${STAGE}"

# SHA256SUMS covers every package still in dist/, newest build included, so a
# reviewer can identify any package they were sent, not only the latest.
( cd "${DIST}" && ls -1 *.zip *.tar.gz 2>/dev/null | LC_ALL=C sort | xargs sha256sum > SHA256SUMS )

cat > "${DIST}/READ-ME-BEFORE-INSTALL.txt" <<TXT
بازارگاه تک‌طب — Tecteb Marketplace Core ${VERSION}

وضعیت این بسته
==============
گیت‌های پذیرش فاز ۱ روی یک WordPress یکبارمصرف با داده مصنوعی اجرا و قبول
شدند. این بسته روی هیچ سایت واقعی نصب نشده است.

  WordPress 7.1 · WooCommerce 11.0.1 · MariaDB 10.11.14
  PHP 8.1.32 (CLI، وب و WP-CLI) — و پیش‌تر PHP 8.4.19

روی همان wp-admin واقعی، با زبان مدیریت fa_IR و RTL (بسته ترجمه حداقلی
آزمایشی): پنج viewport (320/375/768/1024/1440)، شبیه‌سازی فضای چیدمان،
device scale factor سطح مرورگر، کیبورد با Tab واقعی و axe-core.

هر مجموعه عدد خودش را دارد و اینجا جمع نمی‌شوند:

  چهار صفحهٔ فاز ۱                 ۲۵۲ بررسی   ۰ شکست
  صفحه‌های محصول                   ۲۴۰ بررسی   ۰ شکست
  نظر و امتیاز و نمودار            ۲۴۰ بررسی   ۰ شکست
  چهار صفحهٔ عملیات (alpha.13)     ۲۸۰ بررسی   ۰ شکست

خروجی خام هر گیت در docs/evidence/acceptance/ است، شواهد بازطراحی در
docs/evidence/redesign/، و شواهد این دور در docs/evidence/operations/ و
docs/evidence/product-ux/.

پیش از نصب، پنج چیز را بدانید
=============================
۱) این نسخه محصول‌های تأییدشده را به WooCommerce می‌فرستد. پیوند دوطرفه است،
   محصول تکراری ساخته نمی‌شود و موجودی قدیمی برنمی‌گردد (ADR-008). محصولات
   موجود سایت و دکان بدون مهاجرت صریح خوانده یا نوشته نمی‌شوند.

۲) بازگشت به نسخه قبلی حالا خودکار امن است. کافی است افزونه را غیرفعال کنید:
   همان لحظه همه محصولات بازارگاه از فروشگاه بیرون می‌روند (draft، بدون حذف
   هیچ داده‌ای) و پرچم «فروش متوقف است» ثبت می‌شود. بعد ZIP قبلی را بازگردانید.
   اگر می‌خواهید افزونه فعال بماند، در wp-admin به «وضعیت فروش بازارگاه» بروید
   و دکمه توقف را بزنید.

   فعال‌سازی دوباره هیچ محصولی را خودکار به فروش برنمی‌گرداند؛ «از سرگیری»
   صریح لازم است و آن هم فقط محصولی را برمی‌گرداند که هنوز شرایطش برقرار است.

   بازگشت مستلزم «از سرگیری فروش» نیست: «تلاش دوباره توقف» فروش را باز
   نمی‌کند. ولی «بازگرداندن سفارش‌های نگه‌داشته» را با آن یکی نگیرید — آن
   سفارش‌ها را به pending/failed برمی‌گرداند، یعنی پرداختشان دوباره باز
   می‌شود و پس از غیرفعال‌شدن این افزونه هم باز می‌ماند. برای rollback لازم
   نیست؛ سفارش‌ها را نگه‌داشته رها کنید.
   جزئیات: docs/upgrade-and-rollback.md بند ۵٫۱ تا ۵٫۳

۲-الف) سفارش پرداخت‌نشده‌ای که قلم بازارگاه دارد، هنگام توقف به وضعیت on-hold
   می‌رود — تنها چیزی که خود WooCommerce «غیرقابل پرداخت» می‌فهمد، پس با
   غیرفعال‌شدن این افزونه هم پرداخت نمی‌شود. سفارش لغو یا خالی نمی‌شود و
   وضعیت قبلی‌اش نگه داشته می‌شود.

   موجودی را WooCommerce جابه‌جا می‌کند، نه ما، و متقارن نیست: برای سفارش
   pending معمولی توقف و بازگرداندن یکدیگر را خنثی می‌کنند؛ ولی سفارشی که
   پیش از توقف هم موجودی را کم کرده بود با بازگرداندن موجودی را زیاد می‌کند،
   و روی WooCommerce پیش از 11.0.0 سفارشِ failed موجودی‌اش برنمی‌گردد. اگر
   سفارش مخلوط باشد، موجودی کالای خود فروشگاه هم تکان می‌خورد.
   اعداد: docs/evidence/guard-stock/

۳) این نسخه تسویه و برداشت دارد، ولی ساختن درخواست برداشت تا بسته‌شدن DEC-02
   و FIN-04 انجام نمی‌شود. مانده فروشنده همچنان محاسبه و نمایش داده می‌شود؛
   بستن عملیات یعنی بستن عملیات، نه پنهان‌کردن عدد.

۴) «بازپرداخت» در این نسخه دو کار از چهار کار را انجام می‌دهد و صریح می‌گوید
   کدام: اصلاح دفترکل و بازگرداندن موجودی انجام می‌شود؛ ثبت refund در
   WooCommerce و انتقال واقعی وجه انجام نمی‌شود، چون هیچ درگاهی وجود ندارد.
   هیچ‌جا «بازپرداخت شد» به تنهایی نوشته نمی‌شود.

۴-الف) رکورد بازپرداخت در WooCommerce حالا ساخته می‌شود — و فقط رکورد. اندازه
   گرفته شد: wc_create_refund() آرگومان refund_payment را پیش‌فرض false
   می‌گیرد، پس درگاه اصلا صدا زده نمی‌شود. پول همچنان جابه‌جا نمی‌شود و
   همان یک خروجی سه دلیلش را نام می‌برد: درگاهی وصل نیست، سفارش شماره
   تراکنش ندارد، و درگاه پرداخت سفارش نصب نیست.

۵) کد تخفیف فروشنده حالا در جعبه کوپن خود WooCommerce کار می‌کند و فقط روی
   محصولات همان فروشگاه اعمال می‌شود؛ کالای خود سایت و کالای دکان دست‌نخورده
   می‌مانند. کد تخفیف سراسری تا بسته‌شدن DEC-04 ساخته نمی‌شود.
   قیمت پلکانی عمده را فروشنده در /vendor/support/ تعیین می‌کند و فقط خریدار
   عمده تأییدشده آن را روی صفحه محصول می‌بیند.

ماژول سفارش روی محیط production اجرا نمی‌شود تا DEC-02 و DEC-04 بسته شوند.
این عمدی است: کلید آزمایشی فقط روی staging/development/local پذیرفته می‌شود.

گزارش مالک درباره staging (شاهدِ ارائه‌شده توسط مالک، نه آزمون ما)
================================================================
نسخه قبلی 0.1.0-alpha.1 روی staging.tecteb.com نصب و فعال شده و مالک
PHP 8.1.34، WordPress 7.1، WooCommerce 11.0.1 و HPOS فعال را گزارش کرده
است. هیچ آزمونی از سوی ما روی آن سایت اجرا نشده است.

آنچه همچنان Not Run است
=======================
  - PHP 8.1.34 (نسخه دقیق سایت مالک؛ آنچه آزموده شد 8.1.32 است)
  - سرور وب واقعی (Apache/LiteSpeed + PHP-FPM)؛ اجرا با SAPI cli-server بود
  - تداخل با LiteSpeed / Hello Elementor / Persian Woo / Rank Math / WP Rocket
    و افزونه‌های نصب‌شده سایت، از جمله دکان
  - بررسی دستی screen reader
  - مرورگرهای غیر Chromium (Firefox، Safari)
  - زوم صفحه از منوی مرورگر و ترجمه رسمی فارسی وردپرس
  - صفحه‌های JS-محور خود دکان. دکان Lite 5.1.1 در محیط آزمون واقعا فعال است و
    کل PHP آن آزموده شد، ولی سورس گیت دکان فایل‌های JS ساخته‌شده را ندارد و
    wordpress.org از آن محیط در دسترس نیست. بسته رسمی روی سایت شما این شکاف
    را ندارد
  - انتقال واقعی وجه در بازپرداخت، و ثبت refund در WooCommerce — انجام
    نمی‌شوند (درگاهی وجود ندارد)
  - Dokan Pro — در دسترس نیست؛ فقط Dokan Lite 5.1.1 آزموده شده
  - نظرات و امتیاز، SEO خودکار، و نمودار روی گزارش‌ها — ساخته نشده‌اند
    (پیوست تیکت، اعلان داخل پنل و خود گزارش‌ها در این نسخه ساخته شدند)

آنچه واقعاً آزموده شده و آنچه نشده، سطربه‌سطر در این فایل‌هاست:
  docs/phase-1-report.md  بند ۳ و بند ۵
  docs/compatibility-matrix.md
  docs/installation.md

تصاویر رابط در docs/evidence/screenshots «نمونه رابط (خارج WordPress)» هستند؛
تصاویر docs/evidence/redesign از wp-admin واقعیِ محیط یکبارمصرف‌اند.
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
