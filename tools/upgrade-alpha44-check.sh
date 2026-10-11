#!/usr/bin/env bash
# The owner's own upgrade: `alpha.44` installed on staging, the new package
# dropped on top — the way forward, a migration that FAILS, the resume, and the
# way back.
#
# Schema 23 → 24 adds ONE column to `tmc_products`, `purchase_step INT NOT NULL
# DEFAULT 1`, and seeds nothing. Seven things this measures that an assertion
# in PHP cannot:
#
#  - the gate runs on an ADMIN REQUEST, not on an activation hook. Replacing
#    the files is what an upload does, and it fires no activation hook at all;
#  - a product the OLD build really wrote, through its own repository, comes
#    out of the migration reading step 1 — «the default IS the backfill»
#    measured rather than asserted;
#  - `verify()` checks the SHAPE: a column of the right NAME with the wrong
#    default is not «complete» (the `alpha.37` rule), and that is the failure
#    stage 5 injects — a real refusal, not a simulated one;
#  - a migration that fails leaves the site on schema 23, which is `alpha.44`
#    and works, records WHY, and the next admin request finishes the job;
#  - forward again runs nothing twice;
#  - going BACK to `alpha.44` needs no database work, and what it COSTS is
#    measured: the column keeps its value and stops being read;
#  - and a vendor's product survives every one of those transitions.
#
#   bash tools/upgrade-alpha44-check.sh docs/evidence/upgrade-alpha45
set -u
OUT="${1:-docs/evidence/upgrade-alpha45}"
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
BASES="${TMC_BASES:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.44.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha44-check.txt"
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
# Every schema number is READ, never written here (`alpha.33`), and a base's
# target comes out of the BASE's own ZIP because `NEW − 1` is a guess about the
# base (`alpha.38`).
zip_schema() {
  unzip -p "$1" 'tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php' \
    | sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' | head -1
}

MIG="$REPO/src/Modules/Product/Infrastructure/Migrations/M0024PurchaseStep.php"
NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
NEW_EXPECT="$(zip_version "$NEW_ZIP")"
STEP_ID="$(sed -n "s/.*return '\(0024_[a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
COL_STEP="$(sed -n "s/.*const STEP = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
TABLE_SUFFIX="$(sed -n "s/.*const PRODUCTS = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
say "new package: $NEW_EXPECT (target schema $NEW_SCHEMA) · step $STEP_ID"
say "column: $COL_STEP on $TABLE_SUFFIX"
[ -n "$NEW_EXPECT" ] && [ -n "$NEW_SCHEMA" ] && [ -n "$STEP_ID" ] \
  && [ -n "$COL_STEP" ] && [ -n "$TABLE_SUFFIX" ] \
  || { say "FAIL: could not read a version, a schema number, a step id, a column or a table name"; exit 2; }

PREFIX="$(wpx eval 'global $wpdb; echo $wpdb->prefix;')"
PRODUCTS="${PREFIX}${TABLE_SUFFIX}"
has_column() {
  dbq "SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS' AND COLUMN_NAME = '$COL_STEP'"
}
# The SHAPE, not the name: a column added NULLable or defaulting to zero would
# satisfy «a column of that name exists» and then hand every legacy row a nought.
column_shape() {
  dbq "SELECT CONCAT(IFNULL(COLUMN_DEFAULT, 'none'), '/', IS_NULLABLE)
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS' AND COLUMN_NAME = '$COL_STEP'"
}
drop_new_shape() {
  [ "$(has_column)" = "1" ] && dbq "ALTER TABLE \`$PRODUCTS\` DROP COLUMN \`$COL_STEP\`" >/dev/null
  return 0
}
state() { cp "$REPO/tools/purchase-step-state.php" "$WPROOT/"; wpx eval-file "$WPROOT/purchase-step-state.php" "$@" 2>&1; }
field_of() { printf '%s\n' "$1" | sed -n "s/^$2=\(.*\)$/\1/p" | head -1; }

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

