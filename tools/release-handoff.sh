#!/usr/bin/env bash
# Build `tecteb-marketplace-release-handoff.zip` — the §10 companion package.
#
# ⛔ It REFUSES rather than shipping a short bundle. §10 names eleven
# documents; a zip that quietly omits one is worse than no zip, because the
# reader has no way to notice. Every required name is checked before anything
# is copied, and a missing one is a failure with the name printed.
#
# ⛔ And every tool it carries must be one that CANNOT WRITE. The audit tools
# are meant to be run on the owner's own site, so the bundle may only contain
# the ones `ReadOnlyInventoryTest` and its siblings vouch for. A writing tool
# in a bundle labelled «audit tools» is how somebody runs a fixture against
# production.
set -eu
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
DIST="${TMC_DIST:-${ROOT}/dist}"
NAME="tecteb-marketplace-release-handoff"
VERSION="$(grep -oP '^\s*\*\s*Version:\s*\K[0-9A-Za-z.\-+]+' tecteb-marketplace-core.php | head -1)"
if [ -z "${VERSION}" ]; then
  echo "could not read the plugin version from the header" >&2
  exit 1
fi

# The eleven §10 names, verbatim, plus the three this cycle added because the
# instruction's own §2-B, §6 and §8 ask for them by content.
REQUIRED_DOCS="
FINAL-RELEASE-AUDIT-FA.md
PRODUCTION-PREFLIGHT-FA.md
STAGING-ACCEPTANCE-FINAL-FA.md
DOKAN-MINMAX-EXIT-FA.md
PRODUCT-OWNERSHIP-RECONCILIATION-FA.md
FINANCIAL-RECONCILIATION-FA.md
ZARINPAL-PAYMENT-TEST-FA.md
PRODUCTION-CUTOVER-FA.md
ROLLBACK-AND-RECOVERY-FA.md
POST-LAUNCH-UI-CLEANUP-FA.md
RELEASE-NOTES-FA.md
CAPTURE-FAILURE-AND-REPAIR-FA.md
ENVIRONMENT-SEPARATION-FA.md
RELEASE-CRITICAL-SWEEP-FA.md
"

# Read-only tools only, and the list is the one the tests vouch for.
TOOLS="
plugin-exit-inventory.php
shop-dependency-census.php
production-audit.php
payment-readiness.php
"

missing=""
for doc in ${REQUIRED_DOCS}; do
  [ -f "docs/${doc}" ] || missing="${missing} docs/${doc}"
done
for tool in ${TOOLS}; do
  [ -f "tools/${tool}" ] || missing="${missing} tools/${tool}"
done
if [ -n "${missing}" ]; then
  echo "refusing to build ${NAME}: these are named in the bundle and are not here:" >&2
  for m in ${missing}; do echo "  ${m}" >&2; done
  exit 1
fi

# ⛔ And every tool in the bundle is cross-checked against the read-only
# exemption list, so a tool that loses its guard cannot ride along.
for tool in ${TOOLS}; do
  if ! grep -q "'${tool}'" tests/Unit/Tools/DisposableOnlyToolsTest.php; then
    echo "refusing to build ${NAME}: tools/${tool} is not on the read-only list" >&2
    exit 1
  fi
done

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT
OUTDIR="${STAGE}/${NAME}"
mkdir -p "${OUTDIR}/docs" "${OUTDIR}/tools"

for doc in ${REQUIRED_DOCS}; do cp "docs/${doc}" "${OUTDIR}/docs/"; done
for tool in ${TOOLS}; do cp "tools/${tool}" "${OUTDIR}/tools/"; done

