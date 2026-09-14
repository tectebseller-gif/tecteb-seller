#!/usr/bin/env bash
# Rehearses the schema 1 → 2 upgrade and the way back, on a DISPOSABLE
# WordPress with synthetic data. Nothing here touches any real site.
#
#   tools/upgrade-rollback-check.sh <php-binary> <evidence-dir> <old-zip> <new-zip>
#
# What it proves, stage by stage (each stage writes its own evidence file):
#   1  a genuine schema-1 site (the previous package, vendor tables absent)
#   2  the documented upgrade: deactivate → replace → activate
#   3  real vendor data, written by the plugin's own services
#   4  the way back to the previous package, with the data still there
#   5  forward again: the same data, still readable
#   6  the file-swap upgrade WordPress performs when a ZIP is uploaded over a
#      running plugin — no activation hook, so the rewrite rules need a flush
#
# The site is left on the new package. The starting database is dumped first,
# so the run is undoable.
set -o pipefail

PHPBIN="${1:?php binary}"; EV="${2:?evidence dir}"; OLD="$(readlink -f "${3:?old zip}")"; NEW="$(readlink -f "${4:?new zip}")"
[ -f "$OLD" ] || { echo "no such package: $3" >&2; exit 2; }
[ -f "$NEW" ] || { echo "no such package: $4" >&2; exit 2; }
WPROOT="${WPROOT:-/home/user/wp-disposable}"
SITE="${SITE:-http://127.0.0.1:8080}"
DB="${DB:-tmc_wp_test}"
SOCK="${SOCK:-/run/mysqld/mysqld.sock}"
PLUGDIR="$WPROOT/wp-content/plugins/tecteb-marketplace-core"
SCRATCH="${SCRATCH:-/tmp/claude-0/upgrade-rehearsal}"

EV="$(mkdir -p "$EV" && readlink -f "$EV")"
mkdir -p "$SCRATCH"
wpx()  { "$PHPBIN" /usr/local/bin/wp --allow-root --path="$WPROOT" "$@" 2>/dev/null; }
dbq()  { mariadb --socket="$SOCK" -uroot --default-character-set=utf8mb4 "$DB" -N -B -e "$1"; }
code() { curl -s -o "$2" -w '%{http_code}' -b "$3" --max-time 20 "$1"; }

fail() { echo "FAIL: $*" | tee -a "$EV/summary.txt"; FAILED=$((FAILED+1)); }
pass() { echo "ok:   $*" | tee -a "$EV/summary.txt"; }
check(){ [ "$2" = "$3" ] && pass "$1 ($2)" || fail "$1: expected [$3], got [$2]"; }
FAILED=0

install_pkg() {  # <zip> — a failed unpack must stop the run, not silently
  rm -rf "$PLUGDIR"                        # skip the next dozen meaningless checks
  ( cd "$WPROOT/wp-content/plugins" && unzip -q -o "$1" ) || { echo "cannot unpack $1" >&2; exit 3; }
  [ -f "$PLUGDIR/tecteb-marketplace-core.php" ] || { echo "package did not unpack to $PLUGDIR" >&2; exit 3; }
}

