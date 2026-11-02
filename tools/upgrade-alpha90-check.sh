#!/usr/bin/env bash
# `alpha.90` → `alpha.91` on the disposable WordPress: structure 36 → 37, an
# ADDITIVE step with NO backfill, an injected failure, a resume, a REAL
# manager confirmation, and a rollback whose cost is measured rather than
# argued.
#
# WHAT THIS MEASURES
#
#   1. The schema numbers come from the two ZIPs themselves, not from this
#      file: a hand-typed number locks the script to one round and three of
#      them did exactly that (`alpha.34`).
#   2. The database is put GENUINELY on 36 first (`unmigrate-37`), so step 37
#      is measured doing its work rather than reporting «already done»
#      (`alpha.70`).
#   3. ⛔ **THE STEP WRITES NO CONFIRMATION.** «این تصمیم نباید خودکار ثبت
#      شود», so the new table is counted: EMPTY after the step. A migration
#      that helpfully confirmed the shops it found would look tidy and be
#      caught here.
#   4. Swapping the files runs NOTHING. `UpgradeGate` runs the step on the
#      next admin request (`admin_init` priority 5), and that is what is
#      triggered here.
#   5. An INJECTED FAILURE: a table of the same NAME and the wrong SHAPE.
#      `CREATE TABLE IF NOT EXISTS` is satisfied by it, so only `verify()`
#      can refuse — the `alpha.51` trap from the other side. The structure
#      stays on 36 and the next request finishes the job (`alpha.46`).
#   6. Then the WHOLE of §2 end to end, with the REAL `WpDokanReader`:
#      Dokan-shaped tables present ⇒ the reconciliation is settled ⇒ the
#      manager confirms ⇒ Dokan's detection table is dropped ⇒ the gate is
#      CLOSED by the confirmation rather than by a count. Then revoked ⇒ the
#      gate re-opens and the row stays as history.
#   7. And the ROLLBACK to `alpha.90` is measured rather than argued: the
#      confirmation table is LEFT IN PLACE, no product row and no archive row
#      is touched, and the older build simply does not know the decision — so
#      the gate goes back to `dokan_absent`. ⛔ That is the COST, printed
#      rather than hidden: the decision is not lost, it stops counting until
#      the files come forward again.
#
# ⛔ One fixture shop, one product row, one archive row and two Dokan-SHAPED
# tables are created and given back. Dokan itself cannot run in this
# environment (`alpha.53`), so the source side is data in Dokan's own table
# names and the reader under measurement is the production one. Nothing else
# on the site is written, no import is re-run, and no real Dokan install is
# read or touched.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha90-check.sh docs/evidence/upgrade-alpha91
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha91}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
NEW_ZIP="${TMC_NEW_ZIP:-}"
OLD_ZIP="${TMC_OLD_ZIP:-}"
STATE_SRC="$REPO/tools/upgrade-alpha90-state.php"
STATE="$WP_PATH/upgrade-alpha90-state.php"
WORK="$(mktemp -d /tmp/tmc-upg91-XXXXXX)"

mkdir -p "$OUT"
RUN="$OUT/run.txt"
: >"$RUN"
PASS=0
FAIL=0

say() { printf '%s\n' "$*" | tee -a "$RUN"; }
check() {
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  ($3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=$2 actual=$3"
  fi
}
note() { say "NOTE  $1  $2"; }
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }
state() { wpx eval-file "$STATE" "$@" 2>>"$RUN"; }
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }
# THE ADMIN REQUEST, which is the only thing that runs a migration.
# WooCommerce needs its admin functions loaded before `admin_init` or the
# hook fatals under WP-CLI (`alpha.67`).
admin_request() {
  wpx eval '
    if (file_exists(WP_PLUGIN_DIR . "/woocommerce/includes/admin/wc-admin-functions.php")) {
        require_once WP_PLUGIN_DIR . "/woocommerce/includes/admin/wc-admin-functions.php";
    }
    do_action("admin_init");
    echo "ran=yes\n";
  ' 2>>"$RUN"
}

