#!/usr/bin/env bash
# The owner's own upgrade: `alpha.28` installed, `alpha.30` dropped on top.
#
# They installed `alpha.28` on staging themselves and read `alpha.29` without
# installing it, so THIS is the path their site will take — and it is the one
# that has to run migration 19 (schema 18 → 19) through the upgrade gate rather
# than through an activation hook, because «replace the files» is what an
# upload does and it fires no activation hook at all.
#
# Nothing here is simulated: `alpha.28` is installed from `dist/`, the schema is
# put back to what an `alpha.28` site really has, that package projects the
# product with its own code, the manager edits it in WooCommerce, and only then
# is `alpha.30` put in place and an ADMIN REQUEST made.
#
#   bash tools/upgrade-alpha28-check.sh docs/evidence/upgrade-alpha28
set -u
OUT="${1:-docs/evidence/upgrade-alpha28}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
SITE="${TMC_DEMO_SITE:-http://127.0.0.1:8081}"
ADMIN="${TMC_DEMO_ADMIN:-tmcowner}"
ADMIN_PASS="${TMC_DEMO_ADMIN_PASS:-demo-owner-2026}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS="$WPROOT/wp-content/plugins"
OLD_ZIP="${TMC_OLD_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.28.zip}"
NEW_ZIP="${TMC_NEW_ZIP:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.30.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha28-check.txt"
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
state() {
  cp "$REPO/tools/legacy-ownership-state.php" "$WPROOT/legacy-ownership-state.php"
  (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root eval-file legacy-ownership-state.php "$@" 2>/dev/null)
}
revision_state() {
  cp "$REPO/tools/revision-baseline-state.php" "$WPROOT/revision-baseline-state.php"
  (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root eval-file revision-baseline-state.php run 2>/dev/null)
}
field() { printf '%s\n' "$1" | grep -o "$2=[^ ]*" | head -1 | cut -d= -f2-; }
PREFIX="$(dbq 'SELECT 1' >/dev/null 2>&1 && wpx eval 'global $wpdb; echo $wpdb->prefix;' || echo 'wp_')"

# ================================================= an alpha.28 site, for real
say "=== the site goes back to what alpha.28 leaves behind ==="
install_zip "$OLD_ZIP"
OLDV="$(wpx plugin get tecteb-marketplace-core --field=version)"
# Schema 19 is exactly two things, and both are undone here so the upgrade gate
# has the work a real alpha.28 site gives it.
dbq "ALTER TABLE \`${PREFIX}tmc_products\` DROP COLUMN approved_baseline" >/dev/null 2>&1
dbq "DROP TABLE IF EXISTS \`${PREFIX}tmc_product_decisions\`" >/dev/null 2>&1
wpx option update tmc_schema_version 18 >/dev/null
COL_BEFORE="$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PREFIX}tmc_products' AND COLUMN_NAME = 'approved_baseline'")"
TBL_BEFORE="$(dbq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PREFIX}tmc_product_decisions'")"
say "installed: $OLDV   schema: $(wpx option get tmc_schema_version)"
check "the installed package is alpha.28"            "$OLDV" "0.1.0-alpha.28"
check "the baseline column is gone"                  "$COL_BEFORE" "0"
check "the decision table is gone"                   "$TBL_BEFORE" "0"
check "and the recorded schema says 18"              "$(wpx option get tmc_schema_version)" "18"

