#!/usr/bin/env bash
# The owner's own upgrade: `alpha.40` installed, the new package dropped on top —
# the way forward, a migration that FAILS in the middle, the resume, and the way
# back.
#
# Schema 22 → 23 adds TWO nullable columns to `tmc_products` and one unique
# index, and seeds NOTHING. §4 of the owner's order: «اگر تغییر ساختار ضروری
# است، migration حداقلی، قابل تکرار و آزموده اضافه کن؛ نسخهٔ ساختار را حدس
# نزن». Six things this measures that an assertion in PHP cannot:
#
#  - the gate runs on an ADMIN REQUEST, not on an activation hook. Replacing
#    the files is what an upload does, and it fires no activation hook at all;
#  - a product the OLD build really wrote, through its own repository, comes
#    out of the migration with `description` NULL — «no backfill» measured
#    rather than asserted;
#  - `verify()` checks the SHAPE: a step that added the columns and not the
#    unique index is not «complete» (the `alpha.37` rule);
#  - a migration that fails leaves the site on schema 22, which is `alpha.40`
#    and works, records WHY, and the next admin request finishes the job;
#  - going BACK to `alpha.40` needs no database work, and what it COSTS is
#    stated and measured: the long text stays in the column and stops being
#    read;
#  - and forward again runs nothing twice.
#
#   bash tools/upgrade-alpha40-check.sh docs/evidence/upgrade-alpha40
set -u
OUT="${1:-docs/evidence/upgrade-alpha40}"
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
BASES="${TMC_BASES:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.40.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha40-check.txt"
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
# references no longer exist.
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
# Every schema number is READ, never written here (`alpha.33`'s rule), and a
# base's target comes out of the BASE's own ZIP because `NEW − 1` is a guess
# about the base (`alpha.38`).
zip_schema() {
  unzip -p "$1" 'tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php' \
    | sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' | head -1
}
MIG="$REPO/src/Modules/Product/Infrastructure/Migrations/M0023ProductDescriptionAndCreateToken.php"
NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
NEW_EXPECT="$(zip_version "$NEW_ZIP")"
STEP_ID="$(sed -n "s/.*return '\(0023_[a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
COL_DESC="$(sed -n "s/.*const DESCRIPTION = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
COL_TOKEN="$(sed -n "s/.*const CREATE_TOKEN = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
IDX="$(sed -n "s/.*const TOKEN_INDEX = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
TABLE_SUFFIX="$(sed -n "s/.*const PRODUCTS = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
say "new package: $NEW_EXPECT (target schema $NEW_SCHEMA) · step $STEP_ID"
say "columns: $COL_DESC, $COL_TOKEN · index $IDX on $TABLE_SUFFIX"
[ -n "$NEW_EXPECT" ] && [ -n "$NEW_SCHEMA" ] && [ -n "$STEP_ID" ] \
  && [ -n "$COL_DESC" ] && [ -n "$COL_TOKEN" ] && [ -n "$IDX" ] && [ -n "$TABLE_SUFFIX" ] \
  || { say "FAIL: could not read a version, a schema number, a step id, a column or an index name"; exit 2; }

PREFIX="$(wpx eval 'global $wpdb; echo $wpdb->prefix;')"
PRODUCTS="${PREFIX}${TABLE_SUFFIX}"
has_column() {
  dbq "SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS' AND COLUMN_NAME = '$1'"
}
# The index AND its uniqueness: a non-unique index of the same name guards
# nothing, and that is exactly the failure stage 5 injects.
# `SEQ_IN_INDEX = 1` because this index spans TWO columns, so `STATISTICS`
# holds a row per column and a plain `COUNT(*)` answers «2» about one index.
unique_index() {
  dbq "SELECT COUNT(*) FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS'
         AND INDEX_NAME = '$IDX' AND NON_UNIQUE = 0 AND SEQ_IN_INDEX = 1"
}
any_index() {
  dbq "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS' AND INDEX_NAME = '$IDX'"
}
drop_new_shape() {
  [ "$(has_column "$COL_DESC")" = "1" ] && dbq "ALTER TABLE \`$PRODUCTS\` DROP COLUMN \`$COL_DESC\`" >/dev/null
  [ "$(any_index)" != "0" ] && dbq "ALTER TABLE \`$PRODUCTS\` DROP INDEX \`$IDX\`" >/dev/null
  [ "$(has_column "$COL_TOKEN")" = "1" ] && dbq "ALTER TABLE \`$PRODUCTS\` DROP COLUMN \`$COL_TOKEN\`" >/dev/null
  return 0
}
state() { cp "$REPO/tools/description-state.php" "$WPROOT/"; wpx eval-file "$WPROOT/description-state.php" "$@" 2>&1; }
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

