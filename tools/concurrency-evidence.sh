#!/usr/bin/env bash
# Raw, reviewable evidence for the concurrency work — sources AND output.
#
# The owner asked that the concurrency test sources and the raw WordPress run
# travel with the package, «تا شواهد قابل بررسی باشند». A summary in a
# delivery note is something to be taken on trust; a PHPUnit transcript beside
# the test that produced it is something to be checked.
#
# So this writes three things into one folder:
#   - the raw PHPUnit transcript of the concurrency suite (--testdox -v)
#   - a copy of the test sources it ran, beside their transcript
#   - the raw stdout of the WordPress-side runs (handover + four-role
#     walkthrough), exactly as they were printed
#
# It also records the FALSIFICATION run: the concurrency guards are removed,
# the suite is run again, and the failure is captured. A guard whose test
# still passes without it is not a guard, and that is not something a reader
# should have to take on trust either.
#
#   bash tools/concurrency-evidence.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/concurrency}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"
mkdir -p "${EV}/sources"

# shellcheck disable=SC1091
[ -f .env.testing ] && . ./.env.testing

FILTER='HandoverConcurrencyTest'

{
  echo "# Raw concurrency evidence"
  echo "generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "plugin_version=$(grep -m1 '^ \* Version:' tecteb-marketplace-core.php | awk '{print $3}')"
  echo "php=$(php -r 'echo PHP_VERSION;')"
  echo "database=$(mysql -h 127.0.0.1 -u"${TMC_TEST_DB_USER:-}" -p"${TMC_TEST_DB_PASS:-}" -N -B -e 'SELECT VERSION();' 2>/dev/null || echo unknown)"
  echo "isolation=$(mysql -h 127.0.0.1 -u"${TMC_TEST_DB_USER:-}" -p"${TMC_TEST_DB_PASS:-}" -N -B -e 'SELECT @@tx_isolation;' 2>/dev/null || echo unknown)"
} > "${EV}/00-header.txt"

echo "=== 1. the concurrency suite, as it ran ==="
vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
  --filter "${FILTER}" --testdox > "${EV}/01-concurrency-run.txt" 2>&1
SUITE_EXIT=$?
echo "exit=${SUITE_EXIT}" >> "${EV}/01-concurrency-run.txt"
tail -6 "${EV}/01-concurrency-run.txt"

# --- 2. the sources, beside the transcript ----------------------------------
cp tests/Database/HandoverConcurrencyTest.php "${EV}/sources/"
cp tests/Support/concurrent-shop-write.php "${EV}/sources/"
cp src/Modules/Migration/Infrastructure/DbShopRecordRepository.php "${EV}/sources/"
cp src/Modules/Migration/Application/DokanFinanceSnapshot.php "${EV}/sources/"
{
  echo "# The files under test and the files that test them."
  echo "# Repository paths, so a reader can diff these against the tree:"
  echo "tests/Database/HandoverConcurrencyTest.php"
  echo "tests/Support/concurrent-shop-write.php"
  echo "src/Modules/Migration/Infrastructure/DbShopRecordRepository.php"
  echo "src/Modules/Migration/Application/DokanFinanceSnapshot.php"
  for f in "${EV}"/sources/*.php; do
    echo "sha256=$(sha256sum "$f" | awk '{print $1}')  $(basename "$f")"
  done
} > "${EV}/sources/MANIFEST.txt"

# --- 3. falsification: remove each guard, prove the test fails --------------
#
# Two guards, two runs. Anything that still passes without its guard is a
# check that was never checking, and the point of recording this is that the
# reader does not have to take «it is falsifiable» on trust.
echo
echo "=== 2. falsification — each guard removed in turn ==="
REPO_SRC='src/Modules/Migration/Infrastructure/DbShopRecordRepository.php'
BACKUP="$(mktemp)"
cp "${REPO_SRC}" "${BACKUP}"
restore() { cp "${BACKUP}" "${REPO_SRC}"; }
trap 'restore; rm -f "${BACKUP}"' EXIT

{
  echo "# Each guard is removed, the suite re-run, and the failure recorded."
  echo

  echo "## guard 1: the FOR UPDATE that holds the shop's version row"
  sed -i 's/WHERE vendor_user_id = %d FOR UPDATE/WHERE vendor_user_id = %d/' "${REPO_SRC}"
  vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
    --filter 'testAnImportLandingBetweenTheCheckAndTheRecordIsMadeToWait' 2>&1 | tail -12
  restore
  echo

  echo "## guard 2: the locking read that decides whose version a rollback moves"
  sed -i "s/WHERE run_id = %s FOR UPDATE'/WHERE run_id = %s'/" "${REPO_SRC}"
  vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
    --filter 'testARollbackMovesTheVersionOfEveryShopWhoseRowsItRemoves' 2>&1 | tail -12
  restore
} > "${EV}/02-falsification.txt" 2>&1
grep -cE 'FAILURES|Failed asserting' "${EV}/02-falsification.txt" | sed 's/^/failures recorded: /'

# --- 4. the WordPress-side raw output ---------------------------------------
# Copied, never re-summarised: whatever the run printed is what is filed.
echo
echo "=== 3. raw WordPress output (copied from the runs that produced it) ==="
for src in docs/evidence/handover/01-run.txt docs/evidence/walkthrough-roles/01-checks.txt; do
  if [ -f "${src}" ]; then
    cp "${src}" "${EV}/03-$(basename "$(dirname "${src}")")-raw.txt"
    echo "copied: ${src}"
  else
    echo "MISSING: ${src} — run its suite first" >&2
  fi
done

echo
echo "evidence written to ${EV}"
exit "${SUITE_EXIT}"
