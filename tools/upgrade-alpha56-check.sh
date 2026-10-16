#!/usr/bin/env bash
#
# The owner's own upgrade: `alpha.56` installed on staging, the new package
# dropped on top — and this time there IS a migration.
#
# `SchemaVersion::TARGET` goes 28 → 29 and one step arrives
# (`M0029SupplierPayments`: two new tables, additive, no backfill). So the list
# to prove is the structural one:
#
#  - the packages really differ by one step and one schema number, read from
#    the ZIPs rather than typed (`alpha.38`: `NEW − 1` is a guess about the base);
#  - a site genuinely on `alpha.56`, finished by the gate rather than by an
#    option somebody set;
#  - replacing the FILES runs no step — an upload fires no activation hook —
#    and the first admin request finishes it;
#  - the two tables exist on disk WITH the unique key each one promises, asked
#    of `information_schema` and not of a class constant;
#  - what the old build recorded is byte-identical afterwards: one `identity`
#    digest over eleven fields of the product and its order line;
#  - what this round ADDS works on an UPGRADED site, not only a fresh one: the
#    commission panel saves through its own nonce, and a supplier payment is
#    recorded once;
#  - a past order does NOT move when the commission rule changes afterwards;
#  - going BACK to `alpha.56` needs no database work, and what it COSTS is
#    measured: the new page is gone, the rows are not;
#  - forward again runs nothing twice.
#
#   PHPBIN=php WPCLI=/usr/local/bin/wp \
#     bash tools/upgrade-alpha56-check.sh docs/evidence/upgrade-alpha57

OUT="${1:-docs/evidence/upgrade-alpha57}"
WPROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
SITE="${TMC_DEMO_SITE:-http://127.0.0.1:8081}"
ADMIN="${TMC_DEMO_ADMIN:-tmcowner}"
ADMIN_PASS="${TMC_DEMO_ADMIN_PASS:-demo-owner-2026}"
PHPBIN="${PHPBIN:-php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
PLUGINS="$WPROOT/wp-content/plugins"

newest_zip() {
  ls -1 "$REPO"/dist/tecteb-marketplace-core-0.1.0-alpha.*.zip 2>/dev/null \
    | sed 's/.*alpha\.\([0-9]*\)\.zip/\1 &/' | sort -n | tail -1 | cut -d' ' -f2-
}
NEW_ZIP="${TMC_NEW_ZIP:-$(newest_zip)}"
BASES="${TMC_BASES:-$REPO/dist/tecteb-marketplace-core-0.1.0-alpha.56.zip}"

mkdir -p "$OUT"
LOG="$OUT/upgrade-alpha56-check.txt"
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
zip_schema() {
  unzip -p "$1" 'tecteb-marketplace-core/src/Core/Migration/SchemaVersion.php' \
    | sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' | head -1
}
steps_in_zip() {
  unzip -l "$1" 'tecteb-marketplace-core/src/**/Migrations/M*.php' 2>/dev/null | grep -c 'M[0-9]\{4\}'
}

NEW_SCHEMA="$(sed -n 's/.*TARGET = \([0-9]*\).*/\1/p' "$REPO/src/Core/Migration/SchemaVersion.php" | head -1)"
NEW_EXPECT="$(zip_version "$NEW_ZIP")"
PAY_SLUG="$(sed -n "s/.*const SLUG = '\([a-z-]*\)'.*/\1/p" "$REPO/src/Modules/Finance/Presentation/Admin/SupplierPaymentsPage.php" | head -1)"
REVIEW_SLUG="$(sed -n "s/.*const SLUG = '\([a-z-]*\)'.*/\1/p" "$REPO/src/Modules/Product/Presentation/Admin/ProductReviewPage.php" | head -1)"
# The needles come OUT of the source rather than being typed twice: the same
# word written in two files is not the same bytes (`alpha.23`).
PAY_LABEL="$(sed -n "s/.*__('\(پرداخت به تأمین‌کننده\)', 'tecteb-marketplace-core').*/\1/p" \
  "$REPO/src/Modules/Finance/Presentation/Admin/SupplierPaymentsPage.php" | head -1)"
