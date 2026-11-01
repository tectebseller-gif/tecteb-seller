#!/usr/bin/env bash
# `alpha.88` → `alpha.89` on the disposable WordPress: structure 35 → 36, an
# ADDITIVE step with NO backfill, an injected failure, a resume, a manager
# decision, and a rollback whose cost is measured rather than argued.
#
# WHAT THIS MEASURES
#
#   1. The schema numbers come from the two ZIPs themselves, not from this
#      file: a hand-typed number locks the script to one round and three of
#      them did exactly that (`alpha.34`).
#   2. The database is put GENUINELY on 35 first (`unmigrate-36`), so step 36
#      is measured doing its work rather than reporting «already done»
#      (`alpha.70`).
#   3. ⛔ **THE STEP WRITES NOTHING.** «مهاجرت ساختار داده 35→36 افزایشی،
#      قابل آزمون و بدون تخریب اطلاعات باشد» is a claim about rows, so the
#      rows are counted: the new table is EMPTY after the step, both fixture
#      products carry byte-for-byte what they carried before, their
#      WooCommerce relationships are untouched, and the number of products
#      waiting for a category is the SAME before and after. A migration that
#      helpfully accepted the four products would look tidy and be caught
#      here.
#   4. Swapping the files runs NOTHING. `UpgradeGate` runs the step on the
#      next admin request (`admin_init` priority 5), and that is what is
#      triggered here.
#   5. An INJECTED FAILURE: a table of the same NAME and the wrong SHAPE.
#      `CREATE TABLE IF NOT EXISTS` is satisfied by it, so only `verify()`
#      can refuse — the `alpha.51` trap from the other side. The structure
#      stays on 35, the reason is recorded, and the next request finishes the
#      job (`alpha.46`).
#   6. Then ONE manager decision through the service, and the gate moves by
#      one — so «شمارنده، فهرست و دروازه یک منطق مشترک دارند» is measured on
#      a real database and not only in the suite.
#   7. And the ROLLBACK to `alpha.88` is measured rather than argued: the
#      acceptance table is LEFT IN PLACE, no product row is touched, and the
#      older build simply omits the clause — so the accepted product goes
#      back to waiting. ⛔ That is the COST, and it is printed, not hidden:
#      the decision is not lost, it stops counting until the files come
#      forward again.
#
# ⛔ Two fixture products and one term are created and given back; nothing
# else on the site is written, no import is re-run, and no Dokan table is
# read or touched.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha88-check.sh docs/evidence/upgrade-alpha89
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha89}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
NEW_ZIP="${TMC_NEW_ZIP:-}"
OLD_ZIP="${TMC_OLD_ZIP:-}"
STATE_SRC="$REPO/tools/upgrade-alpha88-state.php"
STATE="$WP_PATH/upgrade-alpha88-state.php"
WORK="$(mktemp -d /tmp/tmc-upg89-XXXXXX)"

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
  NEW_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.89.zip 2>/dev/null | head -1)"
fi
if [[ -z "$OLD_ZIP" ]]; then
  OLD_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.88.zip 2>/dev/null | head -1)"
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
check "0b and the old one targets the previous structure" "35" "$OLD_TARGET"

install_zip() {
  wpx plugin install "$1" --force >>"$RUN" 2>&1
  wpx plugin activate tecteb-marketplace-core >>"$RUN" 2>&1
}