cleanup() {
  state reset >/dev/null 2>&1
  rm -f "$STATE"
  rm -rf "$WORK"
}
trap cleanup EXIT

# ------------------------------------------------------- the two packages
if [[ -z "$NEW_ZIP" ]]; then
  NEW_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.91.zip 2>/dev/null | head -1)"
fi
if [[ -z "$OLD_ZIP" ]]; then
  OLD_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.90.zip 2>/dev/null | head -1)"
fi
say "=== the packages ==="
say "new_zip=${NEW_ZIP:-__absent__}"
say "old_zip=${OLD_ZIP:-__absent__}"
if [[ ! -f "$NEW_ZIP" || ! -f "$OLD_ZIP" ]]; then
  say "FAIL  0a both packages have to exist; build the new one first"
  exit 1
fi
# ⛔ THE NUMBERS COME FROM THE ZIPS. Reading them from this checkout would
# make every sentence below a sentence about this checkout (`alpha.34`).
schema_of() {
  rm -rf "$WORK/peek"
  mkdir -p "$WORK/peek"
  unzip -qo "$1" -d "$WORK/peek"
  sed -n 's/.*public const TARGET = \([0-9]\+\);.*/\1/p' \
    "$WORK/peek/tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php" | head -1
}
NEW_TARGET="$(schema_of "$NEW_ZIP")"
OLD_TARGET="$(schema_of "$OLD_ZIP")"
say "new_target=$NEW_TARGET"
say "old_target=$OLD_TARGET"
check "0a the new package targets one step further" "$((OLD_TARGET + 1))" "$NEW_TARGET"
check "0b and the old one targets the previous structure" "36" "$OLD_TARGET"

install_zip() {
  wpx plugin install "$1" --force >>"$RUN" 2>&1
  wpx plugin activate tecteb-marketplace-core >>"$RUN" 2>&1
}

# =================================================== a site genuinely on 36
say ""
say "=== 1 · the site is put on structure 36, for real ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
state reset >>"$RUN" 2>&1
S_UN="$(state unmigrate-37)"; printf '%s\n' "$S_UN" >>"$RUN"
check "1a the site is genuinely on 36" "36" "$(val "$S_UN" schema_version)"
check "1b …with no confirmation table" "no" "$(val "$S_UN" table_present)"
S_ARR="$(state arrange)"; printf '%s\n' "$S_ARR" >>"$RUN"
check "1c the archive row was recorded" "recorded" "$(val "$S_ARR" archive)"
check "1d Dokan's detection table is present" "yes" "$(val "$S_ARR" dokan_orders)"
check "1e the source holds one balance row" "1" "$(val "$S_ARR" dokan_balance_rows)"
check "1f and we hold the matching one" "1" "$(val "$S_ARR" held)"
VERSION_TAKEN="$(val "$S_ARR" version)"
note "1g the version the confirmation will be taken against" "$VERSION_TAKEN"
if [[ "$VERSION_TAKEN" == "0" ]]; then
  FAIL=$((FAIL + 1)); say "FAIL  1h the counter must have moved, or this measures the zero case"
else
  PASS=$((PASS + 1)); say "PASS  1h the counter moved ($VERSION_TAKEN)"
fi
S0="$(state state)"; printf '%s\n' "$S0" >>"$RUN"
check "1i the old build does not know the step" "no" "$(val "$S0" confirmed_known)"
check "1j and its confirm service is absent" "absent" "$(val "$(state confirm)" service)"

# ============================================ swapping the files runs nothing
say ""
say "=== 2 · the files are swapped, and NOTHING has run yet ==="
install_zip "$NEW_ZIP"
S1="$(state state)"; printf '%s\n' "$S1" >>"$RUN"
check "2a the structure has not moved" "36" "$(val "$S1" schema_version)"
check "2b no table yet" "no" "$(val "$S1" table_present)"
check "2c but the new build knows the step" "yes" "$(val "$S1" confirmed_known)"

