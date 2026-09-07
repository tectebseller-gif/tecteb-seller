#!/usr/bin/env bash
# Syntax check every shipped PHP file with the available PHP runtime.
# NOTE: this runs on whatever PHP is installed. Running it on PHP 8.1 is a
# separate, currently Not Run gate (see docs/compatibility-matrix.md).
set -u
cd "$(dirname "$0")/.."
fail=0
files=$(find src tecteb-marketplace-core.php uninstall.php -name '*.php' | sort)
count=0
for f in $files; do
  count=$((count+1))
  if ! out=$(php -l "$f" 2>&1); then echo "$out"; fail=1; fi
done
echo "php -l: ${count} files checked with PHP $(php -r 'echo PHP_VERSION;'), failures: ${fail}"
exit $fail
