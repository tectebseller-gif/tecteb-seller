#!/usr/bin/env bash
# `alpha.67` → `alpha.68` on the disposable WordPress: structure 33 → 34, an
# injected failure, a resume, a rollback — and the §4 RECOVERY DRILL, which
# is the half the owner asked for.
#
# WHAT THIS MEASURES
#
#   1. The schema numbers come from the two ZIPs themselves, not from this
#      file: a hand-typed number locks the script to one round and three of
#      them did exactly that (`alpha.34`).
#   2. The database is put GENUINELY on 33 first (`unmigrate-34`), so step 34
#      is measured doing its work rather than reporting «already done».
#   3. Swapping the files runs NOTHING. `UpgradeGate` runs the step on the
#      next admin request (`admin_init` priority 5), and that is what is
#      triggered here.
#   4. An INJECTED FAILURE on step 34 — a column already widened to a shape
#      `verify()` refuses — leaves the schema where it was, records the
#      reason, and the next request finishes the job (`alpha.46`).
#   5. ⛔ **THE RECOVERY DRILL.** A dump is taken BEFORE the upgrade. Then a
#      line is recorded at the finer unit, the rollback gate says `blocked`,
#      `lossSince()` counts what a restore would drop, the dump is restored,
#      and the gate says `safe` with every count at nought. That is the
#      «مسیر بازیابی سازگار و آزموده» of `docs/upgrade-and-rollback.md` §48.3,
#      executed rather than described.
#   6. And the ROLLBACK of the files to `alpha.67` is measured too: the
#      nullable column accepts everything the older build writes.
#
# ⛔ The dump covers the WHOLE disposable database, because a selective
# restore of the `tmc_` tables alone makes a site whose WooCommerce knows
# about an order the marketplace has never heard of — which §48.3 says not to
# do. This is a DISPOSABLE database; the guard in the state tool refuses
# anything else.
#
# Usage: PHPBIN=php WPCLI=/usr/local/bin/wp \
#          bash tools/upgrade-alpha67-check.sh docs/evidence/upgrade-alpha68
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/upgrade-alpha68}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
DB_NAME="${TMC_DB_NAME:-tmc_wp_demo}"
DB_USER="${TMC_DB_USER:-tmc_wp}"
DB_PASS="${TMC_DB_PASS:-tmc_wp_disposable}"
DB_HOST="${TMC_DB_HOST:-127.0.0.1}"
NEW_ZIP="${TMC_NEW_ZIP:-}"
OLD_ZIP="${TMC_OLD_ZIP:-}"
STATE_SRC="$REPO/tools/upgrade-alpha67-state.php"
STATE="$WP_PATH/upgrade-alpha67-state.php"
WORK="$(mktemp -d /tmp/tmc-upg68-XXXXXX)"

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
}
trap cleanup EXIT

# ------------------------------------------------------- the two packages
if [[ -z "$NEW_ZIP" ]]; then
  NEW_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.68.zip 2>/dev/null | head -1)"
fi
if [[ -z "$OLD_ZIP" ]]; then
  OLD_ZIP="$(ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.67.zip 2>/dev/null | head -1)"
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
check "0b and the old one targets the previous structure" "33" "$OLD_TARGET"

install_zip() {
  wpx plugin install "$1" --force >>"$RUN" 2>&1
  wpx plugin activate tecteb-marketplace-core >>"$RUN" 2>&1
}

# =================================================== a site genuinely on 33
say ""
say "=== 1 · the site is put on structure 33, for real ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
S_ARR="$(state arrange)"; printf '%s\n' "$S_ARR" >>"$RUN"
check "1a the fixture vendor's registry unit is exponent 0" "0" "$(val "$S_ARR" registry_exponent)"
S_UN="$(state unmigrate-34)"; printf '%s\n' "$S_UN" >>"$RUN"
check "1b the column is narrowed back to alpha.67's shape" "NO" "$(val "$S_UN" nullable_after)"
check "1c and the recorded structure says 33" "33" "$(val "$S_UN" schema_version)"
S_COL="$(state column)"; printf '%s\n' "$S_COL" >>"$RUN"
check "1d the per-unit price cannot say «unknown»" "NO" "$(val "$S_COL" nullable)"

# -------------------------------------------------- THE BACKUP, before anything
say ""
say "=== 2 · the pre-upgrade dump — the only compatible recovery path ==="
BACKUP="$WORK/pre-upgrade.sql"
BACKUP_AT="$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -N -B -e "SELECT NOW()" 2>/dev/null | head -1)"
mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --single-transaction --routines \
  "$DB_NAME" >"$BACKUP" 2>>"$RUN"