# ======================================= an injected failure, then a resume
say ""
say "=== 3 · a table of the same name and the wrong shape ==="
S_MIS="$(state misshape)"; printf '%s\n' "$S_MIS" >>"$RUN"
check "3a the impostor is there" "yes" "$(val "$S_MIS" table_present)"
check "3b with one column" "1" "$(val "$S_MIS" columns)"
admin_request >>"$RUN" 2>&1
S2="$(state state)"; printf '%s\n' "$S2" >>"$RUN"
check "3c verify() refused, so the structure stays on 36" "36" "$(val "$S2" schema_version)"
LAST_ERROR="$(wpx option get tmc_migration_last_error 2>/dev/null | head -3 | tr '\n' ' ')"
check "3d …and the reason was recorded" "yes" \
  "$([[ -n "$LAST_ERROR" ]] && echo yes || echo no)"
note  "3e the recorded reason" "${LAST_ERROR:0:140}"
# And the resume: with the impostor gone the next request finishes the job.
S_UN2="$(state unmigrate-37)"; printf '%s\n' "$S_UN2" >>"$RUN"
check "3f the impostor is gone" "no" "$(val "$S_UN2" table_present)"
# ⛔ A FAILED STEP IS THROTTLED: `UpgradeGate::RETRY_COOLDOWN_SECONDS` is 300,
# so the very next admin request declines by design (`alpha.46`). Deleting the
# recorded failure stands in for waiting those five minutes — it is the same
# state the wait produces, not a safety gate being stepped around. The FIRST
# run of this script did not do it, measured step 37 declining, and reported a
# healthy build as broken (`alpha.29`).
note  "3g seconds this build would otherwise wait" "300"
wpx option delete tmc_migration_last_error >>"$RUN" 2>&1

say ""
say "=== 4 · the step runs on the next admin request ==="
admin_request >>"$RUN" 2>&1
S3="$(state state)"; printf '%s\n' "$S3" >>"$RUN"
check "4a the structure is 37" "37" "$(val "$S3" schema_version)"
check "4b the table exists" "yes" "$(val "$S3" table_present)"
# ⛔ THE STEP WROTE NOTHING. «هیچ تأییدی خودکار صادر نشود.»
check "4c and it is EMPTY — no backfill" "0" "$(val "$S3" rows)"
check "4d so the gate is where it was" "satisfied" "$(val "$S3" gate_records)"
note "4e the reconciliation state while Dokan answers" "$(val "$S3" records_state)"

# ================================================= §2, end to end, for real
say ""
say "=== 5 · one explicit confirmation, with the real Dokan reader ==="
S_CONF="$(state confirm)"; printf '%s\n' "$S_CONF" >>"$RUN"
check "5a the manager confirmed" "yes" "$(val "$S_CONF" ok)"
check "5b with the expected code" "records_confirmed" "$(val "$S_CONF" code)"
S_ROWS="$(state rows)"; printf '%s\n' "$S_ROWS" >>"$RUN"
check "5c one row" "1" "$(val "$S_ROWS" rows)"
check "5d live" "9911" "$(val "$S_ROWS" row0_live)"
check "5e taken against the real version" "$VERSION_TAKEN" "$(val "$S_ROWS" row0_version)"
check "5f audited once" "1" "$(val "$S_ROWS" audit_confirmed)"
RECON="$(val "$S_ROWS" row0_reconciliation)"
if [[ "$RECON" == *"balance:"* ]]; then
  PASS=$((PASS + 1)); say "PASS  5g the reconciliation it was taken against is recorded ($RECON)"
else
  FAIL=$((FAIL + 1)); say "FAIL  5g expected the reconciliation to name the kinds, got=$RECON"
