#!/usr/bin/env bash
# `alpha.69` on the disposable site: the owner's two code items, each
# reproduced on the bytes of the DELIVERED `alpha.68` package and then
# measured again on this tree.
#
# WHAT IS MEASURED, and why each one is a before/after rather than a claim
#
#   §1 — the owner has DECIDED the allocation rule, so an inexact partial
#        return is no longer refused: it is floored in the line's own
#        smallest unit, the remainder goes to the LAST return of the line,
#        and the totals close on the original document. `alpha.68` refused
#        the owner's own example outright — a return of one of three on a
#        line whose total is ۱۰۱ تومان — and left the request stuck at
#        «approved», so «one then two» was impossible in both orders.
#
#   §2 — `DbLedgerRepository::balances()` cast the database's exact `SUM()`
#        text to `int`, and a cast SATURATES rather than failing; and it
#        guarded the rescaling multiplication but not the addition of the
#        products. Both halves are measured, and the most negative amount is
#        measured too because `abs(PHP_INT_MIN)` is a float, so `alpha.68`
#        does not refuse there — it stops the page with a `TypeError`.
#
#   Plus CONTROLS, each of which must answer the SAME on both trees: the
#   owner's exact-sum control (۱۰۰ at e0 plus ۸۸٫۳۷۵ at e4 is ۱۸۸٫۳۷۵), the
#   two-currency refusal, a rescale that already overflowed and already
#   refused, a partial return that DOES divide, the second press of a
#   refund, and the per-unit column this round did NOT touch. A measurement
#   that only ever looks at the broken case cannot tell a fix from a feature
#   that stopped working (`alpha.46`).
#
# ⛔ THE BASE PACKAGE HAS TO BE THE INSTALLED ONE. Check `0a` asserts it:
# running this script with a LATER package installed reports that round's
# fixes as this round's failures, which `CLAUDE.md` records as a rule after
# it happened twice.
#
# ⛔ AND THE «AFTER» TREE COMES FROM `$REPO/src`, never from the install:
# «what is installed» and «what this checkout says» are two things, and
# `alpha.66`'s run measured a stale copy and reported eleven failures about
# code that was already fixed.
#
# ⛔ NO REAL MAIL, SMS OR PAYMENT. The probe short-circuits `wp_mail` on
# every run, and the order gate is opened with the plugin's OWN local trial
# key, which this script gives back in a trap.
#
# ⛔ Dokan is NOT activated and cannot be in this environment (`alpha.53`).
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha68-reproduction.sh docs/evidence/alpha68-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha68-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_REF="${TMC_BASE_REF:-}"
PROBE_SRC="$REPO/tests/Support/alpha68-probe.php"
PROBE="$WP_PATH/alpha68-probe.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha68-XXXXXX)"

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
note() { say "NOTE  $1  $2"; }
wpx() { "$PHPBIN" "$WPCLI" --allow-root --path="$WP_PATH" "$@"; }
probe() { wpx eval-file "$PROBE" "$@" 2>>"$RUN"; }
# The order gate needs the environment DECLARED, the way every round since
# `alpha.7` has exercised it. The trial switch is asked for and given back
# inside this script — a key left on by an earlier run answers the gate
# about a different site (`alpha.66`).
probel() {
  wpx --exec='define("WP_ENVIRONMENT_TYPE","local");' eval-file "$PROBE" "$@" 2>>"$RUN"
}
val() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }
digest() { (cd "$1" && find src -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16); }

restore() {
  if [[ -d "$KEEP/src" ]]; then
    rm -rf "$INSTALLED/src"
    cp -a "$KEEP/src" "$INSTALLED/src"
  fi
  if [[ -f "$PROBE" ]]; then
    wpx --exec='define("WP_ENVIRONMENT_TYPE","local");' \
      eval-file "$PROBE" trial-off >/dev/null 2>&1
    wpx eval-file "$PROBE" reset >/dev/null 2>&1
  fi
  rm -f "$PROBE"
}
trap restore EXIT

# --------------------------------------------------------------- the trees
cp -a "$INSTALLED/src" "$KEEP/src"
ORIGINAL_HASH="$(digest "$KEEP")"
NOW_HASH="$(digest "$REPO")"
cp "$PROBE_SRC" "$PROBE"

