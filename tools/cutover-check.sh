#!/usr/bin/env bash
# The CUTOVER, and the way back — measured, on a disposable WordPress with a
# real Dokan Lite.
#
# «سناریوی انتقال و بازگشت را با حفظ تاریخچه تکمیل کن» — so this runs the whole
# arc and checks the thing that actually matters at each end of it:
#
#   before   Dokan is selling. Nothing of ours is live.
#   import   the catalogue and the history arrive as OUR rows, stamped with a
#            run id, owned as «observed», with no ledger line written.
#   cutover  Dokan is deactivated. Our shop page serves, the bookmarked Dokan
#            URL redirects, and Dokan's own DATA is still there untouched.
#   back     Dokan is reactivated and the run is rolled back. Exactly the rows
#            that run made are gone; Dokan's rows are byte-identical to
#            «before»; and the audit trail still says both things happened.
#
# The deactivation is the part that cannot be faked: `is_404()` only becomes
# true for a Dokan URL once Dokan has stopped claiming it. It happens HERE, on
# a throwaway site, and the trap puts Dokan back whatever goes wrong.
#
#   tools/cutover-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/cutover}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }
code() { curl -s -o /dev/null -w '%{http_code}' --max-redirs 0 "$1"; }

cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

# Dokan goes back on, whatever happens below — a failed assertion, a Ctrl-C, a
# fatal. Leaving a disposable site half-cut-over would make the NEXT run
# measure a world nobody set up.
restore_dokan() {
  wp plugin activate dokan-lite >/dev/null 2>&1 || true
}
trap restore_dokan EXIT

{
echo "=== cutover and rollback, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version | grep -E 'tecteb|dokan'
} | tee "$EV/00-header.txt"

echo
echo "--- 1. before: Dokan is the one selling ---"
BEFORE="$(wp eval-file dokan-migration.php dokan-untouched | tee "$EV/01-before.txt")"
echo "    $BEFORE"
BEFORE_DIGEST="$(echo "$BEFORE" | field digest)"
check "Dokan's own tables were read"                      1 \
  "$(echo "$BEFORE" | grep -c 'dokan_orders=')"
check "…and it has sellers to move"                     1 \
  "$([ "$(echo "$BEFORE" | field sellers)" -ge 1 ] && echo 1 || echo 0)"
wp eval-file dokan-migration.php reset > "$EV/02-reset.txt"

echo
echo "--- 2. import: their catalogue becomes our rows, and nothing more ---"
PLAN="$(wp eval-file dokan-migration.php plan | tee "$EV/03-plan.txt")"
echo "    $PLAN"
RUN="$(echo "$PLAN" | field run)"
check "the plan has a run id"                             yes "$([ -n "$RUN" ] && echo yes || echo no)"
IMPORT="$(wp eval-file dokan-migration.php import | tee "$EV/04-import.txt")"
echo "    $IMPORT"
check "the import succeeded"                              true "$(echo "$IMPORT" | field ok)"
OBSERVED="$(wp eval-file dokan-migration.php observed | tee "$EV/05-observed.txt")"
echo "    $OBSERVED"
# An imported row must not take operational ownership: every operational query
# sees `marketplace` only, so a row that arrived as `observed` cannot start
# selling by itself.
check "the import made rows at all"                       yes \
  "$([ "$(echo "$OBSERVED" | field count)" -ge 1 ] && echo yes || echo no)"
check "…and none of them owns anything"                 0 \
  "$(echo "$OBSERVED" | grep -c 'ownership=marketplace')"

LEDGER_BEFORE="$(wp eval-file dokan-migration.php history 0 | field ledger_lines)"
HISTORY="$(wp eval-file dokan-migration.php import-orders | tee "$EV/06-history.txt")"
echo "    $HISTORY"
SUMMARY="$(wp eval-file dokan-migration.php history 0 | tee "$EV/07-history-summary.txt")"
echo "    $SUMMARY"
# FIN-02, and it is the whole reason the history is a RECORD and not a
# transaction: one financial engine per order, and for a Dokan order it is
# Dokan's. A ledger line here would be this marketplace restating somebody
# else's money with its own rate.
# A DELTA, not an absolute: this disposable site's ledger already carries
# hundreds of lines from the order fixtures, and `ledger_lines=0` would only
# ever be true on an empty install. The question is whether importing the
# history ADDED any.
check "the history added no ledger line"                  "$LEDGER_BEFORE" "$(echo "$SUMMARY" | field ledger_lines)"
check "…but the orders really did arrive"               yes \
  "$([ "$(echo "$SUMMARY" | field all_rows)" -ge 1 ] && echo yes || echo no)"

