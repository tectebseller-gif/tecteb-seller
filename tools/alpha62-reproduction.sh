#!/usr/bin/env bash
# `alpha.62` on the disposable site: the owner's seven decisions, measured.
#
# WHAT IS MEASURED, in the owner's own order, from the INSTALLED bytes of the
# delivered ZIP:
#
#   §1 a fixed commission bigger than the line, BEFORE any money is taken —
#      in the classic cart and through the Store API, with identical
#      sentences, and with the discount doing the work in one case (the
#      owner's actual scenario). Four controls: a rule under the line, no
#      rule, a rule of nought, and a hundred per cent.
#   §3 the agreed amount with its history, two partial payments and the
#      balance after each, an over-payment refused by name, a repeated
#      submission writing once, and a REAL two-process race on one balance.
#   §5 the void: the reversal, the two injected failures (document and
#      ledger) leaving nothing half done, the repeat making no second
#      effect, and the item freed for a correction.
#   §6 the same payment reviewed once its share is knowable, with the first
#      figure still in its own column and no second payment.
#   §2 the debt recorded, collected, offset, and a second collection of the
#      same amount refused.
#   §7 the request path closed with the trial switch ON, the figures still
#      readable, and an earlier request neither cancelled nor released.
#   §4 the picker: its own capability, an audit row per shop opened, no shop
#      preselected, no form, and no IBAN printed.
#
# WHY THERE IS NO «BEFORE» TREE HERE. The three `alpha.61` findings were
# reproduced on `alpha.60`'s bytes last round and that evidence stands; this
# round implements DECISIONS the owner made, so there is no earlier build in
# which they were wrong — only one in which they did not exist. Where that
# matters the probe says so: its verbs answer `absent=1` on the older tree.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha62-reproduction.sh docs/evidence/alpha62-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha62-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
PROBE="$REPO/tests/Support/alpha62-probe.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"

mkdir -p "$OUT"
RUN="$OUT/run.txt"
: >"$RUN"
PASS=0
FAIL=0

say() { printf '%s\n' "$*" | tee -a "$RUN" >/dev/null; }
check() { # check <label> <expected> <actual>
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  ($3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=$2 actual=$3"
  fi
}
# The sale gate and the trial switch are refused on a «production»
# environment, which is what WP-CLI reports here. Declared per call, the way
# `alpha.53` did, and the plugin's own switch is put back by the probe.
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }
wpl() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
          "--exec=define('WP_ENVIRONMENT_TYPE','local');" "$@"; }
probe() { wpx eval-file "$PROBE" "$@" 2>>"$RUN"; }
probel() { wpl eval-file "$PROBE" "$@" 2>>"$RUN"; }
# One key out of one block of probe output (`alpha.41`: one key per line).
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }

[[ -d "$INSTALLED" ]] || { say "FAIL  the plugin is not installed at $INSTALLED"; echo "PASS=0 FAIL=1" | tee -a "$RUN"; exit 1; }

say "=== the tree under test ==="
VERSION="$(wpx plugin get tecteb-marketplace-core --field=version 2>/dev/null | tr -d '\r')"
say "installed_version=$VERSION"
SRC_DIFF="$(diff -rq "$REPO/src" "$INSTALLED/src" 2>&1 | head -5)"
check "1a the installed src/ is this checkout's" "" "$SRC_DIFF"
check "1b the schema target is 31" "31" "$(wpx eval 'echo \Tecteb\Marketplace\Core\Migration\SchemaVersion::TARGET;' 2>/dev/null)"
check "1c the recorded schema is 31" "31" "$(wpx option get tmc_schema_version 2>/dev/null | tr -d '\r')"

say ""
say "=== §3 the agreed amount, partial payments and the balance ==="
probe reset >/dev/null
A="$(probe arrange)"
check "3a the fixture has a line with a share" "900000" "$(val "$A" share_a)"
check "3b …and one with none" "__null__" "$(val "$A" share_b)"
AG="$(probe agree 96211 900000)"
check "3c the agreed amount is recorded" "supplier_amount_agreed" "$(val "$AG" agree_code)"
check "3d …as the number that was typed" "900000" "$(val "$AG" agreed_minor)"
check "3e …with a balance of all of it" "900000" "$(val "$AG" balance_minor)"
check "3f …and one history row" "1" "$(val "$AG" history_rows)"
AG2="$(probe agree 96211 850000)"
check "3g a change is recorded" "supplier_amount_agreed" "$(val "$AG2" agree_code)"
check "3h …and the history grows" "2" "$(val "$AG2" history_rows)"
AG3="$(probe agree 96211 850000)"
check "3i the same number again writes nothing" "supplier_amount_unchanged" "$(val "$AG3" agree_code)"
check "3j …and no history row" "2" "$(val "$AG3" history_rows)"
probe agree 96211 900000 >/dev/null

