#!/usr/bin/env bash
# THE PATH THE OWNER WILL ACTUALLY TAKE: `alpha.65`, data structure 31, to
# this round's package — three steps in one go, 31 → 32 → 33 → 34.
#
# WHY THIS SCRIPT AND NOT ANOTHER `upgrade-alphaNN-check.sh`. Every upgrade
# check in this repository so far measured ONE step from the round before it,
# because that is what each round delivered. The owner has now stated what
# staging runs — `alpha.65`, structure 31, PHP 8.1.34 — so the real path is
# three steps, and «the runner runs the chain» is a sentence about code. This
# puts the disposable database genuinely on 31 and then asks what happened.
#
# WHAT IS MEASURED
#
#   1. The two schema numbers come from the two ZIPS, not from this file: a
#      hand-typed number locks a script to one round and three of them did
#      exactly that (`alpha.34`).
#   2. The database is put GENUINELY on 31 — the columns of steps 32 and 33
#      dropped, step 34's column narrowed back — so each step is measured
#      doing its work rather than reporting «already done».
#   3. Swapping the files runs NOTHING. `UpgradeGate` runs the chain on the
#      next admin request (`admin_init` priority 5), and that is what is
#      triggered here.
#   4. An INJECTED FAILURE in the MIDDLE of the chain: step 33's own column
#      renamed out from under it. The chain must stop there, record the
#      reason, and leave the structure where the last good step left it —
#      and the next request must finish the job (`alpha.46`).
#   5. THE ROLLBACK QUESTION, and this is the part the corrected checklist
#      rests on: `alpha.65` does not ship `MoneyScaleRollbackGate` at all, so
#      the compatibility verdict cannot be read there. It is measured on both
#      halves to show exactly that.
#   6. And a product save still works afterwards, because a structure number
#      that moved is not the same claim as a site that works.
#
# ⛔ NOTHING IS DONE ON THE OWNER'S SITES. This is the disposable install,
# and both the state tool and the probe refuse any other database.
#
# ⛔ PHP 8.1.34 ITSELF IS `Not Run` HERE: there is no 8.1 interpreter in this
# environment. `composer compat` (PHPCompatibility, `testVersion 8.1-`) is
# STATIC ANALYSIS and is not called a run.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha65-direct-check.sh docs/evidence/upgrade-alpha70
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha70}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
DB_NAME="${TMC_DB_NAME:-tmc_wp_demo}"
DB_USER="${TMC_DB_USER:-tmc_wp}"
DB_PASS="${TMC_DB_PASS:-tmc_wp_disposable}"
DB_HOST="${TMC_DB_HOST:-127.0.0.1}"
NEW_ZIP="${TMC_NEW_ZIP:-}"
OLD_ZIP="${TMC_OLD_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.65.zip}"
STATE_SRC="$REPO/tools/upgrade-alpha65-state.php"
STATE="$WP_PATH/upgrade-alpha65-state.php"
WORK="$(mktemp -d /tmp/tmc-upg70-XXXXXX)"

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
mysqlx() { mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null; }
# THE ADMIN REQUEST — the only thing that runs a migration. WooCommerce needs
# its admin functions loaded before `admin_init` or the hook fatals under
# WP-CLI (`alpha.67`).
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
}
trap cleanup EXIT

# ------------------------------------------------------- the two packages
if [[ -z "$NEW_ZIP" ]]; then
  NEW_ZIP="$(ls -1t "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.*.zip 2>/dev/null | head -1)"
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
version_of() {
  rm -rf "$WORK/peekv"
  mkdir -p "$WORK/peekv"
  unzip -qo "$1" -d "$WORK/peekv"
  sed -n 's/^ \* Version: *\(.*\)$/\1/p' \
    "$WORK/peekv/tecteb-marketplace-core/tecteb-marketplace-core.php" | head -1
}
NEW_TARGET="$(schema_of "$NEW_ZIP")"
OLD_TARGET="$(schema_of "$OLD_ZIP")"
say "new_version=$(version_of "$NEW_ZIP")"
say "old_version=$(version_of "$OLD_ZIP")"
say "new_target=$NEW_TARGET"
say "old_target=$OLD_TARGET"
check "0a the old package is the one staging runs, at structure 31" "31" "$OLD_TARGET"
check "0b the new package targets 34" "34" "$NEW_TARGET"
check "0c so the path is three steps" "3" "$((NEW_TARGET - OLD_TARGET))"

install_zip() {
  wpx plugin install "$1" --force >>"$RUN" 2>&1
  wpx plugin activate tecteb-marketplace-core >>"$RUN" 2>&1
}

# ============================================ a site genuinely on alpha.65/31
say ""
say "=== 1 · staging's starting state, rebuilt ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
S_UN="$(state unmigrate-to-31)"; printf '%s\n' "$S_UN" >>"$RUN"
check "1a every column steps 32 and 33 added is gone" "0" \
  "$(val "$S_UN" columns_that_would_not_drop)"
note  "1b how many were dropped" "$(val "$S_UN" columns_dropped)"
check "1c step 34's column is narrowed back" "yes" "$(val "$S_UN" unit_price_narrowed)"
check "1d and the recorded structure says 31" "31" "$(val "$S_UN" schema_version)"
S_31="$(state shape)"; printf '%s\n' "$S_31" >>"$RUN"
check "1e the mirrored-status column is absent" "0/1" "$(val "$S_31" step32_columns_present)"
check "1f the eight unit columns are absent" "0/8" "$(val "$S_31" step33_columns_present)"
check "1g the per-unit price cannot say «unknown»" "NO" "$(val "$S_31" unit_price_nullable)"
# ⛔ THE SENTENCE THE CORRECTED CHECKLIST RESTS ON.
check "1h alpha.65 has no rollback gate at all" "no" "$(val "$S_31" gate_class_exists)"
check "1i no migration error is standing" "__none__" "$(val "$S_31" last_error)"

