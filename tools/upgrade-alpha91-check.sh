#!/usr/bin/env bash
# `alpha.91` → `alpha.92` on the disposable WordPress: structure 37 → 38, a
# BACKFILL with no DDL, an injected failure, a resume, a REAL transfer in both
# directions, and a rollback whose cost is measured rather than argued.
#
# WHAT THIS MEASURES
#
#   1. The schema numbers come from the two ZIPs themselves, not from this
#      file: a hand-typed number locks the script to one round and three of
#      them did exactly that (`alpha.34`).
#   2. The database is put GENUINELY on 37 first (`unmigrate-38`) — stamps
#      cleared, version walked back — because installing an older package does
#      NOT lower the structure, so without it the step reports «already done»
#      and nothing is measured (`alpha.89`).
#   3. ⛔ **THE HAZARD, ON THE REAL PACKAGES.** On `alpha.91` a transfer of an
#      unstamped row beside a published product SUCCEEDS; on `alpha.92` the
#      same call is refused by name with both statuses printed. That is
#      `docs/dokan-exit-drift-analysis-alpha91-fa.md` §2, measured on bytes
#      rather than argued from source.
#   4. Swapping the files runs NOTHING. `UpgradeGate` runs the step on the
#      next admin request (`admin_init` priority 5), and that is what is
#      triggered here.
#   5. An INJECTED FAILURE that a backfill can actually have: the column the
#      step's `UPDATE` names is moved, so the statement is refused and `up()`
#      throws. The structure stays on 37, the reason is recorded, and the next
#      request finishes the job (`alpha.46`).
#   6. The backfill's SCOPE, as three separate answers: the imported row is
#      filled from its live product, the MARKETPLACE-owned row is left alone,
#      and the row whose product was deleted gets nothing — and `verify()`
#      does not wait for it, so the chain cannot lock on a product that no
#      longer exists.
#   7. A REAL transfer in both directions afterwards, with the WooCommerce
#      product's own status, visibility, price, SKU and stock read before and
#      after: «هیچ محصولی نباید … از publish به draft یا hidden تغییر کند».
#   8. And the ROLLBACK to `alpha.91` is measured: the stamps STAY (the column
#      is older than this round), so the rows are MORE protected under the old
#      package, not less — and the COST is that the old build's `take()` has
#      no status guard, so the hazard returns for a row whose stamp is absent.
#      Printed rather than hidden.
#
# ⛔ One fixture vendor, three WooCommerce products and three rows are created
# and given back. No import is re-run, no real Dokan install is read, and
# nothing else on the site is written.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha91-check.sh docs/evidence/upgrade-alpha92
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha92}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
NEW_ZIP="${TMC_NEW_ZIP:-}"
OLD_ZIP="${TMC_OLD_ZIP:-}"
STATE_SRC="$REPO/tools/upgrade-alpha91-state.php"
STATE="$WP_PATH/upgrade-alpha91-state.php"
WORK="$(mktemp -d /tmp/tmc-upg92-XXXXXX)"

mkdir -p "$OUT"
RUN="$OUT/run.txt"
: >"$RUN"
PASS=0
FAIL=0

say() { printf '%s\n' "$*" | tee -a "$RUN"; }
# ⛔ AN EMPTY MEASUREMENT IS NOT A PASS, whatever it is compared against.
#
# Measured this round: with the probe broken, five checks in §7 read
# «PASS  the publish status is untouched  ()» — comparing one empty string to
# another. Sixteen of forty-four «passes» in that run were about nothing. A
# check whose `actual` is empty did not measure anything, so it is RED and
# says so by name (`alpha.46`). A check that genuinely expects emptiness has
# to say which emptiness: the probe prints `__null__`, `__norow__` or
# `__absent__`, never a bare blank.
check() {
  if [[ -z "$3" ]]; then
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=$2 actual=<nothing measured>"
  elif [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  ($3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=$2 actual=$3"
  fi
}
note() { say "NOTE  $1  $2"; }
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }
state() { wpx eval-file "$STATE" "$@" 2>>"$RUN"; }
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }
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
  # ⛔ In the trap, so a run stopped halfway still gives the site back —
  # `alpha.76`'s rule, and `fix-column` FIRST because every later verb needs
  # the column it renames.
  state fix-column >/dev/null 2>&1
  state reset >/dev/null 2>&1
  rm -f "$STATE"
  rm -rf "$WORK"
}
trap cleanup EXIT

# ------------------------------------------------------- the two packages
if [[ -z "$NEW_ZIP" ]]; then
  NEW_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.92.zip 2>/dev/null | head -1)"
fi
if [[ -z "$OLD_ZIP" ]]; then
  OLD_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.91.zip 2>/dev/null | head -1)"
