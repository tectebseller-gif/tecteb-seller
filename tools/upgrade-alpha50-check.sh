#!/usr/bin/env bash
#
# The owner's own upgrade: `alpha.50` installed, the new package dropped on
# top — the way forward, a migration that FAILS, the resume, and the way back.
#
# Schema 25 → 26 adds ONE COLUMN: `brand_proposal_id BIGINT UNSIGNED NOT NULL
# DEFAULT 0` on `tmc_review_seen`. What this measures that an assertion in PHP
# cannot:
#
#  - the gate runs on an ADMIN REQUEST, not on an activation hook. Replacing
#    the files is what an upload does, and it fires no activation hook at all;
#  - **AN OPEN BRAND REQUEST AND AN OPEN PROPOSAL SURVIVE EVERY TRANSITION.**
#    This is the property the owner asked for by name — «درخواست‌های برند و
#    نسخه‌های پیشنهادی موجود با ارتقا گم نشوند و همچنان قابل رسیدگی باشند» — so
#    both are made by the OLD build, through its own services, and counted at
#    every stage including after the way back;
#  - `DEFAULT 0` is a real backfill, and that is measured rather than argued:
#    the marks `alpha.50` wrote keep reading as seen, and only the product with
#    an open request becomes unseen;
#  - `verify()` checks the SHAPE, not the name. A column of this name added
#    NULL-able would make «I had seen no request» indistinguishable from «I do
#    not know», and `<>` against NULL is neither true nor false — so a nullable
#    one would quietly stop counting those rows. That is the failure stage 5
#    injects: a real refusal, not a simulated one;
#  - a migration that fails leaves the site on schema 25 and records WHY — and
#    it does NOT leave `alpha.50` running. The new files are on disk and active;
#    what is behind is the DATABASE;
#  - the retry is automatic but THROTTLED, by a cooldown read out of
#    `UpgradeGate` rather than typed here;
#  - forward again runs nothing twice;
#  - going BACK to `alpha.50` needs no database work, and what it COSTS is
#    measured: the column stays and stops being read, so a brand request stops
#    lighting the badge again — which is the behaviour `alpha.50` shipped with.
#
#   PHPBIN=php WPCLI=/usr/local/bin/wp \
#     bash tools/upgrade-alpha50-check.sh docs/evidence/upgrade-alpha51

OUT="${1:-docs/evidence/upgrade-alpha51}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
SITE="${TMC_DEMO_SITE:-http://127.0.0.1:8081}"
ADMIN="${TMC_DEMO_ADMIN:-tmcowner}"
ADMIN_PASS="${TMC_DEMO_ADMIN_PASS:-demo-owner-2026}"
PHPBIN="${PHPBIN:-php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS="$WPROOT/wp-content/plugins"

# The newest package in `dist/`, DERIVED (`alpha.34`).
newest_zip() {
  ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.*.zip 2>/dev/null \
    | sed 's/.*alpha\.\([0-9]*\)\.zip/\1 &/' | sort -n | tail -1 | cut -d' ' -f2-
}
NEW_ZIP="${TMC_NEW_ZIP:-$(newest_zip)}"
BASES="${TMC_BASES:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.50.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha50-check.txt"
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
# A base's target comes out of the BASE's own ZIP: `NEW − 1` is a guess about
# the base (`alpha.38`).
zip_schema() {
  unzip -p "$1" 'tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php' \
    | sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' | head -1
}

MIG="$REPO/src/Modules/Product/Infrastructure/Migrations/M0026ReviewSeenBrand.php"
SEEN_MIG="$REPO/src/Modules/Product/Infrastructure/Migrations/M0021ReviewSeen.php"
NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
NEW_EXPECT="$(zip_version "$NEW_ZIP")"
STEP_ID="$(sed -n "s/.*return '\(0026_[a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
COL="$(sed -n "s/.*const BRAND_PROPOSAL = '\([a-z_]*\)'.*/\1/p" "$MIG" | head -1)"
SEEN_SUFFIX="$(sed -n "s/.*const SEEN = '\([a-z_]*\)'.*/\1/p" "$SEEN_MIG" | head -1)"
say "new package: $NEW_EXPECT (target schema $NEW_SCHEMA) · step $STEP_ID"
say "column: $COL on $SEEN_SUFFIX"
[ -n "$NEW_EXPECT" ] && [ -n "$NEW_SCHEMA" ] && [ -n "$STEP_ID" ] && [ -n "$COL" ] && [ -n "$SEEN_SUFFIX" ] \
  || { say "FAIL: could not read a version, a schema number, a step id, a column or a table name"; exit 2; }