if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(sed -n 's/^0\.1\.0-alpha\.68 *\([0-9a-f]\{7,\}\).*$/\1/p' "$REPO/dist/DELIVERED.txt" | head -1)"
fi
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(cd "$REPO" && git log --format=%H --grep='^alpha\.68:' -1)"
fi
say "=== the trees ==="
say "base_ref=$BASE_REF"
say "this_checkout_src_digest=$NOW_HASH"
say "installed_src_digest=$ORIGINAL_HASH"
if [[ -z "$BASE_REF" ]]; then
  say "FAIL  0a the alpha.68 commit could not be resolved"
  exit 1
fi
mkdir -p "$KEEP/base"
(cd "$REPO" && git archive "$BASE_REF" src) | tar -x -C "$KEEP/base" || {
  say "FAIL  0a the alpha.68 tree could not be checked out"
  exit 1
}
BASE_HASH="$(digest "$KEEP/base")"
say "base_src_digest=$BASE_HASH"
check "0a the installed package is this round's base (alpha.68)" "$BASE_HASH" "$ORIGINAL_HASH"
check "0b this checkout differs from the base" "differ" \
  "$([[ "$NOW_HASH" == "$BASE_HASH" ]] && echo same || echo differ)"

use_tree() {
  rm -rf "$INSTALLED/src"
  cp -a "$1/src" "$INSTALLED/src"
}

# ⛔ ONE DATABASE, TWO SETS OF CODE BYTES. This round adds NO migration, so
# the structure is 34 in both halves because it already was — asserted, not
# assumed, because a structure that moved in the middle would make the two
# halves two sites.
SCHEMA="$(wpx option get tmc_schema_version 2>/dev/null | head -1)"
say "schema_for_both_halves=$SCHEMA"
check "0c the structure is 34 and this round does not move it" "34" "$SCHEMA"
check "0d no migration file changed in this round" "0" \
  "$(cd "$REPO" && git diff --name-only "$BASE_REF" -- 'src/**/Migrations/' | wc -l | tr -d ' ')"

# =========================================================== BEFORE (alpha.68)
use_tree "$KEEP/base"
check "0e the installed tree is now alpha.68's" "$BASE_HASH" "$(digest "$INSTALLED")"
probel trial-off >/dev/null
probe reset >/dev/null

say ""
say "=== BEFORE · §2 the ledger sum saturates, overflows and throws ==="
B_AR="$(probe books-arrange)"; printf '%s\n' "$B_AR" >>"$RUN"
check "2a the ledger subject owns no rows of anybody else's" "0" "$(val "$B_AR" pre_existing_rows)"
note  "2b this build's integer ceiling" "$(val "$B_AR" php_int_max)"
B_CT="$(probe books-control)"; printf '%s\n' "$B_CT" >>"$RUN"
check "2c the owner's control is exact" "1883750" "$(val "$B_CT" control_minor)"
check "2d …at the finer exponent" "4" "$(val "$B_CT" control_exponent)"
check "2e …and says so" "yes" "$(val "$B_CT" control_exact)"
B_GR="$(probe books-group-overflow)"; printf '%s\n' "$B_GR" >>"$RUN"
check "2f the database answers the wide sum truthfully" "18446744073709551614" "$(val "$B_GR" db_sum)"
# ⛔ THE FINDING, first half.
check "2g THE DEFECT: the cast saturates to the ceiling" \
  "9223372036854775807" "$(val "$B_GR" group_minor)"
check "2h …and the wrong figure is called exact" "yes" "$(val "$B_GR" group_exact)"
check "2i …with nothing refused" "__none__" "$(val "$B_GR" group_refused)"
B_SM="$(probe books-sum-overflow)"; printf '%s\n' "$B_SM" >>"$RUN"
# ⛔ THE FINDING, second half: each group fits, the total does not.
check "2j THE DEFECT: the total becomes a float" "yes" \
  "$([[ "$(val "$B_SM" sum_minor)" == *E+* ]] && echo yes || echo no)"
note  "2k what it printed" "$(val "$B_SM" sum_minor)"
check "2l …and that is called exact too" "yes" "$(val "$B_SM" sum_exact)"
B_SC="$(probe books-scale-overflow)"; printf '%s\n' "$B_SC" >>"$RUN"
check "2m a rescale that overflows already refused" \
  "scale_exceeds_integer_range" "$(val "$B_SC" scale_refused)"
