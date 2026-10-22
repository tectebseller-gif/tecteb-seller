#!/usr/bin/env bash
# `alpha.68` on the disposable site: the four things the owner asked for, each
# reproduced on the bytes of the DELIVERED `alpha.67` package and then
# measured again on this tree.
#
# WHAT IS MEASURED, and why each one is a before/after rather than a claim
#
#   §1 — `M0033::carriedBy()` turned a FAILED read of
#        `information_schema.COLUMNS` into `false`, and the readers then
#        substituted `NULL` for columns that were PRESENT and carrying
#        exponent 4. On an upgraded database that is not «conservative»: it is
#        a reinterpretation, and a share of ۸۸٫۳۷۵ تومان reads as ۸۸۳٬۷۵۰.
#        The failure is injected on that one read, on a vendor who holds
#        exponent-0 history AND an exponent-4 line, and the factor is printed.
#
#   §2 — `DbLedgerRepository::balances()` summed `amount_minor` grouped by
#        account alone. The owner's control: ۱۰۰ تومان at exponent 0 plus
#        ۸۸٫۳۷۵ تومان at exponent 4 is exactly ۱۸۸٫۳۷۵ — `1883750` at
#        exponent 4, not `883850`. And two CURRENCIES must not be summed at
#        all, in either direction.
#
#   §3 — `ManageReturns::shareOf()` used `intdiv()` to split a partial return,
#        and `CaptureOrder` truncated the per-unit price. On the owner's own
#        example — a line whose total is ۱۰۱ تومان for three units at ۱۲٫۵٪ —
#        a return of one of three asks for ۳۳۶۶۶۶٫۶۶۶… and no exponent makes
#        that an integer. Measured both halves, with the FULL return as the
#        control that must keep working.
#
#   §4 — nothing answered «is going back still safe». Now a count does, in
#        three states, and the count is the gate.
#
#   Plus CONTROLS, each of which must answer the SAME on both trees or in a
#   named direction on purpose: a partial return that DOES divide is still
#   recorded, a whole per-unit price is still written, a healthy read says
#   `present` on both trees, and the full return gives back the whole line.
#   A measurement that only ever looks at the broken case cannot tell a fix
#   from a feature that stopped working (`alpha.46`).
#
# ⛔ THE BASE PACKAGE HAS TO BE THE INSTALLED ONE. Check `0a` asserts it:
# running this script with a LATER package installed reports that round's
# fixes as this round's failures, which `CLAUDE.md` records as a rule after it
# happened twice. The database schema is this tree's (34) in both halves on
# purpose — the same database, two sets of code bytes — and `alpha.67`'s code
# simply does not read the column migration 34 widens.
#
# ⛔ AND THE «AFTER» TREE COMES FROM `$REPO/src`, never from the install:
# «what is installed» and «what this checkout says» are two things, and
# `alpha.66`'s run measured a stale copy and reported eleven failures about
# code that was already fixed.
#
# ⛔ Dokan is NOT activated and cannot be in this environment (`alpha.53`).
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha67-reproduction.sh docs/evidence/alpha67-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha67-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_REF="${TMC_BASE_REF:-}"
PROBE_SRC="$REPO/tests/Support/alpha67-probe.php"
PROBE="$WP_PATH/alpha67-probe.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha67-XXXXXX)"

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
# inside this script — a key left on by an earlier run answers the gate about
# a different site (`alpha.66`).
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
    # THE OPERATIONAL KEY FIRST, and off, whatever it was when we arrived.
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
  BASE_REF="$(sed -n 's/^0\.1\.0-alpha\.67 *\([0-9a-f]\{7,\}\).*$/\1/p' "$REPO/dist/DELIVERED.txt" | head -1)"
fi
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(cd "$REPO" && git log --format=%H --grep='^alpha\.67:' -1)"
fi
say "=== the trees ==="
say "base_ref=$BASE_REF"
say "this_checkout_src_digest=$NOW_HASH"
say "installed_src_digest=$ORIGINAL_HASH"
if [[ -z "$BASE_REF" ]]; then
  say "FAIL  0a the alpha.67 commit could not be resolved"
  exit 1
fi
mkdir -p "$KEEP/base"
(cd "$REPO" && git archive "$BASE_REF" src) | tar -x -C "$KEEP/base" || {
  say "FAIL  0a the alpha.67 tree could not be checked out"
  exit 1
}
BASE_HASH="$(digest "$KEEP/base")"
say "base_src_digest=$BASE_HASH"
check "0a the installed package is this round's base (alpha.67)" "$BASE_HASH" "$ORIGINAL_HASH"
check "0b this checkout differs from the base" "differ" \
  "$([[ "$NOW_HASH" == "$BASE_HASH" ]] && echo same || echo differ)"

