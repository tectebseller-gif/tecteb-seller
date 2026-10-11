#!/usr/bin/env bash
#
# The owner's own upgrade: `alpha.47` installed on staging, the new package
# dropped on top — the way forward, a migration that FAILS, the resume, and the
# way back.
#
# Schema 24 → 25 adds ONE TABLE (`tmc_brand_proposals`, with a unique index on
# the normalised brand name) and ONE COLUMN (`brand_term_id BIGINT UNSIGNED
# NULL` on `tmc_products`), and seeds nothing. What this measures that an
# assertion in PHP cannot:
#
#  - the gate runs on an ADMIN REQUEST, not on an activation hook. Replacing
#    the files is what an upload does, and it fires no activation hook at all;
#  - a product the OLD build really wrote, through its own repository, survives
#    every transition below;
#  - `verify()` checks the SHAPE, not the name — a same-named table with one
#    column satisfies `CREATE TABLE IF NOT EXISTS` and accepts no write, which
#    is the `alpha.37` rule and the failure stage 5 injects. A real refusal, not
#    a simulated one;
#  - a migration that fails leaves the site on schema 24 and records WHY — and
#    it does NOT leave `alpha.47` running. The new files are on disk and active;
#    what is behind is the DATABASE;
#  - AND THIS IS THE ROUND THAT CHANGES: with the new files on disk and the
#    column absent, a product save is now REFUSED rather than landing. That is
#    the finding `alpha.45` opened and `alpha.48` closed, measured in the
#    upgrade path itself (stages 4h–4k and 5g);
#  - the retry is automatic but THROTTLED, by a cooldown read out of
#    `UpgradeGate` rather than typed here;
#  - the next admin request after the obstacle is cleared finishes the job;
#  - forward again runs nothing twice;
#  - going BACK to `alpha.47` needs no database work, and what it COSTS is
#    measured: the table and the column stay and stop being read, so brand
#    requests stop being possible and brand edits stop being checked against
#    the list.
#
#   PHPBIN=php WPCLI=/usr/local/bin/wp \
#     bash tools/upgrade-alpha47-check.sh docs/evidence/upgrade-alpha48

OUT="${1:-docs/evidence/upgrade-alpha48}"
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
BASES="${TMC_BASES:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.47.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha47-check.txt"
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

MIG="$REPO/src/Modules/Product/Infrastructure/Migrations/M0025BrandRegistry.php"
NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
NEW_EXPECT="$(zip_version "$NEW_ZIP")"
STEP_ID="$(sed -n "s/.*return '\(0025_[a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
PROPOSALS_SUFFIX="$(sed -n "s/.*const PROPOSALS = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
TABLE_SUFFIX="$(sed -n "s/.*const PRODUCTS = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
COL_BRAND="$(sed -n "s/.*const BRAND_TERM = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
say "new package: $NEW_EXPECT (target schema $NEW_SCHEMA) · step $STEP_ID"
say "table: $PROPOSALS_SUFFIX · column: $COL_BRAND on $TABLE_SUFFIX"
[ -n "$NEW_EXPECT" ] && [ -n "$NEW_SCHEMA" ] && [ -n "$STEP_ID" ] \
  && [ -n "$COL_BRAND" ] && [ -n "$TABLE_SUFFIX" ] && [ -n "$PROPOSALS_SUFFIX" ] \
  || { say "FAIL: could not read a version, a schema number, a step id, a column or a table name"; exit 2; }