check "2n …and carried no readable figure" "__null__" "$(val "$B_SC" scale_minor)"
B_NG="$(probe books-most-negative)"; printf '%s\n' "$B_NG" >>"$RUN"
# ⛔ THE FINDING, third half: not a refusal — a stopped page.
check "2o THE DEFECT: the most negative amount throws" "TypeError" "$(val "$B_NG" negative_error)"
B_TC="$(probe books-two-currencies)"; printf '%s\n' "$B_TC" >>"$RUN"
check "2p two currencies are refused by name" "mixed_currencies" "$(val "$B_TC" two_currencies_refused)"
check "2q …with no total at all" "__null__" "$(val "$B_TC" two_currencies_minor)"
B_RP="$(probe books-report)"; printf '%s\n' "$B_RP" >>"$RUN"
# ⛔ THE CONSUMER, on the base: it prints the saturated figure as the
# marketplace's balance and names no difference at all.
check "2r THE DEFECT: the reconciliation report prints the saturated figure" \
  "9223372036854775807" "$(val "$B_RP" report_printed_minor)"
check "2s …and names nothing unsummable" "no" "$(val "$B_RP" report_names_not_summable)"

say ""
say "=== BEFORE · §1 the owner's own example is refused outright ==="
probel trial-on >/dev/null
B_SA="$(probel split-arrange)"; printf '%s\n' "$B_SA" >>"$RUN"
check "1a the order gate is open on the trial key" "yes" "$(val "$B_SA" gate_ready)"
check "1b the line is three units" "3" "$(val "$B_SA" item_quantity)"
check "1c …۱۰۱ تومان in total, at exponent 4" "1010000" "$(val "$B_SA" item_base_minor)"
check "1d …a commission of ۱۲٫۶۲۵" "126250" "$(val "$B_SA" item_commission_minor)"
check "1e …and a share of ۸۸٫۳۷۵" "883750" "$(val "$B_SA" item_share_minor)"
check "1f the total does not divide by three" "no" "$(val "$B_SA" base_divides_exactly)"
B_SS="$(probel split-screen)"; printf '%s\n' "$B_SS" >>"$RUN"
check "1g the screen is told the split is not exact" "no" "$(val "$B_SS" screen_exact)"
check "1h …but nothing tells it the remainder" "no" "$(val "$B_SS" screen_has_remainder_key)"
B_O1="$(probel split-one)"; printf '%s\n' "$B_O1" >>"$RUN"
# ⛔ THE FINDING: refused at the goods, so the money is never reached.
check "1i THE DEFECT: accepting one of three is refused" \
  "return_share_not_exact" "$(val "$B_O1" one_receive_code)"
check "1j …the money step is not even attempted" "__not_attempted__" "$(val "$B_O1" one_refund_code)"
check "1k …and the request is stuck at approved" "approved" "$(val "$B_O1" one_status)"
B_O2="$(probel split-two)"; printf '%s\n' "$B_O2" >>"$RUN"
check "1l …and two of three is refused the same way" \
  "return_share_not_exact" "$(val "$B_O2" two_receive_code)"
B_TT="$(probel split-totals)"; printf '%s\n' "$B_TT" >>"$RUN"
check "1m so nothing of the line came back" "0" "$(val "$B_TT" split_returned_quantity)"
B_UP="$(probel split-unit-price)"; printf '%s\n' "$B_UP" >>"$RUN"
check "1n the per-unit column is empty rather than rounded" "__null__" "$(val "$B_UP" unit_price_minor)"
B_EA="$(probel even-arrange)"; printf '%s\n' "$B_EA" >>"$RUN"
check "1o the control line divides exactly" "yes" "$(val "$B_EA" base_divides_exactly)"
B_EO="$(probel even-one)"; printf '%s\n' "$B_EO" >>"$RUN"
check "1p a divisible partial return is recorded" "return_refunded" "$(val "$B_EO" even_refund_code)"
check "1q …one third of the base" "1000000" "$(val "$B_EO" even_refund_minor)"
check "1r …one third of the commission" "125000" "$(val "$B_EO" even_commission_reversed_minor)"
check "1s …the share derived from the two" "yes" "$(val "$B_EO" even_share_is_derived)"
check "1t …three ledger lines, all in one unit" "4" "$(val "$B_EO" even_ledger_exponents)"
check "1u …summing to nothing" "0" "$(val "$B_EO" even_ledger_sum)"
check "1v …and a second press writes no second document" \
  "already_refunded" "$(val "$B_EO" even_second_press)"