use_tree() {
  rm -rf "$INSTALLED/src"
  cp -a "$1/src" "$INSTALLED/src"
}

# THE ADMIN REQUEST, which is the only thing that runs a migration.
# WooCommerce needs its admin functions loaded first or the hook fatals under
# WP-CLI (`alpha.67`).
admin_request() {
  wpx eval '
    if (file_exists(WP_PLUGIN_DIR . "/woocommerce/includes/admin/wc-admin-functions.php")) {
        require_once WP_PLUGIN_DIR . "/woocommerce/includes/admin/wc-admin-functions.php";
    }
    do_action("admin_init");
    echo "ran=yes\n";
  ' 2>>"$RUN"
}

# ⛔ ONE DATABASE, TWO SETS OF CODE BYTES — so the structure is brought to
# THIS tree's (34) BEFORE the «before» half runs, not between the halves. A
# structure that moved in the middle would make the two halves two sites, and
# `alpha.67`'s code writes an integer into the widened column perfectly well:
# the column gained a state, it did not lose one.
use_tree "$REPO"
admin_request >>"$RUN" 2>&1
SCHEMA="$(wpx option get tmc_schema_version 2>/dev/null | head -1)"
say "schema_for_both_halves=$SCHEMA"
check "0b2 the structure is this tree's before either half runs" "34" "$SCHEMA"

# =========================================================== BEFORE (alpha.67)
use_tree "$KEEP/base"
check "0c the installed tree is now alpha.67's" "$BASE_HASH" "$(digest "$INSTALLED")"

say ""
say "=== BEFORE · §1 a failed catalogue read becomes «the columns are absent» ==="
probe reset >/dev/null
B_ARR="$(probe unit-arrange)"; printf '%s\n' "$B_ARR" >>"$RUN"
check "1a the vendor's registry unit is exponent 0" "0" "$(val "$B_ARR" registry_exponent)"
check "1b a legacy line carries no unit" "__null__" "$(val "$B_ARR" row_legacy_exponent)"
check "1c and a new line carries exponent 4" "4" "$(val "$B_ARR" row_exact_exponent)"
B_ST="$(probe unit-state)"; printf '%s\n' "$B_ST" >>"$RUN"
check "1d the question is asked with a boolean" "yes" "$(val "$B_ST" has_carried_by)"
check "1e there is no three-state answer" "no" "$(val "$B_ST" has_three_states)"
check "1f a healthy read says the columns are there" "yes" "$(val "$B_ST" carried_present)"
# ⛔ THE FINDING, at the guard.
check "1g THE DEFECT: a failed read says they are NOT" "no" "$(val "$B_ST" carried_blinded)"
B_OK="$(probe unit-healthy)"; printf '%s\n' "$B_OK" >>"$RUN"
check "1h a healthy read sees the exponent-4 row's unit" "4" "$(val "$B_OK" view_exact_exponent)"
B_BL="$(probe unit-read-failure)"; printf '%s\n' "$B_BL" >>"$RUN"
check "1i the catalogue read really was blinded" "1" "$(val "$B_BL" blinded_reads)"
# ⛔ THE FINDING, as a number.
check "1j THE DEFECT: the exponent-4 row is reported as carrying no unit" \
  "__null__" "$(val "$B_BL" view_exact_exponent)"
check "1k …and is then read at the registry's exponent" "0" "$(val "$B_BL" exact_row_read_at)"
check "1l …though the row states exponent 4" "4" "$(val "$B_BL" exact_row_stated_at)"
check "1m …so a factor of ten thousand is applied" "10000" "$(val "$B_BL" exact_row_factor_applied)"
check "1n …the balance differs from the healthy one" "yes" "$(val "$B_BL" earned_differs)"
check "1o …and nothing says the answer is a fallback" "no" "$(val "$B_BL" balance_unreadable)"
note "1p the exact share invented, in minor units at e4" "$(val "$B_BL" exact_share_lost)"
B_WR="$(probe unit-write-refusal)"; printf '%s\n' "$B_WR" >>"$RUN"
# A NAMED DIRECTION CHANGE, not a defect on either tree: the old guard could
# only refuse, because it had no way of telling «absent» from «could not
# ask». The refusal was the safe half of a two-state answer.
check "1q a unit-bearing write is refused while the read is blinded" "0" "$(val "$B_WR" write_result)"
check "1r …so no row landed" "0" "$(val "$B_WR" row_landed)"

