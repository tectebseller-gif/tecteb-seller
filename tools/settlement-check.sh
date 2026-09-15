#!/usr/bin/env bash
# Settlement and withdrawal on a real WooCommerce site: what a vendor may ask
# for, when, and what is refused while the financial decisions are open.
#
#   tools/settlement-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/settlement}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
VENDOR="${VENDOR:-4}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-58s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-58s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
state() { wp eval-file purchase-block-state.php "$@"; }
field() { echo "$1" | grep -oP "$2=\K[^ ]+"; }

echo "=== settlement and withdrawal ==="
wp eval-file purchase-block-state.php trial 1 > /dev/null
# Start from the approved four days, so the waiting-period branch is exercised
# rather than skipped because a previous run left it at zero.
wp eval '$s = get_option("tmc_settings"); $s["values"]["settlement_delay_days"] = 4; update_option("tmc_settings", $s);' > /dev/null

ITEM="$(wp eval "global \$wpdb; echo (int) \$wpdb->get_var(\$wpdb->prepare('SELECT id FROM ' . \$wpdb->prefix . 'tmc_order_items WHERE vendor_user_id = %d ORDER BY id ASC LIMIT 1', ${VENDOR}));")"
if [ -z "$ITEM" ] || [ "$ITEM" = "0" ]; then
  echo "no recorded order line for vendor ${VENDOR}; run the order trial first" >&2
  exit 2
fi
# This script SPENDS its fixture: it settles a line and pays a withdrawal
# against it, and neither can happen twice. Running it again on the same data
# produced nine confusing failures once, so it now says the one true thing
# instead — re-seed and run it again.
SPENT="$(wp eval "global \$wpdb; echo (int) \$wpdb->get_var('SELECT COUNT(*) FROM ' . \$wpdb->prefix . 'tmc_order_items WHERE id = ${ITEM} AND withdrawal_id IS NOT NULL');")"
if [ "${SPENT:-0}" != "0" ]; then
  echo "order line ${ITEM} is already settled and paid by an earlier run." >&2
  echo "re-seed first: wp eval-file order-evidence.php reset && wp eval-file order-trial-seed.php <vendor-a> <vendor-b>" >&2
  exit 2
fi
echo "    settling on order line ${ITEM}"

# A bank account, because A.4 makes settlement wait for one. Set through the
# store repository, which is the path the vendor's own settings screen uses.
wp eval "\$c = Tecteb\\Marketplace\\Infrastructure\\WordPress\\Bootstrap::container();
\$c->get(Tecteb\\Marketplace\\Modules\\Vendor\\Application\\StoreRepositoryInterface::class)
  ->saveBank(${VENDOR}, 'IR820540102680020817909002', 'دارندهٔ حساب آزمایشی', 0, 'approved', false);
echo 'bank set';" > /dev/null

b0="$(state balance "$VENDOR")"; echo "    $b0"
check "the share is earned"                          true "$([ "$(field "$b0" earned)" -gt 0 ] && echo true || echo false)"
check "…and not yet askable for"                     0 "$(field "$b0" eligible)"
check "…because nobody has called the sale complete" 1 "$(field "$b0" awaiting_completion)"

w0="$(state withdraw "$VENDOR")"; echo "    $w0"
check "so a request is refused, by name"             nothing_eligible "$(field "$w0" code)"

echo
echo "--- the manager records completion (ORDER-01) ---"
settled="$(state settle "$ITEM" 1)"; echo "    $settled"
check "the manager may record it"                    true "$(field "$settled" ok)"
b1="$(state balance "$VENDOR")"; echo "    $b1"
delay="$(field "$b1" delay_days)"
if [ "$delay" -gt 0 ]; then
  check "the approved waiting period holds it back"   0 "$(field "$b1" eligible)"
  check "…and says which of the two reasons it is"    1 "$(field "$b1" awaiting_delay)"
  wp eval '$s = get_option("tmc_settings"); $s["values"]["settlement_delay_days"] = 0; update_option("tmc_settings", $s);' > /dev/null
  b1="$(state balance "$VENDOR")"; echo "    $b1"