# =================================================== a site genuinely on 35
say ""
say "=== 1 · the site is put on structure 35, for real ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
state reset >>"$RUN" 2>&1
# ⛔ PUTTING THE SITE BACK ON 35 IS ITS OWN STEP. Installing the older package
# does not lower anything — `MigrationRunner` never walks backwards and the
# table stays where it is, which is exactly the rollback promise. So a run
# that follows a previous one would measure step 36 reporting «already done»
# (`alpha.70`).
S_UN0="$(state unmigrate-36)"; printf '%s\n' "$S_UN0" >>"$RUN"
check "1a0 the site is genuinely on 35" "35" "$(val "$S_UN0" schema_version)"
check "1a1 …with no acceptance table" "no" "$(val "$S_UN0" table_present)"
WAIT_EMPTY="$(val "$(state waiting)" waiting)"
S_ARR="$(state arrange)"; printf '%s\n' "$S_ARR" >>"$RUN"
check "1a the two fixture rows exist" "yes" "$(val "$S_ARR" arranged)"
DEFAULT_TERM="$(val "$S_ARR" default_term)"
REAL_TERM="$(val "$S_ARR" real_term)"
say "default_term=$DEFAULT_TERM real_term=$REAL_TERM"
check "1b the shop has a default product category" "yes" \
  "$([[ "${DEFAULT_TERM:-0}" -gt 0 ]] && echo yes || echo no)"
check "1c and the control fixture has a real one, which is not the default" "yes" \
  "$([[ "${REAL_TERM:-0}" -gt 0 && "$REAL_TERM" != "$DEFAULT_TERM" ]] && echo yes || echo no)"
S_ROWS0="$(state rows)"; printf '%s\n' "$S_ROWS0" >>"$RUN"
check "1d the default-only row carries exactly the default term" "$DEFAULT_TERM" \
  "$(val "$S_ROWS0" terms_default)"
check "1e …no category key" "__empty__" "$(val "$S_ROWS0" key_default)"
check "1f …and no recorded relationship" "0" "$(val "$S_ROWS0" link_rows_for_fixtures)"
check "1g the control row carries the real category" "$REAL_TERM" "$(val "$S_ROWS0" terms_real)"
S_W0="$(state waiting)"; printf '%s\n' "$S_W0" >>"$RUN"
WAIT_BEFORE="$(val "$S_W0" waiting)"
check "1h the recorded structure says 35" "35" "$(val "$S_W0" schema_version)"
# ⛔ BOTH are waiting, and that is the honest starting point: the control row
# carries a REAL category in WooCommerce and still has no recorded set and no
# key, which is exactly what `categories_mapped` counts. It is here so that a
# clause which quietly settled everybody would be caught.
check "1i both fixtures are counted as waiting, and the number is the gate's own" "2" \
  "$(( WAIT_BEFORE - WAIT_EMPTY ))"
check "1j the older build has no step 36 at all" "absent" "$(val "$(state carried)" step_class)"
S_T0="$(state table)"; printf '%s\n' "$S_T0" >>"$RUN"
check "1k …and still no acceptance table" "no" "$(val "$S_T0" table_present)"

# =================================================== the injected failure
say ""
say "=== 2 · the files are swapped, and a failing step stays failed ==="
install_zip "$NEW_ZIP"
cp "$STATE_SRC" "$STATE"
S_BEFORE="$(state table)"; printf '%s\n' "$S_BEFORE" >>"$RUN"
check "2a swapping the files ran nothing" "35" "$(val "$S_BEFORE" schema_version)"
check "2b …and the table is still not there" "no" "$(val "$S_BEFORE" table_present)"
S_MIS="$(state misshape)"; printf '%s\n' "$S_MIS" >>"$RUN"
check "2c the injection left a one-column table of that name" "product_id" \
  "$(val "$S_MIS" columns)"
admin_request >>"$RUN" 2>&1
S_FAILED="$(state table)"; printf '%s\n' "$S_FAILED" >>"$RUN"
check "2d ⛔ IF NOT EXISTS was satisfied, and verify() refused anyway" "35" \
  "$(val "$S_FAILED" schema_version)"
check "2e …the mis-shaped table is still the one on disk" "product_id" \
  "$(val "$S_FAILED" columns)"
LAST_ERROR="$(wpx option get tmc_migration_last_error 2>/dev/null | head -3 | tr '\n' ' ')"
check "2f …and the reason was recorded" "yes" \
  "$([[ -n "$LAST_ERROR" ]] && echo yes || echo no)"
