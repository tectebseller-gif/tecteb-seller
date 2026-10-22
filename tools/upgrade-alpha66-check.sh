#!/usr/bin/env bash
# `alpha.66 → alpha.67` on a real WordPress: a round with TWO migrations.
#
# Its own file rather than another base for `upgrade-alpha65-check.sh`, for the
# reason that script gives about ITS predecessor: the mechanics repeat and the
# PROSE does not, and a run that passes while naming the wrong package is a
# true sentence about something else (`alpha.34`, `alpha.54`).
#
# WHAT THIS HAS TO PROVE, and why each part is measured rather than argued:
#
#   1. both schema numbers are read FROM THE ZIPS (`alpha.38`), so «two steps»
#      is a fact about the packages and not a sentence in a document;
#   2. a line written by the BASE — five money columns, no unit — is still
#      there afterwards, field by field, and is still READ as the money it
#      was: `NULL` means «this row does not say», resolved from the vendor's
#      single money unit exactly as every release before this one resolved it.
#      That is the whole «مبلغ و واحد تاریخی بازتفسیر یا بازنویسی نشوند»
#      requirement, as a number;
#   3. the two steps are ADDITIVE and run from the upgrade GATE on an admin
#      request, not from the activation hook — which is the hole `UpgradeGate`
#      exists to close, because swapping files does not activate anything;
#   4. a FAILURE mid-chain leaves the structure where it was and the next
#      admin request finishes it;
#   5. and going BACK needs no database work — with THIS round's cost stated
#      plainly, because it is the first rollback in this plugin's history with
#      a data-INTERPRETATION cost rather than only a feature cost.
#
# ⛔ The site is put GENUINELY on 31 first (`state unmigrate-31`): without
# that the forward step would measure the creation of columns on a site that
# never had the older shape, which is an upgrade of nothing.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha66-check.sh docs/evidence/upgrade-alpha67
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha67}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
STATE_SRC="$REPO/tools/upgrade-alpha66-state.php"
STATE="$WP_PATH/upgrade-alpha66-state.php"
NEW_ZIP="${TMC_NEW_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.67.zip}"
BASE_ZIP="${TMC_BASE_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.66.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha66-check.txt"
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
note() { say "NOTE  $1  $2"; }
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }
state() { wpx eval-file "$STATE" "$@" 2>>"$LOG"; }
schema_now() { wpx option get tmc_schema_version 2>/dev/null | tr -d '\r'; }
# An admin request is what runs the gate. `admin_init` fires at priority 5
# there; `do_action` is the same call wp-admin makes (`alpha.46` read the
# source for the retry and throttle behaviour).
# WooCommerce's admin functions are loaded on an `is_admin()` request and not
# by WP-CLI, so firing the hook without them is a fatal inside
# `OrderAttributionController` — a true sentence about the CLI and nothing
# about the gate. Required explicitly, which is what wp-admin does.
admin_request() {
  wpx eval '
    $f = WP_PLUGIN_DIR . "/woocommerce/includes/admin/wc-admin-functions.php";
    if (file_exists($f)) { require_once $f; }
    do_action("admin_init");
  ' >>"$LOG" 2>&1
}

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
check "1a the base declares schema 31" "31" "$BASE_SCHEMA"
check "1b the new package declares 33" "33" "$NEW_SCHEMA"
check "1c so this round has TWO steps" "2" "$(( NEW_SCHEMA - BASE_SCHEMA ))"

# --------------------------------------------- a site genuinely on the base
say ""
say "=== on the base package: a line in the old shape ==="
install_zip "$BASE_ZIP"
check "2a the base is installed" "$BASE_VERSION" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
state reset >/dev/null
U="$(state unmigrate-31)"; printf '%s\n' "$U" >>"$LOG"
check "2b the site is put genuinely on 31" "31" "$(val "$U" schema_recorded)"
C0="$(state columns)"; printf '%s\n' "$C0" >>"$LOG"
check "2c mirrored_status is not there" "no" "$(val "$C0" products_mirrored_status)"
check "2d nor the unit on the order line" "no" "$(val "$C0" tmc_order_items_exponent)"
check "2e nor on a withdrawal" "no" "$(val "$C0" tmc_withdrawals_exponent)"
check "2f nor on a withdrawal line" "no" "$(val "$C0" tmc_withdrawal_lines_exponent)"
check "2g nor on a return" "no" "$(val "$C0" tmc_returns_exponent)"
A="$(state arrange)"; printf '%s\n' "$A" >>"$LOG"
check "2h the base writes a line" "yes" "$([[ "$(val "$A" line)" -gt 0 ]] && echo yes || echo no)"
D0="$(state digest)"; printf '%s\n' "$D0" >>"$LOG"
BEFORE="$(val "$D0" identity)"
EARNED_BEFORE="$(val "$D0" balance_earned)"
LEDGER_BEFORE="$(val "$D0" ledger_rows)"
say "identity_before=$BEFORE"
say "balance_earned_before=$EARNED_BEFORE"
check "2i the row carries no unit column at all" "__absent__" "$(val "$D0" legacy_exponent)"
check "2j and the balance is ۴۰۵٬۰۰۰ تومان of share" "405000" "$EARNED_BEFORE"
check "2k the recorded structure is 31" "31" "$(schema_now)"

