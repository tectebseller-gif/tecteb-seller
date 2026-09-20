#!/usr/bin/env bash
# The staff activity count, measured past the page size — and falsified.
#
# The owner's finding: `StaffActivityReport` asked for up to 2000 rows while
# `WpAuditRepository::search()` caps a page at 200. So a shop whose staff did
# more than 200 things was counted at 200, and the «these numbers are a
# floor» warning — written as `count($rows) >= 2000` — could never fire. The
# report understated the work AND said it was complete.
#
# The fix is a `GROUP BY` with no page to be capped to. That claim is only
# worth anything if a test would notice its absence, so this records both
# halves: the suite passing on the fix, and the SAME test failing once the
# old fetch-and-tally shape is put back.
#
#   bash tools/staff-activity-evidence.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/staff-activity}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"
mkdir -p "${EV}/sources"

# shellcheck disable=SC1091
[ -f .env.testing ] && . ./.env.testing

REPORT_SRC='src/Modules/Vendor/Application/StaffActivityReport.php'
FILTER='StoreAndStaffFlowTest'

{
  echo "# Staff activity: exact counts past the search page size"
  echo "generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "plugin_version=$(grep -m1 '^ \* Version:' tecteb-marketplace-core.php | awk '{print $3}')"
  echo "php=$(php -r 'echo PHP_VERSION;')"
  echo "search_page_cap=$(grep -n 'min(200' src/Infrastructure/WordPress/WpAuditRepository.php | head -1)"
  echo "events_in_test=260 + 45 by this shop's staff, 300 by the manager"
} > "${EV}/00-header.txt"

echo "=== 1. the staff suite, as it ran ==="
vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
  --filter "${FILTER}" --testdox > "${EV}/01-staff-run.txt" 2>&1
SUITE_EXIT=$?
echo "exit=${SUITE_EXIT}" >> "${EV}/01-staff-run.txt"
tail -6 "${EV}/01-staff-run.txt"

# --- 2. falsification: put the old shape back, prove the test fails ---------
echo
echo "=== 2. falsification — the 200-capped fetch-and-tally, restored ==="
BACKUP="$(mktemp)"
cp "${REPORT_SRC}" "${BACKUP}"
trap 'cp "${BACKUP}" "${REPORT_SRC}"; rm -f "${BACKUP}"' EXIT

python3 - "${REPORT_SRC}" <<'PY'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
new = """        $scope = ['actors' => array_keys($byUser), 'from' => $from];
        $counts = $this->audit->countByActor($scope);
        $latest = $this->audit->latestByActor($scope);"""
old = """        $scope = ['actors' => array_keys($byUser), 'from' => $from];
        $counts = [];
        $latest = [];
        foreach ($this->audit->search($scope, 2000, 0) as $line) {
            $who = (int) $line->actorId;
            $counts[$who] = ($counts[$who] ?? 0) + 1;
            $latest[$who] = $latest[$who] ?? [
                'event_type' => $line->eventType,
                'created_at' => $line->createdAtUtc->format('Y-m-d H:i:s'),
            ];
        }"""
if new not in s:
    sys.exit('falsification patch did not apply: the aggregate calls are not where this script expects them')
open(p, 'w', encoding='utf-8').write(s.replace(new, old))
PY
PATCHED=$?

{
  echo "# The aggregate calls replaced by the pre-fix shape:"
  echo "#   search(\$scope, 2000, 0) — which WpAuditRepository caps at 200."
  echo "# If this still passes, the test is not testing the cap."
  echo
  if [ "${PATCHED}" -ne 0 ]; then
    echo "PATCH FAILED — no falsification was run, and that is a failure, not a pass."
  else
    vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php \
      --filter 'testCountsAreExactWellPastTheSearchPageSize' 2>&1 | tail -16
  fi
} > "${EV}/02-falsification.txt" 2>&1
cp "${BACKUP}" "${REPORT_SRC}"
grep -cE 'FAILURES|Failed asserting' "${EV}/02-falsification.txt" | sed 's/^/failures recorded: /'

# A falsification run that recorded no failure proves the opposite of what it
# was for, so it is not allowed to look like success.
if ! grep -qE 'FAILURES|Failed asserting' "${EV}/02-falsification.txt"; then
  echo "staff-activity: FALSIFICATION DID NOT FAIL — the guard is not guarded" >&2
  SUITE_EXIT=1
fi

# --- 3. the sources, beside the transcript ----------------------------------
cp "${REPORT_SRC}" "${EV}/sources/"
cp src/Infrastructure/WordPress/WpAuditRepository.php "${EV}/sources/"
cp src/Contracts/AuditRepositoryInterface.php "${EV}/sources/"
cp tests/Database/StoreAndStaffFlowTest.php "${EV}/sources/"
cp tests/Unit/Modules/Vendor/StaffActivityLabelsTest.php "${EV}/sources/"
{
  echo "# The files under test and the files that test them."
  echo "src/Modules/Vendor/Application/StaffActivityReport.php"
  echo "src/Infrastructure/WordPress/WpAuditRepository.php"
  echo "src/Contracts/AuditRepositoryInterface.php"
  echo "tests/Database/StoreAndStaffFlowTest.php"
  echo "tests/Unit/Modules/Vendor/StaffActivityLabelsTest.php"
  for f in "${EV}"/sources/*.php; do
    echo "sha256=$(sha256sum "$f" | awk '{print $1}')  $(basename "$f")"
  done
} > "${EV}/sources/MANIFEST.txt"

echo
echo "evidence written to ${EV}"
exit "${SUITE_EXIT}"
