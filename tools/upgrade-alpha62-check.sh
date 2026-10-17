#!/usr/bin/env bash
# `alpha.62 → alpha.63` on a real WordPress: a round with NO migration.
#
# The two structural paths (`alpha.61 → alpha.63`, thirty to thirty-one, and
# `alpha.56 → alpha.63`, three steps) are measured by
# `tools/upgrade-alpha61-check.sh` and they pass from this ZIP. This script
# measures the OTHER path — the one the owner is actually on, since
# `alpha.62` was delivered — and the thing that matters there is that
# NOTHING happens to the database.
#
# Why it is its own file rather than another base for that script: with a
# base of the same data structure, every stage that one asserts about a new
# column is a stage about nothing. `alpha.42`'s rule — «مرحله‌های
# شکست/ازسرگیری با نام رد می‌شوند نه با assert ِ بی‌معنا» — and running it
# with `TMC_BASES` pointing at `alpha.62` reported twenty-seven failures
# about a build that was entirely correct.
#
# Measured, from the ZIPs themselves rather than from a typed number
# (`alpha.38`):
#
#   1. both packages declare schema 31, so the step count is nought,
#   2. a supplier payment, an obligation and its history written on
#      `alpha.62` are still there afterwards, field by field,
#   3. the upgrade gate reports the structure complete and runs no step,
#   4. and going BACK to `alpha.62` needs no database work — with the cost
#      stated rather than implied.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha62-check.sh docs/evidence/upgrade-alpha63
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha63}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
STATE_SRC="$REPO/tools/upgrade-alpha61-state.php"
STATE="$WP_PATH/upgrade-alpha61-state.php"
NEW_ZIP="${TMC_NEW_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.63.zip}"
BASE_ZIP="${TMC_BASE_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.62.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha62-check.txt"
: >"$LOG"
PASS=0
FAIL=0

say() { printf '%s\n' "$*" | tee -a "$LOG"; }
check() {
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  ($3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=$2 actual=$3"
  fi
}
skip() { say "SKIP  $1  — $2"; }
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }
state() { wpx eval-file "$STATE" "$@" 2>>"$LOG"; }

# The schema number a package DECLARES, read from the ZIP (`alpha.56`).
zip_schema() {
  local z="$1" d
  d="$(mktemp -d)"
  unzip -o -q "$z" 'tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php' -d "$d" 2>/dev/null
  sed -n 's/.*TARGET *= *\([0-9]\+\).*/\1/p' \
    "$d/tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php" 2>/dev/null | head -1
  rm -rf "$d"
}
zip_version() {
  local z="$1" d
  d="$(mktemp -d)"
  unzip -o -q "$z" 'tecteb-marketplace-core/tecteb-marketplace-core.php' -d "$d" 2>/dev/null
  sed -n 's/^ \* Version: *//p' "$d/tecteb-marketplace-core/tecteb-marketplace-core.php" 2>/dev/null | head -1
  rm -rf "$d"
}
install_zip() {
  wpx plugin install "$1" --force --activate >>"$LOG" 2>&1
}

[[ -f "$NEW_ZIP" && -f "$BASE_ZIP" ]] || { say "FAIL  a package is missing"; say "PASS=0 FAIL=1"; exit 1; }
cp "$STATE_SRC" "$STATE"
trap 'rm -f "$STATE"' EXIT

BASE_SCHEMA="$(zip_schema "$BASE_ZIP")"
NEW_SCHEMA="$(zip_schema "$NEW_ZIP")"
say "=== the two packages, read from the ZIPs ==="
say "base_package=$(zip_version "$BASE_ZIP") base_schema=$BASE_SCHEMA"
say "new_package=$(zip_version "$NEW_ZIP") new_schema=$NEW_SCHEMA"
check "1a the base declares a schema" "31" "$BASE_SCHEMA"
check "1b the new package declares the SAME one" "$BASE_SCHEMA" "$NEW_SCHEMA"
check "1c so this round has no migration" "0" "$(( NEW_SCHEMA - BASE_SCHEMA ))"

# ------------------------------------------------- state written on alpha.62
say ""
say "=== on the base package: something worth keeping ==="
install_zip "$BASE_ZIP"
check "2a the base is installed" "$(zip_version "$BASE_ZIP")" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
state reset >/dev/null
A="$(state arrange)"
printf '%s\n' "$A" >>"$LOG"
check "2b the fixture is arranged" "yes" "$([[ -n "$(val "$A" line)" ]] && echo yes || echo no)"
P="$(state pay)"
printf '%s\n' "$P" >>"$LOG"
check "2c the base records a payment" "supplier_payment_recorded" "$(val "$P" pay_code)"
PAYMENT_ID="$(val "$P" payment_id)"
say "payment_id=$PAYMENT_ID"
G="$(state agree)"
printf '%s\n' "$G" >>"$LOG"
check "2d …and an agreed amount" "supplier_amount_agreed" "$(val "$G" agree_code)"
D0="$(state digest)"
printf '%s\n' "$D0" >>"$LOG"
BEFORE="$(val "$D0" identity)"
ROWS_BEFORE="$(val "$D0" payment_rows)"
say "identity_before=$BEFORE"
say "payment_rows_before=$ROWS_BEFORE"
SCHEMA_BEFORE="$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
check "2e the recorded structure is 31" "31" "$SCHEMA_BEFORE"

# --------------------------------------------------------------- the upgrade
say ""
say "=== swapping the files for alpha.63 ==="
install_zip "$NEW_ZIP"
check "3a the new files are on disk" "$(zip_version "$NEW_ZIP")" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "3b the structure number did not move" "31" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
# An admin request is what runs the upgrade gate; here there is nothing for
# it to do, and «nothing to do» is the measurement.
wpx eval 'do_action("admin_init");' >>"$LOG" 2>&1
check "3c …and an admin request leaves it there" "31" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
skip "3d the failure and resume stages" "there is no step to fail (\`alpha.42\`)"

D1="$(state digest)"
printf '%s\n' "$D1" >>"$LOG"
AFTER="$(val "$D1" identity)"
say "identity_after=$AFTER"
check "3e the product, its order line and the payments hash the same" "$BEFORE" "$AFTER"
check "3f the payment rows are untouched" "$ROWS_BEFORE" "$(val "$D1" payment_rows)"
V="$(state void "$PAYMENT_ID")"
printf '%s\n' "$V" >>"$LOG"
check "3g a void still works on the carried-over payment" "supplier_payment_voided" "$(val "$V" void_code)"
check "3h …and the record is kept, marked void" "yes" "$(val "$V" is_void)"

# --------------------------------------------------------------- going back
say ""
say "=== and back to alpha.62 ==="
install_zip "$BASE_ZIP"
check "4a the old files are on disk" "$(zip_version "$BASE_ZIP")" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "4b the structure is still 31" "31" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
D2="$(state digest)"
printf '%s\n' "$D2" >>"$LOG"
check "4c no database work is needed" "$AFTER" "$(val "$D2" identity)"
check "4d and the rows are all still there" "$ROWS_BEFORE" "$(val "$D2" payment_rows)"
say "note=rolling back restores alpha.62's two defects: a refused line turns"
say "note=into a free order again, and a second partial payment writes no row."

# ------------------------------------------------------------- forward again
say ""
say "=== forward again, because a rollback is not a decision ==="
install_zip "$NEW_ZIP"
check "5a alpha.63 is installed" "$(zip_version "$NEW_ZIP")" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "5b the structure is 31" "31" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
check "5c and the data is the same" "$AFTER" "$(val "$(state digest)" identity)"
state reset >/dev/null

say ""
say "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