check "2a the dump was taken" "yes" "$([[ -s "$BACKUP" ]] && echo yes || echo no)"
note  "2b its size in bytes" "$(stat -c %s "$BACKUP" 2>/dev/null || echo unknown)"
note  "2c the moment it was taken" "$BACKUP_AT"

# =================================================== the injected failure
say ""
say "=== 3 · the files are swapped, and a failing step stays failed ==="
install_zip "$NEW_ZIP"
cp "$STATE_SRC" "$STATE"
S_BEFORE="$(state column)"; printf '%s\n' "$S_BEFORE" >>"$RUN"
check "3a swapping the files ran nothing" "33" "$(val "$S_BEFORE" schema_version)"
check "3b …and the column is still the old shape" "NO" "$(val "$S_BEFORE" nullable)"
check "3b2 …with the default alpha.67 gave it" "0" "$(val "$S_BEFORE" col_default)"
# THE INJECTION: the column is RENAMED, so the step cannot see it at all.
# `getRow()` answers `null` — «no such column» and «the read failed» are the
# same answer there (`alpha.38`) — and the step throws rather than reporting
# «nothing to do». A `DEFAULT`-only injection would not do: `up()` would
# simply repair it, which is correct behaviour and measures nothing.
printf 'ALTER TABLE wp_tmc_order_items CHANGE `unit_price_minor` `unit_price_minor_x` BIGINT NOT NULL DEFAULT 0;\n' | mysqlx
admin_request >>"$RUN" 2>&1
S_FAILED="$(state column)"; printf '%s\n' "$S_FAILED" >>"$RUN"
check "3c the failed step did not move the structure" "33" "$(val "$S_FAILED" schema_version)"
check "3c2 …and the step could not see its column" "no" "$(val "$S_FAILED" column_present)"
LAST_ERROR="$(wpx option get tmc_migration_last_error 2>/dev/null | head -3 | tr '\n' ' ')"
check "3d …and the reason was recorded" "yes" \
  "$([[ -n "$LAST_ERROR" ]] && echo yes || echo no)"
note  "3e the recorded reason" "${LAST_ERROR:0:120}"

say ""
say "=== 4 · the injection is removed and the next request finishes the job ==="
printf 'ALTER TABLE wp_tmc_order_items CHANGE `unit_price_minor_x` `unit_price_minor` BIGINT NOT NULL DEFAULT 0;\n' | mysqlx
wpx option delete tmc_migration_last_error >>"$RUN" 2>&1
admin_request >>"$RUN" 2>&1
S_DONE="$(state column)"; printf '%s\n' "$S_DONE" >>"$RUN"
check "4a the structure moved to 34" "34" "$(val "$S_DONE" schema_version)"
check "4b the column can say «unknown» now" "YES" "$(val "$S_DONE" nullable)"
check "4c …with no default standing in for it" "__no_default__" "$(val "$S_DONE" col_default)"
check "4d …and it is still a BIGINT" "bigint(20)" "$(val "$S_DONE" col_type)"

# =================================================== the recovery drill
say ""
say "=== 5 · a line at the finer unit, and the gate that counts it ==="
S_GATE0="$(state gate)"; printf '%s\n' "$S_GATE0" >>"$RUN"
check "5a the gate exists on this package" "yes" "$(val "$S_GATE0" has_gate)"
check "5b …and it could read every table" "yes" "$(val "$S_GATE0" readable)"
note  "5c what it counted before this round's line" "$(val "$S_GATE0" finer_rows_total)"
S_LINE="$(state line-finer)"; printf '%s\n' "$S_LINE" >>"$RUN"
check "5d the line landed" "1" "$(val "$S_LINE" written)"
check "5e …at exponent 4" "4" "$(val "$S_LINE" row_exponent)"
check "5f …and its per-unit price says «no whole one»" "__null__" "$(val "$S_LINE" unit_price_minor)"
S_GATE1="$(state gate)"; printf '%s\n' "$S_GATE1" >>"$RUN"
check "5g ⛔ the gate now BLOCKS a plain rollback" "blocked" "$(val "$S_GATE1" verdict)"
check "5h …which is not «safe»" "no" "$(val "$S_GATE1" safe)"
check "5i …and the order-item count went up by one" \
  "$(( $(val "$S_GATE0" count_order_items) + 1 ))" "$(val "$S_GATE1" count_order_items)"

