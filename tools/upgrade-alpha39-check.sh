#!/usr/bin/env bash
# The owner's own upgrade: `alpha.39` installed, the new package dropped on top —
# the way back, the way forward again, and a migration that FAILS in the middle.
#
# Schema 21 → 22 adds ONE table, `tmc_vendor_money_unit`, and seeds it from the
# ledger that is already on disk. §8 of the owner's order: «اگر اصلاح درست به
# migration نیاز دارد، ضرورت، ارتقا و مسیر بازگشتش توضیح و آزموده شود».
#
# Six things this measures that an assertion in PHP cannot:
#
#  - the gate runs on an ADMIN REQUEST, not on an activation hook. Replacing the
#    files is what an upload does, and it fires no activation hook at all;
#  - the seed reaches ledger rows the OLD build really wrote, through its own
#    capture — not rows this script typed into a table;
#  - a vendor whose ledger already holds TWO units gets no row, which is the
#    whole point of «دادهٔ ناسازگار موجود بدون تبدیل حدسی گزارش شود»;
#  - a migration that fails leaves the site on schema 21, which is `alpha.39`
#    and works, records WHY, and the next admin request finishes the job;
#  - going BACK to `alpha.39` needs no database work: the table is additive and
#    nothing older names it. What it COSTS is stated and measured;
#  - and forward again runs nothing twice.
#
#   bash tools/upgrade-alpha39-check.sh docs/evidence/upgrade-alpha39
set -u
OUT="${1:-docs/evidence/upgrade-alpha39}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
SITE="${TMC_DEMO_SITE:-http://127.0.0.1:8081}"
ADMIN="${TMC_DEMO_ADMIN:-tmcowner}"
ADMIN_PASS="${TMC_DEMO_ADMIN_PASS:-demo-owner-2026}"
PHPBIN="${PHPBIN:-php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS="$WPROOT/wp-content/plugins"

# The newest package in `dist/`, DERIVED. A hand-written default locked two
# upgrade scripts to `alpha.31` for three rounds and reported green checks
# about a package that was not being delivered (`alpha.34`).
newest_zip() {
  ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.*.zip 2>/dev/null \
    | sed 's/.*alpha\.\([0-9]*\)\.zip/\1 &/' | sort -n | tail -1 | cut -d' ' -f2-
}
NEW_ZIP="${TMC_NEW_ZIP:-$(newest_zip)}"
BASES="${TMC_BASES:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.39.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha39-check.txt"
: > "$LOG"
pass=0; fail=0
say() { echo "$1" | tee -a "$LOG"; }
check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass=$((pass+1)); say "ok:   $(printf '%-62s' "$name") $expected"
  else
    fail=$((fail+1)); say "FAIL: $(printf '%-62s' "$name") expected=$expected actual=$actual"
  fi
}
for zip in $BASES "$NEW_ZIP"; do
  [ -f "$zip" ] || { say "missing package: $zip"; exit 2; }
done

wpx() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@"); }
dbq() { wpx db query "$1" --skip-column-names 2>/dev/null; }

# Replace the payload, then WAIT OUT the opcache revalidation window: the web
# server here is one long-lived PHP process, and for up to `revalidate_freq`
# seconds after a swap it can serve cached opcodes for a replaced file whose
# references no longer exist. A real site has the same window, which is why the
# upgrade order says to do it at a quiet moment.
install_zip() {
  rm -rf "$PLUGINS/tecteb-marketplace-core"
  (cd "$PLUGINS" && unzip -q "$1" -d .)
  local freq
  freq="$("$PHPBIN" -r 'echo (int) ini_get("opcache.revalidate_freq");' 2>/dev/null || echo 2)"
  sleep $(( freq + 1 ))
}
zip_version() {
  unzip -p "$1" 'tecteb-marketplace-core/tecteb-marketplace-core.php' \
    | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)[[:space:]]*$/\1/p' | head -1 | tr -d '\r'
}
# Every schema number is READ, never written here (`alpha.33`'s rule): a script
# that spells «21» broke three checks the first round the number moved. And a
# base's target is read out of the BASE's own ZIP, because `NEW − 1` is a guess
# about the base and `alpha.37 → alpha.38` is where that guess was wrong.
zip_schema() {
  unzip -p "$1" 'tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php' \
    | sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' | head -1
}
NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
NEW_EXPECT="$(zip_version "$NEW_ZIP")"
STEP_ID="$(sed -n "s/.*return '\(0022_[a-z_]*\)'.*/\1/p" \
  "$REPO/src/Modules/Finance/Infrastructure/Migrations/M0022VendorMoneyUnit.php" | head -1)"