check "1w …still three lines for that return" "3" "$(val "$B_EO" even_rows_for_return)"
B_RH="$(probe rollback-hazard)"; printf '%s\n' "$B_RH" >>"$RUN"
check "1x the rollback gate answers on the base too" "yes" "$(val "$B_RH" has_rollback_gate)"
note  "1y its verdict while this fixture's rows are present" "$(val "$B_RH" gate_verdict)"
probel trial-off >/dev/null
probe reset >/dev/null

# ============================================================ AFTER (this tree)
use_tree "$REPO"
check "0f the installed tree is now this checkout's" "$NOW_HASH" "$(digest "$INSTALLED")"
AF_RP="$(probe report)"; printf '%s\n' "$AF_RP" >>"$RUN"
check "0g the allocation class exists on this tree only" "yes" "$(val "$AF_RP" has_return_allocation)"

say ""
say "=== AFTER · §2 an out-of-range total is refused by name, with no figure ==="
A_AR="$(probe books-arrange)"; printf '%s\n' "$A_AR" >>"$RUN"
check "2A the ledger subject still owns no rows of anybody else's" "0" "$(val "$A_AR" pre_existing_rows)"
A_CT="$(probe books-control)"; printf '%s\n' "$A_CT" >>"$RUN"
check "2B the owner's control is unchanged" "1883750" "$(val "$A_CT" control_minor)"
check "2C …at the finer exponent" "4" "$(val "$A_CT" control_exponent)"
check "2D …and still exact" "yes" "$(val "$A_CT" control_exact)"
A_GR="$(probe books-group-overflow)"; printf '%s\n' "$A_GR" >>"$RUN"
check "2E a group's own sum out of range is refused" \
  "total_exceeds_integer_range" "$(val "$A_GR" group_refused)"
check "2F …with no figure that could be read as a total" "__null__" "$(val "$A_GR" group_minor)"
check "2G …and exact is forbidden for it" "no" "$(val "$A_GR" group_exact)"
A_SM="$(probe books-sum-overflow)"; printf '%s\n' "$A_SM" >>"$RUN"
check "2H groups that each fit but do not add up are refused" \
  "total_exceeds_integer_range" "$(val "$A_SM" sum_refused)"
check "2I …with no figure" "__null__" "$(val "$A_SM" sum_minor)"
check "2J …and not called exact" "no" "$(val "$A_SM" sum_exact)"
A_SC="$(probe books-scale-overflow)"; printf '%s\n' "$A_SC" >>"$RUN"
check "2K the rescale refusal is unchanged" \
  "scale_exceeds_integer_range" "$(val "$A_SC" scale_refused)"
A_NG="$(probe books-most-negative)"; printf '%s\n' "$A_NG" >>"$RUN"
check "2L the most negative amount no longer throws" "__none__" "$(val "$A_NG" negative_error)"
check "2M …it is refused by name" "total_exceeds_integer_range" "$(val "$A_NG" negative_refused)"
check "2N …with no figure" "__null__" "$(val "$A_NG" negative_minor)"
A_TC="$(probe books-two-currencies)"; printf '%s\n' "$A_TC" >>"$RUN"
check "2O two currencies are still refused by name" \
  "mixed_currencies" "$(val "$A_TC" two_currencies_refused)"
check "2P …with no total at all" "__null__" "$(val "$A_TC" two_currencies_minor)"
A_RP2="$(probe books-report)"; printf '%s\n' "$A_RP2" >>"$RUN"
check "2Q the reconciliation report prints no figure" "__null__" "$(val "$A_RP2" report_printed_minor)"
check "2R …and names the account unsummable" "yes" "$(val "$A_RP2" report_names_not_summable)"