# Evidence this cycle produced, as text — the falsification log, which is the
# one file that says whether the guards were measured rather than asserted.
if [ -d docs/evidence/alpha103-falsification ]; then
  mkdir -p "${OUTDIR}/evidence/alpha103-falsification"
  cp docs/evidence/alpha103-falsification/*.txt "${OUTDIR}/evidence/alpha103-falsification/" 2>/dev/null || true
fi

cat > "${OUTDIR}/RUN-COMMANDS.txt" <<TXT
فرمان‌های هر ابزار — همه فقط‌خواندنی
نسخهٔ بسته: ${VERSION}

⛔ هیچ‌کدام از این‌ها چیزی نمی‌نویسد. گاردش یک آزمون است نه یک جمله:
   tests/Unit/ReadOnlyInventoryTest.php و خواهرهایش بایت‌های هر فایل را
   می‌خوانند و هر فعل نوشتنی و هر SQL تغییردهنده را ممنوع می‌کنند، با
   کامنت‌های خالی‌شده.

⛔ و ابزارهای دیگرِ پوشهٔ tools/ در این بسته نیستند، به‌عمد: آن‌ها فقط روی
   دیتابیس یکبارمصرف اجرا می‌شوند و روی سایت واقعی با صدای بلند امتناع
   می‌کنند. آن امتناع یک گارد است.

# ۱) چه چیزی نصب است، و چه داده‌ای دارد
wp eval-file tools/plugin-exit-inventory.php            # همه
wp eval-file tools/plugin-exit-inventory.php files      # مسیر، سربرگ، هش، ثابت نسخه
wp eval-file tools/plugin-exit-inventory.php minmax     # ⛔ تنها جایی که حالتِ مؤثر Min Max تعیین می‌شود
wp eval-file tools/plugin-exit-inventory.php dokan      # جدول‌ها، ماژول‌ها، پاک‌سازی
wp eval-file tools/plugin-exit-inventory.php deactivation
wp eval-file tools/plugin-exit-inventory.php exit
wp eval-file tools/plugin-exit-inventory.php intake     # مسیر ورود: قلاب‌ها، سایزها، مهلت‌های PHP
wp eval-file tools/plugin-exit-inventory.php finance 102

# ۲) مالکیت هر محصول و هر تنوع
wp eval-file tools/production-audit.php products
wp eval-file tools/production-audit.php products --csv > products.csv

# ۳) مالیِ دکان، ردیف‌به‌ردیف و بی هیچ جمعی
wp eval-file tools/production-audit.php finance
wp eval-file tools/production-audit.php finance --csv > finance.csv

# ۴) آمادگی پرداخت — بی نمایشِ هیچ راز و بی هیچ درخواست خروجی
wp eval-file tools/payment-readiness.php

# ۵) وابستگی‌های یک فروشگاه، پیش از تصمیم آرشیو
wp eval-file tools/shop-dependency-census.php <vendor_user_id>

# ترتیب خواندن اسناد:
#   1. FINAL-RELEASE-AUDIT-FA.md        ← جدول GO/NO-GO و موانع
#   2. PRODUCTION-PREFLIGHT-FA.md       ← فقط خواندن، پیش از هر تصمیمی
#   3. STAGING-ACCEPTANCE-FINAL-FA.md   ← تنها دستورالعمل اجرایی روی staging
#   4. PRODUCT-OWNERSHIP-RECONCILIATION-FA.md
#   5. FINANCIAL-RECONCILIATION-FA.md
#   6. DOKAN-MINMAX-EXIT-FA.md
#   7. ZARINPAL-PAYMENT-TEST-FA.md      ← با اجازهٔ صریح شما، نه پیش از آن
#   8. PRODUCTION-CUTOVER-FA.md
#   9. ROLLBACK-AND-RECOVERY-FA.md
#  10. CAPTURE-FAILURE-AND-REPAIR-FA.md · ENVIRONMENT-SEPARATION-FA.md · RELEASE-CRITICAL-SWEEP-FA.md
#  11. POST-LAUNCH-UI-CLEANUP-FA.md     ← فاز بعد، نه این دور
#  12. RELEASE-NOTES-FA.md
TXT

( cd "${OUTDIR}" && find . -type f ! -name 'SHA256SUMS' -print0 \
    | LC_ALL=C sort -z | xargs -0 sha256sum > SHA256SUMS )

mkdir -p "${DIST}"
TARGET="${DIST}/${NAME}-${VERSION}.zip"
rm -f "${TARGET}"
# Same reproducibility rule as the plugin package, and the SAME derivation:
# one version, one stamp — never a wall-clock time, or two builds of one
# version would differ in bytes and nobody could say which one they hold.
SOURCE_DATE="${SOURCE_DATE_EPOCH:-$(bash tools/release-stamp.sh "${VERSION}")}"
find "${OUTDIR}" -exec touch -h -d "@${SOURCE_DATE}" {} +
( cd "${STAGE}" && find . -type f | LC_ALL=C sort | sed 's|^\./||' | zip -q -X -9 "${TARGET}" -@ )
echo "built ${TARGET}"
sha256sum "${TARGET}"