# ------------------------------------------- a real failure, then a resume
say ""
say "=== the first step is made to FAIL, on purpose ==="
install_zip "$NEW_ZIP"
check "3a the new files are on disk" "$NEW_VERSION" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "3b swapping files alone runs NO step" "31" "$(schema_now)"
# A column of the RIGHT NAME and the WRONG SHAPE: `NOT NULL DEFAULT ''` is
# exactly what `verify()` refuses, and refusing it is the point — a step that
# said «complete» here would leave absence inexpressible on every legacy row
# (`alpha.51`).
wpx db query "ALTER TABLE wp_tmc_products ADD COLUMN mirrored_status VARCHAR(20) NOT NULL DEFAULT ''" >>"$LOG" 2>&1
admin_request
check "3c the chain stops where it was" "31" "$(schema_now)"
note "3d the gate retries on its own, throttled" "RETRY_COOLDOWN_SECONDS = 300 (\`alpha.46\`)"
wpx db query "ALTER TABLE wp_tmc_products DROP COLUMN mirrored_status" >>"$LOG" 2>&1
# The cooldown is keyed on `tmc_migration_last_error` (SchemaVersion::
# LAST_ERROR_OPTION), read from the source rather than guessed: the first
# version of this line deleted an option that does not exist, wp-cli said so
# in a warning nobody asserted on, and the resume never happened — eleven
# failures about a build that worked.
wpx option delete tmc_migration_last_error >>"$LOG" 2>&1
admin_request
check "3e …and the next admin request finishes BOTH steps" "33" "$(schema_now)"

C1="$(state columns)"; printf '%s\n' "$C1" >>"$LOG"
check "4a mirrored_status is there now" "yes" "$(val "$C1" products_mirrored_status)"
check "4b the order line carries a unit" "yes" "$(val "$C1" tmc_order_items_exponent)"
check "4c …and a currency" "yes" "$(val "$C1" tmc_order_items_currency)"
check "4d a withdrawal too" "yes" "$(val "$C1" tmc_withdrawals_exponent)"
check "4e a withdrawal line too" "yes" "$(val "$C1" tmc_withdrawal_lines_exponent)"
check "4f and a return too" "yes" "$(val "$C1" tmc_returns_exponent)"

D1="$(state digest)"; printf '%s\n' "$D1" >>"$LOG"
AFTER="$(val "$D1" identity)"
say "identity_after=$AFTER"
check "5a the line and its ledger hash the same" "$BEFORE" "$AFTER"
check "5b the ledger rows are untouched" "$LEDGER_BEFORE" "$(val "$D1" ledger_rows)"
# ⛔ THE REQUIREMENT, AS A NUMBER. The column exists and says NOTHING, which
# is the truth about every row written before this release; `NULL` is not 0
# and not 4, and the balance reads it from the vendor's own money unit.
check "5c the new column says nothing about this row" "__none__" "$(val "$D1" legacy_exponent)"
check "5d nor about its currency" "__none__" "$(val "$D1" legacy_currency)"
check "5e and the old amount still means what it meant" "$EARNED_BEFORE" "$(val "$D1" balance_earned)"
check "5f the balance is read in the unit the row was written in" "0" "$(val "$D1" balance_exponent)"

# --------------------------------------------------------------- going back
say ""
say "=== and back to $BASE_VERSION ==="
install_zip "$BASE_ZIP"
check "6a the old files are on disk" "$BASE_VERSION" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "6b the structure stays at 33 — a migration is additive" "33" "$(schema_now)"
D2="$(state digest)"; printf '%s\n' "$D2" >>"$LOG"
check "6c no database work is needed to go back" "$AFTER" "$(val "$D2" identity)"
check "6d and the old line still reads the same" "$EARNED_BEFORE" "$(val "$D2" balance_earned)"
say "note=⛔ THE COST OF GOING BACK, and this one is different from every"
say "note=   earlier round: it is the first rollback with a DATA-INTERPRETATION"
say "note=   cost rather than only a feature cost."
say "note=  1. Any commission RECORDED BY alpha.67 is stored at four more"
say "note=     decimal places. alpha.66 reads those columns without a scale,"
say "note=     so ۳۷٫۸۷۵ would read as ۳۷۸٬۷۵۰ — ten thousand times too much."
say "note=     Lines recorded BEFORE alpha.67 are unaffected: they carry no"
say "note=     unit and alpha.66 reads them exactly as it wrote them."
say "note=  2. A pending product's status is held again only while alpha.67 is"
say "note=     installed; under alpha.66 the next projection writes 'draft'."
say "note=  So: stop sales, settle open withdrawal requests and decide any"
say "note=  return awaiting a refund BEFORE swapping back — the five-step"
say "note=  order in docs/upgrade-and-rollback.md §5.2-ب, and §47 for this"
say "note=  round's own table."

# ------------------------------------------------------------- forward again
say ""
say "=== forward again, because a rollback is not a decision ==="
install_zip "$NEW_ZIP"
admin_request
check "7a the new package is installed" "$NEW_VERSION" \
  "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
check "7b the structure is 33 and no step ran again" "33" "$(schema_now)"
check "7c and the data is the same" "$AFTER" "$(val "$(state digest)" identity)"
state reset >/dev/null

say ""
say "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
