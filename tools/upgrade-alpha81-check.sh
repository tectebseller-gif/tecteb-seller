#!/usr/bin/env bash
# `alpha.81` → `alpha.82` on the disposable WordPress: structure 34 → 35, an
# honest backfill, an injected failure, a resume, and a rollback.
#
# WHAT THIS MEASURES
#
#   1. The schema numbers come from the two ZIPs themselves, not from this
#      file: a hand-typed number locks the script to one round and three of
#      them did exactly that (`alpha.34`).
#   2. The database is put GENUINELY on 34 first (`unmigrate-35`), so step 35
#      is measured doing its work rather than reporting «already done»
#      (`alpha.70`).
#   3. ⛔ **THE BACKFILL IS MEASURED ON ALL THREE SHAPES**, because that is
#      where «invents nothing» either holds or does not: a numeric key gets
#      one row, an EMPTY key gets none, and a LEGACY SLUG gets none. Counting
#      only the rows written would pass on a backfill that guessed.
#   4. Swapping the files runs NOTHING. `UpgradeGate` runs the step on the
#      next admin request (`admin_init` priority 5), and that is what is
#      triggered here.
#   5. An INJECTED FAILURE: a table of the same NAME and the wrong SHAPE.
#      `CREATE TABLE IF NOT EXISTS` is satisfied by it, so only `verify()` can
#      refuse — the `alpha.51` trap from the other side. The structure stays
#      on 34, the reason is recorded, and the next request finishes the job
#      (`alpha.46`).
#   6. And the ROLLBACK to `alpha.81` is measured rather than argued: the
#      link table is left in place, the older build reads the single key, and
#      NO WooCommerce relationship is touched either way.
#
# ⛔ No product is created, no term is created or deleted, and nothing is
# written outside the three fixture rows this script gives back at the end.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha81-check.sh docs/evidence/upgrade-alpha82
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha82}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
NEW_ZIP="${TMC_NEW_ZIP:-}"
OLD_ZIP="${TMC_OLD_ZIP:-}"
STATE_SRC="$REPO/tools/upgrade-alpha81-state.php"
STATE="$WP_PATH/upgrade-alpha81-state.php"
WORK="$(mktemp -d /tmp/tmc-upg82-XXXXXX)"

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
# THE ADMIN REQUEST, which is the only thing that runs a migration. WooCommerce
# needs its admin functions loaded before `admin_init` or the hook fatals
# under WP-CLI (`alpha.67`).
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
  NEW_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.82.zip 2>/dev/null | head -1)"
fi
if [[ -z "$OLD_ZIP" ]]; then
  OLD_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.81.zip 2>/dev/null | head -1)"
fi
say "=== the packages ==="
say "new_zip=${NEW_ZIP:-__absent__}"
say "old_zip=${OLD_ZIP:-__absent__}"
if [[ ! -f "$NEW_ZIP" || ! -f "$OLD_ZIP" ]]; then
  say "FAIL  0a both packages have to exist; build the new one first"
  exit 1
fi
# ⛔ THE NUMBERS COME FROM THE ZIPS. Reading them from this checkout would make
# every sentence below a sentence about this checkout (`alpha.34`).
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
check "0b and the old one targets the previous structure" "34" "$OLD_TARGET"

install_zip() {
  wpx plugin install "$1" --force >>"$RUN" 2>&1
  wpx plugin activate tecteb-marketplace-core >>"$RUN" 2>&1
}

# =================================================== a site genuinely on 34
say ""
say "=== 1 · the site is put on structure 34, for real ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
S_ARR="$(state arrange)"; printf '%s\n' "$S_ARR" >>"$RUN"
check "1a the three fixture rows exist" "yes" "$(val "$S_ARR" arranged)"
S_ROWS="$(state rows)"; printf '%s\n' "$S_ROWS" >>"$RUN"
check "1b one carries a numeric key" "31" "$(val "$S_ROWS" key_numeric)"
check "1c one carries no key at all" "__empty__" "$(val "$S_ROWS" key_empty)"
check "1d one carries a pre-alpha.25 slug" "medical-glue" "$(val "$S_ROWS" key_slug)"
S_UN="$(state unmigrate-35)"; printf '%s\n' "$S_UN" >>"$RUN"
check "1e the link table is not there" "no" "$(val "$S_UN" table_present)"
check "1f and the recorded structure says 34" "34" "$(val "$S_UN" schema_version)"
check "1g the older build has no step 35 at all" "absent" "$(val "$(state carried)" step_class)"

# =================================================== the injected failure
say ""
say "=== 2 · the files are swapped, and a failing step stays failed ==="
install_zip "$NEW_ZIP"
cp "$STATE_SRC" "$STATE"
S_BEFORE="$(state table)"; printf '%s\n' "$S_BEFORE" >>"$RUN"
check "2a swapping the files ran nothing" "34" "$(val "$S_BEFORE" schema_version)"
check "2b …and the table is still not there" "no" "$(val "$S_BEFORE" table_present)"
S_MIS="$(state misshape)"; printf '%s\n' "$S_MIS" >>"$RUN"
check "2c the injection left a one-column table of that name" "product_id" "$(val "$S_MIS" columns)"
admin_request >>"$RUN" 2>&1
S_FAILED="$(state table)"; printf '%s\n' "$S_FAILED" >>"$RUN"
check "2d ⛔ IF NOT EXISTS was satisfied, and verify() refused anyway" "34" \
  "$(val "$S_FAILED" schema_version)"