PREFIX="$(wpx eval 'global $wpdb; echo $wpdb->prefix;')"
PRODUCTS="${PREFIX}${TABLE_SUFFIX}"
PROPOSALS="${PREFIX}${PROPOSALS_SUFFIX}"
has_column() {
  dbq "SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS' AND COLUMN_NAME = '$COL_BRAND'"
}
# The SHAPE, not the name. A `brand_term_id` added NOT NULL DEFAULT 0 would
# satisfy «a column of that name exists» and then tell every legacy row its
# brand is term zero. MariaDB reports a nullable column's absent default as the
# STRING `NULL`, which is why the shape reads `NULL/YES` rather than `none/YES`.
column_shape() {
  dbq "SELECT CONCAT(IFNULL(COLUMN_DEFAULT, 'none'), '/', IS_NULLABLE)
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS' AND COLUMN_NAME = '$COL_BRAND'"
}
has_table() {
  dbq "SELECT COUNT(*) FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PROPOSALS'"
}
# «A table of that name exists» is not the question (`alpha.37`). The unique
# index on the normalised name is what makes «one open request per brand» the
# database's answer, so it is asked for BY NAME — and `COUNT(*)` on STATISTICS
# counts COLUMNS, which for this one-column key is one row (`alpha.41`).
table_shape() {
  echo "$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PROPOSALS'")/$(dbq "SELECT COUNT(*) FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PROPOSALS'
         AND INDEX_NAME = 'open_name' AND NON_UNIQUE = 0")"
}
drop_new_shape() {
  [ "$(has_column)" = "1" ] && dbq "ALTER TABLE \`$PRODUCTS\` DROP COLUMN \`$COL_BRAND\`" >/dev/null
  [ "$(has_table)" = "1" ] && dbq "DROP TABLE \`$PROPOSALS\`" >/dev/null
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
    check "1c the brand column is absent" "$(has_column)" "0"
    check "1c2 and so is the brand-request table" "$(has_table)" "0"
  else
    check "1c the brand column is already there" "$(has_column)" "1"
    check "1d and already the right shape" "$(column_shape)" "NULL/YES"
    check "1d2 with the request table and its unique key" "$(table_shape)" "12/1"
  fi
  check "1e and the old build serves wp-admin" "$(admin_request "$JAR")" "200"

  # ---- stage 2: a product written BY THE OLD BUILD ----------------------
  SEED="$(state seed)"; say "  $SEED"
  PRODUCT="$(field_of "$SEED" product)"
  [ -n "$PRODUCT" ] && [ "$PRODUCT" != "0" ] \
    || { say "FAIL: the product fixture did not run on the old build — every later check would be void"; exit 2; }
  check "2 the old build wrote a product through its own repository" \
    "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "1"
  # GIVE THE FIXTURE BACK. `seed` reuses an existing product, so the second
  # base in one invocation would otherwise inherit the first base's rule —
  # `2/6` instead of the pre-upgrade `1/null` — and stage 4 would report a
  # failure about a site that is entirely correct, depending on the ORDER the
  # bases were listed in. `reset` writes the pre-upgrade shape through the
  # OLD build's own repository, arity-safe.
  RESET="$(state reset)"; say "  $RESET"
  check "2a the fixture was returned to its pre-upgrade rule" \
    "$(field_of "$RESET" reset)" "1"
  check "2a2 which is «no minimum, no maximum»" \
    "$(field_of "$RESET" min)/$(field_of "$RESET" max)" "1/null"
  # The product fixture is `purchase-step-state.php`, which both builds have —
  # `alpha.47` added the step and reads it, so the step is NOT the subject of
  # this round and is used only as a value that must survive every transition.
  WROTE_BEFORE="$(state write)"; say "  $WROTE_BEFORE"
  check "2b the old build wrote the rule through its own repository" \
    "$(field_of "$WROTE_BEFORE" step)" "2"
  check "2c with its two numbers" \
    "$(field_of "$WROTE_BEFORE" min)/$(field_of "$WROTE_BEFORE" max)" "2/6"

  # ---- stage 3: the upgrade, from the admin request ---------------------
  install_zip "$NEW_ZIP"
  check "3 the files are now $NEW_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"
  check "3b replacing files runs no migration by itself" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "3c the first admin request answers 200" "$(admin_request "$JAR")" "200"
  check "3d and the gate has moved the schema to $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "3e the brand column exists" "$(has_column)" "1"
  check "3f nullable with no default, which is the shape verify() demands" "$(column_shape)" "NULL/YES"
  check "3f2 and the request table is there with its unique key" "$(table_shape)" "12/1"
  check "3g no migration error was recorded" "$(wpx option get tmc_migration_last_error 2>/dev/null || echo '')" ""

  # ---- stage 4: NO backfill, and the NULL means something ---------------
  #
  # `brand_term_id` arrives NULL on every existing row and nothing fills it,
  # which is the decision: NULL says «this row's brand predates the registry»,
  # and matching the free-text brands on the owner's shop to terms would be a
  # guess. So what is measured is that nothing was guessed.
  REPORT="$(state report)"; say "  $REPORT"
  check "4 every pre-upgrade row's brand term is NULL" \
    "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE \`$COL_BRAND\` IS NOT NULL")" "0"
  check "4b and the rule the old build wrote is read back unchanged" \
    "$(field_of "$REPORT" min)/$(field_of "$REPORT" max)/$(field_of "$REPORT" step)" "2/6/2"
  check "4c the request table is empty — nothing was invented" \
    "$(dbq "SELECT COUNT(*) FROM \`$PROPOSALS\`")" "0"
  WROTE="$(state write)"; say "  $WROTE"
  check "4f a vendor writing the rule after the upgrade still stores it" \
    "$(field_of "$WROTE" step)" "2"
  check "4g with its minimum and maximum" \
    "$(field_of "$WROTE" min)/$(field_of "$WROTE" max)" "2/6"

  # ---- stage 5: a migration that fails ---------------------------------
  #
  # Not a simulated failure. A TABLE of the right name and the wrong shape is
  # left in place, so `up()`'s `CREATE TABLE IF NOT EXISTS` is satisfied and
  # does nothing — and `verify()` then refuses, because it asks for the columns
  # and the unique index rather than for a table of that name. That is the
  # `alpha.37` rule, and this is the shape that reaches it.
  if [ "$STRUCTURAL" = "yes" ]; then
    install_zip "$BASE_ZIP"; drop_new_shape
    wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1

    # ---- stage 4h: NEW CODE, OLD STRUCTURE, COLUMN ABSENT ---------------
    #
    # THE ROUND'S OWN SUBJECT, measured in the upgrade path. The new files are
    # on disk and active, `tmc_products` has no `brand_term_id`, and no admin
    # request has run yet (WP-CLI does not fire `admin_init`), so the gate has
    # had no chance to migrate.
    #
    # On `alpha.45` the equivalent measurement answered `wrote=1`: the save
    # landed against a structure the build did not have. From `alpha.48`
    # `ProductTableShape` reads the SHAPE at the write and the save is REFUSED
    # — which is the finding closed, in the place it would actually happen.
    install_zip "$NEW_ZIP"
    check "4h with the new files on disk, the brand column really is absent" "$(has_column)" "0"
    check "4h2 and the request table too" "$(has_table)" "0"
    check "4i and the schema is still the old one" \
      "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
    ABSENT_WRITE="$(state write)"; say "  absent_column: $ABSENT_WRITE"
    check "4j a product save is REFUSED against the missing column" \
      "$(field_of "$ABSENT_WRITE" wrote)" "0"
    check "4k and the version on disk is the NEW one, not the base" \
      "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"

    # ---- the injected failure: a same-named table of the wrong shape -----
    install_zip "$BASE_ZIP"; drop_new_shape
    dbq "CREATE TABLE \`$PROPOSALS\` (\`id\` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (\`id\`))" >/dev/null
    wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    install_zip "$NEW_ZIP"
    check "5 a same-named table of the wrong shape is really in the way" "$(has_table)" "1"
    check "5b and its shape is not the one verify() demands" "$(table_shape)" "1/0"
    check "5c the admin request still answers 200" "$(admin_request "$JAR")" "200"
    check "5d the site stays on schema $BASE_SCHEMA" \
      "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
    LAST_ERROR="$(wpx option get tmc_migration_last_error --format=json 2>/dev/null || echo '')"
    say "  last_error=$LAST_ERROR"
    check "5e the reason is recorded, naming the step" \
      "$(printf '%s' "$LAST_ERROR" | grep -c "$STEP_ID")" "1"
    check "5f and the vendor's product is untouched" \
      "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE id = $PRODUCT")" "1"

    # ---- stage 5g: WHAT A FAILED UPGRADE ACTUALLY LEAVES RUNNING ---------
    #
    # The new files are on disk and active; what is behind is the DATABASE.
    # «The previous package is running» is what the checklist used to claim and
    # it is not true.
    #
    # AND THE MEASUREMENT CORRECTED THE EXPECTATION HERE. The first version of
    # this stage expected the product save to be refused and it answered
    # `wrote=1` — correctly. The obstacle is a mis-shaped TABLE, and `up()`
    # runs in order: `CREATE TABLE IF NOT EXISTS` is satisfied by the obstacle
    # and does nothing, then the `ALTER TABLE` ADDS `brand_term_id` and
    # succeeds. Only `verify()` refuses, on the table. So the structure a
    # PRODUCT WRITE depends on is genuinely there, and refusing the save would
    # be the needless stop §6 warns against: «مسیر سالم را بی‌دلیل متوقف نکن».
    #
    # Both halves are therefore measured: the product write goes through
    # because its columns are sound, and the schema is still 24 because the
    # BRAND REQUEST table is not — which is what stops brand requests, and
    # nothing else.
    WROTE_ON_OLD_SCHEMA="$(state write)"; say "  $WROTE_ON_OLD_SCHEMA"
    check "5g the product save goes through, because ITS columns did land" \
      "$(field_of "$WROTE_ON_OLD_SCHEMA" wrote)" "1"
    check "5g2 the brand column really is there and the right shape" \
      "$(column_shape)" "NULL/YES"
    check "5g3 and what is wrong is the request table, which is why verify() refused" \
      "$(table_shape)" "1/0"
    check "5g4 the vendor's rule is intact" \
      "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE id = $PRODUCT AND min_purchase = 2")" "1"
    check "5h the version on disk is still the new one" \
      "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"
    check "5i so what is behind is the schema, not the code" \
      "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
    # And the retry is automatic but THROTTLED — read from the class, never
    # typed here (`alpha.33`: a number written by hand breaks the next round).
    COOLDOWN="$(sed -n 's/.*RETRY_COOLDOWN_SECONDS = \([0-9]*\).*/\1/p' \
      "$REPO/src/Core/Migration/UpgradeGate.php" | head -1)"
    say "  retry_cooldown_seconds=$COOLDOWN"
    check "5j the cooldown is a real number read from UpgradeGate" \
      "$([ -n "$COOLDOWN" ] && [ "$COOLDOWN" -gt 0 ] && echo yes || echo no)" "yes"
    ERROR_AT_BEFORE="$(printf '%s' "$LAST_ERROR" | sed -n 's/.*"at":"\([^"]*\)".*/\1/p')"
    admin_request "$JAR" >/dev/null
    ERROR_AT_AFTER="$(wpx option get tmc_migration_last_error --format=json 2>/dev/null \
      | sed -n 's/.*"at":"\([^"]*\)".*/\1/p')"
    check "5k an admin request inside the cooldown does not retry" \
      "$([ "$ERROR_AT_BEFORE" = "$ERROR_AT_AFTER" ] && echo same || echo retried)" "same"

    # ---- stage 6: the resume finishes the job ---------------------------
    dbq "DROP TABLE \`$PROPOSALS\`" >/dev/null
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    check "6 the next admin request answers 200" "$(admin_request "$JAR")" "200"
    check "6b the schema reaches $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
    check "6c the table is the right shape this time" "$(table_shape)" "12/1"
    check "6c2 and the column with it" "$(column_shape)" "NULL/YES"
    check "6d the error is cleared" "$(wpx option get tmc_migration_last_error 2>/dev/null || echo '')" ""
    RESUMED="$(state write)"; say "  $RESUMED"
    check "6e and the product write still goes through" \
      "$(field_of "$RESUMED" wrote)" "1"
    check "6e2 with the request table now able to accept one" \
      "$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PROPOSALS' AND COLUMN_NAME = 'open_key'")" "1"

    # ---- stage 7: forward again runs nothing twice ----------------------
    BEFORE_COLS="$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS'")"
    BEFORE_ROWS="$(dbq "SELECT COUNT(*) FROM \`$PROPOSALS\`")"
    check "7 a second admin request answers 200" "$(admin_request "$JAR")" "200"
    check "7b the column count did not move" \
      "$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PRODUCTS'")" "$BEFORE_COLS"
    check "7b2 nor the request table's rows" \
      "$(dbq "SELECT COUNT(*) FROM \`$PROPOSALS\`")" "$BEFORE_ROWS"
    check "7c and the schema is still $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  else
    say "  stages 5-7 skipped: structural=$STRUCTURAL, so there is no migration step to fail, resume or re-run"
  fi

  # ---- stage 8: back to the old build ----------------------------------
  #
  # No database work, and the COST measured rather than described: the table
  # and the column keep what they hold and stop being read, so on `alpha.47`
  # a vendor cannot ask for a brand and a brand edit stops being checked
  # against the manager's list — which is the defect this round fixes.
  state write >/dev/null 2>&1
  BRAND_BEFORE="$(dbq "SELECT COUNT(*) FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$PROPOSALS'")"
  install_zip "$BASE_ZIP"
  check "8 the old build serves wp-admin with the new schema recorded" "$(admin_request "$JAR")" "200"
  check "8b it does not take the schema back down" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "8c the request table is still there — nothing is dropped on the way back" "$(has_table)" "$BRAND_BEFORE"
  check "8c2 and so is the column" "$(has_column)" "1"
  BACK="$(state report)"; say "  $BACK"
  check "8d the old build still reads the product's rule" \
    "$(field_of "$BACK" min)/$(field_of "$BACK" max)/$(field_of "$BACK" step)" "2/6/2"
  check "8e and its write still works, because nothing it needs was taken away" \
    "$(field_of "$(state write)" wrote)" "1"

  # ---- stage 9: forward once more, from the real package ---------------
  install_zip "$NEW_ZIP"
  check "9 forward again answers 200" "$(admin_request "$JAR")" "200"
  check "9b the schema is $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  FORWARD="$(state report)"; say "  $FORWARD"
  check "9c and the rule the vendor wrote is read again, unchanged" \
    "$(field_of "$FORWARD" min)/$(field_of "$FORWARD" max)/$(field_of "$FORWARD" step)" "2/6/2"
  check "9d with the brand column and table in the shape verify() demands" \
    "$(column_shape)/$(table_shape)" "NULL/YES/12/1"
done

say ""
say "checks=$((pass+fail)) pass=$pass fail=$fail"
[ "$fail" -eq 0 ] || exit 1