say ""
say "=== BEFORE · §2 the ledger sum ignores the unit ==="
B_BK="$(probe books-sum)"; printf '%s\n' "$B_BK" >>"$RUN"
check "2a the answer is a bare integer" "int" "$(val "$B_BK" balances_shape)"
check "2b …so no unit comes back with it" "__not_reported__" "$(val "$B_BK" vendor_earning_unit)"
note  "2c what the rows at exponent 0 hold" "$(val "$B_BK" rows_at_e0)"
note  "2d what the rows at exponent 4 hold" "$(val "$B_BK" rows_at_e4)"
note  "2e the owner's control, in minor units at e4" "$(val "$B_BK" control_exact_minor_at_e4)"
B_CU="$(probe books-two-currencies)"; printf '%s\n' "$B_CU" >>"$RUN"
# ⛔ THE FINDING: a USD row added into an IRT total.
check "2f THE DEFECT: two currencies are summed" "summed" \
  "$(val "$B_CU" two_currencies_answer | cut -d: -f1)"
check "2g the fixture gave its USD rows back" "0" "$(val "$B_CU" usd_rows_left)"

say ""
say "=== BEFORE · §3 truncation ==="
probel trial-on >/dev/null
B_SA="$(probel split-arrange)"; printf '%s\n' "$B_SA" >>"$RUN"
check "3a the order gate opens on the trial key" "yes" "$(val "$B_SA" gate_ready)"
check "3b the line is three units" "3" "$(val "$B_SA" item_quantity)"
check "3c …whose total is ۱۰۱ تومان at the finer unit" "1010000" "$(val "$B_SA" item_base_minor)"
check "3d …with an exact commission of ۱۲٫۶۲۵" "126250" "$(val "$B_SA" item_commission_minor)"
check "3e …and an exact share of ۸۸٫۳۷۵" "883750" "$(val "$B_SA" item_share_minor)"
# ⛔ THE FINDING, half one.
check "3f THE DEFECT: the per-unit price is truncated" "336666" "$(val "$B_SA" item_unit_price_minor)"
B_UP="$(probel split-unit-price)"; printf '%s\n' "$B_UP" >>"$RUN"
check "3g …and does not reconstruct its own line" "1009998" "$(val "$B_UP" unit_price_times_quantity)"
check "3h …which the line itself contradicts" "1010000" "$(val "$B_UP" base_minor)"
check "3i …so it is not exact" "no" "$(val "$B_UP" unit_price_is_exact)"
B_SP="$(probel split-partial)"; printf '%s\n' "$B_SP" >>"$RUN"
# ⛔ THE FINDING, half two.
check "3j accepting the goods is allowed" "return_received" "$(val "$B_SP" receive_code)"
check "3k THE DEFECT: the partial return is recorded anyway" "return_refunded" "$(val "$B_SP" refund_code)"
check "3l …with a floored third of the base" "336666" "$(val "$B_SP" stored_refund_minor)"
check "3m …and a floored third of the commission" "42083" "$(val "$B_SP" stored_commission_reversed_minor)"
check "3n …though neither divides exactly" "no" "$(val "$B_SP" base_divides_exactly)"
check "3o …and the stored third is not a third" "no" "$(val "$B_SP" stored_refund_is_exact)"
B_SF="$(probel split-full)"; printf '%s\n' "$B_SF" >>"$RUN"
check "3p the rest of the line still comes back whole" "yes" "$(val "$B_SF" whole_line_given_back)"
probel trial-off >/dev/null

say ""
say "=== BEFORE · §4 nothing answers «is going back safe» ==="
B_RB="$(probe rollback-hazard)"; printf '%s\n' "$B_RB" >>"$RUN"
check "4a there is no rollback gate" "no" "$(val "$B_RB" has_rollback_gate)"
check "4b …and nothing answers the question" "__nothing_answers_this__" "$(val "$B_RB" verdict)"
note  "4c rows a release before alpha.67 would misread" "$(val "$B_RB" rows_finer_than_the_registry)"
note  "4d ledger entries it would misread" "$(val "$B_RB" ledger_entries_finer_than_the_registry)"

probe reset >/dev/null

# ============================================================ AFTER (this tree)
say ""
use_tree "$REPO"
check "0d the installed tree is now this checkout's" "$NOW_HASH" "$(digest "$INSTALLED")"

