#!/usr/bin/env bash
# `alpha.82 → alpha.83` on a real WordPress: a round with NO migration.
#
# Its own file rather than another base for an earlier script, because those
# scripts PRINT the names of their own round — running one here would pass
# while saying «swapping the files for alpha.65», a true sentence about the
# wrong package (`alpha.34`, `alpha.54`).
#
# Everything measurable is read from the ZIPs rather than typed (`alpha.38`):
#
#   1. both packages declare schema 35, so the step count is nought,
#   2. the product taken in on the base package — its price, SKU, weight and
#      its PARENT+CHILD category set — is still there afterwards, field by
#      field,
#   3. the upgrade gate reports the structure complete and runs no step,
#   4. on the new package a WooCommerce edit SURVIVES the next projection,
#   5. and going BACK needs no database work — with the cost MEASURED rather
#      than argued: on `alpha.82`'s bytes the same projection overwrites the
#      same edit. «صرف باقی‌ماندن جدول جدید، اثبات ایمن بودن رفتار نسخهٔ
#      قدیمی نیست» — so the old behaviour is run, not reasoned about.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha82-check.sh docs/evidence/upgrade-alpha83
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha83}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
STATE_SRC="$REPO/tools/upgrade-alpha82-state.php"
STATE="$WP_PATH/upgrade-alpha82-state.php"
NEW_ZIP="${TMC_NEW_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.83.zip}"
BASE_ZIP="${TMC_BASE_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.82.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha82-check.txt"
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
refute() {
  if [[ "$2" != "$3" ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  (not $2; got $3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  should not be $2"
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
install_zip() { wpx plugin install "$1" --force --activate >>"$LOG" 2>&1; }

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
check "1a the base declares a schema" "35" "$BASE_SCHEMA"
check "1b the new package declares the SAME one" "$BASE_SCHEMA" "$NEW_SCHEMA"
check "1c so this round has no migration" "0" "$(( NEW_SCHEMA - BASE_SCHEMA ))"

# ------------------------------------------- a product taken in on the base
say ""
say "=== on the base package: a product that has already been taken in ==="
install_zip "$BASE_ZIP"
check "2a the base is installed" "$BASE_VERSION" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
state reset >/dev/null
A="$(state arrange)"
printf '%s\n' "$A" >>"$LOG"
WC="$(val "$A" wc)"
ROW="$(val "$A" row)"
TERMS="$(val "$A" shop_terms)"
say "wc=$WC row=$ROW terms=$TERMS"
check "2b the fixture is arranged" "yes" "$([[ -n "$WC" && -n "$ROW" ]] && echo yes || echo no)"
check "2c the parent and its child are both on the product" "2" \
  "$(printf '%s' "$TERMS" | tr ',' '\n' | grep -c '[0-9]')"
check "2d the recorded set matches the shop's" "$TERMS" "$(val "$(state fields)" row_set)"
check "2e the base has no shop-edit record of its own" "absent" "$(val "$(state fields)" record)"
D0="$(state digest)"
printf '%s\n' "$D0" >>"$LOG"
BEFORE="$(val "$D0" identity)"
say "identity_before=$BEFORE"
SCHEMA_BEFORE="$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
check "2f the recorded structure is 35" "35" "$SCHEMA_BEFORE"

# ------------------------------------- the defect, measured on alpha.82 bytes
say ""
say "=== the defect this round closes, on $BASE_VERSION's own bytes ==="
E="$(state shop-edit)"
printf '%s\n' "$E" >>"$LOG"
check "3a the owner's edit lands in WooCommerce" "480000" "$(val "$E" shop_price)"
P="$(state project)"
printf '%s\n' "$P" >>"$LOG"
check "3b a projection runs" "yes" "$(val "$P" project_ok)"
check "3c …and the base OVERWRITES the edited price" "no" "$(val "$P" price_kept)"
check "3d …the edited SKU" "no" "$(val "$P" sku_kept)"
check "3e …and the edited weight" "no" "$(val "$P" weight_kept)"
check "3f the price it put back is the stored one" "$(( 200000 ))" \
  "$(printf '%s' "$(val "$P" price_after)" | sed 's/\.0*$//')"
check "3g the category set is NOT reduced on the base either" "yes" "$(val "$P" terms_kept)"
check "3h and the base names no conflict, because it has no record" "absent" \
  "$(val "$(state fields)" conflicts)"

# --------------------------------------------------------------- the upgrade
say ""
say "=== swapping the files for $NEW_VERSION ==="
install_zip "$NEW_ZIP"
check "4a the new files are on disk" "$NEW_VERSION" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "4b the structure number did not move" "35" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
# An admin request is what runs the upgrade gate; here there is nothing for
# it to do, and «nothing to do» IS the measurement.
wpx eval 'do_action("admin_init");' >>"$LOG" 2>&1
check "4c …and an admin request leaves it there" "35" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
skip "4d the failure and resume stages" "there is no step to fail (\`alpha.42\`)"
F1="$(state fields)"
printf '%s\n' "$F1" >>"$LOG"
check "4e the carried-over row still holds the whole set" "$TERMS" "$(val "$F1" row_set)"
check "4f the shop still holds the whole set" "$TERMS" "$(val "$F1" shop_terms)"

# ------------------------------------- the same edit, on the new package
say ""
say "=== the same scenario on $NEW_VERSION ==="
E2="$(state shop-edit)"
printf '%s\n' "$E2" >>"$LOG"
check "5a the owner's edit lands again" "480000" "$(val "$E2" shop_price)"
P2="$(state project)"
printf '%s\n' "$P2" >>"$LOG"
check "5b a projection runs" "yes" "$(val "$P2" project_ok)"
check "5c the edited price SURVIVES" "yes" "$(val "$P2" price_kept)"
check "5d the edited SKU survives" "yes" "$(val "$P2" sku_kept)"
check "5e the edited weight survives" "yes" "$(val "$P2" weight_kept)"
check "5f and the category set is untouched" "yes" "$(val "$P2" terms_kept)"
F2="$(state fields)"
printf '%s\n' "$F2" >>"$LOG"
check "5g each kept field is NAMED for the manager" "price,sku,weight" "$(val "$F2" conflicts)"
# ⛔ `keep_unknown`, not `keep_edited` — and this is the honest state of an
# UPGRADED site rather than a weaker check. The first projection ran on
# `alpha.82`, which has no record at all, so when the shop and our row differ
# there is no evidence either way and the conservative answer is to keep. Both
# numbers are still on the row for the manager.
check "5h …with both numbers, and «we have no record of writing this»" \
  "keep_unknown:480000/200000" "$(val "$F2" conflict_price)"
# AND AGAIN: «survives the next projection» is not the promise.
P3="$(state project)"
printf '%s\n' "$P3" >>"$LOG"
check "5i it survives EVERY later projection" "yes" "$(val "$P3" price_kept)"
check "5j a kept projection claims nothing, so there is still no record" "__empty__" \
  "$(val "$(state fields)" record)"

# ⛔ AND THE OTHER HALF, which this very script found: the vendor's own price
# change must still reach the shop. On an upgraded site NO product has a
# record, so a rule that answered «keep» here too would hold every edited
# product's vendor back for ever — «این اختیار نباید محدود شود».
V="$(state vendor-price 500000)"
printf '%s\n' "$V" >>"$LOG"
check "5k the vendor's own change is written" "price_written" "$(val "$V" push_code)"
check "5l …and the shop now holds the vendor's price" "yes" "$(val "$V" shop_price_is_the_vendors)"
F3="$(state fields)"
printf '%s\n' "$F3" >>"$LOG"
check "5m the conflict is RECORDED, not silently resolved" "write_conflict:480000/500000" \
  "$(val "$F3" conflict_price)"
check "5n and from here the record exists" "price" "$(val "$F3" record)"
check "5o so the next projection is an ordinary fresh write" "yes" "$(val "$(state project)" price_kept)"

# --------------------------------------------------------------- going back
say ""
say "=== and back to $BASE_VERSION ==="
D1="$(state digest)"
printf '%s\n' "$D1" >>"$LOG"
EDITED="$(val "$D1" identity)"
install_zip "$BASE_ZIP"
check "6a the old files are on disk" "$BASE_VERSION" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "6b the structure is still 35" "35" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
check "6c no database work is needed to go back" "$EDITED" "$(val "$(state digest)" identity)"
check "6d the link table from migration 35 is still read" "$TERMS" "$(val "$(state fields)" row_set)"
# ⛔ And the cost, MEASURED on these bytes rather than argued. The table
# surviving a rollback is a statement about the TABLE; what the old code does
# is a separate question, and the only way to answer it is to run it.
E4="$(state shop-edit)"
printf '%s\n' "$E4" >>"$LOG"
check "6e the owner edits the price again, on the old bytes" "480000" "$(val "$E4" shop_price)"
P4="$(state project)"
printf '%s\n' "$P4" >>"$LOG"
refute "6f the rollback cost: the edit is overwritten again" "yes" "$(val "$P4" price_kept)"
check "6g …the price it puts back is the stored one" "500000" \
  "$(printf '%s' "$(val "$P4" price_after)" | sed 's/\.0*$//')"
check "6h …and nothing names it any more" "absent" "$(val "$(state fields)" conflicts)"
say "note=the link table of migration 35 survives a rollback and is still read,"
say "note=but that is a statement about the TABLE. On these bytes the three"
say "note=fields are written unconditionally again and price is sensitive again."

# ------------------------------------------------------------- forward again
say ""
say "=== forward again, because a rollback is not a decision ==="
install_zip "$NEW_ZIP"
check "7a the new package is installed" "$NEW_VERSION" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "7b the structure is 35" "35" \
  "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
E3="$(state shop-edit)"
printf '%s\n' "$E3" >>"$LOG"
check "7c the owner's edit lands" "480000" "$(val "$E3" shop_price)"
check "7d …and is kept again" "yes" "$(val "$(state project)" price_kept)"
check "7e with the whole set intact" "$TERMS" "$(val "$(state fields)" shop_terms)"
state reset >/dev/null

say ""
say "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
