#!/usr/bin/env bash
#
# The owner's own upgrade: `alpha.55` installed on staging, the new package
# dropped on top — and this time there is NO migration.
#
# `SchemaVersion::TARGET` is 28 on both trees and no file under
# `src/**/Migrations/` moved, so there is no table to swap, no failure to
# inject and no resume to measure. What has to be proved instead is that
# replacing the files costs NOTHING, which is a different list:
#
#  - the schema stays 28 and the gate runs no step. «هیچ migration ای» is a
#    claim about the database, so it is read FROM the database;
#  - a product the OLD build took in, through its own `importBatch()`, is
#    still there afterwards — the same row id, the same run id, the same
#    ownership;
#  - the WooCommerce product it points at is untouched down to every field §9
#    names: id, address, status, author, price, stock, images, variations and
#    its link to an EARLIER ORDER. One digest, measured before and after;
#  - what this round ADDS answers after the swap: the one product list with
#    its two new facets, and the retired intake address redirecting rather
#    than saying «you are not allowed»;
#  - acceptance refuses a shop-side change nobody saw, by name, and the
#    explicit confirm after a refresh goes through (§2-ج, in the upgrade path
#    rather than only on the bytes);
#  - going BACK to `alpha.55` needs no database work, and what it COSTS is
#    measured rather than asserted: the old intake screen answers again and
#    the one list loses its facets;
#  - forward again runs nothing twice.
#
# The gate runs on an ADMIN REQUEST, not on an activation hook — replacing
# files is what an upload does, and it fires no activation hook at all.
#
#   PHPBIN=php WPCLI=/usr/local/bin/wp \
#     bash tools/upgrade-alpha55-check.sh docs/evidence/upgrade-alpha56

OUT="${1:-docs/evidence/upgrade-alpha56}"
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
BASES="${TMC_BASES:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.55.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha55-check.txt"
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
# target comes out of the BASE's own ZIP because `NEW − 1` is a guess about
# the base (`alpha.38`).
zip_schema() {
  unzip -p "$1" 'tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php' \
    | sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' | head -1
}

NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
NEW_EXPECT="$(zip_version "$NEW_ZIP")"
REVIEW_SLUG="$(sed -n "s/.*const SLUG = '\([a-z-]*\)'.*/\1/p" "$REPO/src/Modules/Product/Presentation/Admin/ProductReviewPage.php" | head -1)"
INTAKE_SLUG="$(sed -n "s/.*const SLUG = '\([a-z-]*\)'.*/\1/p" "$REPO/src/Modules/Migration/Presentation/Admin/ProductIntakePage.php" | head -1)"
# The needles are READ out of the view rather than typed twice: the same word
# written in two files is not the same bytes (combining hamza, ZWNJ) and three
# checks once reported «absent» about pages that were perfectly correct
# (`alpha.23`).
SOURCE_LABEL="$(sed -n "s/.*__('\(منبع محصول\)', 'tecteb-marketplace-core').*/\1/p" \
  "$REPO/src/Modules/Product/Presentation/Admin/ProductCatalogueView.php" | head -1)"
MANAGE_LABEL="$(sed -n "s/.*__('\(وضعیت مدیریت بازارگاه\)', 'tecteb-marketplace-core').*/\1/p" \
  "$REPO/src/Modules/Product/Presentation/Admin/ProductCatalogueView.php" | head -1)"
say "new package: $NEW_EXPECT (target schema $NEW_SCHEMA) · NO migration this round"
say "pages: $REVIEW_SLUG (the one list) · $INTAKE_SLUG (retired)"
[ -n "$NEW_EXPECT" ] && [ -n "$NEW_SCHEMA" ] && [ -n "$REVIEW_SLUG" ] && [ -n "$INTAKE_SLUG" ] \
  && [ -n "$SOURCE_LABEL" ] && [ -n "$MANAGE_LABEL" ] \
  || { say "FAIL: could not read a version, a schema number, a page slug or a facet label"; exit 2; }

PREFIX="$(wpx eval 'global $wpdb; echo $wpdb->prefix;')"
PRODUCTS="${PREFIX}tmc_products"
# How many migration steps the chain holds, read from the tree rather than
# typed: «no migration this round» is the statement that this number is the
# same on both packages.
steps_in_zip() {
  unzip -l "$1" 'tecteb-marketplace-core/src/**/Migrations/M*.php' 2>/dev/null | grep -c 'M[0-9]\{4\}'
}

state() { cp "$REPO/tools/upgrade-alpha55-state.php" "$WPROOT/"; wpx eval-file "$WPROOT/upgrade-alpha55-state.php" "$@" 2>&1 | grep -v 'sendmail'; }
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
admin_page() {   # slug -> status code, body into $3
  curl -s -o "${3:-/dev/null}" -w '%{http_code}' -b "$1" --max-time 120 \
       "$SITE/wp-admin/admin.php?page=$2"
}
admin_page_nofollow() {
  curl -s -o /dev/null -w '%{http_code}' -b "$1" --max-time 120 \
       "$SITE/wp-admin/admin.php?page=$2"
}
body_has() { grep -qF "$2" "$1" && echo yes || echo no; }