fi
# A confirmation changes NOTHING while Dokan still answers.
S4="$(state state)"; printf '%s\n' "$S4" >>"$RUN"
check "5h the gate still answers from the reconciliation" "satisfied" "$(val "$S4" gate_records)"

say ""
say "=== 6 · Dokan goes, and the confirmation is what closes the gate ==="
check "6a the detection table is gone" "no" "$(val "$(state dokan-off)" dokan_present)"
S5="$(state state)"; printf '%s\n' "$S5" >>"$RUN"
check "6b the state names the confirmation" "confirmed" "$(val "$S5" records_state)"
check "6c and the requirement is MET by the confirmation" "satisfied" "$(val "$S5" gate_records)"

say ""
say "=== 7 · revocation re-opens it, and keeps the row ==="
S_REV="$(state revoke)"; printf '%s\n' "$S_REV" >>"$RUN"
check "7a revoked" "yes" "$(val "$S_REV" ok)"
S_ROWS2="$(state rows)"; printf '%s\n' "$S_ROWS2" >>"$RUN"
check "7b the row is HISTORY and stays" "1" "$(val "$S_ROWS2" rows)"
check "7c with no live key" "__null__" "$(val "$S_ROWS2" row0_live)"
check "7d and its own stamp" "yes" "$(val "$S_ROWS2" row0_revoked)"
check "7e audited" "1" "$(val "$S_ROWS2" audit_revoked)"
S6="$(state state)"; printf '%s\n' "$S6" >>"$RUN"
check "7f the requirement is unmet again" "unsatisfied" "$(val "$S6" gate_records)"
check "7g and it says Dokan cannot be asked" "dokan_absent" "$(val "$S6" records_state)"
# Confirm again, so the rollback below has something to cost.
S_CONF2="$(state dokan-on)"; printf '%s\n' "$S_CONF2" >>"$RUN"
S_CONF3="$(state confirm)"; printf '%s\n' "$S_CONF3" >>"$RUN"
check "7h a second confirmation is possible" "yes" "$(val "$S_CONF3" ok)"
check "7i two decisions, one live" "2" "$(val "$(state rows)" rows)"
state dokan-off >>"$RUN" 2>&1
check "7j and the requirement is met again" "satisfied" "$(val "$(state state)" gate_records)"

# ==================================================== the rollback, measured
say ""
say "=== 8 · back to alpha.90, with the cost printed ==="
install_zip "$OLD_ZIP"
S7="$(state state)"; printf '%s\n' "$S7" >>"$RUN"
check "8a the structure does NOT go backwards" "37" "$(val "$S7" schema_version)"
check "8b the table is left in place" "yes" "$(val "$S7" table_present)"
check "8c and both decisions are still there" "2" "$(val "$S7" rows)"
check "8d the old build does not know the step" "no" "$(val "$S7" confirmed_known)"
# ⛔ THE COST, named: the older build has no arm for a confirmation, so the
# gate goes back to «Dokan cannot be asked». The decision is not lost — it
# stops counting until the files come forward again.
check "8e so the requirement is unmet again" "unsatisfied" "$(val "$S7" gate_records)"
check "8f and says Dokan cannot be asked" "dokan_absent" "$(val "$S7" records_state)"
note "8g the cost of rolling back" "the confirmation stays on disk and stops counting"

say ""
say "=== 9 · forward again, and it counts again ==="
install_zip "$NEW_ZIP"
admin_request >>"$RUN" 2>&1
S8="$(state state)"; printf '%s\n' "$S8" >>"$RUN"
check "9a the structure is 37 and no step re-ran" "37" "$(val "$S8" schema_version)"
check "9b the decisions are untouched" "2" "$(val "$S8" rows)"
check "9c and the requirement is met again" "satisfied" "$(val "$S8" gate_records)"

say ""
say "pass=$PASS fail=$FAIL"
[[ "$FAIL" == "0" ]] || exit 1