P1="$(probe pay 96211 500000 TRK-62-A)"
check "3k a payment under the agreed amount is PARTIAL" "supplier_payment_recorded_partial" "$(val "$P1" pay_code)"
check "3l …and names the remaining balance" "400000" "$(val "$P1" balance_after)"
check "3m …read back from the rows" "400000" "$(val "$P1" db_balance_minor)"
check "3n …with one claim row, not two" "1" "$(val "$P1" claim_rows)"
OVER="$(probe pay 96211 400001 TRK-62-OVER)"
check "3o one unit over the balance is refused BY NAME" "supplier_payment_above_balance" "$(val "$OVER" pay_code)"
check "3p …and nothing is written" "1" "$(val "$OVER" db_payments)"
check "3q …the balance is untouched" "400000" "$(val "$OVER" db_balance_minor)"
P2="$(probe pay 96211 400000 TRK-62-B tok-62)"
check "3r the rest of the balance is paid" "0" "$(val "$P2" db_balance_minor)"
check "3s …by a second payment" "2" "$(val "$P2" db_payments)"
check "3t …and still one claim row" "1" "$(val "$P2" claim_rows)"
REP="$(probe pay 96211 400000 TRK-62-B tok-62)"
check "3u the same submission again writes nothing" "supplier_payment_already_recorded" "$(val "$REP" pay_code)"
check "3v …and no third payment exists" "2" "$(val "$REP" db_payments)"
SET="$(probe pay 96211 1 TRK-62-C)"
check "3w a covered obligation says so" "supplier_obligation_settled" "$(val "$SET" pay_code)"

say ""
say "=== §3 the race: two processes, one balance ==="
probe reset >/dev/null
probe arrange >/dev/null
probe agree 96211 900000 >/dev/null
probe pay 96211 500000 TRK-62-SEED >/dev/null
RACE="$(TMC_REPO="$REPO" TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" probe race 96211 400000)"
check "3x the rival process committed first" "yes" "$(val "$RACE" rival_committed)"
check "3y …and this request is refused" "supplier_obligation_settled" "$(val "$RACE" my_code)"
check "3z …the balance is covered exactly once" "0" "$(val "$RACE" db_balance_minor)"
check "3aa …and by the agreed amount, not twice it" "900000" "$(val "$RACE" db_paid_minor)"

say ""
say "=== §5 the void ==="
probe reset >/dev/null
probe arrange >/dev/null
VP="$(probe pay 96211 900000 TRK-62-V)"
PID="$(val "$VP" payment_id)"
check "5a a payment matching the share is posted" "supplier_payment_recorded" "$(val "$VP" pay_code)"
FL="$(probe void-fail-ledger "$PID")"
check "5b a reversal that cannot be written refuses" "storage_failed" "$(val "$FL" void_code)"
check "5c …the document is NOT void" "no" "$(val "$FL" is_void)"
check "5d …no ledger row was left behind" "$(val "$FL" ledger_rows_before)" "$(val "$FL" ledger_rows_after)"
check "5e …and the item is still held" "1" "$(val "$FL" live_claims)"
FD="$(probe void-fail-doc "$PID")"
check "5f a document write that fails refuses" "storage_failed" "$(val "$FD" void_code)"
check "5g …the document is NOT void" "no" "$(val "$FD" is_void)"
check "5h …and no reversal was left behind" "$(val "$FD" ledger_rows_before)" "$(val "$FD" ledger_rows_after)"
VD="$(probe void "$PID" 'شمارهٔ پیگیری اشتباه بود')"
check "5i the void succeeds" "supplier_payment_voided" "$(val "$VD" void_code)"
check "5j …the record is NOT deleted" "yes" "$(val "$VD" row_exists)"
check "5k …the reason is on it" "شمارهٔ_پیگیری_اشتباه_بود" "$(val "$VD" void_reason)"
check "5l …with a compensating event" "supplier-payment-void:$PID" "$(val "$VD" void_event)"
check "5m …two ledger rows for the reversal" "$(( $(val "$VD" ledger_rows_before) + 2 ))" "$(val "$VD" ledger_rows_after)"
check "5n …the claim row stays on disk" "1" "$(val "$VD" claim_rows)"
check "5o …and is no longer live" "0" "$(val "$VD" live_claims)"
VR="$(probe void-repeat "$PID" 'دلیل دوم')"
check "5p a repeated void is refused" "supplier_payment_already_void" "$(val "$VR" void_code)"
check "5q …the first reason stands" "شمارهٔ_پیگیری_اشتباه_بود" "$(val "$VR" void_reason)"
check "5r …and no second financial effect" "$(val "$VR" ledger_rows_before)" "$(val "$VR" ledger_rows_after)"
COR="$(probe pay 96211 850000 TRK-62-RIGHT)"
check "5s the correct payment can then be recorded" "supplier_payment_recorded_unposted" "$(val "$COR" pay_code)"

