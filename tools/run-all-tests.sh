#!/usr/bin/env bash
# Runs every gate that CAN run in this environment and writes the logs that
# docs/phase-1-report.md cites. Gates that cannot run here (real WordPress
# install, WP/WC integration, HPOS modes, manual screen reader) are NOT
# simulated: they stay Not Run in the report.
set -u
cd "$(dirname "$0")/.."
mkdir -p docs/evidence
[ -f .env.testing ] && { set -a; . ./.env.testing; set +a; }
rc=0
run() { # name, command...
  local name="$1"; shift
  echo "=== ${name} ==="
  "$@" > "docs/evidence/${name}.log" 2>&1
  local code=$?
  echo "exit=${code}  log=docs/evidence/${name}.log"
  tail -3 "docs/evidence/${name}.log" | sed 's/^/    /'
  [ $code -ne 0 ] && rc=1
  echo
  return 0
}
echo "PHP $(php -r 'echo PHP_VERSION;')  |  $(vendor/bin/phpunit --version | head -1)"
echo "DB: $(php -r '$d=getenv("TMC_TEST_DB_DSN"); echo $d?:"(not configured)";')"
echo
run unit         vendor/bin/phpunit --testsuite unit
run architecture vendor/bin/phpunit --testsuite architecture
run contract     vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php
run database     vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php
run packaging    vendor/bin/phpunit --testsuite packaging
run lint         bash tools/lint.sh
run phpcompat-8.1 vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 8.1- --extensions=php -p src tecteb-marketplace-core.php uninstall.php
run contrast     php tools/contrast.php
echo "overall exit=${rc}"
exit $rc
