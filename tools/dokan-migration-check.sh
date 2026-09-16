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
# Copied on every run. `wp eval-file` reads the file from the WordPress root,
# and a stale copy there answers «usage:» to a command this version added —
# which reads like the feature is missing rather than the file being old.
cp "$(dirname "$0")/dokan-migration.php" "$WPROOT/" 2>/dev/null || true
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
# First: take back whatever an earlier run left. This script imports and rolls
# back, and a rollback that could not undo itself — which is the bug alpha.14
# fixed — leaves a shop profile behind. Without this the NEXT run imports
# nothing, has no vendor to ask about, and passes by measuring an empty set.
RESET="$(mig reset | tee "$EV/00b-reset.txt")"
echo "    $RESET"
BEFORE="$(fingerprint)"
ROWS_BEFORE="$(tmc_rows)"
VENDORS_BEFORE="$(tmc_vendors)"
# Taken as a BASELINE, never compared to zero: this site carries the ledger of
# every evidence run before this one, and «expected=0» would be measuring the
# site's history rather than this import's behaviour.
ledger_lines() { wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_ledger_entries");'; }
LEDGER_BEFORE="$(ledger_lines)"
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
# Read NOW, while the manifest still holds it: section 2b deletes the manifest
# on purpose, and reading it afterwards asked about vendor 0.
DOKAN_VENDOR="$(mig runs | grep -m1 "run=${RUN_ID} " | field vendors | cut -d, -f1)"
echo "    imported vendor: ${DOKAN_VENDOR}"

echo
echo "--- 2b. the run is written on the rows, not only beside them ---"
# The manifest is an option written AFTER the rows. A process that died in
# between left rows nothing could name — and a rollback that read only the
# manifest left a manager looking at products they could not undo.
STAMPS="$(mig stamps | tee "$EV/02b-stamps.txt")"
echo "    $STAMPS"
check "every imported row carries the run that made it"  "$IMPORTED_PRODUCTS" \
  "$(printf '%s' "$STAMPS" | grep -m1 "stamp run=${RUN_ID} " | field rows)"
check "…and the list agrees with the manifest"           manifest \
  "$(mig runs | grep -m1 "run=${RUN_ID} " | field source)"

# Now the crash itself: the option goes, the rows stay.
CRASH="$(mig forget-manifest "$RUN_ID" | tee "$EV/02c-crash.txt")"
echo "    $CRASH"
check "the run WAS in the manifest before the crash"     true "$(printf '%s' "$CRASH" | field was_in_manifest)"
check "…and is still listed after it"                    true "$(printf '%s' "$CRASH" | field still_listed)"
check "…now derived from the rows themselves"            rows_only "$(printf '%s' "$CRASH" | field source)"
check "…with every row still accounted for"              "$IMPORTED_PRODUCTS" "$(printf '%s' "$CRASH" | field rows)"
check "…and the shop it created too"                     "$IMPORTED_VENDORS" "$(printf '%s' "$CRASH" | field vendors)"

echo
echo "--- 2d. Dokan's past orders arrive as records, not as money ---"
HISTORY="$(mig history "$DOKAN_VENDOR" | tee "$EV/02d-orders.txt")"
echo "    $HISTORY"
check "the history store is there"                       true "$(printf '%s' "$HISTORY" | field available)"
check "…and the past orders were recorded"                yes \
  "$([ "$(printf '%s' "$HISTORY" | field all_rows)" -gt 0 ] && echo yes || echo no)"
# Asked of the shop that HAS the orders. The shop whose catalogue was imported
# and the shop with the sales need not be the same one, and asking the wrong
# one gets «no orders» from a page that is working perfectly.
check "…and a shop's summary adds up over them"          yes \
  "$([ "$(printf '%s' "$HISTORY" | field top_orders)" -gt 0 ] && echo yes || echo no)"
# The figures are Dokan's own, carried whole: a marketplace that recomputed
# somebody else's commission would be inventing numbers about real money.
check "…carrying a total, not a recomputed one"          yes \
  "$([ "$(printf '%s' "$HISTORY" | field top_total_minor)" -gt 0 ] && echo yes || echo no)"
# FIN-02: one financial engine per order, and it is not this one.
check "…and the ledger did not grow by one line"         "$LEDGER_BEFORE" "$(ledger_lines)"
# The JOB's own page method over the same orders. It must add nothing and say
# so — `UNIQUE(wc_order_id, vendor_user_id)` is what makes a resumed job safe,
# and «already there» must be distinguishable from «did not work».
AGAIN="$(mig import-orders "$RUN_ID" | tee -a "$EV/02d-orders.txt")"
echo "    $AGAIN"
check "the resumable path records nothing new"           0 "$(printf '%s' "$AGAIN" | field recorded)"
# Counted against the WHOLE table, not one shop's slice: the six orders span
# two sellers and the resumable path walks all of them.
check "…and reports them as already there"               "$(printf '%s' "$HISTORY" | field all_rows)" \
  "$(printf '%s' "$AGAIN" | field already_there)"
# A failure must never be counted as «already there». It was, until an
# evidence run said «already imported» about six orders the table did not have.
check "…with nothing counted as a silent failure"        0 "$(printf '%s' "$AGAIN" | field failed)"

echo
echo "--- 3. the way back leaves no trace ---"
mig runs > "$EV/03-runs.txt"
# Rolled back from the rows alone — the manifest is gone. This is the whole
# point of the stamp: the run that crashed is still undoable.
ROLLBACK="$(mig rollback "$RUN_ID" | tee "$EV/04-rollback.txt")"
echo "    $ROLLBACK"
check "the rollback reports success"                     true "$(echo "$ROLLBACK" | field ok)"
check "marketplace products are back where they started" "$ROWS_BEFORE" "$(tmc_rows)"
# With the manifest deleted in 2b, this can only work if the PROFILE carries
# the run too. It did not until alpha.14 — the first run of this section
# removed the products and left the shop standing.
check "marketplace vendors are back where they started"  "$VENDORS_BEFORE" "$(tmc_vendors)"
check "the WooCommerce product was never removed"        publish "$(wp post get "$WC_ID" --field=post_status)"
check "and Dokan is byte-for-byte what it was at step 0" "$BEFORE" "$(fingerprint)"
check "the run is gone from the list"                    0 "$(mig runs | field count)"
# And its order history went with it: a rollback that left the records behind
# would show a shop figures for an import that no longer exists.
check "…and its order history went with it"             0 \
  "$(mig history "$DOKAN_VENDOR" | field all_rows)"
# And gone from the rows too, or the next `runs` would resurrect it.
check "…and no row still carries its stamp"             0 \
  "$(mig stamps | grep -c "stamp run=${RUN_ID} " || true)"

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