say ""
say "=== §6 reviewing the same payment ==="
probe reset >/dev/null
probe arrange >/dev/null
RP="$(probe pay 96212 630000 TRK-62-R)"
RPID="$(val "$RP" payment_id)"
check "6a a payment whose share is unknown says so" "supplier_payment_recorded_share_unknown" "$(val "$RP" pay_code)"
E="$(probe reconcile "$RPID")"
check "6b reviewing while unknown changes nothing" "supplier_payment_still_unknown" "$(val "$E" reconcile_code)"
check "6c …and names the item" "$(val "$(probe report)" line_b)" "$(val "$E" unknown_items)"
F="$(probe fill-share 96212)"
check "6d the share is filled in by the repair path" "630000" "$(val "$F" share_now)"
R="$(probe reconcile "$RPID")"
check "6e the SAME payment is reviewed" "supplier_payment_reconciled" "$(val "$R" reconcile_code)"
check "6f …the figure recorded at the time is kept" "0" "$(val "$R" db_share_minor)"
check "6g …the reviewed figure goes beside it" "630000" "$(val "$R" db_share_final)"
check "6h …and no second payment was made" "$(val "$R" payments_before)" "$(val "$R" payments_after)"
R2="$(probe reconcile "$RPID")"
check "6i reviewing twice keeps the first review" "supplier_payment_already_reconciled" "$(val "$R2" reconcile_code)"

say ""
say "=== §2 the debt, the collection and the offset ==="
probe reset >/dev/null
probe arrange >/dev/null
probe pay 96211 900000 TRK-62-D >/dev/null
D="$(probe debt 96211 300000 620)"
RID="$(val "$D" receivable_id)"
check "2a the debt is recorded" "supplier_receivable_recorded" "$(val "$D" debt_code)"
D2="$(probe debt 96211 300000 620)"
check "2b the same return again records nothing" "supplier_receivable_already_recorded" "$(val "$D2" debt_code)"
check "2c …and points at the first one" "$RID" "$(val "$D2" receivable_id)"
C="$(probe collect "$RID" 100000 t-62-1)"
check "2d a real collection is recorded" "supplier_receivable_collected" "$(val "$C" settle_code)"
check "2e …and the balance comes down" "200000" "$(val "$C" balance_minor)"
O="$(probe offset "$RID" 200000 t-62-2)"
check "2f an offset is a DIFFERENT event" "supplier_receivable_offset" "$(val "$O" settle_code)"
check "2g …on the same balance" "0" "$(val "$O" balance_minor)"
check "2h …and both kinds are on record" "collected|offset" "$(val "$O" kinds)"
C2="$(probe collect "$RID" 1 t-62-3)"
check "2i one unit more is refused" "supplier_receivable_above_balance" "$(val "$C2" settle_code)"
C3="$(probe collect "$RID" 100000 t-62-1)"
check "2j the same submission again writes nothing" "supplier_receivable_already_settled" "$(val "$C3" settle_code)"
check "2k …and there are still two settlements" "2" "$(val "$C3" settlements)"
D3="$(probe debt 96213 100000 621)"
check "2l a debt on an unpaid item is refused" "supplier_item_not_paid" "$(val "$D3" debt_code)"

say ""
say "=== §7 the withdrawal request path ==="
# A FRESH FIXTURE, so the eligible figure is unambiguous. Run on whatever the
# §2 block left behind, this said 540,000 — a TRUE number about an item that
# had just been paid by hand, and a confusing one to read as «the figures stay
# readable». Two lines with a recorded share and nothing claimed: 900,000 +
# 540,000.
probe reset >/dev/null
probe arrange >/dev/null
W="$(probe withdraw)"
check "7a the request is refused by the owner's closure" "withdrawal_requests_closed" "$(val "$W" request_code)"
check "7b …and the policy says why" "no" "$(val "$W" policy_open)"
check "7c …with nothing created" "__none__" "$(val "$W" open_request)"
check "7d …while the figures stay readable" "1440000" "$(val "$W" eligible_minor)"
S="$(probe withdraw-seed)"
SID="$(val "$S" seeded_withdrawal)"
W2="$(probe withdraw)"
check "7e an earlier request is still there" "$SID" "$(val "$W2" open_request)"
check "7f …and in the history" "1" "$(val "$W2" history_rows)"
check "7g …and a new one is still refused" "withdrawal_requests_closed" "$(val "$W2" request_code)"