# However this ends, the site is left CONSISTENT, not merely on the new files.
# A fixture that spends something gives it back (`alpha.38`'s rule): when the
# new shape is not there the option goes one BELOW the target so the gate
# rebuilds it on the next admin request.
restore_new() {
  install_zip "$NEW_ZIP"
  state reset >/dev/null 2>&1
  if [ "$(unique_index)" = "1" ] && [ "$(has_column "$COL_DESC")" = "1" ]; then
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
  # Whether THIS base needs a migration at all. A base already on the target
  # schema — `alpha.41 → alpha.42`, which adds none — has the columns and the
  # index from the start, so the stages about building them have nothing to
  # prove and are skipped by name rather than asserted into a false pass.
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
    # The base's own schema IS the target, so the shape has to be THERE —
    # and built by the gate, not by this script: the option goes one below
    # and an admin request rebuilds it, which is also how a real site that
    # had rolled back would come back (`alpha.38`'s rule about a fixture
    # leaving the site in a state the gate can finish).
    wpx option update tmc_schema_version "$((BASE_SCHEMA - 1))" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    admin_request "$JAR" >/dev/null
  fi
  check "1 the install is $OLD_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "1b on schema $BASE_SCHEMA" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  if [ "$STRUCTURAL" = "yes" ]; then
    check "1c the long-description column is absent" "$(has_column "$COL_DESC")" "0"
    check "1d the create-token column is absent" "$(has_column "$COL_TOKEN")" "0"
    check "1e no index of that name exists" "$(any_index)" "0"
  else
    check "1c the long-description column is already there" "$(has_column "$COL_DESC")" "1"
    check "1d the create-token column is already there" "$(has_column "$COL_TOKEN")" "1"
    check "1e and its index is already unique" "$(unique_index)" "1"
  fi
  check "1f and the old build serves wp-admin" "$(admin_request "$JAR")" "200"

  # ---- stage 2: a product written BY THE OLD BUILD ----------------------
  SEED="$(state seed)"; say "  $SEED"
  PRODUCT="$(field_of "$SEED" product)"
  [ -n "$PRODUCT" ] && [ "$PRODUCT" != "0" ] \
    || { say "FAIL: the product fixture did not run on the old build — every later check would be void"; exit 2; }
  check "2 the old build wrote a product through its own repository" \
    "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "1"
  if [ "$STRUCTURAL" = "yes" ]; then
    check "2b and had nowhere to put a long description" "$(field_of "$SEED" long)" "no-field"
  else
    # The base HAS the field, so the interesting fixture is a product with a
    # long description already written by the OLD build — the thing the
    # upgrade must not disturb.
    WROTE_BEFORE="$(state write)"; say "  $WROTE_BEFORE"
    check "2b the old build wrote a long description of its own" \
      "$(field_of "$WROTE_BEFORE" long)" '<p>متن کاملِ فروشنده</p>'
  fi

  # ---- stage 3: the upgrade, from the admin request ---------------------
  install_zip "$NEW_ZIP"
  check "3 the files are now $NEW_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"
  check "3b replacing files runs no migration by itself" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "3c the first admin request answers 200" "$(admin_request "$JAR")" "200"
  check "3d and the gate has moved the schema to $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "3e the long-description column exists" "$(has_column "$COL_DESC")" "1"
  check "3f the create-token column exists" "$(has_column "$COL_TOKEN")" "1"
  check "3g and the index is UNIQUE, not merely present" "$(unique_index)" "1"
  check "3h no migration error was recorded" "$(wpx option get tmc_migration_last_error 2>/dev/null || echo '')" ""

  # ---- stage 4: no backfill ---------------------------------------------
  #
  # The one thing the owner's order is explicit about: «برای دادهٔ قدیمی، نبود
  # فیلد جدید باید از درخواست پاک‌سازی صریح متمایز باشد». NULL is the row
  # saying nobody has ever asked for anything.
  REPORT="$(state report)"; say "  $REPORT"
  if [ "$STRUCTURAL" = "yes" ]; then
    check "4 the pre-upgrade product's long description is NULL, not ''" \
      "$(dbq "SELECT IF(\`$COL_DESC\` IS NULL, 'null', CONCAT('value:', \`$COL_DESC\`)) FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "null"
    check "4b which the plugin reads back as «never written»" "$(field_of "$REPORT" long)" "null"
    check "4c its create token is NULL too, so it collides with nothing" \
      "$(dbq "SELECT IF(\`$COL_TOKEN\` IS NULL, 'null', 'value') FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "null"
    check "4d and NULL may repeat, which is what lets every legacy row keep one" \
      "$(dbq "SELECT COUNT(*) >= 1 FROM \`$PRODUCTS\` WHERE \`$COL_TOKEN\` IS NULL")" "1"
    WROTE="$(state write)"; say "  $WROTE"
    check "4e a vendor writing the field stores exactly what they wrote" \
      "$(field_of "$WROTE" long)" '<p>متن کاملِ فروشنده</p>'
  else
    # NOTHING MOVED. The only thing a schema-neutral upgrade may do to a
    # vendor's long description is leave it alone, and §1 of this round is
    # precisely a path on which an upgrade DID change one.
    check "4 the long description written before the upgrade is untouched" \
      "$(field_of "$REPORT" long)" '<p>متن کاملِ فروشنده</p>'
    check "4b and the plugin still reads the field" "$(field_of "$REPORT" has_field)" "1"
  fi

  # ---- stage 5: a migration that fails in the middle --------------------
  #
  # Not a simulated failure: a NON-UNIQUE index of the same name is left on the
  # table, so `ADD UNIQUE KEY` really is refused by MySQL. This is also the
  # `alpha.37` shape rule — a step whose columns are there and whose index is
  # the wrong KIND must not report «complete».
  if [ "$STRUCTURAL" = "yes" ]; then
  install_zip "$BASE_ZIP"; drop_new_shape
  dbq "ALTER TABLE \`$PRODUCTS\` ADD COLUMN \`$COL_TOKEN\` VARCHAR(64) NULL" >/dev/null
  dbq "ALTER TABLE \`$PRODUCTS\` ADD INDEX \`$IDX\` (\`vendor_user_id\`)" >/dev/null
  wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  install_zip "$NEW_ZIP"
  check "5 a same-named NON-unique index is really in the way" "$(any_index)" "1"
  check "5b and it is not unique" "$(unique_index)" "0"
  check "5c the admin request still answers 200" "$(admin_request "$JAR")" "200"
  check "5d the site stays on schema $BASE_SCHEMA, which is $OLD_EXPECT and works" \
    "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  LAST_ERROR="$(wpx option get tmc_migration_last_error --format=json 2>/dev/null || echo '')"
  say "  last_error=$LAST_ERROR"
  check "5e the reason is recorded, naming the step" \
    "$(printf '%s' "$LAST_ERROR" | grep -c "$STEP_ID")" "1"
  check "5f and the vendor's product is untouched" \
    "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "1"

  # ---- stage 6: the resume finishes the job -----------------------------
  dbq "ALTER TABLE \`$PRODUCTS\` DROP INDEX \`$IDX\`" >/dev/null
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  check "6 the next admin request answers 200" "$(admin_request "$JAR")" "200"
  check "6b the schema reaches $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "6c the index is unique this time" "$(unique_index)" "1"
  check "6d the column the failed run had already added is not duplicated" \
    "$(has_column "$COL_TOKEN")" "1"
  check "6e and the error is cleared" "$(wpx option get tmc_migration_last_error 2>/dev/null || echo '')" ""

  # ---- stage 7: forward again runs nothing twice ------------------------
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
  # No database work, and the COST measured rather than described: the long
  # text stays in the column and stops being read, so on `alpha.40` it looks
  # like it vanished. It did not.
  state write >/dev/null 2>&1
  install_zip "$BASE_ZIP"
  check "8 the old build serves wp-admin with the new schema recorded" "$(admin_request "$JAR")" "200"
  check "8b it does not take the schema back down" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "8c the columns are still there — nothing is dropped on the way back" \
    "$(has_column "$COL_DESC")" "1"
  BACK="$(state report)"; say "  $BACK"
  if [ "$STRUCTURAL" = "yes" ]; then
    check "8d the old build cannot read the field at all" "$(field_of "$BACK" has_field)" "0"
  else
    check "8d the old build reads the field, because it has always had it" "$(field_of "$BACK" has_field)" "1"
    check "8d2 and reads the very text that was written" \
      "$(field_of "$BACK" long)" '<p>متن کاملِ فروشنده</p>'
  fi
  check "8e but the vendor's text is still ON DISK, not lost" \
    "$(dbq "SELECT IF(\`$COL_DESC\` IS NULL, 'null', 'kept') FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "kept"

  # ---- stage 9: forward once more, and it comes back -------------------
  install_zip "$NEW_ZIP"
  check "9 wp-admin answers 200" "$(admin_request "$JAR")" "200"
  AGAIN="$(state report)"; say "  $AGAIN"
  check "9b and the text written before the rollback is read again" \
    "$(field_of "$AGAIN" long)" '<p>متن کاملِ فروشنده</p>'
done

say ""
say "checks passed: $pass"
say "checks failed: $fail"
say "evidence: $OUT"
[ "$fail" -eq 0 ] || exit 1
exit 0
