#!/usr/bin/env bash
# Syntax check every shipped PHP file, on EVERY PHP the plugin claims to run
# on — not just the one the build machine happens to have.
#
# WHY BOTH: `php -l` compiles, and some errors are compile-time and
# version-specific. `EnumCase->value` inside a `const` expression is legal
# from 8.2 and a FATAL on 8.1, so an 8.4-only lint reported a clean tree while
# the package it produced killed every page of an 8.1 site. That happened;
# this loop is the fix. PHPCompatibility runs beside it and catches a
# different class of problem (deprecations, removed functions), not this one.
set -u
cd "$(dirname "$0")/.."
fail=0
files=$(find src tecteb-marketplace-core.php uninstall.php -name '*.php' | sort)

# The default runtime first, then any older one that is installed.
runtimes="php"
for extra in /opt/php81/bin/php; do
  [ -x "$extra" ] && runtimes="$runtimes $extra"
done

for runtime in $runtimes; do
  version=$("$runtime" -r 'echo PHP_VERSION;')
  count=0
  runtime_fail=0
  for f in $files; do
    count=$((count+1))
    if ! out=$("$runtime" -l "$f" 2>&1); then echo "$out"; runtime_fail=1; fail=1; fi
  done
  echo "php -l: ${count} files checked with PHP ${version}, failures: ${runtime_fail}"
done
exit $fail
