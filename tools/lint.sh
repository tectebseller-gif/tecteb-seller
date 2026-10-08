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

# The default runtime first, then every older one this plugin claims to run on.
#
# **A missing interpreter is announced, not skipped.** Until `alpha.38` the
# loop was `[ -x "$extra" ] && …`, so a machine without PHP 8.1 printed one
# clean 8.4 line and nothing else — and the site this plugin is for runs
# **PHP 8.1.34**, where a compile-time error kills every page rather than one
# of them. A check that does not run looks exactly like a check that did not
# fail, which is the one shape this repository keeps having to unpick. So the
# absence is printed in those words and is a FAILURE, unless the caller says
# out loud that it is building without that check:
#
#   TMC_ALLOW_MISSING_RUNTIME=1 bash tools/lint.sh
#
# and then has to report it as `Not Run`, because that is what it is.
runtimes="php"
missing=""
for extra in /opt/php81/bin/php; do
  if [ -x "$extra" ]; then
    runtimes="$runtimes $extra"
  else
    missing="$missing $extra"
  fi
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

for absent in $missing; do
  echo "php -l: NOT RUN on ${absent} — interpreter is not installed. This is NOT a pass."
  if [ "${TMC_ALLOW_MISSING_RUNTIME:-0}" != "1" ]; then
    fail=1
  fi
done
exit $fail
