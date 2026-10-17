#!/usr/bin/env bash
#
# The owner's upgrade to `alpha.62` — and this file does NOT assume which
# version is on their site.
#
# It runs with TWO bases, both from real delivered ZIPs: `alpha.61`
# (schema 30 → 31, one step) and `alpha.56` (schema 28 → 31, three steps).
# Every claim about the distance between them is READ FROM THE PACKAGES
# rather than typed (`alpha.38`: `NEW − 1` is a guess about the base).
#
# `M0031SupplierObligations` adds five tables and seven columns, and MOVES one
# unique key onto a nullable column so a voided payment's claim row can stop
# being a claim without being deleted. The list to prove is the structural one:
#
#  - the packages differ by the number of steps their schema numbers claim;
#  - a site genuinely on the base — put there by `unmigrate-31`, so the
#    forward step is measured against the older SHAPE and not against the
#    creation of a table on a site that never had it;
#  - replacing the FILES runs no step (an upload fires no activation hook)
#    and the first ADMIN request finishes it;
#  - the new tables exist with the unique keys they promise, the claim table
#    carries `live_item_id` with its own unique key, and the earlier steps
#    STILL verify — the `alpha.51`/`alpha.54` trap, where a `verify()` that
#    insists on yesterday's shape freezes the chain and the next step pays;
#  - what the old build recorded is byte-identical afterwards: one `identity`
#    digest over the payment rows, their item rows and the order line;
#  - what this round ADDS works on an UPGRADED site, not only a fresh one;
#  - going BACK needs no database work, and what it COSTS is measured;
#  - forward again runs nothing twice.
#
#   PHPBIN=php WPCLI=/usr/local/bin/wp \
#     bash tools/upgrade-alpha61-check.sh docs/evidence/upgrade-alpha62
set -uo pipefail

OUT="${1:-docs/evidence/upgrade-alpha62}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
SITE="${TMC_DEMO_SITE:-http://127.0.0.1:8081}"
ADMIN="${TMC_DEMO_ADMIN:-tmcowner}"
ADMIN_PASS="${TMC_DEMO_ADMIN_PASS:-demo-owner-2026}"
PHPBIN="${PHPBIN:-php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
PLUGINS="$WPROOT/wp-content/plugins"
STATE="$REPO/tools/upgrade-alpha61-state.php"

newest_zip() {
  ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.*.zip 2>/dev/null \
    | sed 's/.*alpha\.\([0-9]*\)\.zip/\1 &/' | sort -n | tail -1 | cut -d' ' -f2-
}
NEW_ZIP="${TMC_NEW_ZIP:-$(newest_zip)}"
BASES="${TMC_BASES:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.61.zip $REPO/dist/tecteb-marketplace-core-0.1.0-alpha.56.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha61-check.txt"
: > "$LOG"
pass=0; fail=0
say() { printf '%s\n' "$1" | tee -a "$LOG" >/dev/null; }
check() { # check <label> <expected> <actual>
  if [[ "$2" == "$3" ]]; then
    pass=$((pass + 1)); say "PASS  $1  ($3)"
  else
    fail=$((fail + 1)); say "FAIL  $1  expected=$2 actual=$3"
  fi
}
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WPROOT" "$@"; }
state() { wpx eval-file "$STATE" "$@" 2>>"$LOG"; }
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }

# The schema number a PACKAGE claims, read from the package rather than typed.
zip_schema() {
  local zip="$1" tmp
  tmp="$(mktemp -d)"
  unzip -o -q "$zip" 'tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php' -d "$tmp" 2>/dev/null
  sed -n "s/.*TARGET = \([0-9]*\);.*/\1/p" \
    "$tmp/tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php" 2>/dev/null | head -1
  rm -rf "$tmp"
}
zip_version() {
  local zip="$1"
  basename "$zip" | sed 's/^tecteb-marketplace-core-//; s/\.zip$//'
}

install_zip() { # install_zip <zip>
  rm -rf "$PLUGINS/tecteb-marketplace-core"
  local tmp
  tmp="$(mktemp -d)"
  unzip -q "$1" -d "$tmp"
  cp -r "$tmp/tecteb-marketplace-core" "$PLUGINS/"
  rm -rf "$tmp"
  # opcache compares MTIME, and `build.sh` derives the stamp FROM THE VERSION
  # for exactly this reason (`alpha.38`). A pause here is about the file
  # system's resolution, not about hoping.
  sleep 2
}