say ""
say "=== §4 the shop picker ==="
SP="$(probe shop-picker)"
check "4a it is behind its own capability" "tmc_view_vendor_shops" "$(val "$SP" capability)"
check "4b …and its address names no shop" "no" "$(val "$SP" url_has_shop)"
check "4c the list renders" "yes" "$(val "$SP" list_renders)"
check "4d …and says it is read-only" "yes" "$(val "$SP" list_mentions_readonly)"
check "4e …with a link per shop" "yes" "$(val "$SP" list_has_links)"
check "4f one shop renders" "yes" "$(val "$SP" shop_renders)"
check "4g …and writes exactly one audit row" "$(( $(val "$SP" audit_rows_before) + 1 ))" "$(val "$SP" audit_rows_after)"
check "4h the shop HAS an IBAN on record" "yes" "$(val "$SP" iban_on_record)"
check "4i …and the page does not print it" "no" "$(val "$SP" prints_iban)"
check "4j …there is no form on it" "no" "$(val "$SP" prints_form)"
check "4k …no nonce either" "no" "$(val "$SP" prints_nonce)"
check "4l …and the manager is still themselves" "yes" "$(val "$SP" identity_unchanged)"

say ""
say "=== §1 the commission gate, in both baskets ==="
probe cart-reset >/dev/null
CAT="$(wpx term list product_cat --field=term_id --number=1 2>/dev/null | head -1 | tr -d '\r')"
CA="$(probe cart-arrange "$CAT")"
check "1d the cart fixture is a marketplace product" "yes" "$(val "$CA" is_ours)"
probe cart-rule fixed 150000 >/dev/null
CC="$(probel cart-classic 1)"
check "1e the sale gate is OPEN for the measurement" "yes" "$(val "$CC" sale_gate_ready)"
check "1f …and the catalogue allows the product" "allowed" "$(val "$CC" catalogue_decision)"
check "1g the line total is the one the order would carry" "100000" "$(val "$CC" cart_line_total)"
check "1h the CLASSIC cart refuses it" "1" "$(val "$CC" errors)"
check "1i …and does not empty the basket" "1" "$(val "$CC" cart_items_after)"
check "1j …and names no rate to the shopper" "no" "$(val "$CC" names_rate)"
CLASSIC_MSG="$(val "$CC" message)"
SA="$(probel cart-store-api 1)"
check "1k the STORE API refuses it too" "woocommerce_cart_error" "$(val "$SA" code)"
check "1l …with the identical sentence" "$CLASSIC_MSG" "$(val "$SA" message)"
probe cart-rule fixed 60000 >/dev/null
CU="$(probel cart-classic 1)"
check "1m a rule UNDER the line sells" "0" "$(val "$CU" errors)"
SU="$(probel cart-store-api 1)"
check "1n …in both baskets" "__none__" "$(val "$SU" code)"
probe cart-rule none '' >/dev/null
CN="$(probel cart-classic 1)"
check "1o no commission at all sells" "0" "$(val "$CN" errors)"
probe cart-rule percent 0 >/dev/null
CZ="$(probel cart-classic 1)"
check "1p a commission of nought sells" "0" "$(val "$CZ" errors)"
probe cart-rule percent 100 >/dev/null
CH="$(probel cart-classic 1)"
check "1q a hundred per cent is the whole line and sells" "0" "$(val "$CH" errors)"
DIS="$(probel cart-discount 90000)"
check "1r the owner's scenario: fine at full price" "0" "$(val "$DIS" full_price_errors)"
check "1s …the coupon halves the line" "50000" "$(val "$DIS" discounted_line_total)"
check "1t …and THEN it is refused" "1" "$(val "$DIS" discounted_errors)"
check "1u …with the same comprehensible sentence" "$CLASSIC_MSG" "$(val "$DIS" discounted_message)"

say ""
say "=== giving back what this run spent ==="
probe cart-reset >/dev/null
RS="$(probe reset)"
check "9a the fixture's order lines are gone" "0" "$(val "$RS" lines_left)"

say ""
say "PASS=$PASS FAIL=$FAIL"
printf 'PASS=%s FAIL=%s\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