check "2e …the mis-shaped table is still the one on disk" "product_id" "$(val "$S_FAILED" columns)"
LAST_ERROR="$(wpx option get tmc_migration_last_error 2>/dev/null | head -3 | tr '\n' ' ')"
check "2f …and the reason was recorded" "yes" "$([[ -n "$LAST_ERROR" ]] && echo yes || echo no)"
note  "2g the recorded reason" "${LAST_ERROR:0:140}"
S_CARRIED_BAD="$(state carried)"; printf '%s\n' "$S_CARRIED_BAD" >>"$RUN"
note  "2h what carriedBy() says about a table of the wrong shape" "$(val "$S_CARRIED_BAD" carried)"

say ""
say "=== 3 · the injection is removed and the next request finishes the job ==="
state unmigrate-35 >>"$RUN" 2>&1
wpx option delete tmc_migration_last_error >>"$RUN" 2>&1
admin_request >>"$RUN" 2>&1
S_DONE="$(state table)"; printf '%s\n' "$S_DONE" >>"$RUN"
check "3a the structure moved to 35" "35" "$(val "$S_DONE" schema_version)"
check "3b the table is there" "yes" "$(val "$S_DONE" table_present)"
check "3c …with the three columns" "product_id,term_id,sort_order" "$(val "$S_DONE" columns)"
check "3d …and the PAIR is unique, which is what makes every write idempotent" \
  "product_id,term_id" "$(val "$S_DONE" primary_key)"
check "3e and the step answers «present», not «unknown»" "yes" "$(val "$(state carried)" carried)"

# ================================================== the backfill, all three
say ""
say "=== 4 · the backfill restates what a row already said, and nothing else ==="
S_SETS="$(state sets)"; printf '%s\n' "$S_SETS" >>"$RUN"
check "4a the numeric key became one recorded relationship" "31" "$(val "$S_SETS" set_numeric)"
check "4b ⛔ the empty key got NO row — «unknown» is not «none»" "__none__" \
  "$(val "$S_SETS" set_empty)"
check "4c ⛔ and the legacy slug got none either: a slug is not a term id" "__none__" \
  "$(val "$S_SETS" set_slug)"
S_ROWS2="$(state rows)"; printf '%s\n' "$S_ROWS2" >>"$RUN"
check "4d the single key itself was not touched" "31" "$(val "$S_ROWS2" key_numeric)"
check "4e …nor was the empty one filled in" "__empty__" "$(val "$S_ROWS2" key_empty)"
check "4f …nor the slug rewritten" "medical-glue" "$(val "$S_ROWS2" key_slug)"
note  "4g rows in the link table on this database" "$(val "$S_SETS" rows_total)"

say ""
say "=== 5 · running the step again changes nothing ==="
admin_request >>"$RUN" 2>&1
S_AGAIN="$(state sets)"; printf '%s\n' "$S_AGAIN" >>"$RUN"
check "5a the same one row for the numeric key" "31" "$(val "$S_AGAIN" set_numeric)"
check "5b and the same total" "$(val "$S_SETS" rows_total)" "$(val "$S_AGAIN" rows_total)"

# =================================================== the rollback
say ""
say "=== 6 · back to alpha.81, which needs no database work ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
S_OLD="$(state table)"; printf '%s\n' "$S_OLD" >>"$RUN"
check "6a the older package reads the structure as it is" "35" "$(val "$S_OLD" schema_version)"
check "6b ⛔ the link table is LEFT IN PLACE — no relationship is lost" "yes" \
  "$(val "$S_OLD" table_present)"
check "6c …and nothing older names it" "absent" "$(val "$(state carried)" step_class)"
S_OLDROWS="$(state rows)"; printf '%s\n' "$S_OLDROWS" >>"$RUN"
check "6d the single key is what the older build reads, and it is unchanged" "31" \
  "$(val "$S_OLDROWS" key_numeric)"
S_OLDSETS="$(state sets)"; printf '%s\n' "$S_OLDSETS" >>"$RUN"
check "6e …and the recorded rows survive the rollback untouched" \
  "$(val "$S_SETS" rows_total)" "$(val "$S_OLDSETS" rows_total)"

say ""
say "=== 7 · forward again, so the machine is left on this package ==="
install_zip "$NEW_ZIP"
cp "$STATE_SRC" "$STATE"
admin_request >>"$RUN" 2>&1
S_FWD="$(state table)"; printf '%s\n' "$S_FWD" >>"$RUN"
check "7a the structure is 35 again" "35" "$(val "$S_FWD" schema_version)"
check "7b …with the table intact" "yes" "$(val "$S_FWD" table_present)"
S_END="$(state sets)"; printf '%s\n' "$S_END" >>"$RUN"
check "7c …and the same rows, after a round trip in both directions" \
  "$(val "$S_SETS" rows_total)" "$(val "$S_END" rows_total)"
check "7d the numeric fixture still has exactly its own relationship" "31" \
  "$(val "$S_END" set_numeric)"

state reset >/dev/null 2>&1
say ""
say "checks=$((PASS + FAIL)) pass=$PASS fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
