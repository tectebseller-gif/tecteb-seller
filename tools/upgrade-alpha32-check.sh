#!/usr/bin/env bash
# The owner's own upgrade: `alpha.32` installed, `alpha.33` dropped on top.
#
# «مبنای کار نسخهٔ 0.1.0-alpha.32 است که اکنون روی staging نصب شده» — so this is
# the path their site will take, and this round is the first since `alpha.29`
# that carries a migration. Schema 19 → 20 adds ONE column and ONE index to
# `tmc_products`, and backfills it in batches.
#
# Three things this measures that an assertion in PHP cannot:
#
#  - the gate runs on an ADMIN REQUEST, not on an activation hook. Replacing
#    the files is what an upload does, and it fires no activation hook at all;
#  - the backfill reaches EVERY row, including the ones written by `alpha.32`
#    before the column existed. An empty key sorts before every real one, so a
#    half-filled column is a list whose first page is arbitrary;
#  - going BACK to `alpha.32` needs no database work. The column is additive and
#    `alpha.32` never names it, so the old package runs against the new table
#    unchanged — which is the claim `docs/upgrade-and-rollback.md` §16 makes,
#    and it is measured here rather than argued.
#
#   bash tools/upgrade-alpha32-check.sh docs/evidence/upgrade-alpha32
set -u
OUT="${1:-docs/evidence/upgrade-alpha32}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
SITE="${TMC_DEMO_SITE:-http://127.0.0.1:8081}"
ADMIN="${TMC_DEMO_ADMIN:-tmcowner}"
ADMIN_PASS="${TMC_DEMO_ADMIN_PASS:-demo-owner-2026}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS="$WPROOT/wp-content/plugins"
OLD_ZIP="${TMC_OLD_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.32.zip}"
NEW_ZIP="${TMC_NEW_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.33.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha32-check.txt"
: > "$LOG"
pass=0; fail=0
say() { echo "$1" | tee -a "$LOG"; }
check() {
  local name="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    pass=$((pass+1)); say "ok:   $(printf '%-58s' "$name") $expected"
  else
    fail=$((fail+1)); say "FAIL: $(printf '%-58s' "$name") expected=$expected actual=$actual"
  fi
}
for zip in "$OLD_ZIP" "$NEW_ZIP"; do
  [ -f "$zip" ] || { say "missing package: $zip"; exit 2; }
done

wpx() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@"); }
dbq() { wpx db query "$1" --skip-column-names 2>/dev/null; }
install_zip() { rm -rf "$PLUGINS/tecteb-marketplace-core"; (cd "$PLUGINS" && unzip -q "$1" -d .); }
admin_session() {
  JAR="$(mktemp -d)/admin.jar"
  curl -s -c "$JAR" -o /dev/null --data-urlencode "log=$ADMIN" --data-urlencode "pwd=$ADMIN_PASS" \
       -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
  echo "$JAR"
}
PREFIX="$(wpx eval 'global $wpdb; echo $wpdb->prefix;')"
COLUMN="title_sort"
INDEX="tmc_product_title_sort"

has_column() {
  dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PREFIX}tmc_products' AND COLUMN_NAME = '${COLUMN}'"
}
has_index() {
  dbq "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PREFIX}tmc_products' AND INDEX_NAME = '${INDEX}'"
}