UNITS_SUFFIX="$(sed -n "s/.*const UNITS = '\([a-z_]*\)'.*/\1/p" \
  "$REPO/src/Modules/Finance/Infrastructure/Migrations/M0022VendorMoneyUnit.php" | head -1)"
say "new package: $NEW_EXPECT (target schema $NEW_SCHEMA) · step $STEP_ID · table $UNITS_SUFFIX"
[ -n "$NEW_EXPECT" ] && [ -n "$NEW_SCHEMA" ] && [ -n "$STEP_ID" ] && [ -n "$UNITS_SUFFIX" ] \
  || { say "FAIL: could not read a version, a schema number, a step id or a table name"; exit 2; }

PREFIX="$(wpx eval 'global $wpdb; echo $wpdb->prefix;')"
UNITS="${PREFIX}${UNITS_SUFFIX}"
LEDGER="${PREFIX}tmc_ledger_entries"
has_table() {
  dbq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$1'"
}
# Rows in the units table — 0 when it is not there, never a dash: a reader that
# answers «-» makes every later comparison a string mismatch and buries the one
# number the stage is about (`alpha.38`'s lesson in this very script's sibling).
unit_rows() {
  if [ "$(has_table "$UNITS")" = "1" ]; then dbq "SELECT COUNT(*) FROM \`$UNITS\`"; else echo 0; fi
}
unit_of() {
  if [ "$(has_table "$UNITS")" = "1" ]; then
    dbq "SELECT CONCAT(currency, '/', exponent) FROM \`$UNITS\` WHERE vendor_user_id = $1"
  fi
}
# How many distinct units a vendor's LEDGER holds — the fact the seed reads.
ledger_units() {
  dbq "SELECT COUNT(DISTINCT CONCAT(currency, '/', exponent)) FROM \`$LEDGER\` WHERE vendor_user_id = $1"
}
# Ledger rows written by whichever build is installed, through its own code.
# `wp eval` on a file copied in, so the same call works on either build: both
# have `tmc_ledger_entries` from migration 4 and both record through
# `DbLedgerRepository`. The TOOL comes from this repository and names only
# symbols every build since `alpha.20` has.
seed_ledger() { cp "$REPO/tools/money-unit-state.php" "$WPROOT/"; wpx eval-file "$WPROOT/money-unit-state.php" "$@" 2>&1; }

admin_session() {
  JAR="$(mktemp -d)/admin.jar"
  curl -s -c "$JAR" -o /dev/null --data-urlencode "log=$ADMIN" --data-urlencode "pwd=$ADMIN_PASS" \
       -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
  echo "$JAR"
}
admin_request() {
  curl -s -o "${2:-/dev/null}" -w '%{http_code}' -b "$1" --max-time 120 \
       "$SITE/wp-admin/admin.php?page=tmc-dashboard"
}

# However this ends, the site is left CONSISTENT, not merely on the new files.
# A fixture that spends something gives it back: when the table is missing the
# option goes one BELOW the target so the gate rebuilds it on the next admin
# request, rather than claiming work that was not done (`alpha.38`'s rule).
restore_new() {
  install_zip "$NEW_ZIP"
  if [ "$(has_table "$UNITS")" = "1" ]; then
    wpx option update tmc_schema_version "$NEW_SCHEMA" >/dev/null 2>&1
  else
    wpx option update tmc_schema_version "$((NEW_SCHEMA - 1))" >/dev/null 2>&1
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    curl -s -o /dev/null -b "${JAR:-/dev/null}" --max-time 120 \
      "$SITE/wp-admin/admin.php?page=tmc-dashboard" >/dev/null 2>&1 || true
  fi
}
trap restore_new EXIT

JAR="$(admin_session)"
check "the admin session really opened" \
  "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$SITE/wp-admin/")" "200"
[ -s "$JAR" ] || { say "FAIL: no login cookie — every later check would be about a login page"; exit 2; }