fi
say "=== the packages ==="
say "new_zip=${NEW_ZIP:-__absent__}"
say "old_zip=${OLD_ZIP:-__absent__}"
if [[ ! -f "$NEW_ZIP" || ! -f "$OLD_ZIP" ]]; then
  say "FAIL  0a both packages have to exist; build the new one first"
  exit 1
fi
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
check "0b and the old one targets the previous structure" "37" "$OLD_TARGET"

install_zip() {
  wpx plugin install "$1" --force >>"$RUN" 2>&1
  wpx plugin activate tecteb-marketplace-core >>"$RUN" 2>&1
}

# =================================================== a site genuinely on 37
say ""
say "=== 1 · the site is put on structure 37, for real ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
state reset >>"$RUN" 2>&1
S_ARR="$(state arrange)"; printf '%s\n' "$S_ARR" >>"$RUN"
check "1a the Dokan-shaped row was made" "yes" \
  "$([[ -n "$(val "$S_ARR" dokan_row)" ]] && echo yes || echo no)"
check "1b its WooCommerce product is published" "publish" "$(val "$S_ARR" dokan_post_status)"
check "1c and the row carries NO stamp, as the Dokan path left it" "__null__" "$(val "$S_ARR" stamp_dokan)"
S_UN="$(state unmigrate-38)"; printf '%s\n' "$S_UN" >>"$RUN"
check "1d the site is genuinely on 37" "37" "$(val "$S_UN" schema_version)"
S0="$(state state)"; printf '%s\n' "$S0" >>"$RUN"
check "1e the old build does not know step 38" "no" "$(val "$S0" step38_known)"
check "1f nor the status rule" "no" "$(val "$S0" status_rule_known)"
check "1g nor the re-snapshot batch" "no" "$(val "$S0" snapshot_batch_known)"

# ================================== the hazard, on the delivered alpha.91
say ""
say "=== 2 · on alpha.91 the transfer goes through — the measured hazard ==="
SHOP_BEFORE="$(state shop dokan)"; printf '%s\n' "$SHOP_BEFORE" >>"$RUN"
check "2a the shop says publish before anything" "publish" "$(val "$SHOP_BEFORE" post_status)"
T_OLD="$(state take dokan)"; printf '%s\n' "$T_OLD" >>"$RUN"
check "2b alpha.91 ALLOWS it" "yes" "$(val "$T_OLD" ok)"
check "2c …and the row becomes the marketplace's" "marketplace" "$(val "$T_OLD" ownership)"
note  "2d this is the state in which the next projection writes draft+hidden" \
      "stamp=$(val "$(state state)" stamp_dokan)"
G_OLD="$(state give-back dokan)"; printf '%s\n' "$G_OLD" >>"$RUN"
check "2e given back, so the measurement below starts where this one did" "observed" "$(val "$G_OLD" ownership)"

# ============================================ swapping the files runs nothing
say ""
say "=== 3 · the files are swapped, and NOTHING has run yet ==="
install_zip "$NEW_ZIP"
S1="$(state state)"; printf '%s\n' "$S1" >>"$RUN"
check "3a the structure has not moved" "37" "$(val "$S1" schema_version)"
check "3b the stamp is still absent" "__null__" "$(val "$S1" stamp_dokan)"
check "3c but the new build knows the step" "yes" "$(val "$S1" step38_known)"
check "3d …and the status rule" "yes" "$(val "$S1" status_rule_known)"
check "3e …and the re-snapshot batch" "yes" "$(val "$S1" snapshot_batch_known)"

# ============================== and it already refuses, before the backfill
say ""
say "=== 4 · alpha.92 refuses the same transfer, by name ==="
T_NEW="$(state take dokan)"; printf '%s\n' "$T_NEW" >>"$RUN"
check "4a refused" "no" "$(val "$T_NEW" ok)"
check "4b …with the reason" "storefront_status_would_change" "$(val "$T_NEW" code)"
check "4c …naming what the shop holds" "publish" "$(val "$T_NEW" now)"
check "4d …and what a projection would write" "draft" "$(val "$T_NEW" would_write)"
check "4e the row is untouched" "observed" "$(val "$T_NEW" ownership)"

# ======================================= an injected failure, then a resume
say ""
say "=== 5 · the step's own column is moved, so its UPDATE is refused ==="
S_BRK="$(state break-column)"; printf '%s\n' "$S_BRK" >>"$RUN"
check "5a the column the step names is gone" "no" "$(val "$S_BRK" stamp_column_present)"
admin_request >>"$RUN" 2>&1
S2="$(state state)"; printf '%s\n' "$S2" >>"$RUN"
check "5b the structure stays on 37" "37" "$(val "$S2" schema_version)"
LAST_ERROR="$(wpx option get tmc_migration_last_error 2>/dev/null | head -3 | tr '\n' ' ')"
check "5c …and the reason was recorded" "yes" \
  "$([[ -n "$LAST_ERROR" ]] && echo yes || echo no)"