# ------------------------------------------------- the pre-upgrade dump
say ""
say "=== 2 · the backup the checklist's step 1 asks for ==="
BACKUP="$WORK/pre-upgrade.sql"
BACKUP_AT="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SELECT NOW()" 2>/dev/null | head -1)"
mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --single-transaction --routines \
  "$DB_NAME" >"$BACKUP" 2>>"$RUN"
check "2a the dump was taken" "yes" "$([[ -s "$BACKUP" ]] && echo yes || echo no)"
note  "2b its size in bytes" "$(stat -c %s "$BACKUP" 2>/dev/null || echo unknown)"
note  "2c the moment it was taken" "$BACKUP_AT"

# ===================================== the files are swapped, nothing runs
say ""
say "=== 3 · swapping the files runs nothing, and a failing step stops the chain ==="
install_zip "$NEW_ZIP"
cp "$STATE_SRC" "$STATE"
S_SWAP="$(state shape)"; printf '%s\n' "$S_SWAP" >>"$RUN"
check "3a the structure is still 31 after the swap" "31" "$(val "$S_SWAP" schema_version)"
check "3b …and the new package's gate is now on disk" "yes" "$(val "$S_SWAP" gate_class_exists)"
# THE INJECTION, in the MIDDLE of the chain: step 33's own column name is
# taken by a column of the wrong shape, so its `verify()` refuses. Step 32
# runs first and must stay done.
printf 'ALTER TABLE wp_tmc_order_items ADD COLUMN `tmc_currency` INT NOT NULL DEFAULT 0;\n' | mysqlx
admin_request >>"$RUN" 2>&1
S_MID="$(state shape)"; printf '%s\n' "$S_MID" >>"$RUN"
check "3c the chain stopped at the failing step" "32" "$(val "$S_MID" schema_version)"
check "3d …with step 32 done" "1/1" "$(val "$S_MID" step32_columns_present)"
check "3e …and the reason recorded" "yes" \
  "$([[ "$(val "$S_MID" last_error)" != "__none__" ]] && echo yes || echo no)"
note  "3f the recorded reason" "$(val "$S_MID" last_error)"

say ""
say "=== 4 · the injection is removed and the next request finishes the chain ==="
printf 'ALTER TABLE wp_tmc_order_items DROP COLUMN `tmc_currency`;\n' | mysqlx
wpx option delete tmc_migration_last_error >>"$RUN" 2>&1
admin_request >>"$RUN" 2>&1
S_DONE="$(state shape)"; printf '%s\n' "$S_DONE" >>"$RUN"
check "4a the structure reached 34" "34" "$(val "$S_DONE" schema_version)"
check "4b step 32's column is there" "1/1" "$(val "$S_DONE" step32_columns_present)"
check "4c all eight of step 33's are there" "8/8" "$(val "$S_DONE" step33_columns_present)"
check "4d step 34 made the per-unit price nullable" "YES" "$(val "$S_DONE" unit_price_nullable)"
check "4e …with no default standing in for «unknown»" "__no_default__" \
  "$(val "$S_DONE" unit_price_default)"
check "4f …and it is still a BIGINT" "bigint(20)" "$(val "$S_DONE" unit_price_type)"
check "4g no error is left standing" "__none__" "$(val "$S_DONE" last_error)"
check "4h nothing was left unreadable" "0" "$(val "$S_DONE" added_columns_unreadable)"

# ================================================= the site still works
say ""
say "=== 5 · a structure number is not a working site ==="
A_EXIT="$(wpx eval-file "$REPO/tests/Support/alpha69-probe.php" report 2>>"$RUN")"
printf '%s\n' "$A_EXIT" >>"$RUN"
check "5a the min max exit verdict answers on the upgraded site" "yes" \
  "$([[ -n "$(val "$A_EXIT" report_verdict)" ]] && echo yes || echo no)"
note  "5b what it says" "$(val "$A_EXIT" report_verdict)"
check "5c the ledger sum is readable" "yes" \
  "$([[ "$(val "$A_EXIT" available)" == "yes" ]] && echo yes || echo no)"
S_GATE="$(state shape)"; printf '%s\n' "$S_GATE" >>"$RUN"
check "5d and the rollback gate can be asked now" "yes" "$(val "$S_GATE" gate_class_exists)"

say ""
say "=== 6 · the rollback question, asked on the right package ==="
# ⛔ THE POINT: the verdict has to be read on the NEW package, BEFORE the
# files go back. On `alpha.65` the class is not there to ask.
G_SRC="$REPO/tools/upgrade-alpha67-state.php"
if [[ -f "$G_SRC" ]]; then
  cp "$G_SRC" "$WP_PATH/upgrade-alpha67-state.php"
  G_NOW="$(wpx eval-file "$WP_PATH/upgrade-alpha67-state.php" gate 2>>"$RUN")"
  printf '%s\n' "$G_NOW" >>"$RUN"
  check "6a the gate answers on the upgraded site" "yes" "$(val "$G_NOW" has_gate)"
  check "6b …and it could read every table it counts" "yes" "$(val "$G_NOW" readable)"
  note  "6c its verdict right after the upgrade" "$(val "$G_NOW" verdict)"
  note  "6d rows already in a finer unit" "$(val "$G_NOW" finer_rows_total)"
  rm -f "$WP_PATH/upgrade-alpha67-state.php"
else
  say "FAIL  6a the gate state tool is missing"
  FAIL=$((FAIL + 1))
fi

say ""
say "=== totals ==="
say "pass=$PASS"
say "fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