# However this ends, the site is left on the NEW build and CONSISTENT, not
# merely on the new files (`alpha.38`'s rule about a fixture that spends
# something).
restore_new() {
  install_zip "$NEW_ZIP"
  wpx option update tmc_schema_version "$NEW_SCHEMA" >/dev/null 2>&1
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  state reset >/dev/null 2>&1
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

  # ---- stage 0: the claim about migrations, from the packages -------------
  check "0 the two packages hold the same number of migration steps" \
    "$(steps_in_zip "$BASE_ZIP")" "$(steps_in_zip "$NEW_ZIP")"
  check "0b and the same schema target, so the upgrade is not structural" \
    "$BASE_SCHEMA" "$NEW_SCHEMA"

  # ---- stage 1: a site genuinely on the old build ------------------------
  install_zip "$NEW_ZIP"; state reset >/dev/null 2>&1
  install_zip "$BASE_ZIP"
  # Walk the version back one step so the gate has something to do, then let
  # an admin request finish it: that proves the old install is COMPLETE rather
  # than merely carrying the right number in an option.
  wpx option update tmc_schema_version "$((BASE_SCHEMA - 1))" >/dev/null
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  check "1 the install is $OLD_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "1b and the gate finishes it on one admin request" "$(admin_request "$JAR")" "200"
  check "1c leaving the old build on schema $BASE_SCHEMA" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "1d with nothing recorded as failed" "$(wpx option get tmc_migration_last_error 2>/dev/null)" ""

  # ---- stage 2: an intake the OLD build really did -----------------------
  state reset >/dev/null 2>&1
  ARRANGED="$(state arrange)"; say "  $(echo "$ARRANGED" | tr '\n' ' ')"
  WC_ID="$(field_of "$ARRANGED" wc)"
  WC_SIMPLE="$(field_of "$ARRANGED" wc_simple)"
  ORDER_ID="$(field_of "$ARRANGED" order)"
  [ -n "$WC_ID" ] && [ "$WC_ID" != "0" ] && [ -n "$WC_SIMPLE" ] && [ "$WC_SIMPLE" != "0" ] \
    || { say "FAIL: the fixture did not arrange on the old build — every later check would be void"; exit 2; }
  check "2 the old build sees a variable product with its two variations" \
    "$(field_of "$ARRANGED" variations)" "2"
  check "2b and an earlier order pointing at it" \
    "$([ -n "$ORDER_ID" ] && [ "$ORDER_ID" != "__none__" ] && echo yes || echo no)" "yes"
  INTAKEN="$(state intake)"; say "  $(echo "$INTAKEN" | tr '\n' ' ')"
  OLD_RUN="$(field_of "$INTAKEN" run)"
  check "2c the OLD build's own importBatch() took both products in" \
    "$(field_of "$INTAKEN" imported)" "2"
  ROW_BEFORE="$(state row)"
  ROW_ID="$(field_of "$ROW_BEFORE" id)"
  check "2d which created a marketplace row" \
    "$([ -n "$ROW_ID" ] && [ "$ROW_ID" != "__none__" ] && echo yes || echo no)" "yes"
  check "2e observed, not managed — being listed is not being run" \
    "$(field_of "$ROW_BEFORE" link_ownership)" "observed"
  check "2f stamped with the run that created it" \
    "$(field_of "$ROW_BEFORE" import_run_id)" "$OLD_RUN"
  DIGEST_BEFORE="$(state digest)"; say "  $(echo "$DIGEST_BEFORE" | tr '\n' ' ')"
  IDENTITY="$(field_of "$DIGEST_BEFORE" identity)"
  check "2g and the WooCommerce product is readable in full" \
    "$([ -n "$IDENTITY" ] && echo yes || echo no)" "yes"
  check "2h with the earlier order's item still pointing at it" \
    "$(field_of "$DIGEST_BEFORE" order_items_linked)" "1"

  # ---- stage 3: the upgrade — replace the files --------------------------
  install_zip "$NEW_ZIP"
  check "3 the files are now $NEW_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"
  check "3b replacing files runs nothing by itself" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "3c the first admin request answers 200" "$(admin_request "$JAR")" "200"
  check "3d and the schema is STILL $NEW_SCHEMA — no step ran" \
    "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "3e with nothing recorded as failed" "$(wpx option get tmc_migration_last_error 2>/dev/null)" ""
  DIGEST_AFTER="$(state digest)"
  check "3f the WooCommerce product is byte-for-byte the one the old build had" \
    "$(field_of "$DIGEST_AFTER" identity)" "$IDENTITY"
  for f in wc slug status author price stock thumb gallery variations order order_items_linked; do
    check "3g $f survived the swap" "$(field_of "$DIGEST_AFTER" "$f")" "$(field_of "$DIGEST_BEFORE" "$f")"
  done
  ROW_AFTER="$(state row)"
  check "3h the marketplace row is the same row" "$(field_of "$ROW_AFTER" id)" "$ROW_ID"
  check "3i still carrying the old build's run id" \
    "$(field_of "$ROW_AFTER" import_run_id)" "$OLD_RUN"
  check "3j and still observed — the upgrade took nothing over" \
    "$(field_of "$ROW_AFTER" link_ownership)" "observed"

  # ---- stage 4: what the new build ADDS, after the swap ------------------
  BODY="$(mktemp)"
  check "4 the one product list answers" "$(admin_page "$JAR" "$REVIEW_SLUG" "$BODY")" "200"
  check "4b and carries the source facet" "$(body_has "$BODY" "$SOURCE_LABEL")" "yes"
  check "4c and the management facet, which is not the publish status" \
    "$(body_has "$BODY" "$MANAGE_LABEL")" "yes"
  check "4d the retired intake address redirects rather than refusing" \
    "$(admin_page_nofollow "$JAR" "$INTAKE_SLUG")" "302"
  check "4e and the health page still answers" "$(admin_page "$JAR" "tmc-health" /dev/null)" "200"
  check "4f as does the handover page" "$(admin_page "$JAR" "tmc-handover" /dev/null)" "200"

  # ---- stage 5: acceptance refuses a change nobody saw -------------------
  ACCEPT="$(state accept)"; say "  $(echo "$ACCEPT" | tr '\n' ' ')"
  check "5 the acceptance call carries «what the page showed»" \
    "$(field_of "$ACCEPT" seen_shop_parameter)" "present"
  check "5b a form without it is REFUSED, not trusted" \
    "$(field_of "$ACCEPT" stale_code)" "storefront_moved"
  check "5c and the refusal names the field" "$(field_of "$ACCEPT" stale_fields)" "قیمت"
  check "5d ownership did not move" "$(field_of "$ACCEPT" ownership_after_refusal)" "observed"
  check "5e refreshing the snapshot is a named success" \
    "$(field_of "$ACCEPT" refresh_code)" "intake_refreshed"
  check "5f after which the explicit confirm goes through" \
    "$(field_of "$ACCEPT" fresh_code)" "ownership_taken"
  check "5g and the product is managed here" "$(field_of "$ACCEPT" ownership_after)" "marketplace"
  ROW_ACCEPTED="$(state row)"
  check "5h the row adopted the SHOP's newer price rather than overwriting it" \
    "$(field_of "$ROW_ACCEPTED" simple_price)" "470000"
  check "5i and the variable product beside it was not touched" \
    "$(field_of "$ROW_ACCEPTED" link_ownership)" "observed"
  check "5j with the WooCommerce side still identical" \
    "$(field_of "$(state digest)" identity)" "$IDENTITY"

  # ---- stage 6: the way back --------------------------------------------
  install_zip "$BASE_ZIP"
  check "6 the files are $OLD_EXPECT again" \
    "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "6b the old build serves wp-admin" "$(admin_request "$JAR")" "200"
  check "6c the schema is untouched at $NEW_SCHEMA — going back needs no database work" \
    "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "6d with nothing recorded as failed" "$(wpx option get tmc_migration_last_error 2>/dev/null)" ""
  DIGEST_BACK="$(state digest)"
  check "6e and the WooCommerce product is still the same one" \
    "$(field_of "$DIGEST_BACK" identity)" "$IDENTITY"
  ROW_BACK="$(state row)"
  check "6f the row is still there, with its run id" \
    "$(field_of "$ROW_BACK" import_run_id)" "$OLD_RUN"
  check "6g and the product accepted on the new build stays accepted" \
    "$(field_of "$ROW_BACK" simple_ownership)" "marketplace"
  # WHAT IT COSTS, measured rather than asserted.
  BODY_BACK="$(mktemp)"
  check "6h the one list still answers on the old build" \
    "$(admin_page "$JAR" "$REVIEW_SLUG" "$BODY_BACK")" "200"
  check "6i but without the source facet — the capability went back, not the data" \
    "$(body_has "$BODY_BACK" "$SOURCE_LABEL")" "no"
  check "6j and the retired screen is a screen again" \
    "$(admin_page_nofollow "$JAR" "$INTAKE_SLUG")" "200"

  # ---- stage 7: forward again, nothing twice ----------------------------
  install_zip "$NEW_ZIP"
  check "7 forward again answers 200" "$(admin_request "$JAR")" "200"
  check "7b the schema is still $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "7c nothing was recorded as failed" "$(wpx option get tmc_migration_last_error 2>/dev/null)" ""
  check "7d exactly one marketplace row still points at the variable product" \
    "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE wc_product_id = $WC_ID")" "1"
  check "7e and one at the simple product" \
    "$(dbq "SELECT COUNT(*) FROM \`$PRODUCTS\` WHERE wc_product_id = $WC_SIMPLE")" "1"
  check "7f the WooCommerce product is unchanged after all seven stages" \
    "$(field_of "$(state digest)" identity)" "$IDENTITY"
  check "7g and the retired address redirects again" \
    "$(admin_page_nofollow "$JAR" "$INTAKE_SLUG")" "302"
done

say ""
say "PASS=$pass FAIL=$fail"
[ "$fail" -eq 0 ]