fi
check "now it is askable for"                        true "$([ "$(field "$b1" eligible)" -gt 0 ] && echo true || echo false)"

echo
echo "--- the vendor asks, once ---"
w1="$(state withdraw "$VENDOR")"; echo "    $w1"
check "the request is made"                          true "$(field "$w1" ok)"
AMOUNT="$(field "$w1" amount)"
w2="$(state withdraw "$VENDOR")"
check "a second click makes no second request"       withdrawal_already_open "$(field "$w2" code)"
b2="$(state balance "$VENDOR")"
check "the money is locked, not eligible"            0 "$(field "$b2" eligible)"
check "…and reported as reserved"                    "$AMOUNT" "$(field "$b2" reserved)"

ID="$(wp eval "global \$wpdb; echo (int) \$wpdb->get_var(\$wpdb->prepare('SELECT id FROM ' . \$wpdb->prefix . 'tmc_withdrawals WHERE vendor_user_id = %d AND open_marker = 1', ${VENDOR}));")"
echo
echo "--- the manager decides (withdrawal ${ID}) ---"
check "paying before approval is refused"            invalid_transition "$(field "$(state review "$ID" paid 'TRACE-X')" code)"
state review "$ID" reviewing > /dev/null
state review "$ID" approved > /dev/null
state review "$ID" payment_in_progress > /dev/null
check "paying without a reference is refused"        reference_required "$(field "$(state review "$ID" paid '')" code)"
check "an unknown outcome needs a reason"            note_required "$(field "$(state review "$ID" reconciliation_required '')" code)"
check "the payment is recorded with its reference"   true "$(field "$(state review "$ID" paid 'TRACE-ALPHA8')" ok)"

b3="$(state balance "$VENDOR")"; echo "    $b3"
check "the amount shows as paid"                     "$AMOUNT" "$(field "$b3" paid)"
check "…and is not eligible again"                   0 "$(field "$b3" eligible)"

{
echo "=== settlement and withdrawal on real WooCommerce ==="
wp plugin list --fields=name,status,version
echo
echo "-- the vendor's balance, step by step --"
echo "$b0"; echo "$b1"; echo "$b2"; echo "$b3"
echo
echo "-- the ledger behind the payment --"
wp eval 'global $wpdb; $t = $wpdb->prefix . "tmc_ledger_entries";
foreach ($wpdb->get_results("SELECT event_key, account, amount_minor FROM {$t} WHERE event_key LIKE \"withdrawal:%\"", ARRAY_A) as $r) {
    printf("%s %s %d\n", $r["event_key"], $r["account"], $r["amount_minor"]);
}' 
echo
echo "-- the request itself --"
wp eval 'global $wpdb; $t = $wpdb->prefix . "tmc_withdrawals";
foreach ($wpdb->get_results("SELECT id, vendor_user_id, status, amount_minor, line_count, reference, open_marker FROM {$t}", ARRAY_A) as $r) {
    printf("id=%s vendor=%s status=%s amount=%s lines=%s reference=%s open=%s\n", $r["id"], $r["vendor_user_id"], $r["status"], $r["amount_minor"], $r["line_count"], $r["reference"], $r["open_marker"] === null ? "closed" : "open");
}'
} > "$EV/01-withdrawal.txt"

echo
echo "--- and with the decisions open again, nothing is created ---"
wp eval-file purchase-block-state.php trial 0 > /dev/null
blocked="$(state withdraw "$VENDOR")"; echo "    $blocked"
check "a request is refused while DEC-02/FIN-04 are open" settlement_blocked "$(field "$blocked" code)"
b4="$(state balance "$VENDOR")"
check "…but the balance is still computable, not hidden" true \
  "$([ -n "$(field "$b4" earned)" ] && echo true || echo false)"
wp eval-file purchase-block-state.php trial 1 > /dev/null
echo "$blocked" >> "$EV/01-withdrawal.txt"

echo
printf 'settlement — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