# ================================================ an alpha.32 site, for real
say "=== the site goes back to what alpha.32 leaves behind ==="
install_zip "$OLD_ZIP"
OLDV="$(wpx plugin get tecteb-marketplace-core --field=version)"
# Schema 20 is exactly one column and one index, and both are undone here so
# the upgrade gate has the work a real alpha.32 site gives it.
dbq "ALTER TABLE \`${PREFIX}tmc_products\` DROP INDEX \`${INDEX}\`" >/dev/null 2>&1
dbq "ALTER TABLE \`${PREFIX}tmc_products\` DROP COLUMN \`${COLUMN}\`" >/dev/null 2>&1
wpx option update tmc_schema_version 19 >/dev/null
TOTAL="$(dbq "SELECT COUNT(*) FROM \`${PREFIX}tmc_products\`")"
check "alpha.32 is the installed package"            "$OLDV" "0.1.0-alpha.32"
check "the recorded schema says 19"                  "$(wpx option get tmc_schema_version)" "19"
check "and the sort column does not exist"           "$(has_column)" "0"
say "products on this site: $TOTAL"
[ "${TOTAL:-0}" -ge 20 ] || { say "FAIL: not enough products to measure a backfill"; fail=$((fail+1)); }

# The old package must WORK on the old shape: a list that fatals here would
# make every later check meaningless.
JAR="$(admin_session)"
check "there is an admin session to make the request with" "$(grep -c wordpress_logged_in "$JAR" || true)" "1"
HTTP32="$(curl -s -o "$OUT/alpha32-list.html" -w '%{http_code}' -b "$JAR" --max-time 40 "$SITE/wp-admin/admin.php?page=tmc-product-review")"
check "alpha.32's own product list opens"            "$HTTP32" "200"
check "and it is alpha.32 serving it" \
  "$(grep -c 'tecteb-marketplace-core/assets/admin/tmc-admin.css' "$OUT/alpha32-list.html" || true)" "1"

# ============================================= the files are replaced
say ""
say "=== the files are replaced and an admin page is opened ==="
install_zip "$NEW_ZIP"
NEWV="$(wpx plugin get tecteb-marketplace-core --field=version)"
SCHEMA_BEFORE="$(wpx option get tmc_schema_version)"
JAR="$(admin_session)"
HTTP="$(curl -s -o "$OUT/admin-request.html" -w '%{http_code}' -b "$JAR" --max-time 60 "$SITE/wp-admin/admin.php?page=tmc-dashboard")"
SCHEMA_AFTER="$(wpx option get tmc_schema_version)"
say "installed: $OLDV -> $NEWV   schema: $SCHEMA_BEFORE -> $SCHEMA_AFTER   admin request: $HTTP"
check "alpha.33 is the installed package"            "$NEWV" "0.1.0-alpha.33"
check "the admin page opened"                        "$HTTP" "200"
check "no activation hook was needed"                "$SCHEMA_BEFORE" "19"
check "the upgrade gate reached schema 20"           "$SCHEMA_AFTER" "20"
check "the sort column exists now"                   "$(has_column)" "1"
check "and it is indexed"                            "$(has_index)" "1"
check "it is a binary collation, not the table's" \
  "$(dbq "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PREFIX}tmc_products' AND COLUMN_NAME = '${COLUMN}'")" "utf8mb4_bin"

# ============================================= the backfill reached every row
say ""
say "=== every row carries a key, including the ones alpha.32 wrote ==="
EMPTY="$(dbq "SELECT COUNT(*) FROM \`${PREFIX}tmc_products\` WHERE \`${COLUMN}\` = '' OR \`${COLUMN}\` IS NULL")"
check "no product was left without a sort key"        "$EMPTY" "0"
check "and every product has one"                     "$(dbq "SELECT COUNT(*) FROM \`${PREFIX}tmc_products\` WHERE \`${COLUMN}\` <> ''")" "$TOTAL"
# A Persian title and a Latin one must land in different groups: the key's
# first character is the group digit, and that is what puts Persian first.
PERSIAN_GROUPS="$(dbq "SELECT COUNT(DISTINCT LEFT(\`${COLUMN}\`, 1)) FROM \`${PREFIX}tmc_products\`")"
say "distinct leading group digits across $TOTAL products: $PERSIAN_GROUPS"
check "at least one group digit is in use"            "$([ "${PERSIAN_GROUPS:-0}" -ge 1 ] && echo yes || echo no)" "yes"

# Running the gate twice must change nothing: the migration is idempotent and
# the backfill only ever touches rows whose key is still empty.
HTTP2="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" --max-time 60 "$SITE/wp-admin/admin.php?page=tmc-dashboard")"
check "a second admin request is still fine"          "$HTTP2" "200"
check "and the schema did not move again"             "$(wpx option get tmc_schema_version)" "20"

# ======================================= going back needs no database work
say ""
say "=== back to alpha.32, with the new column left where it is ==="
install_zip "$OLD_ZIP"
# The schema option is NOT put back: a rollback that edits the database is the
# thing this check exists to show is unnecessary.
JAR="$(admin_session)"
BACK="$(curl -s -o "$OUT/alpha32-after-rollback.html" -w '%{http_code}' -b "$JAR" --max-time 40 "$SITE/wp-admin/admin.php?page=tmc-product-review")"
check "alpha.32's product list still opens"          "$BACK" "200"
check "with the column still on the table"           "$(has_column)" "1"
check "and alpha.32 never mentions it" \
  "$(grep -rc "$COLUMN" "$PLUGINS/tecteb-marketplace-core/src" 2>/dev/null | awk -F: '{s+=$2} END {print s+0}')" "0"
check "no fatal on the rolled-back page" \
  "$(grep -ci 'Fatal error' "$OUT/alpha32-after-rollback.html" || true)" "0"

# ============================================= and forward again, for real
say ""
say "=== and forward again, which is how the owner will leave it ==="
install_zip "$NEW_ZIP"
JAR="$(admin_session)"
FWD="$(curl -s -o "$OUT/alpha33-list.html" -w '%{http_code}' -b "$JAR" --max-time 60 "$SITE/wp-admin/admin.php?page=tmc-product-review")"
check "alpha.33's product list opens"                "$FWD" "200"
check "the schema is 20"                             "$(wpx option get tmc_schema_version)" "20"
check "and no row lost its key"                      "$(dbq "SELECT COUNT(*) FROM \`${PREFIX}tmc_products\` WHERE \`${COLUMN}\` = ''")" "0"

say ""
say "checks: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