PREFIX="$(wpx eval 'global $wpdb; echo $wpdb->prefix;')"
SEEN="${PREFIX}${SEEN_SUFFIX}"
PROPOSALS="${PREFIX}tmc_brand_proposals"
REVISIONS="${PREFIX}tmc_product_revisions"

has_column() {
  dbq "SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$SEEN' AND COLUMN_NAME = '$COL'"
}
# The SHAPE, not the name (`alpha.37`). `0/NO` is what `verify()` requires:
# a default of nought and NOT NULL. Anything else — a nullable column, or one
# with no default — must be refused, which is what stage 5 arranges.
column_shape() {
  dbq "SELECT CONCAT(IFNULL(COLUMN_DEFAULT, 'none'), '/', IS_NULLABLE)
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$SEEN' AND COLUMN_NAME = '$COL'"
}
drop_new_shape() {
  [ "$(has_column)" = "1" ] && dbq "ALTER TABLE \`$SEEN\` DROP COLUMN \`$COL\`" >/dev/null
  return 0
}
open_requests() { dbq "SELECT COUNT(*) FROM \`$PROPOSALS\` WHERE status IN ('pending','registered')"; }
open_revisions() { dbq "SELECT COUNT(*) FROM \`$REVISIONS\` WHERE status = 'pending'"; }
schema_now() { wpx eval 'echo (int) get_option("tmc_schema_version");'; }

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
restore_new() {
  install_zip "$NEW_ZIP"
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  if [ "$(has_column)" != "1" ]; then
    wpx option update tmc_schema_version "$((NEW_SCHEMA - 1))" >/dev/null 2>&1
  fi
  local jar; jar="$(admin_session)"
  admin_request "$jar" >/dev/null
  say ""
  say "left on: $(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null) · schema $(schema_now) · column $(has_column)"
}
trap restore_new EXIT

COOLDOWN="$(sed -n 's/.*RETRY_COOLDOWN_SECONDS = \([0-9]*\).*/\1/p' \
  "$REPO/src/Core/Lifecycle/UpgradeGate.php" 2>/dev/null | head -1)"
COOLDOWN="${COOLDOWN:-300}"
say "retry cooldown, read from UpgradeGate: ${COOLDOWN}s"
say ""

