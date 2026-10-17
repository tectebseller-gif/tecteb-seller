#!/usr/bin/env bash
# `alpha.63` on the disposable site: two findings, reproduced on the bytes of
# the DELIVERED `alpha.62` package and then measured again on this tree.
#
# WHAT IS MEASURED
#
#   §1 THE FREE ORDER. `alpha.62` answered `woocommerce_order_needs_payment`
#      with `false` for an order whose line its commission rule refuses. This
#      script does not argue about the hook; it evaluates the expressions
#      WooCommerce 11.0.1 evaluates:
#        · `apply_filters('woocommerce_cart_needs_payment', $order->needs_payment(), WC()->cart)`
#          — `includes/class-wc-checkout.php:1444`, whose `false` branch is
#          `process_order_without_payment()`,
#        · `$order->needs_payment()` — `StoreApi/Routes/V1/Checkout.php:682`,
#          whose `false` branch is `process_without_payment()`, which is
#          `$order->payment_complete()` (`CheckoutTrait.php:73`),
#        · `wc_notice_count('error')` after `woocommerce_before_pay_action`,
#          because `pay_action()` calls `process_payment()` only when that
#          count is nought (`class-wc-form-handler.php:504,543`),
#        · and whether `woocommerce_store_api_checkout_order_processed`
#          throws.
#      With the sale gate OPEN, so the numbers are about the commission rule
#      rather than about `CartGuard` refusing a closed marketplace.
#
#   §2 AN ITEM NOBODY HOLDS. Two partial payments against one obligation,
#      then the first is voided. `alpha.62` wrote no row at all for the
#      second payment and cleared the first one's live key, so the line ended
#      up claimed by nobody while a real payment still covered it — and
#      `reserve()` took it.
#
# Both verbs load on both trees (`alpha.32`). The script swaps `src/` under
# the installed plugin, measures, and puts the original bytes back in a
# `trap` with an absolute path (`alpha.37`) — and checks the hash afterwards,
# not `git diff` (`alpha.32`).
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha63-reproduction.sh docs/evidence/alpha63-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha63-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
PROBE_SRC="$REPO/tests/Support/alpha63-probe.php"
PROBE62_SRC="$REPO/tests/Support/alpha62-probe.php"
PROBE="$WP_PATH/alpha63-probe.php"
PROBE62="$WP_PATH/alpha62-probe.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha63-XXXXXX)"

mkdir -p "$OUT"
RUN="$OUT/run.txt"
: >"$RUN"
PASS=0
FAIL=0

say() { printf '%s\n' "$*" | tee -a "$RUN"; }
check() {
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  ($3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=$2 actual=$3"
  fi
}
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }
wpl() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" \
          "--exec=define('WP_ENVIRONMENT_TYPE','local');" "$@"; }
probe() { wpx eval-file "$PROBE" "$@" 2>>"$RUN"; }
probel() { wpl eval-file "$PROBE" "$@" 2>>"$RUN"; }
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }

restore() {
  if [[ -d "$KEEP/src" ]]; then
    rm -rf "$INSTALLED/src"
    cp -a "$KEEP/src" "$INSTALLED/src"
  fi
  rm -f "$PROBE" "$PROBE62"
}
trap restore EXIT

# --------------------------------------------------------------- the trees
cp -a "$INSTALLED/src" "$KEEP/src"
BASE_VERSION="$(sed -n 's/^ \* Version: *//p' "$INSTALLED/tecteb-marketplace-core.php" | head -1)"
BASE_HASH="$(cd "$KEEP" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16)"
cp "$PROBE_SRC" "$PROBE"
cp "$PROBE62_SRC" "$PROBE62"

say "=== the trees ==="
say "base_version=$BASE_VERSION"
say "base_src_digest=$BASE_HASH"
check "0a the installed tree is the delivered alpha.62" "0.1.0-alpha.62" "$BASE_VERSION"

# The refused product belongs to the `alpha.62` fixture; this round does not
# build a second one. Arranged on whichever tree is installed.
wpl eval-file "$PROBE62" cart-arrange >>"$RUN" 2>&1

# ============================================== BEFORE, on alpha.62's bytes
say ""
say "=== BEFORE · §1 the free order, on the delivered bytes ==="
probe reset >/dev/null
B1="$(probel pay-paths)"
printf '%s\n' "$B1" >>"$RUN"
check "1a the sale gate is open, so this is about the rule" "allowed" "$(val "$B1" sale_gate_open)"
check "1b the order carries the figure the basket would make" "100000" "$(val "$B1" line_total)"
check "1c needs_payment is answered NO" "no" "$(val "$B1" needs_payment)"
check "1d …so the CLASSIC checkout would take the free branch" "without_payment" "$(val "$B1" classic_branch)"
check "1e …and the BLOCK checkout would too" "without_payment" "$(val "$B1" store_api_branch)"
check "1f the pay link would hand the order to a gateway" "yes" "$(val "$B1" pay_action_would_charge)"
check "1g and nothing refuses the Store API" "passed" "$(val "$B1" store_api_order_processed)"