snapshot() {     # <file> — everything this plugin owns, in one comparable text
  {
    echo "## plugin"
    wpx plugin get tecteb-marketplace-core --field=version
    echo "## schema_option"
    wpx option get tmc_schema_version
    echo "## options"
    dbq "SELECT option_name FROM wp_options WHERE option_name LIKE 'tmc\\_%' ORDER BY option_name"
    echo "## settings_value"
    wpx option get tmc_settings --format=json
    echo "## tables"
    dbq "SHOW TABLES LIKE 'wp_tmc%'"
    echo "## row_counts"
    for t in $(dbq "SHOW TABLES LIKE 'wp_tmc%'"); do
      echo "$t=$(dbq "SELECT COUNT(*) FROM \`$t\`")"
    done
    echo "## audit_rows"
    dbq "SELECT CONCAT_WS('|', id, event_type, object_type, created_at) FROM wp_tmc_audit_events ORDER BY id"
    echo "## vendor_applications"
    dbq "SELECT CONCAT_WS('|', id, user_id, status, store_name, contact_mobile) FROM wp_tmc_vendor_applications ORDER BY id" 2>/dev/null
    echo "## vendor_documents"
    dbq "SELECT CONCAT_WS('|', id, application_id, type_slug, original_name, size_bytes) FROM wp_tmc_vendor_documents ORDER BY id" 2>/dev/null
    echo "## vendor_document_types"
    dbq "SELECT CONCAT_WS('|', id, slug, label, required) FROM wp_tmc_vendor_document_types ORDER BY id" 2>/dev/null
    echo "## vendor_profiles"
    dbq "SELECT CONCAT_WS('|', id, user_id, store_name, can_sell, can_publish_directly) FROM wp_tmc_vendor_profiles ORDER BY id" 2>/dev/null
    echo "## admin_caps"
    wpx user meta get 1 wp_capabilities --format=json | tr ',' '\n' | grep tmc_ | sort
    echo "## private_files"
    find "$WPROOT/wp-content/uploads/tmc-private" -type f | sed "s|$WPROOT||" | sort
  } > "$1" 2>&1
}

vendor_area() {  # <file> — "vendor-dashboard" | "absent"
  code "$SITE/vendor/" "$1" "$SCRATCH/seller.jar" > /dev/null
  grep -q 'پیشخوان فروشنده' "$1" && echo "vendor-dashboard" || echo "absent"
}

pages() {        # <prefix> <cookie-jar> — the four phase-1 admin screens
  for p in tmc-dashboard tmc-health tmc-settings tmc-modules; do
    echo "$p=$(code "$SITE/wp-admin/admin.php?page=$p" "$EV/$1-$p.html" "$2")"
  done
}

# ---------------------------------------------------------------- stage 0
{ echo "generated_at   = $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "site           = $SITE  (disposable, synthetic data only)"
  echo "wordpress      = $(wpx core version)"
  echo "woocommerce    = $(wpx plugin get woocommerce --field=version)"
  echo "php_cli        = $("$PHPBIN" -r 'echo PHP_VERSION;')"
  echo "mariadb        = $(mariadb --socket=$SOCK -uroot -N -B -e 'SELECT VERSION()')"
  echo "old_package    = $(basename "$OLD")  $(sha256sum "$OLD" | cut -d' ' -f1)"
  echo "new_package    = $(basename "$NEW")  $(sha256sum "$NEW" | cut -d' ' -f1)"
} > "$EV/00-environment.txt"
: > "$EV/summary.txt"

mariadb-dump --socket="$SOCK" -uroot "$DB" > "$SCRATCH/before-rehearsal.sql" 2>/dev/null
echo "db_dump        = $SCRATCH/before-rehearsal.sql ($(wc -c < "$SCRATCH/before-rehearsal.sql") bytes)" >> "$EV/00-environment.txt"

# an applicant that is an ordinary customer, nothing more
VENDOR_ID=$(wpx user get tmc_seller --field=ID)
if [ -z "$VENDOR_ID" ]; then
  VENDOR_ID=$(wpx user create tmc_seller seller@example.test --role=customer --user_pass='TmcSeller!2026' --porcelain)
else
  wpx user update "$VENDOR_ID" --user_pass='TmcSeller!2026' >/dev/null   # a run must not depend on an earlier one
fi
echo "vendor_user_id = $VENDOR_ID" >> "$EV/00-environment.txt"

curl -s -c "$SCRATCH/admin.jar" -o /dev/null --data-urlencode "log=tmcadmin" --data-urlencode "pwd@/root/.wp_pass" \
     -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
curl -s -c "$SCRATCH/seller.jar" -o /dev/null --data-urlencode "log=tmc_seller" --data-urlencode "pwd=TmcSeller!2026" \
     -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
check "admin session"  "$(code "$SITE/wp-admin/profile.php" /dev/null "$SCRATCH/admin.jar")"  "200"
# Not profile.php: WooCommerce sends customers away from wp-admin, so a 302
# there would say nothing about the login. The cookie is the evidence.
check "seller session" "$(grep -c wordpress_logged_in "$SCRATCH/seller.jar")" "1"

# ---------------------------------------------------------------- stage 1
# Reconstruct an honest schema-1 site: the previous package, and a database
# that has never seen the vendor tables.
echo; echo "===== stage 1: the site as it is before the upgrade ====="
wpx plugin deactivate tecteb-marketplace-core >/dev/null
install_pkg "$OLD"
for t in wp_tmc_vendor_documents wp_tmc_vendor_document_types wp_tmc_vendor_profiles wp_tmc_vendor_applications; do
  dbq "DROP TABLE IF EXISTS \`$t\`"
done
wpx option delete tmc_vendor_documents_none >/dev/null
wpx cap remove administrator tmc_review_vendor tmc_manage_vendor_documents >/dev/null
wpx option update tmc_schema_version 1 >/dev/null
wpx plugin activate tecteb-marketplace-core >/dev/null
# a non-default settings value, so "preserved" means something
wpx option patch update tmc_settings default_commission_rate_bp 1234 >/dev/null
wpx option patch update tmc_settings settlement_delay_days 9 >/dev/null
snapshot "$EV/01-before-upgrade.txt"
check "stage 1 version" "$(wpx plugin get tecteb-marketplace-core --field=version)" "0.1.0-alpha.2"
check "stage 1 schema"  "$(wpx option get tmc_schema_version)" "1"
check "stage 1 vendor tables absent" "$(dbq "SHOW TABLES LIKE 'wp_tmc_vendor%'" | wc -l)" "0"
{ pages 01 "$SCRATCH/admin.jar"; echo "vendor_area=$(vendor_area "$EV/01-vendor.html")"; } > "$EV/01-http.txt"
check "stage 1 admin pages 200" "$(grep -c '=200' "$EV/01-http.txt")" "4"
check "stage 1 no vendor area"  "$(grep -o 'vendor_area=.*' "$EV/01-http.txt")" "vendor_area=absent"

# ---------------------------------------------------------------- stage 2
echo; echo "===== stage 2: the documented upgrade ====="
wpx plugin deactivate tecteb-marketplace-core >/dev/null
install_pkg "$NEW"
wpx plugin activate tecteb-marketplace-core >/dev/null
snapshot "$EV/02-after-upgrade.txt"
diff -u "$EV/01-before-upgrade.txt" "$EV/02-after-upgrade.txt" > "$EV/03-diff-upgrade.txt"
check "stage 2 version" "$(wpx plugin get tecteb-marketplace-core --field=version)" "0.1.0-alpha.3"
check "stage 2 schema"  "$(wpx option get tmc_schema_version)" "2"
check "stage 2 vendor tables created" "$(dbq "SHOW TABLES LIKE 'wp_tmc_vendor%'" | wc -l)" "4"
check "stage 2 commission preserved" "$(wpx option get tmc_settings --format=json | grep -o '"default_commission_rate_bp":[0-9]*')" '"default_commission_rate_bp":1234'
check "stage 2 no audit row lost" \
  "$(comm -23 <(grep -A999 '## audit_rows' "$EV/01-before-upgrade.txt" | sed -n '2,/^## /p' | grep '|' | sort) \
              <(grep -A999 '## audit_rows' "$EV/02-after-upgrade.txt" | sed -n '2,/^## /p' | grep '|' | sort) | wc -l)" "0"
{ pages 02 "$SCRATCH/admin.jar"; echo "vendor_area=$(vendor_area "$EV/02-vendor.html")"; } > "$EV/02-http.txt"
check "stage 2 admin pages 200" "$(grep -c '=200' "$EV/02-http.txt")" "4"
check "stage 2 vendor area live" "$(grep -o 'vendor_area=.*' "$EV/02-http.txt")" "vendor_area=vendor-dashboard"

# ---------------------------------------------------------------- stage 3
echo; echo "===== stage 3: real vendor data, written by the plugin itself ====="
wpx eval-file /home/user/tecteb-seller/tools/upgrade-seed.php 1 "$VENDOR_ID" > "$EV/04-seed.txt" 2>&1
cat "$EV/04-seed.txt"
check "stage 3 seed complete" "$(grep -c '^seed=complete' "$EV/04-seed.txt")" "1"
snapshot "$EV/05-with-vendor-data.txt"
check "stage 3 application stored" "$(dbq "SELECT COUNT(*) FROM wp_tmc_vendor_applications")" "1"
check "stage 3 document stored"    "$(dbq "SELECT COUNT(*) FROM wp_tmc_vendor_documents")" "1"
PRIVATE_FILES=$(find "$WPROOT/wp-content/uploads/tmc-private" -name '*.pdf' | wc -l | tr -d ' ')
echo "private_pdf_files=$PRIVATE_FILES" >> "$EV/04-seed.txt"

# ---------------------------------------------------------------- stage 4
echo; echo "===== stage 4: back to the previous package ====="
wpx plugin deactivate tecteb-marketplace-core >/dev/null
install_pkg "$OLD"
wpx plugin activate tecteb-marketplace-core >/dev/null
snapshot "$EV/06-after-rollback.txt"
diff -u "$EV/05-with-vendor-data.txt" "$EV/06-after-rollback.txt" > "$EV/07-diff-rollback.txt"
check "stage 4 version" "$(wpx plugin get tecteb-marketplace-core --field=version)" "0.1.0-alpha.2"
check "stage 4 schema untouched"       "$(wpx option get tmc_schema_version)" "2"
check "stage 4 vendor tables kept"     "$(dbq "SHOW TABLES LIKE 'wp_tmc_vendor%'" | wc -l)" "4"
check "stage 4 application kept"       "$(dbq "SELECT COUNT(*) FROM wp_tmc_vendor_applications")" "1"
check "stage 4 document row kept"      "$(dbq "SELECT COUNT(*) FROM wp_tmc_vendor_documents")" "1"
check "stage 4 private file kept"      "$(find "$WPROOT/wp-content/uploads/tmc-private" -name '*.pdf' | wc -l | tr -d ' ')" "$PRIVATE_FILES"
{ pages 06 "$SCRATCH/admin.jar"; echo "vendor_area=$(vendor_area "$EV/06-vendor.html")"; } > "$EV/06-http.txt"
check "stage 4 admin pages 200" "$(grep -c '=200' "$EV/06-http.txt")" "4"
check "stage 4 vendor area gone" "$(grep -o 'vendor_area=.*' "$EV/06-http.txt")" "vendor_area=absent"
# the health page must say, in Persian, that the database is ahead of this build
grep -o 'نسخه ساختار داده[^<]*' "$EV/06-tmc-health.html" | head -3 > "$EV/08-health-ahead-message.txt"
check "stage 4 health warns 'ahead'" "$(grep -c 'نسخه ساختار داده' "$EV/08-health-ahead-message.txt")" "1"

# ---------------------------------------------------------------- stage 5
echo; echo "===== stage 5: forward again, same data ====="
wpx plugin deactivate tecteb-marketplace-core >/dev/null
install_pkg "$NEW"
wpx plugin activate tecteb-marketplace-core >/dev/null
snapshot "$EV/09-after-reupgrade.txt"
check "stage 5 application still there" "$(dbq "SELECT COUNT(*) FROM wp_tmc_vendor_applications")" "1"
STORE=$(dbq "SELECT store_name FROM wp_tmc_vendor_applications LIMIT 1")
echo "vendor_area=$(vendor_area "$EV/09-vendor.html")" > "$EV/09-http.txt"
check "stage 5 vendor area live" "$(grep -o 'vendor_area=.*' "$EV/09-http.txt")" "vendor_area=vendor-dashboard"
# the store name is synthetic and non-empty; an empty needle would match anything
check "stage 5 dashboard shows the stored application" \
  "$([ -n "$STORE" ] && grep -qF "$STORE" "$EV/09-vendor.html" && echo yes || echo no)" "yes"

# ---------------------------------------------------------------- stage 6
echo; echo "===== stage 6: files replaced under a running plugin ====="
# What WordPress does for "upload and replace": no deactivation, no activation
# hook — so the migration must come from the upgrade gate, and the /vendor/
# rewrite rules are NOT flushed.
wpx plugin deactivate tecteb-marketplace-core >/dev/null
install_pkg "$OLD"
wpx plugin activate tecteb-marketplace-core >/dev/null
# A real site on the previous package has never had a /vendor/ rule. The
# deactivation above regenerated the rules while the NEW plugin was still
# loaded, so they must be regenerated once more with only the old one active —
# otherwise this stage would test a rule set no real site ever has.
wpx rewrite flush >/dev/null
for t in wp_tmc_vendor_documents wp_tmc_vendor_document_types wp_tmc_vendor_profiles wp_tmc_vendor_applications; do
  dbq "DROP TABLE IF EXISTS \`$t\`"
done
wpx option update tmc_schema_version 1 >/dev/null
install_pkg "$NEW"                                          # swap files only
{ echo "before_request_schema=$(wpx option get tmc_schema_version)"
  echo "admin_request=$(code "$SITE/wp-admin/admin.php?page=tmc-dashboard" "$EV/10-inplace-dashboard.html" "$SCRATCH/admin.jar")"
  echo "after_request_schema=$(wpx option get tmc_schema_version)"
  echo "vendor_before_flush=$(vendor_area "$EV/10-vendor-before-flush.html")"
} > "$EV/10-inplace-upgrade.txt"
wpx rewrite flush >/dev/null                                # = Settings › Permalinks › Save
echo "vendor_after_flush=$(vendor_area "$EV/10-vendor-after-flush.html")" >> "$EV/10-inplace-upgrade.txt"
cat "$EV/10-inplace-upgrade.txt"
check "stage 6 migration ran without activation" "$(grep -o 'after_request_schema=[0-9]*' "$EV/10-inplace-upgrade.txt")" "after_request_schema=2"
check "stage 6 /vendor/ needs a flush"           "$(grep -o 'vendor_before_flush=.*' "$EV/10-inplace-upgrade.txt")" "vendor_before_flush=absent"
check "stage 6 /vendor/ answers after flush"     "$(grep -o 'vendor_after_flush=.*' "$EV/10-inplace-upgrade.txt")" "vendor_after_flush=vendor-dashboard"

# ------------------------------------------------------------------ end
wpx plugin deactivate tecteb-marketplace-core >/dev/null
install_pkg "$NEW"
wpx plugin activate tecteb-marketplace-core >/dev/null
snapshot "$EV/11-final-state.txt"
echo; echo "checks failed: $FAILED" | tee -a "$EV/summary.txt"
exit $(( FAILED > 0 ))