# However this ends, the site is left CONSISTENT, not merely on the new files
# (`alpha.38`'s rule about a fixture that spends something).
restore_new() {
  install_zip "$NEW_ZIP"
  state reset >/dev/null 2>&1
  if [ "$(has_column)" = "1" ]; then
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
  install_zip "$NEW_ZIP"; state reset >/dev/null 2>&1
  install_zip "$BASE_ZIP"
  if [ "$STRUCTURAL" = "yes" ]; then
    drop_new_shape
    wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
  else
    wpx option update tmc_schema_version "$((BASE_SCHEMA - 1))" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    admin_request "$JAR" >/dev/null
  fi
  check "1 the install is $OLD_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "1b on schema $BASE_SCHEMA" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  if [ "$STRUCTURAL" = "yes" ]; then
    check "1c the step column is absent" "$(has_column)" "0"
  else
    check "1c the step column is already there" "$(has_column)" "1"
    check "1d and already the right shape" "$(column_shape)" "1/NO"
  fi
  check "1e and the old build serves wp-admin" "$(admin_request "$JAR")" "200"

  # ---- stage 2: a product written BY THE OLD BUILD ----------------------
  SEED="$(state seed)"; say "  $SEED"
  PRODUCT="$(field_of "$SEED" product)"
  [ -n "$PRODUCT" ] && [ "$PRODUCT" != "0" ] \
    || { say "FAIL: the product fixture did not run on the old build — every later check would be void"; exit 2; }
  check "2 the old build wrote a product through its own repository" \
    "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "1"
  if [ "$STRUCTURAL" = "yes" ]; then
    check "2b and had nowhere to put a step" "$(field_of "$SEED" step)" "no-field"
    check "2c nor any field to read" "$(field_of "$SEED" has_field)" "0"
  else
    WROTE_BEFORE="$(state write)"; say "  $WROTE_BEFORE"
    check "2b the old build wrote a step of its own" "$(field_of "$WROTE_BEFORE" step)" "2"
  fi

  # ---- stage 3: the upgrade, from the admin request ---------------------
  install_zip "$NEW_ZIP"
  check "3 the files are now $NEW_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"
  check "3b replacing files runs no migration by itself" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "3c the first admin request answers 200" "$(admin_request "$JAR")" "200"
  check "3d and the gate has moved the schema to $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "3e the step column exists" "$(has_column)" "1"
  check "3f with default 1 and NOT NULL, which is the shape verify() demands" "$(column_shape)" "1/NO"
  check "3g no migration error was recorded" "$(wpx option get tmc_migration_last_error 2>/dev/null || echo '')" ""

  # ---- stage 4: the default IS the backfill -----------------------------
  #
  # There is nothing to guess at here and that is the point: `1` means «no
  # stepping», which is what every pre-upgrade row already does. So the row the
  # old build wrote must read 1 — not 0, which `PurchaseQuantity::of()` would
  # coerce back to 1 and thereby hide a wrong column.
  REPORT="$(state report)"; say "  $REPORT"
  check "4 the pre-upgrade product's step is 1 in the column" \
    "$(dbq "SELECT \`$COL_STEP\` FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "1"
  check "4b which the plugin reads back as «no stepping»" "$(field_of "$REPORT" step)" "1"
  check "4c and the field is readable at all now" "$(field_of "$REPORT" has_field)" "1"
  check "4d no row anywhere was left at zero" \
    "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE \`$COL_STEP\` < 1")" "0"
  check "4e the two numbers it works with are untouched by the upgrade" \
    "$(field_of "$REPORT" min)/$(field_of "$REPORT" max)" "1/null"
  WROTE="$(state write)"; say "  $WROTE"
  check "4f a vendor writing the rule stores exactly what they wrote" \
    "$(field_of "$WROTE" step)" "2"
  check "4g with its minimum and maximum" \
    "$(field_of "$WROTE" min)/$(field_of "$WROTE" max)" "2/6"

  # ---- stage 5: a migration that fails ---------------------------------
  #
  # Not a simulated failure. A column of the RIGHT NAME and the WRONG SHAPE is
  # left on the table, so `up()` finds it and skips — and `verify()` then
  # refuses, because it checks the default and the nullability rather than the
  # name. That is the `alpha.37` rule, and this is the one shape that can
  # reach it.
  if [ "$STRUCTURAL" = "yes" ]; then
    install_zip "$BASE_ZIP"; drop_new_shape
    dbq "ALTER TABLE \`$PRODUCTS\` ADD COLUMN \`$COL_STEP\` INT NULL DEFAULT 0" >/dev/null
    wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    install_zip "$NEW_ZIP"
    check "5 a same-named column of the wrong shape is really in the way" "$(has_column)" "1"
    check "5b and its shape is not the one verify() demands" "$(column_shape)" "0/YES"
    check "5c the admin request still answers 200" "$(admin_request "$JAR")" "200"
    check "5d the site stays on schema $BASE_SCHEMA, which is $OLD_EXPECT and works" \
      "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
    LAST_ERROR="$(wpx option get tmc_migration_last_error --format=json 2>/dev/null || echo '')"
    say "  last_error=$LAST_ERROR"
    check "5e the reason is recorded, naming the step" \
      "$(printf '%s' "$LAST_ERROR" | grep -c "$STEP_ID")" "1"
    check "5f and the vendor's product is untouched" \
      "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "1"

    # ---- stage 6: the resume finishes the job ---------------------------
    dbq "ALTER TABLE \`$PRODUCTS\` DROP COLUMN \`$COL_STEP\`" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    check "6 the next admin request answers 200" "$(admin_request "$JAR")" "200"
    check "6b the schema reaches $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
    check "6c the column is the right shape this time" "$(column_shape)" "1/NO"
    check "6d and the error is cleared" "$(wpx option get tmc_migration_last_error 2>/dev/null || echo '')" ""

    # ---- stage 7: forward again runs nothing twice ----------------------
    BEFORE_COLS="$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS'")"
    check "7 a second admin request answers 200" "$(admin_request "$JAR")" "200"
    check "7b the column count did not move" \
      "$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS'")" "$BEFORE_COLS"
    check "7c and the schema is still $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  else
    say "  stages 5-7 skipped: structural=$STRUCTURAL, so there is no migration step to fail, resume or re-run"
  fi

  # ---- stage 8: back to the old build ----------------------------------
  #
  # No database work, and the COST measured rather than described: the column
  # keeps its value and stops being read, so on `alpha.44` the step looks like
  # it vanished. It did not — and because `alpha.44` reads none of the three
  # quantity rules anywhere, what actually comes back is the defect.
  state write >/dev/null 2>&1
  install_zip "$BASE_ZIP"
  check "8 the old build serves wp-admin with the new schema recorded" "$(admin_request "$JAR")" "200"
  check "8b it does not take the schema back down" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "8c the column is still there — nothing is dropped on the way back" "$(has_column)" "1"
  BACK="$(state report)"; say "  $BACK"
  if [ "$STRUCTURAL" = "yes" ]; then
    check "8d the old build cannot read the field at all" "$(field_of "$BACK" has_field)" "0"
  else
    check "8d the old build reads the field, because it has always had it" "$(field_of "$BACK" has_field)" "1"
  fi
  check "8e but the step is still ON DISK, not lost" \
    "$(dbq "SELECT \`$COL_STEP\` FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "2"
  check "8f and so are the two numbers it works with" \
    "$(dbq "SELECT CONCAT(min_purchase, '/', IFNULL(max_purchase, 'null')) FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "2/6"

  # ---- stage 9: forward once more, from the real package ---------------
  install_zip "$NEW_ZIP"
  check "9 forward again answers 200" "$(admin_request "$JAR")" "200"
  check "9b the schema is $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  FORWARD="$(state report)"; say "  $FORWARD"
  check "9c and the rule the vendor wrote is read again, unchanged" \
    "$(field_of "$FORWARD" min)/$(field_of "$FORWARD" max)/$(field_of "$FORWARD" step)" "2/6/2"
done

say ""
say "checks=$((pass+fail)) pass=$pass fail=$fail"
[ "$fail" -eq 0 ] || exit 1