say ""
say "=== AFTER · §1 the owner's own example is allocated and closes exactly ==="
probel trial-on >/dev/null
A_SA="$(probel split-arrange)"; printf '%s\n' "$A_SA" >>"$RUN"
check "1A the same line, unchanged in every figure" "1010000" "$(val "$A_SA" item_base_minor)"
check "1B …the same commission" "126250" "$(val "$A_SA" item_commission_minor)"
check "1C …the same share" "883750" "$(val "$A_SA" item_share_minor)"
A_SS="$(probel split-screen)"; printf '%s\n' "$A_SS" >>"$RUN"
check "1D the screen is told the remainder" "yes" "$(val "$A_SS" screen_has_remainder_key)"
check "1E …two ten-thousandths on the base" "2" "$(val "$A_SS" screen_remainder_base)"
check "1F …one on the commission" "1" "$(val "$A_SS" screen_remainder_commission)"
check "1G …and that this is not the last return of the line" "no" "$(val "$A_SS" screen_last_of_line)"
A_O1="$(probel split-one)"; printf '%s\n' "$A_O1" >>"$RUN"
check "1H accepting one of three now passes" "return_received" "$(val "$A_O1" one_receive_code)"
check "1I …the money is recorded" "return_refunded" "$(val "$A_O1" one_refund_code)"
check "1J …floored in the line's own smallest unit" "336666" "$(val "$A_O1" one_refund_minor)"
check "1K …the commission floored the same way" "42083" "$(val "$A_O1" one_commission_reversed_minor)"
check "1L …the share derived, never rounded on its own" "yes" "$(val "$A_O1" one_share_is_derived)"
check "1M …which is the two subtracted" "294583" "$(val "$A_O1" one_vendor_share_reversed_minor)"
check "1N …three ledger lines, all in one unit" "4" "$(val "$A_O1" one_ledger_exponents)"
check "1O …summing to nothing" "0" "$(val "$A_O1" one_ledger_sum)"
check "1P …and a second press writes no second document" \
  "already_refunded" "$(val "$A_O1" one_second_press)"
A_O2="$(probel split-two)"; printf '%s\n' "$A_O2" >>"$RUN"
check "1Q the remaining two are the LAST return of the line" \
  "return_refunded" "$(val "$A_O2" two_refund_code)"
check "1R …and carry the remainder" "673334" "$(val "$A_O2" two_refund_minor)"
check "1S …the commission remainder too" "84167" "$(val "$A_O2" two_commission_reversed_minor)"
check "1T …with the share still derived" "yes" "$(val "$A_O2" two_share_is_derived)"
A_TT="$(probel split-totals)"; printf '%s\n' "$A_TT" >>"$RUN"
check "1U the whole line came back" "yes" "$(val "$A_TT" split_whole_line_returned)"
check "1V …the base closes on the document" "yes" "$(val "$A_TT" split_base_closes)"
check "1W …the commission closes" "yes" "$(val "$A_TT" split_commission_closes)"
check "1X …and the share closes" "yes" "$(val "$A_TT" split_share_closes)"
check "1Y nothing was forgiven: the totals are the line's own" \
  "1010000" "$(val "$A_TT" split_returned_base)"
A_UP="$(probel split-unit-price)"; printf '%s\n' "$A_UP" >>"$RUN"
check "1Z the per-unit column is untouched by this round" "__null__" "$(val "$A_UP" unit_price_minor)"
A_EA="$(probel even-arrange)"; printf '%s\n' "$A_EA" >>"$RUN"
check "1AA the control line still divides exactly" "yes" "$(val "$A_EA" base_divides_exactly)"
A_EO="$(probel even-one)"; printf '%s\n' "$A_EO" >>"$RUN"
check "1AB a divisible partial return is recorded as before" \
  "return_refunded" "$(val "$A_EO" even_refund_code)"
check "1AC …the same third of the base" "1000000" "$(val "$A_EO" even_refund_minor)"
check "1AD …the same third of the commission" "125000" "$(val "$A_EO" even_commission_reversed_minor)"
check "1AE …and a second press still writes nothing" \
  "already_refunded" "$(val "$A_EO" even_second_press)"
A_RH="$(probe rollback-hazard)"; printf '%s\n' "$A_RH" >>"$RUN"
check "1AF the rollback gate still counts five stores" "5" "$(val "$A_RH" gate_counts_tables)"
check "1AG …and still has the counted loss question" "yes" "$(val "$A_RH" has_loss_since)"
probel trial-off >/dev/null
probe reset >/dev/null

# ------------------------------------------------------------------ the tree
restore
trap - EXIT
check "0h the installed tree is byte-for-byte the base again" \
  "$BASE_HASH" "$(digest "$INSTALLED")"

say ""
say "=== totals ==="
say "pass=$PASS fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