COOKIE="$(mktemp)"
admin_request() { # one real logged-in admin request — the only thing that runs a step
  curl -s -c "$COOKIE" -b "$COOKIE" -o /dev/null \
    -d "log=$ADMIN&pwd=$ADMIN_PASS&wp-submit=Log+In&redirect_to=$SITE/wp-admin/&testcookie=1" \
    "$SITE/wp-login.php" >/dev/null 2>&1
  curl -s -b "$COOKIE" -o /dev/null -w '%{http_code}' "$SITE/wp-admin/admin.php?page=tmc-health"
}

[[ -f "$NEW_ZIP" ]] || { say "FAIL  no new ZIP found"; printf 'PASS=0 FAIL=1\n' | tee -a "$LOG"; exit 1; }
NEW_SCHEMA="$(zip_schema "$NEW_ZIP")"
say "new_package=$(zip_version "$NEW_ZIP") new_schema=$NEW_SCHEMA"

for BASE_ZIP in $BASES; do
  [[ -f "$BASE_ZIP" ]] || { say "SKIP  missing base $BASE_ZIP"; continue; }
  BASE_SCHEMA="$(zip_schema "$BASE_ZIP")"
  BASE_NAME="$(zip_version "$BASE_ZIP")"
  say ""
  say "================ base $BASE_NAME (schema $BASE_SCHEMA) ================"
  STEPS=$(( NEW_SCHEMA - BASE_SCHEMA ))
  check "0a the two packages differ by $STEPS step(s), read from the ZIPs" \
    "yes" "$([[ "$STEPS" -ge 1 ]] && echo yes || echo no)"

  # ---- 1. a site genuinely on the base -----------------------------------
  install_zip "$BASE_ZIP"
  check "1a the base is installed" "$BASE_NAME" "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
  # The newer SHAPE is taken back off, so the forward step is measured
  # against the older one rather than against an empty database.
  # ONLY A BASE THAT HAS THE TABLES can have the newer shape taken off it.
  # `alpha.56` is schema 28 and the two supplier-payment tables arrive with
  # step 29, so there `unmigrate-31` answers `absent=1` — which is the
  # measurement, not a skip. Measured rather than assumed: the first run of
  # this script asked for a service that base does not bind and read five
  # empty values as five failures.
  U="$(state unmigrate-31)"
  if [[ "$BASE_SCHEMA" -ge 29 ]]; then
    check "1b the newer columns are gone" "no" "$(val "$U" voided_at)"
    check "1c the live claim key is gone" "no" "$(val "$U" live_item_id)"
    check "1d the old claim key is back" "yes" "$(val "$U" unique_order_item_id)"
  else
    check "1b-d this base has no supplier payments to un-migrate" "1" "$(val "$U" absent)"
  fi
  wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null 2>&1
  check "1e the site reports the base schema" "$BASE_SCHEMA" "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
  check "1f the gate finishes it" "200" "$(admin_request)"
  check "1g …and the base is complete" "$BASE_SCHEMA" "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"

  # ---- 2. data the OLD build recorded ------------------------------------
  state reset >/dev/null
  A="$(state arrange)"
  check "2a a sale with a share exists" "900000" "$(val "$A" share)"
  BEFORE=""
  if [[ "$BASE_SCHEMA" -ge 29 ]]; then
    P="$(state pay 900000 TRK-U62-BASE)"
    check "2b the OLD build records a payment" "supplier_payment_recorded" "$(val "$P" pay_code)"
    OLD_PAYMENT="$(val "$P" payment_id)"
    AG="$(state agree 900000)"
    check "2c …and knows nothing about an agreed amount" "1" "$(val "$AG" absent)"
    VD="$(state void "$OLD_PAYMENT")"
    check "2d …nor about voiding one" "1" "$(val "$VD" absent)"
    D0="$(state digest)"
    BEFORE="$(val "$D0" identity)"
  else
    P="$(state pay 900000 TRK-U62-BASE)"
    check "2b-d this base cannot record a supplier payment at all" "1" "$(val "$P" absent)"
  fi
  say "identity_before=$BEFORE"

  # ---- 3. replacing the files runs nothing -------------------------------
  install_zip "$NEW_ZIP"
  check "3a the new files are on disk" "$(zip_version "$NEW_ZIP")" "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
  check "3b …and swapping them ran NO step" "$BASE_SCHEMA" "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
  check "3c the first admin request answers" "200" "$(admin_request)"
  check "3d …and finishes the upgrade" "$NEW_SCHEMA" "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"

  # ---- 4. the shape it promises ------------------------------------------
  T="$(state tables)"
  check "4a the payment table still exists" "1" "$(val "$T" payments_table)"
  check "4b voided_at is there" "yes" "$(val "$T" voided_at)"
  check "4c void_reason is there" "yes" "$(val "$T" void_reason)"
  check "4d share_minor_final is there" "yes" "$(val "$T" share_minor_final)"
  check "4e reconciled_at is there" "yes" "$(val "$T" reconciled_at)"
  check "4f the claim table carries live_item_id" "yes" "$(val "$T" live_item_id)"
  check "4g …with its own unique key" "yes" "$(val "$T" unique_live_item_id)"
  check "4h …and the old single-column key is gone" "no" "$(val "$T" unique_order_item_id)"
  check "4i the obligations table exists" "1" "$(val "$T" tmc_supplier_obligations)"
  check "4j its history table exists" "1" "$(val "$T" tmc_supplier_obligation_history)"
  check "4k the allocations table exists" "1" "$(val "$T" tmc_supplier_payment_allocations)"
  check "4l the receivables table exists" "1" "$(val "$T" tmc_supplier_receivables)"
  check "4m its settlements table exists" "1" "$(val "$T" tmc_supplier_receivable_settlements)"
  check "4n step 29 STILL verifies" "yes" "$(val "$T" m0029_verifies)"
  check "4o step 30 still verifies" "yes" "$(val "$T" m0030_verifies)"
  check "4p step 31 verifies" "yes" "$(val "$T" m0031_verifies)"

  # ---- 5. nothing the old build recorded moved ---------------------------
  D1="$(state digest)"
  if [[ -n "$BEFORE" ]]; then
    check "5a the recorded payment is byte-identical" "$BEFORE" "$(val "$D1" identity)"
    check "5b …one payment row, still" "1" "$(val "$D1" payment_rows)"
    check "5c …one claim row, still" "1" "$(val "$D1" item_rows)"
  else
    check "5a-c there was no payment on this base to preserve" "0" "$(val "$D1" payment_rows)"
  fi

  # ---- 6. what this round adds works on an UPGRADED site -----------------
  state reset >/dev/null
  state arrange >/dev/null
  AG2="$(state agree 900000)"
  check "6a an agreed amount can be recorded" "supplier_amount_agreed" "$(val "$AG2" agree_code)"
  PP="$(state pay 500000 TRK-U62-PART)"
  check "6b a partial payment is recorded as one" "supplier_payment_recorded_partial" "$(val "$PP" pay_code)"
  check "6c …with the right balance" "400000" "$(val "$PP" balance_after)"
  PID2="$(val "$PP" payment_id)"
  VD2="$(state void "$PID2" 'اصلاح ارتقا')"
  check "6d it can be voided" "supplier_payment_voided" "$(val "$VD2" void_code)"
  check "6e …and reads as void" "yes" "$(val "$VD2" is_void)"
  PP2="$(state pay 500000 TRK-U62-AGAIN)"
  check "6f …and the item is free for a correction" "supplier_payment_recorded_partial" "$(val "$PP2" pay_code)"

  # ---- 7. going back -----------------------------------------------------
  D2="$(state digest)"
  AFTER_NEW="$(val "$D2" identity)"
  install_zip "$BASE_ZIP"
  check "7a the base is back on disk" "$BASE_NAME" "$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
  check "7b …with NO database work, and the structure is not pulled back" \
    "$NEW_SCHEMA" "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
  check "7c the older build still answers" "200" "$(admin_request)"
  # THE COST, measured rather than argued: the older build has no `voided_at`
  # in any query, so the payment this round voided reads as live again — and
  # it therefore refuses a further payment for that item.
  PB="$(state pay 400000 TRK-U62-BACK)"
  AGB="$(state agree 900000)"
  if [[ "$BASE_SCHEMA" -ge 29 ]]; then
    check "7d on the older build a voided claim still holds the item" \
      "supplier_item_already_paid" "$(val "$PB" pay_code)"
    check "7e …and the agreed amount is invisible to it" "1" "$(val "$AGB" absent)"
  else
    check "7d-e this base has no supplier payment path at all" "1" "$(val "$PB" absent)"
  fi

  # ---- 8. forward again runs nothing twice -------------------------------
  install_zip "$NEW_ZIP"
  check "8a forward again" "200" "$(admin_request)"
  check "8b …the structure is unchanged" "$NEW_SCHEMA" "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"
  D3="$(state digest)"
  check "8c …and nothing was rewritten" "$AFTER_NEW" "$(val "$D3" identity)"
  state reset >/dev/null
done

rm -f "$COOKIE"
say ""
say "PASS=$pass FAIL=$fail"
printf 'PASS=%s FAIL=%s\n' "$pass" "$fail"
[[ "$fail" -eq 0 ]]