say ""
say "=== BEFORE · §2 the item nobody holds ==="
probe reset >/dev/null
B2="$(probe partial-void)"
printf '%s\n' "$B2" >>"$RUN"
check "2a the second partial payment wrote NO row" "1" "$(val "$B2" rows_for_item)"
check "2b the void succeeds" "supplier_payment_voided" "$(val "$B2" void_first)"
check "2c …and the item is claimed by nobody" "0" "$(val "$B2" live_claims)"
check "2d …with no holder at all" "__none__" "$(val "$B2" holder)"
RESERVED_BEFORE="$(val "$B2" withdrawal_reserved)"
printf '%s\n' "reserved_before=$RESERVED_BEFORE" >>"$RUN"
if [[ "$RESERVED_BEFORE" == TAKEN:* ]]; then
  PASS=$((PASS + 1)); say "PASS  2e …and the withdrawal cycle TAKES the line  ($RESERVED_BEFORE)"
else
  FAIL=$((FAIL + 1)); say "FAIL  2e expected=TAKEN:<id> actual=$RESERVED_BEFORE"
fi

# ================================================== AFTER, on this checkout
say ""
say "=== swapping in this checkout's src/ ==="
probe reset >/dev/null
rm -rf "$INSTALLED/src"
cp -a "$REPO/src" "$INSTALLED/src"
AFTER_HASH="$(cd "$INSTALLED" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16)"
say "after_src_digest=$AFTER_HASH"
if [[ "$AFTER_HASH" != "$BASE_HASH" ]]; then
  PASS=$((PASS + 1)); say "PASS  0b the tree under test is a different one  ($AFTER_HASH)"
else
  FAIL=$((FAIL + 1)); say "FAIL  0b the src/ swap changed nothing"
fi

say ""
say "=== AFTER · §1 the order pays or it does not sell ==="
A1="$(probel pay-paths)"
printf '%s\n' "$A1" >>"$RUN"
check "3a the sale gate is still open" "allowed" "$(val "$A1" sale_gate_open)"
check "3b needs_payment is left ALONE" "yes" "$(val "$A1" needs_payment)"
check "3c …so the classic checkout stays on the paying branch" "payment" "$(val "$A1" classic_branch)"
check "3d …and so does the block checkout" "payment" "$(val "$A1" store_api_branch)"
check "3e the pay link is refused before any gateway" "no" "$(val "$A1" pay_action_would_charge)"
check "3f …with a sentence the shopper can act on" \
  "خرید_«کالای_سبد_۶۲»_در_حال_حاضر_امکان‌پذیر_نیست؛_تنظیمات_فروش_این_کالا_نیاز_به_بازبینی_دارد._لطفاً_با_پشتیبانی_تماس_بگیرید." \
  "$(val "$A1" pay_notice)"
check "3g and both Store API routes throw before the branch" "threw:RouteException" "$(val "$A1" store_api_order_processed)"
check "3h the order itself is untouched" "pending" "$(val "$A1" status_after)"
check "3i …and unpaid" "__none__" "$(val "$A1" date_paid)"

say ""
say "=== AFTER · §1 control: a rule the line satisfies still sells ==="
C1="$(probel pay-control)"
printf '%s\n' "$C1" >>"$RUN"
check "4a needs_payment is true" "yes" "$(val "$C1" needs_payment)"
check "4b the classic checkout pays" "payment" "$(val "$C1" classic_branch)"
check "4c the pay link WOULD charge" "yes" "$(val "$C1" pay_action_would_charge)"
check "4d nothing is said to the shopper" "__none__" "$(val "$C1" pay_notice)"
check "4e and the Store API passes" "passed" "$(val "$C1" store_api_order_processed)"

say ""
say "=== AFTER · §2 the live claim is handed on, not dropped ==="
probe reset >/dev/null
A2="$(probe partial-void)"
printf '%s\n' "$A2" >>"$RUN"
check "5a both payments wrote a row" "2" "$(val "$A2" rows_for_item)"
check "5b the void still succeeds" "supplier_payment_voided" "$(val "$A2" void_first)"
check "5c the item is still claimed" "1" "$(val "$A2" live_claims)"
check "5d …by the payment that survived" "surviving_payment" "$(val "$A2" holder)"
check "5e both rows are still on disk" "2" "$(val "$A2" rows_still_on_disk)"
check "5f and the withdrawal cycle is REFUSED" "refused" "$(val "$A2" withdrawal_reserved)"

say ""
say "=== AFTER · §2 control: with every payment voided, the item is free ==="
probe reset >/dev/null
A3="$(probe partial-void-both)"
printf '%s\n' "$A3" >>"$RUN"
check "6a the second void succeeds too" "supplier_payment_voided" "$(val "$A3" void_second)"
check "6b nothing covers the line now" "0" "$(val "$A3" live_claims)"
check "6c no holder" "__none__" "$(val "$A3" holder)"
check "6d both rows are kept" "2" "$(val "$A3" rows_still_on_disk)"
RESERVED_AFTER="$(val "$A3" withdrawal_reserved)"
if [[ "$RESERVED_AFTER" == TAKEN:* ]]; then
  PASS=$((PASS + 1)); say "PASS  6e …and a fully voided line CAN be reserved  ($RESERVED_AFTER)"
else
  FAIL=$((FAIL + 1)); say "FAIL  6e expected=TAKEN:<id> actual=$RESERVED_AFTER"
fi

# ------------------------------------------------- giving back what we spent
say ""
say "=== giving back what this run spent ==="
probe reset >/dev/null
R="$(probe report)"
printf '%s\n' "$R" >>"$RUN"
check "9a the fixture's order line is gone" "__none__" "$(val "$R" line)"

restore
trap - EXIT
BACK_HASH="$(cd "$INSTALLED" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16)"
check "9b the installed bytes are back" "$BASE_HASH" "$BACK_HASH"
rm -rf "$KEEP"

say ""
say "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