say ""
say "=== AFTER · §1 three answers, and a second way to ask ==="
A_ARR="$(probe unit-arrange)"; printf '%s\n' "$A_ARR" >>"$RUN"
check "5a the same fixture: registry exponent 0" "0" "$(val "$A_ARR" registry_exponent)"
check "5b the same legacy line" "__null__" "$(val "$A_ARR" row_legacy_exponent)"
check "5c the same exponent-4 line" "4" "$(val "$A_ARR" row_exact_exponent)"
A_ST="$(probe unit-state)"; printf '%s\n' "$A_ST" >>"$RUN"
check "5d the boolean question is gone" "no" "$(val "$A_ST" has_carried_by)"
check "5e there are three states" "yes" "$(val "$A_ST" has_three_states)"
check "5f a healthy read says present" "present" "$(val "$A_ST" state_present)"
# ⛔ THE FIX.
check "5g a failed catalogue read still says present" "present" "$(val "$A_ST" state_blinded)"
check "5h …and a table with no such columns says absent" "absent" "$(val "$A_ST" state_absent_table)"
A_BL="$(probe unit-read-failure)"; printf '%s\n' "$A_BL" >>"$RUN"
check "5i the catalogue read really was blinded" "1" "$(val "$A_BL" blinded_reads)"
check "5j the exponent-4 row still states its unit" "4" "$(val "$A_BL" view_exact_exponent)"
check "5k …and the balance is the healthy one" "no" "$(val "$A_BL" earned_differs)"
check "5l …with nothing invented" "0" "$(val "$A_BL" exact_share_lost)"
A_OK="$(probe unit-healthy)"; printf '%s\n' "$A_OK" >>"$RUN"
check "5m a healthy read is unchanged" "4" "$(val "$A_OK" view_exact_exponent)"
check "5n …and says so rather than guessing" "no" "$(val "$A_OK" balance_unreadable)"
A_WR="$(probe unit-write-refusal)"; printf '%s\n' "$A_WR" >>"$RUN"
# ⛔ AND THE DIRECTION CHANGES ON PURPOSE. The write no longer has to refuse,
# because the state is KNOWN to be `present` — the second read answered it.
# Refusing here would be a guard that costs a sale for a question that has an
# answer (`alpha.60`).
check "5o a unit-bearing write now lands while the read is blinded" "1" "$(val "$A_WR" row_landed)"
check "5p …with a real row id" "yes" \
  "$([[ "$(val "$A_WR" write_result)" -gt 0 ]] && echo yes || echo no)"

say ""
say "=== AFTER · §2 one unit, exactly, and two currencies refused ==="
A_BK="$(probe books-sum)"; printf '%s\n' "$A_BK" >>"$RUN"
check "6a each account answers with its unit" "array" "$(val "$A_BK" balances_shape)"
check "6b …named" "IRT/1e4" "$(val "$A_BK" vendor_earning_unit)"
check "6c …and exact" "yes" "$(val "$A_BK" vendor_earning_exact)"
note  "6d the figure, in minor units at e4" "$(val "$A_BK" vendor_earning_raw)"
A_CU="$(probe books-two-currencies)"; printf '%s\n' "$A_CU" >>"$RUN"
check "6e two currencies are refused by name" "mixed_currencies" "$(val "$A_CU" two_currencies_answer)"
check "6f …with no figure at all" "__none__" "$(val "$A_CU" two_currencies_minor)"
check "6g …and both named" "IRT,USD" "$(val "$A_CU" two_currencies_currencies)"
check "6h the fixture gave its USD rows back" "0" "$(val "$A_CU" usd_rows_left)"

say ""
say "=== AFTER · §3 nothing is truncated, and nothing is chosen silently ==="
probel trial-on >/dev/null
A_SA="$(probel split-arrange)"; printf '%s\n' "$A_SA" >>"$RUN"
check "7a the same line: three units of ۱۰۱ تومان in total" "1010000" "$(val "$A_SA" item_base_minor)"
check "7b the commission is still exact" "126250" "$(val "$A_SA" item_commission_minor)"
check "7c the share is still exact" "883750" "$(val "$A_SA" item_share_minor)"
# ⛔ THE FIX, half one.
check "7d the per-unit price says «no whole one»" "__null__" "$(val "$A_SA" item_unit_price_minor)"
A_UP="$(probel split-unit-price)"; printf '%s\n' "$A_UP" >>"$RUN"
check "7e …read back from the row" "__null__" "$(val "$A_UP" unit_price_minor)"
check "7f …and the line's own total is untouched" "1010000" "$(val "$A_UP" base_minor)"
A_SP="$(probel split-partial)"; printf '%s\n' "$A_SP" >>"$RUN"
# ⛔ THE FIX, half two — and it refuses at the LAST REVERSIBLE STEP, not at
# the money: `received → refunded` is the only move out of `received`, so a
# refusal there would strand the return and block the whole-line path too.
check "7g the refusal happens when the goods are accepted" "received" "$(val "$A_SP" refusal_step)"
check "7h …by name" "return_share_not_exact" "$(val "$A_SP" receive_code)"
check "7i …with the line's own total stated" "1010000" "$(val "$A_SP" refusal_base_minor)"
check "7j …and which parts do not divide" "base,commission" "$(val "$A_SP" refusal_parts)"
check "7k the money step is not even attempted" "__not_attempted__" "$(val "$A_SP" refund_code)"
check "7l nothing was written" "__absent__" "$(val "$A_SP" return_row)"
check "7m …and the return is still cancellable" "approved" "$(val "$A_SP" return_status)"
A_SF="$(probel split-full)"; printf '%s\n' "$A_SF" >>"$RUN"
check "7n the stranded request can be cancelled" "yes" \
  "$([[ "$(val "$A_SF" cancelled_open_returns)" -ge 1 ]] && echo yes || echo no)"