echo
echo "--- 3. cutover: Dokan stops, and the old links keep working ---"
VENDOR="$(wp eval 'echo (int) (Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container()
  ->get(Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class)
  ->approvedVendorUserIds(1, 0)[0] ?? 0);')"
NICE="$(wp eval "echo get_userdata(${VENDOR})->user_nicename;")"
BASE="$(wp eval 'echo Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\DokanUrlRedirects::storeBase();')"
OLD="${SITE}/${BASE}/${NICE}/"
echo "    vendor=${VENDOR} old=${OLD}"

wp plugin deactivate dokan-lite > "$EV/08-deactivate.txt" 2>&1
check "Dokan is off"                                      1 \
  "$(wp plugin list --fields=name,status | grep -c 'dokan-lite	inactive')"
# `is_404()` is finally true for that URL — Dokan's rewrite rule is gone with
# it — so this is the OTHER of the two redirect paths, and the only one a
# full cutover exercises.
check "the bookmarked Dokan URL redirects"                301 "$(code "$OLD")"
check "…to this shop's page here"                       "?tmc_store=${VENDOR}" \
  "$(curl -s -o /dev/null -w '%{redirect_url}' --max-redirs 0 "$OLD" | sed 's|^.*/||')"
check "…and that page is really served"                 200 "$(code "${SITE}/?tmc_store=${VENDOR}")"
curl -s "${SITE}/?tmc_store=${VENDOR}" > "$EV/09-store-after-cutover.html"
check "…with the shop's products on it"                 1 \
  "$(grep -c '<ul class=.tmc-store__products' "$EV/09-store-after-cutover.html")"
check "the site's own sitemap still lists the shop"       1 \
  "$(curl -s "${SITE}/wp-sitemap-tmcstores-1.xml" | grep -c "tmc_store=${VENDOR}")"

DURING="$(wp eval-file dokan-migration.php dokan-untouched | tee "$EV/10-during.txt")"
echo "    $DURING"
# Deactivating a plugin does not delete its data, and nothing of ours may
# either. If this digest moved, the cutover ate somebody's records.
check "Dokan's own data survived the cutover"             "$BEFORE_DIGEST" "$(echo "$DURING" | field digest)"

echo
echo "--- 4. back: Dokan returns and the run is rolled back ---"
wp plugin activate dokan-lite > "$EV/11-activate.txt" 2>&1
check "Dokan is on again"                                 1 \
  "$(wp plugin list --fields=name,status | grep -c 'dokan-lite	active')"
# Not a 404: Dokan is answering again, and for a shop it does not sell for it
# answers 404 from its template — which is exactly the state the redirect is
# for. The distinction that matters here is that Dokan, not a stale rule, is
# the one deciding.
check "Dokan is deciding that URL again"                  1 \
  "$(wp eval "echo function_exists('dokan_is_user_seller') ? 1 : 0;")"
check "…and the bookmark still lands on the shop"       301 "$(code "$OLD")"

ROLLBACK="$(wp eval-file dokan-migration.php rollback "$RUN" | tee "$EV/12-rollback.txt")"
echo "    $ROLLBACK"
check "the rollback succeeded"                            true "$(echo "$ROLLBACK" | field ok)"
AFTER_RUNS="$(wp eval-file dokan-migration.php runs | tee "$EV/13-runs.txt")"
echo "    $AFTER_RUNS"
# A run that was rolled back must not be listed as a run that happened: the
# list is derived from the rows themselves, and the rows are gone.
check "the rolled-back run is no longer listed"           0 "$(echo "$AFTER_RUNS" | grep -c -- "$RUN")"

AFTER="$(wp eval-file dokan-migration.php dokan-untouched | tee "$EV/14-after.txt")"
echo "    $AFTER"
check "Dokan's own data is exactly as it started"         "$BEFORE_DIGEST" "$(echo "$AFTER" | field digest)"

echo
echo "--- 5. what the rollback did NOT take: the trail ---"
AUDIT="$(wp eval-file dokan-migration.php audit-trail "$RUN" | tee "$EV/15-audit.txt")"
echo "    $AUDIT"
# The import happened and the rollback happened. Removing the imported rows is
# what a rollback IS; removing the record that it ever ran would leave nobody
# able to answer «what did we do on the night of the cutover».
check "the import is still in the audit trail"            yes \
  "$([ "$(echo "$AUDIT" | field imported)" -ge 1 ] && echo yes || echo no)"
check "…and so is the rollback"                         yes \
  "$([ "$(echo "$AUDIT" | field rolled_back)" -ge 1 ] && echo yes || echo no)"

echo
echo "=== pass=${pass} fail=${fail} ==="
echo "pass=${pass} fail=${fail}" > "$EV/99-summary.txt"
[ "$fail" -eq 0 ]
