#!/usr/bin/env bash
# The Dokan migration, measured: a dry run that writes nothing, a trial import
# that changes nothing of Dokan's, and a rollback that leaves no trace.
#
# The owner's instruction is «مهاجرت دکان ابتدا به‌صورت آزمایشی، با تطبیق
# داده‌ها و امکان بازگشت اجرا شود», so the three things under test are exactly
# those three, and the strongest check is the fingerprint: every Dokan user,
# product and order this migration can see, hashed before and after each step.
#
#   tools/dokan-migration-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/dokan-migration}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"

mkdir -p "$EV"
EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
mig() { wp eval-file dokan-migration.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }
fingerprint() { mig fingerprint | field dokan_fingerprint; }
tmc_rows() { wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_products");'; }
tmc_vendors() { wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_vendor_profiles");'; }

{
echo "=== Dokan migration: dry run, trial import, and the way back ==="
wp plugin list --fields=name,status,version
} | tee "$EV/00-header.txt"

echo
echo "--- 0. what Dokan looks like before anything ---"
BEFORE="$(fingerprint)"
ROWS_BEFORE="$(tmc_rows)"
VENDORS_BEFORE="$(tmc_vendors)"
echo "    dokan fingerprint ${BEFORE:0:16}… · tmc products=${ROWS_BEFORE} vendors=${VENDORS_BEFORE}"

echo
echo "--- 1. the dry run writes nothing ---"
PLAN="$(mig plan | tee "$EV/01-plan.txt" | head -1)"
echo "    $PLAN"
check "the plan names a run id"                          yes \
  "$(case "$(echo "$PLAN" | field run)" in dokan-*) echo yes;; *) echo no;; esac)"
check "…and reports it as clean"                         true "$(echo "$PLAN" | field clean)"
check "the dry run wrote no marketplace product"         "$ROWS_BEFORE" "$(tmc_rows)"
check "…and no marketplace vendor"                       "$VENDORS_BEFORE" "$(tmc_vendors)"
check "…and Dokan is byte-for-byte what it was"          "$BEFORE" "$(fingerprint)"

echo
echo "--- 2. the trial import creates OUR rows and only ours ---"
IMPORT="$(mig import | tee "$EV/02-import.txt")"
echo "    $IMPORT"
RUN_ID="$(echo "$IMPORT" | field run)"
IMPORTED_PRODUCTS="$(echo "$IMPORT" | field products)"
IMPORTED_VENDORS="$(echo "$IMPORT" | field vendors)"
check "the import reports success"                       true "$(echo "$IMPORT" | field ok)"
check "marketplace products grew by what it said"        "$((ROWS_BEFORE + IMPORTED_PRODUCTS))" "$(tmc_rows)"
check "marketplace vendors grew by what it said"         "$((VENDORS_BEFORE + IMPORTED_VENDORS))" "$(tmc_vendors)"
check "and Dokan is STILL byte-for-byte what it was"     "$BEFORE" "$(fingerprint)"

# The imported row points at the EXISTING WooCommerce product, so the id and
# the URL a customer bookmarked keep working — and it is a draft, so nothing
# appeared on the storefront.
IMPORTED="$(wp eval 'global $wpdb; $r = $wpdb->get_row("SELECT id, wc_product_id, status FROM {$wpdb->prefix}tmc_products ORDER BY id DESC LIMIT 1", ARRAY_A);
printf("tmc=%d wc=%d status=%s", (int) $r["id"], (int) $r["wc_product_id"], $r["status"]);')"
echo "    imported: $IMPORTED"
check "the new row links to the existing WooCommerce id" yes \
  "$(case "$(echo "$IMPORTED" | field wc)" in 0|'') echo no;; *) echo yes;; esac)"
check "…and arrives as a draft, not on the shelf"        draft "$(echo "$IMPORTED" | field status)"
WC_ID="$(echo "$IMPORTED" | field wc)"
check "the WooCommerce post itself still says publish"   publish "$(wp post get "$WC_ID" --field=post_status)"
check "…and still belongs to the Dokan seller"           yes \
  "$(wp eval "echo (int) get_post_field('post_author', $WC_ID) === 1 ? 'no' : 'yes';")"

echo
echo "--- 3. the way back leaves no trace ---"
mig runs > "$EV/03-runs.txt"
ROLLBACK="$(mig rollback "$RUN_ID" | tee "$EV/04-rollback.txt")"
echo "    $ROLLBACK"
check "the rollback reports success"                     true "$(echo "$ROLLBACK" | field ok)"
check "marketplace products are back where they started" "$ROWS_BEFORE" "$(tmc_rows)"
check "marketplace vendors are back where they started"  "$VENDORS_BEFORE" "$(tmc_vendors)"
check "the WooCommerce product was never removed"        publish "$(wp post get "$WC_ID" --field=post_status)"
check "and Dokan is byte-for-byte what it was at step 0" "$BEFORE" "$(fingerprint)"
check "the run is gone from the list"                    0 "$(mig runs | field count)"

echo
echo "--- 4. a second dry run sees the same thing it saw the first time ---"
SECOND="$(mig plan | head -1)"
check "the same rows are offered again"                  "$(echo "$PLAN" | field products)" "$(echo "$SECOND" | field products)"

{
echo "=== after the round trip ==="
mig plan
mig runs
} > "$EV/05-final-state.txt"

echo
printf 'dokan migration — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
