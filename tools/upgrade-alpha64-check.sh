#!/usr/bin/env bash
# `alpha.64 → alpha.65` on a real WordPress: a round with NO migration.
#
# Its own file rather than another base for `upgrade-alpha62-check.sh`, and
# not because the mechanics differ — they do not — but because that script
# PRINTS the names of its own round. Running it here passed twenty-two checks
# while saying «swapping the files for alpha.63» and «rolling back restores
# alpha.62's two defects», which is a true sentence about the wrong package.
# `alpha.34`'s rule («a piece of evidence that hard-codes a version locks
# itself to an old one») and `alpha.54`'s («each round's evidence tool has its
# own name»), together.
#
# Everything measurable is read from the ZIPs rather than typed (`alpha.38`):
#
#   1. both packages declare schema 31, so the step count is nought,
#   2. a supplier payment, an obligation and its history written on the base
#      are still there afterwards, field by field,
#   3. the upgrade gate reports the structure complete and runs no step,
#   4. and going BACK needs no database work — with THIS round's cost stated.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha64-check.sh docs/evidence/upgrade-alpha65
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha65}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
STATE_SRC="$REPO/tools/upgrade-alpha61-state.php"
STATE="$WP_PATH/upgrade-alpha61-state.php"
NEW_ZIP="${TMC_NEW_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.65.zip}"
BASE_ZIP="${TMC_BASE_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.64.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha64-check.txt"
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

BASE_VERSION="$(zip_version "$BASE_ZIP")"
NEW_VERSION="$(zip_version "$NEW_ZIP")"
BASE_SCHEMA="$(zip_schema "$BASE_ZIP")"
NEW_SCHEMA="$(zip_schema "$NEW_ZIP")"
say "=== the two packages, read from the ZIPs ==="
say "base_package=$BASE_VERSION base_schema=$BASE_SCHEMA"
say "new_package=$NEW_VERSION new_schema=$NEW_SCHEMA"
check "1a the base declares a schema" "31" "$BASE_SCHEMA"
check "1b the new package declares the SAME one" "$BASE_SCHEMA" "$NEW_SCHEMA"
check "1c so this round has no migration" "0" "$(( NEW_SCHEMA - BASE_SCHEMA ))"

# ------------------------------------------------- state written on the base package
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
say "=== swapping the files for $NEW_VERSION ==="
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
say "=== and back to $BASE_VERSION ==="
install_zip "$BASE_ZIP"
check "4a the old files are on disk" "$(zip_version "$BASE_ZIP")" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "4b the structure is still 31" "31" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
D2="$(state digest)"
printf '%s\n' "$D2" >>"$LOG"
check "4c no database work is needed" "$AFTER" "$(val "$D2" identity)"
check "4d and the rows are all still there" "$ROWS_BEFORE" "$(val "$D2" payment_rows)"
say "note=rolling back restores the defect this round closed: voiding a payment"
say "note=that was reconciled between the read and the write writes no"
say "note=compensating entry, so the ledger keeps saying the money left."

# ------------------------------------------------------------- forward again
say ""
say "=== forward again, because a rollback is not a decision ==="
install_zip "$NEW_ZIP"
check "5a the new package is installed" "$(zip_version "$NEW_ZIP")" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "5b the structure is 31" "31" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
check "5c and the data is the same" "$AFTER" "$(val "$(state digest)" identity)"
state reset >/dev/null

say ""
say "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
