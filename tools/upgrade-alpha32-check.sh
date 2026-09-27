#!/usr/bin/env bash
# The owner's own upgrade: `alpha.32` installed, the new package dropped on top —
# and then the way back, with somebody working on the old one.
#
# «مبنای کار نسخهٔ 0.1.0-alpha.32 است که اکنون روی staging نصب شده» — so this is
# the path their site takes, and schema 19 → 20 adds ONE column and ONE index to
# `tmc_products` and backfills it in batches.
#
# Four things this measures that an assertion in PHP cannot:
#
#  - the gate runs on an ADMIN REQUEST, not on an activation hook. Replacing
#    the files is what an upload does, and it fires no activation hook at all;
#  - the backfill reaches EVERY row, including the ones written by `alpha.32`
#    before the column existed. An empty key sorts before every real one, so a
#    half-filled column is a list whose first page is arbitrary;
#  - going BACK to `alpha.32` needs no database work. The column is additive and
#    `alpha.32` never names it, so the old package runs against the new table
#    unchanged;
#  - and — the part `alpha.33` did NOT measure, which the owner found by reading
#    this script — that going back and then FORWARD again repairs the keys that
#    the old package left lying. See «the hole» below.
#
#   bash tools/upgrade-alpha32-check.sh docs/evidence/upgrade-alpha32
#
# ---------------------------------------------------------------- the hole
#
# `alpha.33` rolled back and came forward again without creating or editing a
# single product in between, so it never asked the only question a rollback
# raises. Three facts from the source answer it:
#
#   1. `alpha.32` does not know `title_sort` exists — measured below as «never
#      mentions it» across its whole `src/`. So it writes `title` and leaves the
#      key alone: an EDITED title keeps the key of the name it used to have, and
#      a CREATED product gets the column default, the empty string.
#   2. Coming forward, `tmc_schema_version` still reads 20, so the upgrade gate
#      sees nothing to do and migration 20 never runs again.
#   3. And its backfill is `WHERE title_sort = ''` by design, so even a FORCED
#      re-run fills the created product's key and walks past the edited one —
#      and then `verify()` reports the table as done, because its own check is
#      the same `WHERE`.
#
# Stage 6 reproduces exactly that, stage 7 falsifies the migration as the answer
# to it, and stage 8 measures `TitleSortRepair` doing the job on one admin
# request. Stage 9 does the same for a key written in the OLD FORMAT, which is
# what `alpha.33` left behind and no row looks broken for.
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
# The NEWEST package in `dist/`, derived rather than written here. `alpha.34`
# removed this same hard-coded default from two other upgrade scripts and left
# it in this one, so the first run of the next round measured `alpha.32 ->
# alpha.34` and reported sixty green checks about a package that was no longer
# the one being delivered. `catalogue-run.mjs` caught it by comparing the
# installed version against the plugin header — which is why that check exists.
newest_zip() {
  ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.*.zip 2>/dev/null \
    | sed 's/.*alpha\.\([0-9]*\)\.zip/\1 &/' | sort -n | tail -1 | cut -d' ' -f2-
}
NEW_ZIP="${TMC_NEW_ZIP:-$(newest_zip)}"

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
# The version each package CLAIMS, read from the package rather than written
# here. A script that hard-codes a number breaks on the first round that moves
# it and reports a failure about a site that is entirely correct — which is what
# `revision-baseline-check.sh` did in `alpha.33`.
zip_version() {
  unzip -p "$1" 'tecteb-marketplace-core/tecteb-marketplace-core.php' \
    | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)[[:space:]]*$/\1/p' | head -1 | tr -d '\r'
}
OLD_EXPECT="$(zip_version "$OLD_ZIP")"
NEW_EXPECT="$(zip_version "$NEW_ZIP")"
say "packages under test: $OLD_EXPECT -> $NEW_EXPECT"
[ -n "$OLD_EXPECT" ] && [ -n "$NEW_EXPECT" ] || { say "FAIL: could not read a version out of a package"; exit 2; }