note  "2g the recorded reason" "${LAST_ERROR:0:140}"
note  "2h what carriedBy() says about a table of the wrong shape" \
  "$(val "$(state carried)" carried)"
S_WBAD="$(state waiting)"; printf '%s\n' "$S_WBAD" >>"$RUN"
check "2i ⛔ and a failed step leaves the gate exactly where it was" "$WAIT_BEFORE" \
  "$(val "$S_WBAD" waiting)"

say ""
say "=== 3 · the injection is removed and the next request finishes the job ==="
state unmigrate-36 >>"$RUN" 2>&1
wpx option delete tmc_migration_last_error >>"$RUN" 2>&1
admin_request >>"$RUN" 2>&1
S_DONE="$(state table)"; printf '%s\n' "$S_DONE" >>"$RUN"
check "3a the structure moved to 36" "36" "$(val "$S_DONE" schema_version)"
check "3b the table is there" "yes" "$(val "$S_DONE" table_present)"
check "3c …with its seven columns" \
  "id,product_id,live_product_id,term_id,decided_by,decided_at,revoked_by,revoked_at" \
  "$(val "$S_DONE" columns)"
check "3d …and the LIVE key alone is unique, which is what makes one decision live" \
  "live_product_id" "$(val "$S_DONE" unique_on)"
check "3e and the step answers «present», not «unknown»" "yes" "$(val "$(state carried)" carried)"
check "3f the step version is 36" "36" "$(val "$(state carried)" step_version)"

# ======================================= the step wrote nothing, measured
say ""
say "=== 4 · ⛔ the step is ADDITIVE: it created a table and wrote no row ==="
check "4a the new table is EMPTY — there is no backfill, by design" "0" \
  "$(val "$S_DONE" accept_rows)"
S_ROWS1="$(state rows)"; printf '%s\n' "$S_ROWS1" >>"$RUN"
check "4b the default-only row still carries exactly the default term" \
  "$(val "$S_ROWS0" terms_default)" "$(val "$S_ROWS1" terms_default)"
check "4c …its key was not filled in" "__empty__" "$(val "$S_ROWS1" key_default)"
check "4d …no relationship was recorded for it" "0" "$(val "$S_ROWS1" link_rows_for_fixtures)"
check "4e …its ownership is untouched" "$(val "$S_ROWS0" owner_default)" \
  "$(val "$S_ROWS1" owner_default)"
check "4f …and so is its price" "$(val "$S_ROWS0" price_default)" \
  "$(val "$S_ROWS1" price_default)"
check "4g the control row's real category is untouched too" "$(val "$S_ROWS0" terms_real)" \
  "$(val "$S_ROWS1" terms_real)"
S_W1="$(state waiting)"; printf '%s\n' "$S_W1" >>"$RUN"
check "4h ⛔ AND THE GATE DID NOT MOVE: the same one row is waiting" "$WAIT_BEFORE" \
  "$(val "$S_W1" waiting)"

say ""
say "=== 5 · running the step again changes nothing ==="
admin_request >>"$RUN" 2>&1
S_AGAIN="$(state table)"; printf '%s\n' "$S_AGAIN" >>"$RUN"
check "5a the structure is still 36" "36" "$(val "$S_AGAIN" schema_version)"
check "5b and the table is still empty" "0" "$(val "$S_AGAIN" accept_rows)"
check "5c …with the same waiting count" "$WAIT_BEFORE" "$(val "$(state waiting)" waiting)"