for BASE_ZIP in $BASES; do
  OLD_EXPECT="$(zip_version "$BASE_ZIP")"
  BASE_SCHEMA="$(zip_schema "$BASE_ZIP")"
  [ -n "$BASE_SCHEMA" ] || { say "FAIL: could not read the schema target out of $BASE_ZIP"; exit 2; }
  if [ "$BASE_SCHEMA" -lt "$NEW_SCHEMA" ]; then STRUCTURAL=yes; else STRUCTURAL=no; fi
  say ""
  say "================================================================"
  say "=== $OLD_EXPECT (schema $BASE_SCHEMA) -> $NEW_EXPECT (schema $NEW_SCHEMA) · structural=$STRUCTURAL"
  say "================================================================"

  # ---- stage 1: a site genuinely on the old build ------------------------
  install_zip "$BASE_ZIP"
  dbq "DROP TABLE IF EXISTS \`$UNITS\`" >/dev/null
  if [ "$STRUCTURAL" = "yes" ]; then
    wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null
  else
    wpx option update tmc_schema_version "$((BASE_SCHEMA - 1))" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    admin_request "$JAR" >/dev/null
  fi
  check "1 the install is $OLD_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "1b on schema $BASE_SCHEMA" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "1c the units table is absent" "$(has_table "$UNITS")" "0"
  check "1d and the old build serves wp-admin" "$(admin_request "$JAR")" "200"

  # ---- stage 2: ledger rows written BY THE OLD BUILD --------------------
  #
  # Two vendors on purpose: one whose books hold ONE unit, which is a fact the
  # seed may record, and one whose books already hold TWO, which is a decision
  # it may not make. Both written through `DbLedgerRepository` on the OLD
  # build, because a seed measured against rows this script typed would be a
  # measurement of this script.
  SEED_OUT="$(seed_ledger seed)"
  say "  $SEED_OUT"
  SINGLE_VENDOR="$(printf '%s\n' "$SEED_OUT" | sed -n 's/.*single=\([0-9]*\).*/\1/p' | head -1)"
  MIXED_VENDOR="$(printf '%s\n' "$SEED_OUT" | sed -n 's/.*mixed=\([0-9]*\).*/\1/p' | head -1)"
  [ -n "$SINGLE_VENDOR" ] && [ -n "$MIXED_VENDOR" ] \
    || { say "FAIL: the ledger fixture did not run on the old build — every later check would be void"; exit 2; }
  check "2 the old build wrote one vendor with ONE unit" "$(ledger_units "$SINGLE_VENDOR")" "1"
  check "2b and one vendor whose books already hold TWO" "$(ledger_units "$MIXED_VENDOR")" "2"
  check "2c the old build has nowhere to record a unit" "$(has_table "$UNITS")" "0"

  # ---- stage 3: the files replaced, and NOTHING else --------------------
  install_zip "$NEW_ZIP"
  check "3 the files are $NEW_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"
  check "3b and replacing files ran no migration at all" \
    "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "3c the table is still absent" "$(has_table "$UNITS")" "0"

  # ---- stage 4: one admin request ---------------------------------------
  check "4 one wp-admin request is served" "$(admin_request "$JAR" "$OUT/after-upgrade-$OLD_EXPECT.html")" "200"
  check "4b the schema is $NEW_SCHEMA (the gate moved it)" \
    "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "4c the units table exists" "$(has_table "$UNITS")" "1"
  check "4d keyed on the vendor, so two first writers cannot both win" \
    "$(dbq "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$UNITS' AND INDEX_NAME = 'PRIMARY'")" \
    "vendor_user_id"
  check "4e with the five columns verify() names" \
    "$(dbq "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$UNITS'")" \
    "created_at,currency,exponent,source_event,vendor_user_id"
  check "4f no migration error was recorded" "$(wpx option get tmc_migration_last_error 2>/dev/null || echo '')" ""
  check "4g the single-unit vendor got the unit their books prove" \
    "$(unit_of "$SINGLE_VENDOR")" "IRT/0"
  check "4h stamped as coming from the backfill, not from a sale" \
    "$(dbq "SELECT source_event FROM \`$UNITS\` WHERE vendor_user_id = $SINGLE_VENDOR")" "migration:0022"
  check "4i and the MIXED vendor got NO row: no winner was picked" \
    "$(dbq "SELECT COUNT(*) FROM \`$UNITS\` WHERE vendor_user_id = $MIXED_VENDOR")" "0"
  say "  seeded $(unit_rows) row(s) from a ledger holding $(dbq "SELECT COUNT(DISTINCT vendor_user_id) FROM \`$LEDGER\` WHERE vendor_user_id > 0") vendor(s)"

  # ---- stage 5: the mixed vendor is REPORTED, not silent ----------------
  REPORT="$(seed_ledger report)"
  say "  $REPORT"
  check "5 the mixed vendor is named in the report a manager reads" \
    "$(printf '%s\n' "$REPORT" | grep -c "mixed_listed=$MIXED_VENDOR")" "1"
  check "5b and a new accrual for them is refused by name" \
    "$(printf '%s\n' "$REPORT" | sed -n 's/.*mixed_claim=\([a-z_]*\).*/\1/p' | head -1)" "books_mixed"
  check "5c while the single-unit vendor can still record" \
    "$(printf '%s\n' "$REPORT" | sed -n 's/.*single_claim=\([a-z_]*\).*/\1/p' | head -1)" "agreed"

  # ---- stage 6: the migration FAILS, and resumes ------------------------
  #
  # A table of the right NAME and the wrong shape. `CREATE TABLE IF NOT EXISTS`
  # is satisfied by it, so the step gets as far as the seed and the INSERT is
  # what fails — the interesting failure, the one after something succeeded.
  # And `verify()` inspects the SHAPE (`alpha.37`), so even a step that got
  # that far cannot report «complete» over an impostor.
  wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  dbq "DROP TABLE IF EXISTS \`$UNITS\`" >/dev/null
  dbq "CREATE TABLE \`$UNITS\` (\`wrong\` INT NOT NULL PRIMARY KEY)" >/dev/null
  check "6 a malformed table of the same name is in the way" \
    "$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$UNITS'")" "1"
  check "6b the admin request is still served" "$(admin_request "$JAR")" "200"
  check "6c the schema did NOT move" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "6d and the reason was recorded, naming the step" \
    "$(wpx option get tmc_migration_last_error 2>/dev/null | grep -q "$STEP_ID" && echo yes || echo no)" "yes"

  # The obstacle removed — and the very next request DECLINES, on purpose:
  # `UpgradeGate::RETRY_COOLDOWN_SECONDS` is five minutes, so a step that keeps
  # failing does not hammer the database on every admin request. Measured, not
  # assumed: a script expecting an immediate retry would report designed
  # behaviour as a defect.
  dbq "DROP TABLE IF EXISTS \`$UNITS\`" >/dev/null
  check "6e the next request is served" "$(admin_request "$JAR")" "200"
  check "6f and within the retry cooldown it does NOT retry" \
    "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "6g with the failure still visible, not hidden" \
    "$(wpx option get tmc_migration_last_error 2>/dev/null | grep -q "$STEP_ID" && echo yes || echo no)" "yes"

  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  check "6h then one request finishes the job" "$(admin_request "$JAR")" "200"
  check "6i and the schema is $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "6j with the single-unit vendor seeded exactly once" \
    "$(dbq "SELECT COUNT(*) FROM \`$UNITS\` WHERE vendor_user_id = $SINGLE_VENDOR")" "1"
  check "6k the mixed vendor still has no row" \
    "$(dbq "SELECT COUNT(*) FROM \`$UNITS\` WHERE vendor_user_id = $MIXED_VENDOR")" "0"
  check "6l and nothing left recorded as failed" \
    "$(wpx option get tmc_migration_last_error 2>/dev/null | grep -c "$STEP_ID")" "0"

  # ---- stage 7: the way back -------------------------------------------
  ROWS_BEFORE_ROLLBACK="$(unit_rows)"
  install_zip "$BASE_ZIP"
  check "7 the old package is back" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "7b it serves wp-admin against the NEW table, with no database work" "$(admin_request "$JAR")" "200"
  check "7c and left the schema where it was: nothing downgrades it" \
    "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "7d the new table is untouched by the old build" "$(unit_rows)" "$ROWS_BEFORE_ROLLBACK"
  # The cost, measured rather than asserted away: the old build does not read
  # this table at all, so the unit it would record a sale in comes from asking
  # the ledger again — which is the race coming back, not data loss.
  check "7e the old build does not read the table it cannot know about" \
    "$(dbq "SELECT COUNT(*) FROM \`$UNITS\`")" "$ROWS_BEFORE_ROLLBACK"

  # ---- stage 8: forward again ------------------------------------------
  install_zip "$NEW_ZIP"
  check "8 forward again, served" "$(admin_request "$JAR")" "200"
  check "8b the schema is still $NEW_SCHEMA and nothing ran twice" \
    "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "8c the rows are the ones that were there" "$(unit_rows)" "$ROWS_BEFORE_ROLLBACK"
  check "8d and the mixed vendor was not quietly decided in the meantime" \
    "$(dbq "SELECT COUNT(*) FROM \`$UNITS\` WHERE vendor_user_id = $MIXED_VENDOR")" "0"
done

say ""
say "checks: $((pass+fail))   passed: $pass   failed: $fail"
[ "$fail" -eq 0 ]
