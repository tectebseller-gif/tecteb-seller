#!/usr/bin/env bash
# `alpha.65` on the disposable site: ONE finding, reproduced on the bytes of
# the DELIVERED `alpha.64` package and then measured again on this tree.
#
# WHAT IS MEASURED
#
#   `RecordSupplierPayment::void()` read the payment at line 664 and opened
#   its transaction at line 680, and line 684 decided from that unlocked
#   read whether a compensating entry was owed:
#
#       $compensating = $payment->ledgerEvent !== '' ? self::voidEventKey($id) : '';
#
#   A payment recorded against an obligation with no agreed amount carries
#   no money line (`posting=agreed_unknown`, `ledger_event` empty), and
#   `reconcile()` is the documented path that gives it one. Both are POST
#   actions on the SAME admin screen (`SupplierPaymentsPage::handle()`,
#   `'void'` and `'reconcile'`), so «two requests» is the ordinary case.
#
#   The interleaving is SCHEDULED rather than raced for and it is not on this
#   connection: a `TransactionInterface` decorator around the same gateway
#   object runs `tests/Support/concurrent-supplier-reconcile.php` in a
#   separate process on the outermost `begin()` and waits for it to exit.
#   `InterferingDatabase` could not do this — its writes join the transaction
#   under test and a rollback takes them away (`alpha.40`).
#
#   BOTH ORDERINGS are measured, as the owner asked, plus a repeated request
#   and an injected failure of the compensating write. The sequential
#   ordering is the control: a build that is right about the interleaving
#   must still be right about the ordering that already worked.
#
# The script swaps `src/` under the installed plugin, measures, and puts the
# original bytes back in a `trap` with an absolute path (`alpha.37`) — and
# checks the hash afterwards, not `git diff` (`alpha.32`).
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha64-reproduction.sh docs/evidence/alpha64-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha64-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
PROBE_SRC="$REPO/tests/Support/alpha64-probe.php"
PROBE="$WP_PATH/alpha64-probe.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha64-XXXXXX)"

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
taken() {
  # A reservation id rather than a fixed number, because the withdrawal
  # table keeps growing across runs.
  if [[ "$3" == TAKEN:* ]]; then
    PASS=$((PASS + 1)); say "PASS  $1  ($3)"
  else
    FAIL=$((FAIL + 1)); say "FAIL  $1  expected=TAKEN:<id> actual=$3"
  fi
}
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }
probe() {
  TMC_REPO="$REPO" TMC_WP_PATH="$WP_PATH" TMC_WPCLI="$WPCLI" \
    wpx eval-file "$PROBE" "$@" 2>>"$RUN"
}
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }

restore() {
  if [[ -d "$KEEP/src" ]]; then
    rm -rf "$INSTALLED/src"
    cp -a "$KEEP/src" "$INSTALLED/src"
  fi
  rm -f "$PROBE"
}
trap restore EXIT

# --------------------------------------------------------------- the trees
cp -a "$INSTALLED/src" "$KEEP/src"
BASE_VERSION="$(sed -n 's/^ \* Version: *//p' "$INSTALLED/tecteb-marketplace-core.php" | head -1)"
BASE_HASH="$(cd "$KEEP" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16)"
cp "$PROBE_SRC" "$PROBE"

say "=== the trees ==="
say "base_version=$BASE_VERSION"
say "base_src_digest=$BASE_HASH"
check "0a the installed tree is the delivered alpha.64" "0.1.0-alpha.64" "$BASE_VERSION"

# ============================================== BEFORE, on alpha.64's bytes
say ""
say "=== BEFORE · the fixture: a payment with no money line ==="
probe reset >/dev/null
B0="$(probe arrange)"
printf '%s\n' "$B0" >>"$RUN"
check "1a the obligation has no agreed amount, so the record is unposted" \
  "agreed_unknown" "$(val "$B0" posting)"
check "1b …and carries no ledger event" "__none__" "$(val "$B0" ledger_event_before)"
check "1c …and is on the reconcile path" "yes" "$(val "$B0" reconcilable)"

say ""
say "=== BEFORE · a reconcile commits inside the void's gap ==="
probe reset >/dev/null
B1="$(probe void-after-reconcile)"
printf '%s\n' "$B1" >>"$RUN"
check "2a the gap was really used" "yes" "$(val "$B1" interleaved)"
check "2b the other request reconciled the payment" \
  "supplier_payment_reconciled" "$(val "$B1" child_reconcile_code)"
check "2c …and gave it a money line" "posted" "$(val "$B1" child_reconcile_posting)"
check "2d the void then SUCCEEDS" "supplier_payment_voided" "$(val "$B1" void_code)"
check "2e …and writes NO compensating document" "__none__" "$(val "$B1" compensating_event)"
check "2f the row records no void event either" "__none__" "$(val "$B1" row_void_event)"
check "2g the payout entry stands" "yes" "$(val "$B1" payout_entry_exists)"
check "2h …with nothing reversing it" "no" "$(val "$B1" reversal_entry_exists)"
check "2i so the books still say the supplier was paid" "0" "$(val "$B1" vendor_earning_balance)"
check "2j and the items are released" "1" "$(val "$B1" items_released_reported)"
check "2k …with no live claim left" "0" "$(val "$B1" live_claims)"
taken "2l …so the withdrawal cycle takes the line" "" "$(val "$B1" withdrawal_reserved)"