note  "5d the recorded reason" "${LAST_ERROR:0:140}"
S_FIX="$(state fix-column)"; printf '%s\n' "$S_FIX" >>"$RUN"
check "5e the column is back" "yes" "$(val "$S_FIX" stamp_column_present)"
# ⛔ A FAILED STEP IS THROTTLED by `UpgradeGate::RETRY_COOLDOWN_SECONDS` (300),
# so the very next admin request declines by design. Deleting the recorded
# failure stands in for waiting those five minutes — the same state the wait
# produces, not a safety gate being stepped around (`alpha.76`).
note  "5f seconds this build would otherwise wait" "300"
wpx option delete tmc_migration_last_error >>"$RUN" 2>&1

say ""
say "=== 6 · the step runs on the next admin request ==="
admin_request >>"$RUN" 2>&1
S3="$(state state)"; printf '%s\n' "$S3" >>"$RUN"
check "6a the structure is 38" "38" "$(val "$S3" schema_version)"
# The three answers of the backfill's scope.
check "6b the imported row is filled from its live product" "publish" "$(val "$S3" stamp_dokan)"
check "6c the MARKETPLACE-owned row is left alone" "__null__" "$(val "$S3" stamp_ours)"
check "6d the row whose product is gone gets nothing" "__null__" "$(val "$S3" stamp_gone)"
# ⛔ And the chain did NOT lock on that last one: the structure moved to 38
# with it still unfilled, which is the whole point of the JOIN in `verify()`.
note  "6e the chain moved with an unfillable row still present" "yes"

# =================================== a real transfer, both ways, five fields
say ""
say "=== 7 · a REAL transfer, and the product's own five fields ==="
SHOP_0="$(state shop dokan)"; printf '%s\n' "$SHOP_0" >>"$RUN"
T1="$(state take dokan)"; printf '%s\n' "$T1" >>"$RUN"
check "7a now it is allowed" "yes" "$(val "$T1" ok)"
check "7b …as a taken ownership" "ownership_taken" "$(val "$T1" code)"
SHOP_1="$(state shop dokan)"; printf '%s\n' "$SHOP_1" >>"$RUN"
check "7c the publish status is untouched" "$(val "$SHOP_0" post_status)" "$(val "$SHOP_1" post_status)"
check "7d the visibility is untouched" "$(val "$SHOP_0" visibility)" "$(val "$SHOP_1" visibility)"
check "7e the price is untouched" "$(val "$SHOP_0" price)" "$(val "$SHOP_1" price)"
check "7f the SKU is untouched" "$(val "$SHOP_0" sku)" "$(val "$SHOP_1" sku)"
check "7g the stock is untouched" "$(val "$SHOP_0" stock)" "$(val "$SHOP_1" stock)"
G1="$(state give-back dokan)"; printf '%s\n' "$G1" >>"$RUN"
check "7h and it goes back" "observed" "$(val "$G1" ownership)"
SHOP_2="$(state shop dokan)"; printf '%s\n' "$SHOP_2" >>"$RUN"
check "7i still publish after both directions" "publish" "$(val "$SHOP_2" post_status)"

# ============================================================ the rollback
say ""
say "=== 8 · back to alpha.91, with the cost printed ==="
install_zip "$OLD_ZIP"
S4="$(state state)"; printf '%s\n' "$S4" >>"$RUN"
# Installing an older package does not lower the structure, and that IS the
# promise of a rollback that needs no database work (`alpha.89`).
check "8a the structure stays on 38" "38" "$(val "$S4" schema_version)"
check "8b the stamp the step wrote is still there" "publish" "$(val "$S4" stamp_dokan)"
check "8c and the old build does not know the step" "no" "$(val "$S4" step38_known)"
# ⛔ THE COST, measured: the old build has no status guard, so a row whose
# stamp is ABSENT can be transferred again — the hazard returns for any row
# the backfill could not reach, and for anything a future Dokan import under
# the old package creates. The row the step filled is safer than before,
# because `alpha.91`'s projector reads the stamp exactly as this one does.
T_BACK="$(state take gone)"; printf '%s\n' "$T_BACK" >>"$RUN"
check "8d an unstamped row is transferable again on alpha.91" "yes" "$(val "$T_BACK" ok)"
note  "8e so the cost of rolling back is the guard, not the data" \
      "stamps kept, status guard gone"
state give-back gone >>"$RUN" 2>&1

say ""
say "=== result ==="
say "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