for BASE_ZIP in $BASES; do
  BASE_VERSION="$(zip_version "$BASE_ZIP")"
  BASE_SCHEMA="$(zip_schema "$BASE_ZIP")"
  STRUCTURAL=$([ "$BASE_SCHEMA" != "$NEW_SCHEMA" ] && echo yes || echo no)
  say "=============================================================="
  say "base: $BASE_VERSION (its own target schema $BASE_SCHEMA) · structural=$STRUCTURAL"
  say "=============================================================="

  # ---- 1. the base, installed, with work in flight ---------------------
  install_zip "$BASE_ZIP"
  # ARRANGED, and said so. Installing older files does NOT push the schema
  # down — that is the property 7c asserts — so a site genuinely on the base
  # has to be put there: the column goes and the stored version goes with it.
  # Written as a fixture rather than inferred from an install.
  drop_new_shape
  wpx option update tmc_schema_version "$BASE_SCHEMA" >/dev/null
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  JAR="$(admin_session)"
  check "1a the base answers an admin request" "$(admin_request "$JAR")" "200"
  check "1b and leaves the database on ITS target" "$(schema_now)" "$BASE_SCHEMA"
  check "1c the new column is not there" "$(has_column)" "0"

  # THE WORK IN FLIGHT, made by the OLD BUILD through its own services. Not
  # inserted by hand: a row written around the service is a row the service
  # never agreed to (`alpha.45`).
  IN_FLIGHT="$( (cd "$REPO" && "$PHPBIN" "$WPCLI" --allow-root --path="$WPROOT" \
      --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' \
      eval-file tests/Support/alpha50-probe.php arrange-in-flight) 2>&1 )"
  printf '%s\n' "$IN_FLIGHT" >>"$LOG"
  check "1d the base recorded an open brand request" \
    "$(printf '%s\n' "$IN_FLIGHT" | sed -n 's/^open_request=\(.*\)$/\1/p' | head -1)" "yes"
  check "1e and an open stock proposal" \
    "$(printf '%s\n' "$IN_FLIGHT" | sed -n 's/^open_revision=\(.*\)$/\1/p' | head -1)" "yes"
  REQ_BEFORE="$(open_requests)"
  REV_BEFORE="$(open_revisions)"
  say "in flight on the base: $REQ_BEFORE open brand request(s), $REV_BEFORE open proposal(s)"

  # ---- 2. the files are replaced. No activation hook fires. -------------
  install_zip "$NEW_ZIP"
  check "2a the new files are on disk" "$(zip_version "$NEW_ZIP")" "$NEW_EXPECT"
  if [ "$STRUCTURAL" = yes ]; then
    check "2b and the database is still behind" "$(schema_now)" "$BASE_SCHEMA"
  else
    check "2b the database needs no step at all" "$(schema_now)" "$NEW_SCHEMA"
  fi

  # ---- 3. the first admin request finishes the job ----------------------
  JAR="$(admin_session)"
  check "3a an admin request succeeds" "$(admin_request "$JAR")" "200"
  check "3b the schema is at the new target" "$(schema_now)" "$NEW_SCHEMA"
  check "3c the column is there" "$(has_column)" "1"
  check "3d with the shape verify() requires" "$(column_shape)" "0/NO"
  check "3e the open brand request survived" "$(open_requests)" "$REQ_BEFORE"
  check "3f the open proposal survived" "$(open_revisions)" "$REV_BEFORE"

  # ---- 4. nothing runs twice -------------------------------------------
  JAR="$(admin_session)"
  check "4a a second admin request also succeeds" "$(admin_request "$JAR")" "200"
  check "4b the schema has not moved" "$(schema_now)" "$NEW_SCHEMA"
  check "4c and the work in flight is still in flight" "$(open_requests)" "$REQ_BEFORE"

  # ---- 5. a migration that really fails --------------------------------
  if [ "$STRUCTURAL" = yes ]; then
    # Back to the base's structure, then an obstacle: a column of the RIGHT
    # NAME and the WRONG SHAPE. `ALTER … ADD COLUMN` then fails on the
    # duplicate name, and even if it did not, `verify()` refuses a nullable
    # one — which is the whole reason it asks for the shape.
    drop_new_shape
    wpx eval "update_option('tmc_schema_version', $BASE_SCHEMA);" >/dev/null
    dbq "ALTER TABLE \`$SEEN\` ADD COLUMN \`$COL\` BIGINT UNSIGNED NULL" >/dev/null
    check "5a an obstacle is in place: right name, wrong shape" "$(column_shape)" "NULL/YES"

    JAR="$(admin_session)"
    check "5b the admin page still answers" "$(admin_request "$JAR")" "200"
    check "5c the schema stays behind" "$(schema_now)" "$BASE_SCHEMA"
      # Asked of the INSTALLED plugin, not of a ZIP: `unzip -p` on a plain PHP
    # file answers nothing, and the first version of this script compared that
    # nothing against the expected version.
  check "5d the NEW files are what is running" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"
    LAST_ERROR="$(wpx option get tmc_migration_last_error --format=json 2>/dev/null || echo '')"
    say "  last_error=$LAST_ERROR"
    check "5e the reason is recorded, naming the step" \
      "$(printf '%s' "$LAST_ERROR" | grep -c "$STEP_ID")" "1"
    check "5f the work in flight is untouched by the failure" "$(open_requests)" "$REQ_BEFORE"

    # The retry is throttled, so an immediate second request changes nothing.
    JAR="$(admin_session)"
    admin_request "$JAR" >/dev/null
    check "5g the throttle holds: still behind" "$(schema_now)" "$BASE_SCHEMA"

    # ---- 6. the obstacle is cleared, and the next request finishes -----
    dbq "ALTER TABLE \`$SEEN\` DROP COLUMN \`$COL\`" >/dev/null
    # The throttle is read from the RECORDED FAILURE's timestamp, so clearing
    # the record is what an operator pressing «try again» does.
    wpx option delete tmc_migration_last_error >/dev/null 2>&1
    JAR="$(admin_session)"
    check "6a the next admin request succeeds" "$(admin_request "$JAR")" "200"
    check "6b and the job is finished" "$(schema_now)" "$NEW_SCHEMA"
    check "6c with the right shape" "$(column_shape)" "0/NO"
    check "6d the work in flight is STILL in flight" "$(open_requests)" "$REQ_BEFORE"
    check "6e and so is the proposal" "$(open_revisions)" "$REV_BEFORE"
  fi

  # ---- 7. the way back -------------------------------------------------
  install_zip "$BASE_ZIP"
  JAR="$(admin_session)"
  check "7a the base answers after going back" "$(admin_request "$JAR")" "200"
  check "7b the column stays — nothing older names it" "$(has_column)" "1"
  check "7c the schema is NOT pushed back down" "$(schema_now)" "$NEW_SCHEMA"
  check "7d and the open brand request is still there" "$(open_requests)" "$REQ_BEFORE"
  check "7e and the open proposal too" "$(open_revisions)" "$REV_BEFORE"
  say "cost of going back: the column is ignored, so a brand request stops"
  say "lighting the badge — the behaviour $BASE_VERSION shipped with."
  say ""
done

say "=============================================================="
say "checks: $((pass+fail))   pass: $pass   fail: $fail"
[ "$fail" -eq 0 ]