admin_session() {
  JAR="$(mktemp -d)/admin.jar"
  curl -s -c "$JAR" -o /dev/null --data-urlencode "log=$ADMIN" --data-urlencode "pwd=$ADMIN_PASS" \
       -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
  echo "$JAR"
}
# One wp-admin request, which is where the upgrade gate and the key repair live.
admin_request() {
  curl -s -o "${2:-/dev/null}" -w '%{http_code}' -b "$1" --max-time 60 \
       "$SITE/wp-admin/admin.php?page=tmc-dashboard"
}
PREFIX="$(wpx eval 'global $wpdb; echo $wpdb->prefix;')"
COLUMN="title_sort"
INDEX="tmc_product_title_sort"
PRODUCTS="${PREFIX}tmc_products"

has_column() {
  dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PRODUCTS}' AND COLUMN_NAME = '${COLUMN}'"
}
has_index() {
  dbq "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PRODUCTS}' AND INDEX_NAME = '${INDEX}'"
}
empty_keys() { dbq "SELECT COUNT(*) FROM \`${PRODUCTS}\` WHERE \`${COLUMN}\` = '' OR \`${COLUMN}\` IS NULL"; }

# How many rows disagree with their own title — asked with the plugin's OWN
# collation class, so this measures the key the code would write today and not a
# second implementation that could drift from it.
# NOTE ON QUOTING: the table name is spliced in with `'"$VAR"'` and **no
# spaces**. Written as `' "$VAR" '` bash sees three words instead of one, `wp
# eval` gets three arguments, and every one of these helpers answers the empty
# string — which then failed nine checks about a site that was entirely correct.
stale_keys() {
  wpx eval '
    global $wpdb;
    $c = "Tecteb\\Marketplace\\Modules\\Product\\Domain\\PersianCollation";
    if (!class_exists($c)) { echo "no_class"; return; }
    $n = 0;
    foreach ($wpdb->get_results("SELECT title, title_sort FROM `'"$PRODUCTS"'`", ARRAY_A) as $r) {
      if ($c::sortKey((string) $r["title"]) !== (string) $r["title_sort"]) { $n++; }
    }
    echo $n;
  ' 2>/dev/null
}
repair_build() {
  wpx eval '$s = get_option("tmc_title_sort_repair", []); echo (int) (is_array($s) ? ($s["build"] ?? 0) : 0);' 2>/dev/null
}