# The product, made and projected by alpha.28's own code.
SEED="$(state seed)"
PRODUCT="$(field "$SEED" product)"
[ -n "$PRODUCT" ] || { say "seed failed: $SEED"; exit 2; }
say "$(state project "$PRODUCT")"
say "$(state manager-edit "$PRODUCT")"
BEFORE="$(state read "$PRODUCT")"
say "$BEFORE"
M_TITLE="$(field "$BEFORE" title)"; M_LONG="$(field "$BEFORE" long)"; M_IMAGES="$(field "$BEFORE" images)"
# The one thing migration 19 carries over: a manager's sentence that lived in
# the single `review_note` column, with nowhere to be read from.
NOTE='یادداشت مدیر از زمان alpha.28'
dbq "UPDATE \`${PREFIX}tmc_products\` SET review_note = '${NOTE}' WHERE id = ${PRODUCT}" >/dev/null
check "alpha.28 left a review note on the product" \
  "$(dbq "SELECT review_note FROM \`${PREFIX}tmc_products\` WHERE id = ${PRODUCT}")" "$NOTE"

# ============================================ files replaced, gate does the rest
say ""
say "=== the files are replaced and an admin page is opened ==="
install_zip "$NEW_ZIP"
NEWV="$(wpx plugin get tecteb-marketplace-core --field=version)"
JAR="$(mktemp -d)/admin.jar"
curl -s -c "$JAR" -o /dev/null --data-urlencode "log=$ADMIN" --data-urlencode "pwd=$ADMIN_PASS" \
     -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
check "there is an admin session to make the request with" "$(grep -c wordpress_logged_in "$JAR" || true)" "1"
SCHEMA_BEFORE="$(wpx option get tmc_schema_version)"
HTTP="$(curl -s -o "$OUT/admin-request.html" -w '%{http_code}' -b "$JAR" --max-time 30 "$SITE/wp-admin/admin.php?page=tmc-dashboard")"
SCHEMA_AFTER="$(wpx option get tmc_schema_version)"
say "installed: $OLDV -> $NEWV   schema: $SCHEMA_BEFORE -> $SCHEMA_AFTER   admin request: $HTTP"
check "the admin page opened"                        "$HTTP" "200"
check "and no activation hook was needed"            "$SCHEMA_BEFORE" "18"
check "the upgrade gate reached schema 19"           "$SCHEMA_AFTER" "19"
check "the baseline column exists now" \
  "$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PREFIX}tmc_products' AND COLUMN_NAME = 'approved_baseline'")" "1"
check "and the decision table with it" \
  "$(dbq "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PREFIX}tmc_product_decisions'")" "1"
check "the baseline arrives EMPTY for the old product" \
  "$(dbq "SELECT COUNT(*) FROM \`${PREFIX}tmc_products\` WHERE id = ${PRODUCT} AND approved_baseline IS NULL")" "1"
check "the manager's sentence became a row in the trail" \
  "$(dbq "SELECT note FROM \`${PREFIX}tmc_product_decisions\` WHERE product_id = ${PRODUCT} AND decision = 'imported'")" "$NOTE"
check "and the old column was NOT cleared, so a rollback finds it" \
  "$(dbq "SELECT review_note FROM \`${PREFIX}tmc_products\` WHERE id = ${PRODUCT}")" "$NOTE"

# ============================== the alpha.28 product behaves under alpha.30
say ""
say "=== a vendor save on the upgraded product ==="
say "$(state vendor-save "$PRODUCT")"
AFTER="$(state read "$PRODUCT")"
say "$AFTER"
say "$(state fields "$PRODUCT")"
check "the manager's title survived the upgrade"     "$(field "$AFTER" title)"  "$M_TITLE"
check "and the full description"                     "$(field "$AFTER" long)"   "$M_LONG"
check "and the picture arrangement"                  "$(field "$AFTER" images)" "$M_IMAGES"
check "behaviour is alpha.28's until the first approval records a baseline" \
  "$(dbq "SELECT COUNT(*) FROM \`${PREFIX}tmc_products\` WHERE id = ${PRODUCT} AND approved_baseline IS NULL")" "1"
SETTLED="$(state settle "$PRODUCT")"
say "$SETTLED"
check "the manager's decision records the first agreement" \
  "$(dbq "SELECT COUNT(*) FROM \`${PREFIX}tmc_products\` WHERE id = ${PRODUCT} AND approved_baseline IS NOT NULL")" "1"

# ============================ and the whole approval path, on this database
say ""
say "=== the approval path, on a database that came from alpha.28 ==="
RUN="$(revision_state)"
printf '%s\n' "$RUN" | tee -a "$LOG" >/dev/null
printf '%s\n' "$RUN"
check "no stage of the approval path fails after the upgrade" \
  "$(printf '%s\n' "$RUN" | grep -c 'ok=false' || true)" "0"

# What a real administrator does after an upload: Settings › Permalinks › Save.
# The files were replaced under a running server, and the first render after
# that is the one nobody should have to reload — flushed here so the site is
# left in the state the upgrade guide tells the owner to put it in.
wpx rewrite flush >/dev/null
curl -s -o /dev/null -b "$JAR" --max-time 30 "$SITE/wp-admin/admin.php?page=tmc-dashboard"
rm -f "$WPROOT/legacy-ownership-state.php" "$WPROOT/revision-baseline-state.php"
say ""
say "checks: $((pass+fail))  pass: $pass  fail: $fail"
[ "$fail" -eq 0 ]