# =================================== one explicit decision, and the gate
say ""
say "=== 6 · ONE manager decision, through the service, and the gate moves ==="
S_ACC="$(state accept)"; printf '%s\n' "$S_ACC" >>"$RUN"
check "6a the decision was taken" "yes" "$(val "$S_ACC" accepted)"
check "6b …with no refusal" "__none__" "$(val "$S_ACC" refused)"
check "6c …and the named outcome" "default_category_accepted" "$(val "$S_ACC" outcome)"
check "6c2 …for the term the page printed" "$DEFAULT_TERM" "$(val "$S_ACC" term)"
S_LIVE="$(state live)"; printf '%s\n' "$S_LIVE" >>"$RUN"
check "6d exactly one row was written" "1" "$(val "$S_LIVE" accept_rows)"
check "6e …it is live" "$(val "$S_ARR" row_default)" "$(val "$S_LIVE" live_default)"
check "6f …for the default term and no other" "$DEFAULT_TERM" "$(val "$S_LIVE" term_default)"
check "6g …and the control product got none" "__none__" "$(val "$S_LIVE" live_real)"
S_ROWS2="$(state rows)"; printf '%s\n' "$S_ROWS2" >>"$RUN"
check "6h ⛔ the decision wrote NO category key" "__empty__" "$(val "$S_ROWS2" key_default)"
check "6i ⛔ …NO link row" "0" "$(val "$S_ROWS2" link_rows_for_fixtures)"
check "6j ⛔ …and did not touch the WooCommerce relationship" \
  "$(val "$S_ROWS0" terms_default)" "$(val "$S_ROWS2" terms_default)"
S_W2="$(state waiting)"; printf '%s\n' "$S_W2" >>"$RUN"
check "6k and NOW the gate moves by exactly one" "$((WAIT_BEFORE - 1))" \
  "$(val "$S_W2" waiting)"
note  "6l ⛔ and the control is STILL waiting" "a real category that was never recorded is not a decision"

# =================================================== the rollback
say ""
say "=== 7 · back to alpha.88, which needs no database work ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
S_OLD="$(state table)"; printf '%s\n' "$S_OLD" >>"$RUN"
check "7a the older package reads the structure as it is" "36" "$(val "$S_OLD" schema_version)"
check "7b ⛔ the acceptance table is LEFT IN PLACE — no decision is deleted" "yes" \
  "$(val "$S_OLD" table_present)"
check "7c …with the decision still in it" "1" "$(val "$S_OLD" accept_rows)"
check "7d …and nothing older names the step" "absent" "$(val "$(state carried)" step_class)"
S_OLDROWS="$(state rows)"; printf '%s\n' "$S_OLDROWS" >>"$RUN"
check "7e no product row changed on the way back" "__empty__" "$(val "$S_OLDROWS" key_default)"
check "7f …and no WooCommerce relationship did either" "$(val "$S_ROWS0" terms_default)" \
  "$(val "$S_OLDROWS" terms_default)"
S_WOLD="$(state waiting)"; printf '%s\n' "$S_WOLD" >>"$RUN"
check "7g ⛔ THE COST, measured: the older build omits the clause, so the accepted product is waiting again" \
  "$WAIT_BEFORE" "$(val "$S_WOLD" waiting)"
note  "7h and that is a report, not a loss" "the row is still there; it stops counting until the files come forward"

say ""
say "=== 8 · forward again, so the machine is left on this package ==="
install_zip "$NEW_ZIP"
cp "$STATE_SRC" "$STATE"
admin_request >>"$RUN" 2>&1
S_FWD="$(state table)"; printf '%s\n' "$S_FWD" >>"$RUN"
check "8a the structure is 36 again" "36" "$(val "$S_FWD" schema_version)"
check "8b …with the table intact" "yes" "$(val "$S_FWD" table_present)"
check "8c …and the one decision still in it" "1" "$(val "$S_FWD" accept_rows)"
check "8d the gate reads it again, after a round trip in both directions" \
  "$((WAIT_BEFORE - 1))" "$(val "$(state waiting)" waiting)"
S_END="$(state rows)"; printf '%s\n' "$S_END" >>"$RUN"
check "8e and the product is byte-for-byte what it was at the start" \
  "$(val "$S_ROWS0" terms_default)" "$(val "$S_END" terms_default)"
check "8f …key included" "__empty__" "$(val "$S_END" key_default)"

state reset >/dev/null 2>&1
say ""
say "checks=$((PASS + FAIL)) pass=$PASS fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