say ""
say "=== 6 · what restoring the dump would cost, counted before it is done ==="
S_LOSS="$(state loss "$BACKUP_AT")"; printf '%s\n' "$S_LOSS" >>"$RUN"
check "6a the loss could be counted" "yes" "$(val "$S_LOSS" readable)"
check "6b …and the fixture line is in it" "1" "$(val "$S_LOSS" loss_order_items)"
note  "6c every money row written since the dump" "$(val "$S_LOSS" loss_total)"
S_LOSS_FUTURE="$(state loss '2099-01-01 00:00:00')"; printf '%s\n' "$S_LOSS_FUTURE" >>"$RUN"
check "6d a dump taken in the future would cost nothing" "0" "$(val "$S_LOSS_FUTURE" loss_total)"

say ""
say "=== 7 · the dump is restored, and the gate says safe again ==="
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" <"$BACKUP" 2>>"$RUN"
cp "$STATE_SRC" "$STATE"
S_AFTER="$(state column)"; printf '%s\n' "$S_AFTER" >>"$RUN"
check "7a the restore put the structure back on 33" "33" "$(val "$S_AFTER" schema_version)"
check "7b …and the column back to its old shape" "NO" "$(val "$S_AFTER" nullable)"
S_DIG="$(state digest)"; printf '%s\n' "$S_DIG" >>"$RUN"
check "7c …and the finer line is gone with it" "__absent__" "$(val "$S_DIG" line)"
S_GATE2="$(state gate)"; printf '%s\n' "$S_GATE2" >>"$RUN"
check "7d the gate reads the restored database" "yes" "$(val "$S_GATE2" readable)"
# ⛔ WHAT A RESTORE PROMISES IS «BACK TO THE DUMP», NOT «CLEAN».
#
# The first version of these two asserted `safe` and `0` outright, and the
# run answered `blocked` and `4` — CORRECTLY, about this database: two
# leftover `tmc_returns` rows of this round's own probe and six ledger rows
# of `alpha.40`'s fixture were genuinely finer than their vendor's recorded
# unit, and they predate the dump, so restoring it keeps them. Both fixtures
# now give those rows back (`alpha.68`), so the pre-dump verdict here is
# `safe` again — but the ASSERTION is the invariant that does not depend on
# a clean start: the restore returns the gate to exactly the verdict and the
# count it had when the dump was taken.
note  "7e the verdict the dump was taken at" "$(val "$S_GATE0" verdict)"
check "7f …and the restore gives back that same verdict" \
  "$(val "$S_GATE0" verdict)" "$(val "$S_GATE2" verdict)"
check "7g …and the same count of finer rows" \
  "$(val "$S_GATE0" finer_rows_total)" "$(val "$S_GATE2" finer_rows_total)"
check "7h …which on this database was nothing finer at all" "0" "$(val "$S_GATE2" finer_rows_total)"

say ""
say "=== 8 · and the files go back to alpha.67 on the restored database ==="
install_zip "$OLD_ZIP"
cp "$STATE_SRC" "$STATE"
S_OLD="$(state column)"; printf '%s\n' "$S_OLD" >>"$RUN"
check "8a the older package reads the restored structure" "33" "$(val "$S_OLD" schema_version)"
check "8b …and has no rollback gate of its own" "no" "$(val "$(state gate)" has_gate)"
S_OLDARR="$(state arrange)"; printf '%s\n' "$S_OLDARR" >>"$RUN"
check "8c …and can still write its own fixture" "yes" "$(val "$S_OLDARR" arranged)"

say ""
say "=== 9 · forward again, so the machine is left on this package ==="
install_zip "$NEW_ZIP"
cp "$STATE_SRC" "$STATE"
admin_request >>"$RUN" 2>&1
S_FWD="$(state column)"; printf '%s\n' "$S_FWD" >>"$RUN"
check "9a the structure is 34 again" "34" "$(val "$S_FWD" schema_version)"
check "9b …and the column is nullable again" "YES" "$(val "$S_FWD" nullable)"
S_END="$(state gate)"; printf '%s\n' "$S_END" >>"$RUN"
check "9c the gate is readable on the final state" "yes" "$(val "$S_END" readable)"
note  "9d the verdict the machine is left with" "$(val "$S_END" verdict)"

state reset >/dev/null 2>&1
say ""
say "checks=$((PASS + FAIL)) pass=$PASS fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