# ================================================ 1. an alpha.32 site, for real
say "=== 1. the site goes back to what alpha.32 leaves behind ==="
install_zip "$OLD_ZIP"
OLDV="$(wpx plugin get tecteb-marketplace-core --field=version)"
# Schema 20 is exactly one column and one index, and both are undone here so
# the upgrade gate has the work a real alpha.32 site gives it. The repair's own
# record goes too: a site that never ran the new package has none.
dbq "ALTER TABLE \`${PRODUCTS}\` DROP INDEX \`${INDEX}\`" >/dev/null 2>&1
dbq "ALTER TABLE \`${PRODUCTS}\` DROP COLUMN \`${COLUMN}\`" >/dev/null 2>&1
wpx option delete tmc_title_sort_repair >/dev/null 2>&1
wpx option update tmc_schema_version 19 >/dev/null
TOTAL="$(dbq "SELECT COUNT(*) FROM \`${PRODUCTS}\`")"
check "alpha.32 is the installed package"            "$OLDV" "$OLD_EXPECT"
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
# The premise every later stage rests on, measured once: this package cannot
# maintain a column whose name it does not contain.
check "alpha.32 never mentions the column anywhere in src/" \
  "$(grep -rc "$COLUMN" "$PLUGINS/tecteb-marketplace-core/src" 2>/dev/null | awk -F: '{s+=$2} END {print s+0}')" "0"

# ============================================= 2. the files are replaced
say ""
say "=== 2. the files are replaced and an admin page is opened ==="
install_zip "$NEW_ZIP"
NEWV="$(wpx plugin get tecteb-marketplace-core --field=version)"
SCHEMA_BEFORE="$(wpx option get tmc_schema_version)"
JAR="$(admin_session)"
HTTP="$(admin_request "$JAR" "$OUT/admin-request.html")"
SCHEMA_AFTER="$(wpx option get tmc_schema_version)"
say "installed: $OLDV -> $NEWV   schema: $SCHEMA_BEFORE -> $SCHEMA_AFTER   admin request: $HTTP"
check "the new package is installed"                 "$NEWV" "$NEW_EXPECT"
check "the admin page opened"                        "$HTTP" "200"
check "no activation hook was needed"                "$SCHEMA_BEFORE" "19"
check "the upgrade gate reached schema 20"           "$SCHEMA_AFTER" "20"
check "the sort column exists now"                   "$(has_column)" "1"
check "and it is indexed"                            "$(has_index)" "1"
check "it is a binary collation, not the table's" \
  "$(dbq "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PRODUCTS}' AND COLUMN_NAME = '${COLUMN}'")" "utf8mb4_bin"

# ============================================= 3. the backfill reached every row
say ""
say "=== 3. every row carries a key, including the ones alpha.32 wrote ==="
check "no product was left without a sort key"        "$(empty_keys)" "0"
check "and every product has one"                     "$(dbq "SELECT COUNT(*) FROM \`${PRODUCTS}\` WHERE \`${COLUMN}\` <> ''")" "$TOTAL"
check "no row disagrees with its own title"           "$(stale_keys)" "0"
PERSIAN_GROUPS="$(dbq "SELECT COUNT(DISTINCT LEFT(\`${COLUMN}\`, 1)) FROM \`${PRODUCTS}\`")"
say "distinct leading group digits across $TOTAL products: $PERSIAN_GROUPS"
check "at least one group digit is in use"            "$([ "${PERSIAN_GROUPS:-0}" -ge 1 ] && echo yes || echo no)" "yes"
check "and the repair recorded which format wrote them" \
  "$([ "$(repair_build)" -ge 1 ] && echo yes || echo no)" "yes"

# Running the gate twice must change nothing.
check "a second admin request is still fine"          "$(admin_request "$JAR")" "200"
check "and the schema did not move again"             "$(wpx option get tmc_schema_version)" "20"

# ======================================= 4. going back needs no database work
say ""
say "=== 4. back to alpha.32, with the new column left where it is ==="
install_zip "$OLD_ZIP"
# The schema option is NOT put back: a rollback that edits the database is the
# thing this check exists to show is unnecessary.
JAR="$(admin_session)"
BACK="$(curl -s -o "$OUT/alpha32-after-rollback.html" -w '%{http_code}' -b "$JAR" --max-time 40 "$SITE/wp-admin/admin.php?page=tmc-product-review")"
check "alpha.32's product list still opens"          "$BACK" "200"
check "with the column still on the table"           "$(has_column)" "1"
check "no fatal on the rolled-back page" \
  "$(grep -ci 'Fatal error' "$OUT/alpha32-after-rollback.html" || true)" "0"

# ============================ 5. and forward again, with nothing having happened
say ""
say "=== 5. forward again after an idle rollback — the alpha.33 measurement ==="
install_zip "$NEW_ZIP"
JAR="$(admin_session)"
FWD="$(admin_request "$JAR" "$OUT/forward-idle.html")"
check "the new package's dashboard opens"            "$FWD" "200"
check "the schema is 20"                             "$(wpx option get tmc_schema_version)" "20"
check "and no row lost its key"                      "$(empty_keys)" "0"
check "nor disagrees with its title"                 "$(stale_keys)" "0"

# ===================== 6. the rollback where somebody actually did some work
say ""
say "=== 6. back to alpha.32, and this time a title is edited and a product made ==="
install_zip "$OLD_ZIP"
# Written as raw column writes because that is EXACTLY the shape of alpha.32's
# own writes: stage 1 measured that its source does not contain the column name,
# so `INSERT`/`UPDATE` from that package name every column but this one.
TARGET="$(dbq "SELECT id FROM \`${PRODUCTS}\` ORDER BY id ASC LIMIT 1")"
OLD_TITLE="$(dbq "SELECT title FROM \`${PRODUCTS}\` WHERE id = ${TARGET}")"
OLD_KEY="$(dbq "SELECT \`${COLUMN}\` FROM \`${PRODUCTS}\` WHERE id = ${TARGET}")"
dbq "UPDATE \`${PRODUCTS}\` SET title = 'یدک خودرو ۱۰۰۰۰۰۰۰۰۰۰۰۰', updated_at = NOW() WHERE id = ${TARGET}" >/dev/null
dbq "INSERT INTO \`${PRODUCTS}\`
     (vendor_user_id, title, type, category_key, brand, short_description, price_minor,
      sku, stock, min_purchase, weight_grams, dimensions, tax_class, status, link_ownership,
      created_at, updated_at)
     SELECT vendor_user_id, 'چای سبز آزمایشی', 'simple', category_key, '', '', 100000,
            '', 3, 1, 0, '', '', 'draft', 'marketplace', NOW(), NOW()
     FROM \`${PRODUCTS}\` WHERE id = ${TARGET}" >/dev/null
CREATED="$(dbq "SELECT MAX(id) FROM \`${PRODUCTS}\`")"
say "edited product $TARGET («$OLD_TITLE» -> «یدک خودرو ۱۰۰۰۰۰۰۰۰۰۰۰۰»), created product $CREATED"
check "the edited row still carries its OLD key" \
  "$(dbq "SELECT \`${COLUMN}\` FROM \`${PRODUCTS}\` WHERE id = ${TARGET}")" "$OLD_KEY"
check "the created row has no key at all"            "$(empty_keys)" "1"

# ============================= 7. falsification: the migration is not the answer
say ""
say "=== 7. falsification — migration 20, forced, does not fix the edited row ==="
install_zip "$NEW_ZIP"
# `wp eval` loads the plugin but never fires `admin_init`, so the repair does NOT
# run here: this is migration 20 on its own, which is the claim being falsified.
FORCED="$(wpx eval '
  $m = new Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0020ProductTitleSort();
  $db = new Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase($GLOBALS["wpdb"]);
  $before = $m->verify($db) ? "yes" : "no";
  $m->up($db);
  echo "verify_before=" . $before . " verify_after=" . ($m->verify($db) ? "yes" : "no");
' 2>&1 | tail -1)"
say "forced migration: $FORCED"
check "verify() saw the empty key"                   "$(echo "$FORCED" | sed -n 's/.*verify_before=\([a-z]*\).*/\1/p')" "no"
check "and calls the table done afterwards"          "$(echo "$FORCED" | sed -n 's/.*verify_after=\([a-z]*\).*/\1/p')" "yes"
check "the created row's key was filled"             "$(empty_keys)" "0"
check "and the EDITED row is still wrong"            "$(stale_keys)" "1"
check "which is the defect, on the real site"        "$(dbq "SELECT \`${COLUMN}\` FROM \`${PRODUCTS}\` WHERE id = ${TARGET}")" "$OLD_KEY"
# The forced migration just filled the created row's key, which is the ONE
# symptom the cheap trigger looks for — so leaving it filled would make the next
# stage measure the rotating audit instead of the path a real re-upgrade takes.
# Put the defect back, so stage 8 is about what the owner will actually see.
dbq "UPDATE \`${PRODUCTS}\` SET \`${COLUMN}\` = '' WHERE id = ${CREATED}" >/dev/null
check "the created row is empty again, as a re-upgrade finds it" "$(empty_keys)" "1"

# ================================== 8. the repair, on one ordinary admin request
say ""
say "=== 8. one wp-admin request, and the keys tell the truth again ==="
JAR="$(admin_session)"
check "the dashboard opens"                          "$(admin_request "$JAR" "$OUT/forward-after-work.html")" "200"
check "no row disagrees with its title"              "$(stale_keys)" "0"
check "and none is empty"                            "$(empty_keys)" "0"
NEW_KEY="$(dbq "SELECT \`${COLUMN}\` FROM \`${PRODUCTS}\` WHERE id = ${TARGET}")"
check "the edited row's key changed"                 "$([ "$NEW_KEY" != "$OLD_KEY" ] && echo yes || echo no)" "yes"
# The thirteen-digit number in that title is the `alpha.34` fix as well: a key
# built the old way would encode it as twelve zeros.
check "and its number carries its length, not twelve digits" \
  "$(echo "$NEW_KEY" | grep -c '0131000000000000' || true)" "1"
# The list itself, which is the only reason any of this exists.
SORTED="$(curl -s -b "$JAR" --max-time 60 "$SITE/wp-admin/admin.php?page=tmc-product-review&orderby=title" -o "$OUT/sorted-after-repair.html" -w '%{http_code}')"
check "the alphabetical list opens after the repair" "$SORTED" "200"
check "and shows no fatal" "$(grep -ci 'Fatal error' "$OUT/sorted-after-repair.html" || true)" "0"

# ===================== 9. a key in the OLD FORMAT — what alpha.33 left behind
say ""
say "=== 9. keys written by alpha.33's format are rebuilt, though none looks broken ==="
# `alpha.33` padded a digit run to twelve and kept the LAST twelve, so a
# thirteen-digit number became twelve zeros. Put that key back by hand — the
# class that produced it no longer exists — and mark the recorded build as 1.
# The title carries 1 followed by twelve zeros — thirteen digits — so the two
# forms are known exactly and the substitution is a literal one. A regex over the
# key would also match a punctuation token (`d0200`), and a fixture that
# sometimes rewrites the wrong part of the key is a fixture that sometimes says
# the guard works.
LEGACY_KEY="$(wpx eval '
  $c = "Tecteb\\Marketplace\\Modules\\Product\\Domain\\PersianCollation";
  global $wpdb;
  $t = (string) $wpdb->get_var("SELECT title FROM `'"$PRODUCTS"'` WHERE id = '"$TARGET"'");
  $now = $c::sortKey($t);
  $then = str_replace("0131000000000000", "000000000000", $now);
  if ($then === $now) { echo "SUBSTITUTION_MISSED"; return; }
  echo $then;
' 2>/dev/null | tail -1)"
dbq "UPDATE \`${PRODUCTS}\` SET \`${COLUMN}\` = '${LEGACY_KEY}' WHERE id = ${TARGET}" >/dev/null
wpx eval '$s = get_option("tmc_title_sort_repair", []); $s = is_array($s) ? $s : []; $s["build"] = 1; $s["reason"] = ""; $s["cursor"] = 0; update_option("tmc_title_sort_repair", $s);' >/dev/null
check "the fixture really produced a different key"   "$([ -n "$LEGACY_KEY" ] && [ "$LEGACY_KEY" != "SUBSTITUTION_MISSED" ] && echo yes || echo no)" "yes"
check "the recorded build says the old format"       "$(repair_build)" "1"
check "and that key is in the column"                "$(dbq "SELECT \`${COLUMN}\` FROM \`${PRODUCTS}\` WHERE id = ${TARGET}")" "$LEGACY_KEY"
check "no row is empty, so nothing looks broken"     "$(empty_keys)" "0"
JAR="$(admin_session)"
check "one admin request"                            "$(admin_request "$JAR")" "200"
check "every key was rebuilt"                        "$(stale_keys)" "0"
check "and the recorded build caught up"             "$(repair_build)" "2"

# =================================================== 10. and it costs nothing idle
say ""
say "=== 10. a settled table is not written to again ==="
BEFORE_SUM="$(dbq "SELECT COALESCE(MD5(GROUP_CONCAT(id, ':', \`${COLUMN}\` ORDER BY id)), '') FROM \`${PRODUCTS}\`")"
for i in 1 2 3; do admin_request "$JAR" >/dev/null; done
check "three more requests changed no key" \
  "$(dbq "SELECT COALESCE(MD5(GROUP_CONCAT(id, ':', \`${COLUMN}\` ORDER BY id)), '') FROM \`${PRODUCTS}\`")" "$BEFORE_SUM"
check "nothing is left open"                         "$(wpx eval '$s = get_option("tmc_title_sort_repair", []); echo (string) ($s["reason"] ?? "") === "" ? "closed" : "open";' 2>/dev/null)" "closed"
# The created row is fixture litter; the catalogue count is what other evidence
# scripts measure against.
dbq "DELETE FROM \`${PRODUCTS}\` WHERE id = ${CREATED}" >/dev/null
dbq "UPDATE \`${PRODUCTS}\` SET title = '$(printf '%s' "$OLD_TITLE" | sed "s/'/''/g")' WHERE id = ${TARGET}" >/dev/null
JAR="$(admin_session)"
# More than one request, and that is the mechanism rather than a workaround: with
# nothing broken enough to open a sweep, this edit is found by the rotating
# audit, which reads 50 rows per request. Asking once and expecting a repair
# would be asserting that a bounded audit is unbounded. Stage 11 measures the
# count; here the loop just leaves the site clean for whatever runs next.
for i in 1 2 3 4 5 6 7 8; do
  admin_request "$JAR" >/dev/null
  [ "$(stale_keys)" = "0" ] && break
done
check "the fixture put the catalogue back"           "$(dbq "SELECT COUNT(*) FROM \`${PRODUCTS}\`")" "$TOTAL"
check "and the repair followed the title back"       "$(stale_keys)" "0"

# ============ 11. the case with NO other symptom: an edit and nothing else
say ""
say "=== 11. a title edited on the old package, with no product created ==="
# This is the variant that leaves no empty key and no build mismatch, so neither
# cheap trigger fires and the rotating audit is the only thing that can find it.
# The number of requests it takes is MEASURED here rather than claimed: the
# audit reads 50 rows per request and wraps, so a catalogue of this size needs a
# few. A loop with a cap, and the count printed — «within a few requests» is not
# a measurement.
EDIT2="$(dbq "SELECT id FROM \`${PRODUCTS}\` ORDER BY id DESC LIMIT 1")"
TITLE2="$(dbq "SELECT title FROM \`${PRODUCTS}\` WHERE id = ${EDIT2}")"
install_zip "$OLD_ZIP"
dbq "UPDATE \`${PRODUCTS}\` SET title = 'ویلچر تاشو آزمایشی', updated_at = NOW() WHERE id = ${EDIT2}" >/dev/null
install_zip "$NEW_ZIP"
check "one row disagrees with its title"              "$(stale_keys)" "1"
check "and NO row is empty — no cheap symptom"        "$(empty_keys)" "0"
check "nor is the recorded build behind"             "$(repair_build)" "2"

JAR="$(admin_session)"
REQUESTS=0
for i in 1 2 3 4 5 6 7 8; do
  admin_request "$JAR" >/dev/null
  REQUESTS=$((REQUESTS+1))
  [ "$(stale_keys)" = "0" ] && break
done
say "wp-admin requests the rotating audit needed on $TOTAL products: $REQUESTS"
check "the audit found it without anybody noticing"   "$(stale_keys)" "0"
check "and it took no more than ceil(total/50)+1" \
  "$([ "$REQUESTS" -le $(( TOTAL / 50 + 2 )) ] && echo yes || echo no)" "yes"
# Put the title back, so the next run of any evidence script sees the catalogue
# it expects.
dbq "UPDATE \`${PRODUCTS}\` SET title = '$(printf '%s' "$TITLE2" | sed "s/'/''/g")' WHERE id = ${EDIT2}" >/dev/null
for i in 1 2 3 4 5 6 7 8; do
  admin_request "$JAR" >/dev/null
  [ "$(stale_keys)" = "0" ] && break
done
check "and the title went back with its key"          "$(stale_keys)" "0"

say ""
say "checks: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