check "7o the whole line then comes back, exactly" "yes" "$(val "$A_SF" whole_line_given_back)"
check "7p …all three units of it" "3" "$(val "$A_SF" refunded_quantity_total)"
probel trial-off >/dev/null

say ""
say "=== AFTER · §4 the gate is a count, in three states ==="
A_RB="$(probe rollback-hazard)"; printf '%s\n' "$A_RB" >>"$RUN"
check "8a there is a rollback gate" "yes" "$(val "$A_RB" has_rollback_gate)"
check "8b …and it read the tables" "yes" "$(val "$A_RB" gate_readable)"
check "8c …and one finer row is enough to block" "blocked" "$(val "$A_RB" gate_verdict)"
check "8d …which is not «safe»" "no" "$(val "$A_RB" gate_safe)"
note  "8e the counts it reported" "$(grep -c '^finer_rows_' <<<"$A_RB") tables"

say ""
say "=== CONTROLS — the same answer on both trees, or in a named direction ==="
probe reset >/dev/null
C_ARR="$(probe unit-arrange)"; printf '%s\n' "$C_ARR" >>"$RUN"
C_ST="$(probe unit-state)"; printf '%s\n' "$C_ST" >>"$RUN"
check "9a a healthy read says present on BOTH trees" "yes" \
  "$([[ "$(val "$B_ST" carried_present)" == yes && "$(val "$C_ST" state_present)" == present ]] && echo yes || echo no)"
check "9b a table with no such columns is absent on BOTH trees" "yes" \
  "$([[ "$(val "$B_ST" carried_absent_table)" == no && "$(val "$C_ST" state_absent_table)" == absent ]] && echo yes || echo no)"
probel trial-on >/dev/null
C_SA="$(probel split-arrange)"; printf '%s\n' "$C_SA" >>"$RUN"
check "9c the exact commission is the same figure on both trees" \
  "$(val "$B_SA" item_commission_minor)" "$(val "$C_SA" item_commission_minor)"
check "9d the exact share is the same figure on both trees" \
  "$(val "$B_SA" item_share_minor)" "$(val "$C_SA" item_share_minor)"
check "9e the line's own total did not move" "1010000" "$(val "$C_SA" item_base_minor)"
C_SF="$(probel split-full)"; printf '%s\n' "$C_SF" >>"$RUN"
check "9f a whole-line return works on both trees" "yes" "$(val "$C_SF" whole_line_given_back)"
probel trial-off >/dev/null
C_RB="$(probe rollback-hazard)"; printf '%s\n' "$C_RB" >>"$RUN"
check "9g the finer rows the gate counts were really there BEFORE too" "yes" \
  "$([[ "$(val "$B_RB" rows_finer_than_the_registry)" -ge 1 ]] && echo yes || echo no)"
C_REP="$(probe report)"; printf '%s\n' "$C_REP" >>"$RUN"
note "9h the schema this ran against" "$(val "$C_REP" schema_version)"
note "9i the package installed when this started" "$(val "$C_REP" plugin_version)"

probe reset >/dev/null
A_REST="$(probe report)"; printf '%s\n' "$A_REST" >>"$RUN"
check "9j the fixture gave its order-item rows back" "0" "$(val "$A_REST" fixture_item_rows)"
check "9k …and its ledger rows" "0" "$(val "$A_REST" fixture_ledger_rows)"

say ""
say "=== the tree is put back ==="
restore
trap - EXIT
check "9l the installed tree is what it was when this started" "$ORIGINAL_HASH" "$(digest "$INSTALLED")"

say ""
say "checks=$((PASS + FAIL)) pass=$PASS fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