say ""
say "=== BEFORE · the mirror: a void commits inside the reconcile's gap ==="
probe reset >/dev/null
B2="$(probe reconcile-after-void)"
printf '%s\n' "$B2" >>"$RUN"
check "3a the gap was used" "yes" "$(val "$B2" interleaved)"
check "3b the other request voided the payment" "supplier_payment_voided" "$(val "$B2" child_void_code)"
# `markReconciled()` already carried `voided_at IS NULL`, so this ordering was
# refused on `alpha.64` too — but as a storage failure, and only AFTER a
# ledger entry had been written and had to be rolled back. The answer, not
# the outcome, is what this round changes.
say "before_mirror_code=$(val "$B2" reconcile_code)"
check "3c no payout entry survives either way" "no" "$(val "$B2" payout_entry_exists)"

say ""
say "=== BEFORE · the control: the same two requests, in sequence ==="
probe reset >/dev/null
B3="$(probe reconcile-then-void)"
printf '%s\n' "$B3" >>"$RUN"
check "4a the sequential reconcile works" "supplier_payment_reconciled" "$(val "$B3" reconcile_code)"
check "4b the sequential void works" "supplier_payment_voided" "$(val "$B3" void_code)"
check "4c …and DOES write the compensating document" \
  "yes" "$([[ "$(val "$B3" compensating_event)" == supplier-payment-void:* ]] && echo yes || echo no)"
check "4d …which reverses the payout" "yes" "$(val "$B3" reversal_entry_exists)"
check "4e so the supplier is owed again" "-900000" "$(val "$B3" vendor_earning_balance)"

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
say "=== AFTER · the decision is taken from the locked row ==="
probe reset >/dev/null
A1="$(probe void-after-reconcile)"
printf '%s\n' "$A1" >>"$RUN"
check "5a the gap is still used" "yes" "$(val "$A1" interleaved)"
check "5b the other request still reconciles" \
  "supplier_payment_reconciled" "$(val "$A1" child_reconcile_code)"
check "5c the void succeeds" "supplier_payment_voided" "$(val "$A1" void_code)"
check "5d …and NOW writes the compensating document" \
  "yes" "$([[ "$(val "$A1" compensating_event)" == supplier-payment-void:* ]] && echo yes || echo no)"
check "5e the row records it too" \
  "yes" "$([[ "$(val "$A1" row_void_event)" == supplier-payment-void:* ]] && echo yes || echo no)"
check "5f the payout entry is reversed" "yes" "$(val "$A1" reversal_entry_exists)"
check "5g so the supplier is owed again" "-900000" "$(val "$A1" vendor_earning_balance)"
check "5h and the items are still released" "1" "$(val "$A1" items_released_reported)"

say ""
say "=== AFTER · the mirror ordering is REFUSED BY NAME ==="
probe reset >/dev/null
A2="$(probe reconcile-after-void)"
printf '%s\n' "$A2" >>"$RUN"
check "6a the other request voided first" "supplier_payment_voided" "$(val "$A2" child_void_code)"
check "6b the reconcile says what happened" "supplier_payment_is_void" "$(val "$A2" reconcile_code)"
check "6c …and writes no money line at all" "__none__" "$(val "$A2" reconcile_event)"
check "6d no payout entry exists" "no" "$(val "$A2" payout_entry_exists)"
check "6e the row keeps no ledger event" "__none__" "$(val "$A2" row_ledger_event)"

say ""
say "=== AFTER · the sequential control still behaves ==="
probe reset >/dev/null
A3="$(probe reconcile-then-void)"
printf '%s\n' "$A3" >>"$RUN"
check "7a the reconcile works" "supplier_payment_reconciled" "$(val "$A3" reconcile_code)"
check "7b the void works" "supplier_payment_voided" "$(val "$A3" void_code)"
check "7c with its compensating document" "yes" "$(val "$A3" reversal_entry_exists)"
check "7d and the supplier owed again" "-900000" "$(val "$A3" vendor_earning_balance)"

say ""
say "=== AFTER · a repeated void makes no second financial effect ==="
probe reset >/dev/null
A4="$(probe void-repeat)"
printf '%s\n' "$A4" >>"$RUN"
check "8a the first void succeeds" "supplier_payment_voided" "$(val "$A4" first_void)"
check "8b the second is told it is already void" "supplier_payment_already_void" "$(val "$A4" second_void)"
check "8c …and claims no compensating document" "__none__" "$(val "$A4" second_compensating)"
check "8d the row is void once" "yes" "$(val "$A4" row_is_void)"
check "8e the claim row is kept" "1" "$(val "$A4" claim_rows)"

say ""
say "=== AFTER · a compensating write that fails takes the void with it ==="
probe reset >/dev/null
A5="$(probe void-reversal-fails)"
printf '%s\n' "$A5" >>"$RUN"
check "9a the payment is reconciled first" "supplier_payment_reconciled" "$(val "$A5" reconcile_code)"
check "9b the reversal's event key is occupied" "yes" "$(val "$A5" key_taken)"
check "9c so the void is REFUSED" "storage_failed" "$(val "$A5" void_code)"
check "9d the document is not void" "no" "$(val "$A5" row_is_void)"
check "9e …and the item is still held" "1" "$(val "$A5" live_claims)"
check "9f …so the withdrawal cycle cannot take it" "refused" "$(val "$A5" withdrawal_reserved)"

# ------------------------------------------------- giving back what we spent
say ""
say "=== giving back what this run spent ==="
probe reset >/dev/null
R="$(probe report)"
printf '%s\n' "$R" >>"$RUN"
check "10a the fixture's order line is gone" "__none__" "$(val "$R" line)"
check "10b …and so are its payments" "0" "$(val "$R" payments)"

restore
trap - EXIT
BACK_HASH="$(cd "$INSTALLED" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16)"
check "10c the installed bytes are back" "$BASE_HASH" "$BACK_HASH"
rm -rf "$KEEP"

say ""
say "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