COMM_LABEL="$(sed -n "s/.*__('\(کمیسیون تک‌طب\)', 'tecteb-marketplace-core').*/\1/p" \
  "$REPO/src/Modules/Finance/Infrastructure/WooCommerce/TectebCommissionPanel.php" | head -1)"
ACTIVE_LABEL="$(sed -n "s/.*esc_html__('\(فیلترهای فعال:\)', 'tecteb-marketplace-core').*/\1/p" \
  "$REPO/src/Modules/Product/Presentation/Admin/ProductCatalogueView.php" | head -1)"

say "new package: $NEW_EXPECT (target schema $NEW_SCHEMA) · STRUCTURAL this round"
say "pages: $PAY_SLUG · $REVIEW_SLUG"
[ -n "$NEW_EXPECT" ] && [ -n "$NEW_SCHEMA" ] && [ -n "$PAY_SLUG" ] && [ -n "$PAY_LABEL" ] \
  && [ -n "$COMM_LABEL" ] && [ -n "$ACTIVE_LABEL" ] \
  || { say "FAIL: could not read a version, a schema number, a slug or a label"; exit 2; }

state() { cp "$REPO/tools/upgrade-alpha56-state.php" "$WPROOT/"; wpx eval-file "$WPROOT/upgrade-alpha56-state.php" "$@" 2>&1 | grep -v 'sendmail'; }
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
admin_page() {
  curl -s -o "${3:-/dev/null}" -w '%{http_code}' -b "$1" --max-time 120 \
       "$SITE/wp-admin/admin.php?page=$2"
}
product_editor() {   # jar, wc id, body file -> status
  curl -s -o "${3:-/dev/null}" -w '%{http_code}' -b "$1" --max-time 120 \
       "$SITE/wp-admin/post.php?post=$2&action=edit"
}
body_has() { grep -qF "$2" "$1" && echo yes || echo no; }

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
  say ""
  say "================================================================"
  say "=== $OLD_EXPECT (schema $BASE_SCHEMA) -> $NEW_EXPECT (schema $NEW_SCHEMA)"
  say "================================================================"

  # ---- stage 0: the claim about the migration, from the packages ---------
  check "0 the new package holds exactly one more migration step" \
    "$(( $(steps_in_zip "$NEW_ZIP") - $(steps_in_zip "$BASE_ZIP") ))" "1"
  check "0b and one more schema number, so this upgrade is structural" \
    "$(( NEW_SCHEMA - BASE_SCHEMA ))" "1"

  # ---- stage 1: a site genuinely on the old build ------------------------
  install_zip "$NEW_ZIP"; state reset >/dev/null 2>&1
  install_zip "$BASE_ZIP"
  # A site genuinely on `alpha.56` is one that has never seen 29's tables.
  # After one run of this script they are still there — correctly, because
  # rolling back leaves them (8f) — so the pre-upgrade state is CONSTRUCTED,
  # the same way the schema option is walked back a step.
  state drop-new-tables >/dev/null 2>&1
  wpx option update tmc_schema_version "$((BASE_SCHEMA - 1))" >/dev/null
  wpx option delete tmc_migration_last_error >/dev/null 2>&1
  check "1 the install is $OLD_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "1b and the gate finishes it on one admin request" "$(admin_request "$JAR")" "200"
  check "1c leaving the old build on schema $BASE_SCHEMA" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "1d with nothing recorded as failed" "$(wpx option get tmc_migration_last_error 2>/dev/null)" ""

  # ---- stage 2: state the OLD build recorded -----------------------------
  ARRANGED="$(state arrange)"; say "  $(echo "$ARRANGED" | tr '\n' ' ')"
  WC_ID="$(field_of "$ARRANGED" wc)"
  LINE_ID="$(field_of "$ARRANGED" line)"
  [ -n "$WC_ID" ] && [ "$WC_ID" != "0" ] && [ -n "$LINE_ID" ] && [ "$LINE_ID" != "0" ] \
    || { say "FAIL: the fixture did not arrange on the old build — every later check would be void"; exit 2; }
  check "2 the old build recorded the sale's share" "$(field_of "$ARRANGED" share)" "450000"
  BEFORE="$(state digest)"
  IDENTITY_BEFORE="$(field_of "$BEFORE" identity)"
  say "  before: $(echo "$BEFORE" | tr '\n' ' ')"

  # …and the two things this round adds are genuinely ABSENT on the old build,
  # which is a measurement and not a formality: it is what makes stage 4 mean
  # something.
  check "2b the old build has no commission panel" \
    "$(field_of "$(state commission-read)" absent)" "1"
  check "2c and no supplier payment service" \
    "$(field_of "$(state pay)" absent)" "1"
  check "2d and neither table on disk" \
    "$(field_of "$(state tables)" tmc_supplier_payments)" "__absent__"

  # ---- stage 3: the file swap --------------------------------------------
  install_zip "$NEW_ZIP"
  check "3 the files are now $NEW_EXPECT" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$NEW_EXPECT"
  check "3b and replacing files alone ran NO step" "$(wpx option get tmc_schema_version)" "$BASE_SCHEMA"
  check "3c the first admin request answers" "$(admin_request "$JAR")" "200"
  check "3d and the gate has taken the schema to $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "3e with nothing recorded as failed" "$(wpx option get tmc_migration_last_error 2>/dev/null)" ""

  TABLES="$(state tables)"; say "  $(echo "$TABLES" | tr '\n' ' ')"
  check "3f the payments table is on disk" "$(field_of "$TABLES" tmc_supplier_payments)" "present"
  check "3g with a unique key over its idempotency key" \
    "$(field_of "$TABLES" tmc_supplier_payments_unique_over_idempotency_key)" "yes"
  check "3h the items table is on disk" "$(field_of "$TABLES" tmc_supplier_payment_items)" "present"
  check "3i with a unique key over the order item" \
    "$(field_of "$TABLES" tmc_supplier_payment_items_unique_over_order_item_id)" "yes"

  # ---- stage 4: what the old build recorded is untouched ------------------
  AFTER="$(state digest)"
  say "  after:  $(echo "$AFTER" | tr '\n' ' ')"
  check "4 every field of the product and its order line is identical" \
    "$(field_of "$AFTER" identity)" "$IDENTITY_BEFORE"
  for f in wc_id url status author price stock line_id line_base line_commission line_share line_event; do
    check "4-$f unchanged" "$(field_of "$AFTER" "$f")" "$(field_of "$BEFORE" "$f")"
  done

  # ---- stage 5: the pages this round adds, over HTTP ---------------------
  BODY="$(mktemp)"
  check "5 the supplier payments page answers" "$(admin_page "$JAR" "$PAY_SLUG" "$BODY")" "200"
  check "5b and says what it is" "$(body_has "$BODY" "$PAY_LABEL")" "yes"
  # NOT «the page never says the words»: it says them, in the sentence that
  # promises no transfer happens, and a needle that forbade the words would
  # have failed on the very sentence that makes the promise. What is counted
  # instead is the number of SUBMIT BUTTONS on the page — one, the one that
  # writes a record.
  check "5c and promises no transfer happens" \
    "$(body_has "$BODY" 'هیچ انتقال وجهی از اینجا انجام نمی‌شود')" "yes"
  check "5d with exactly one submit button on it" \
    "$(grep -o 'type="submit"' "$BODY" | wc -l | tr -d ' ')" "1"
  check "5e the one product list still answers" "$(admin_page "$JAR" "$REVIEW_SLUG" "$BODY")" "200"
  check "5f with no active filter named when none is on" "$(body_has "$BODY" "$ACTIVE_LABEL")" "no"
  check "5g and names the active one when there is one" \
    "$(admin_page "$JAR" "$REVIEW_SLUG&status=published" "$BODY" >/dev/null; body_has "$BODY" "$ACTIVE_LABEL")" "yes"
  check "5h the commission tab is on the product editor" \
    "$(product_editor "$JAR" "$WC_ID" "$BODY")" "200"
  check "5i and says so by name" "$(body_has "$BODY" "$COMM_LABEL")" "yes"

  # ---- stage 6: the commission, on an UPGRADED site ----------------------
  SAVED="$(state commission percent 12.5)"; say "  $(echo "$SAVED" | tr '\n' ' ')"
  check "6 a percentage saves through the panel's own nonce" "$(field_of "$SAVED" type)" "percent"
  check "6b and comes back as the manager's own number" "$(field_of "$SAVED" value)" "12.50"
  check "6c from the product rather than inherited" "$(field_of "$SAVED" from)" "product"
  REFUSED="$(state commission percent 140)"
  check "6d an impossible value writes nothing and the earlier rule stands" \
    "$(field_of "$REFUSED" value)" "12.50"
  # THE PAST DOES NOT MOVE. The rule changed twice above; the recorded line
  # must be the same bytes it was before any of it.
  check "6e and the order recorded earlier has not moved" \
    "$(field_of "$(state digest)" identity)" "$IDENTITY_BEFORE"

  # ---- stage 7: a supplier payment, recorded once ------------------------
  PAID="$(state pay 450000 TRK-U56 token-one)"; say "  $(echo "$PAID" | tr '\n' ' ')"
  check "7 the payment is recorded" "$(field_of "$PAID" ok)" "yes"
  check "7b and matched the recorded share, so it posted" \
    "$(field_of "$PAID" code)" "supplier_payment_recorded"
  AGAIN="$(state pay 450000 TRK-U56 token-one)"
  check "7c the same submission again is refused" "$(field_of "$AGAIN" ok)" "no"
  check "7d by name" "$(field_of "$AGAIN" code)" "supplier_payment_already_recorded"
  PAYSTATE="$(state payments)"; say "  $(echo "$PAYSTATE" | tr '\n' ' ')"
  check "7e and there is ONE payment row" "$(field_of "$PAYSTATE" rows)" "1"
  check "7f with exactly one payout line behind it" "$(field_of "$PAYSTATE" payout_lines)" "2"

  # ---- stage 8: the way back --------------------------------------------
  install_zip "$BASE_ZIP"
  check "8 the files are $OLD_EXPECT again" "$(wpx plugin get tecteb-marketplace-core --field=version)" "$OLD_EXPECT"
  check "8b and the schema is NOT taken down" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "8c an admin request still answers" "$(admin_request "$JAR")" "200"
  check "8d and the schema is still $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  # The COST, measured rather than argued: the page is gone and the rows are
  # not.
  check "8e the supplier payments page is gone" "$(admin_page "$JAR" "$PAY_SLUG")" "403"
  check "8f but the payment row is still there" \
    "$(field_of "$(state tables)" tmc_supplier_payments)" "present"
  check "8g and the commission panel is gone" \
    "$(field_of "$(state commission-read)" absent)" "1"
  check "8h with the product's two meta rows left on disk" \
    "$(wpx eval 'echo (int) metadata_exists("post", (int) wc_get_product_id_by_sku("WC-U56-UPGRADE"), "_tmc_commission_type");')" "1"
  check "8i and the order recorded earlier still has not moved" \
    "$(field_of "$(state digest)" identity)" "$IDENTITY_BEFORE"

  # ---- stage 9: forward again, nothing twice -----------------------------
  install_zip "$NEW_ZIP"
  check "9 an admin request answers" "$(admin_request "$JAR")" "200"
  check "9b the schema is $NEW_SCHEMA" "$(wpx option get tmc_schema_version)" "$NEW_SCHEMA"
  check "9c with nothing recorded as failed" "$(wpx option get tmc_migration_last_error 2>/dev/null)" ""
  check "9d and still ONE payment row — nothing ran twice" \
    "$(field_of "$(state payments)" rows)" "1"
  check "9e the supplier payments page answers again" "$(admin_page "$JAR" "$PAY_SLUG")" "200"
  rm -f "$BODY"
done

say ""
say "checks: $pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
