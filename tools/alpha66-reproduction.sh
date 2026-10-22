#!/usr/bin/env bash
# `alpha.67` on the disposable site: the two things the owner asked for, each
# reproduced on the bytes of the DELIVERED `alpha.66` package and then
# measured again on this tree.
#
# WHAT IS MEASURED, and why each one is a before/after rather than a claim
#
#   §1 — a WooCommerce `pending` product through the whole path the owner
#        named: intake → accepting management → an ordinary save → the FIRST
#        projection. `WooCommerceProjector` wrote
#        `set_status($published ? 'publish' : 'draft')` UNCONDITIONALLY, and
#        `ProductStatus::forIntakeOf()` mapped `pending` onto «پیش‌نویس», so
#        the projection turned a pending product into a draft AND took it out
#        of the catalogue and out of search. Both are public changes nobody
#        asked for. The status is measured at EVERY stage, from the post and
#        from the catalogue facet, because §1-الف asks for both.
#
#   §2 — the commission, with no rounding anywhere. Both calculators called
#        `RoundingPolicy::apply($base * $bp, 10000)`, which rounds half up, so
#        ۱۰۱ تومان at ۱۲٫۵٪ owed ۱۳ instead of ۱۲٫۶۲۵. The precision is
#        derived from the rate's own denominator
#        (`CommissionPrecision::EXTRA_SCALE`), the unit travels on the row
#        (migration 33), and the balance is summed per unit and combined
#        exactly.
#
#   Plus CONTROLS, each of which must answer the SAME on both trees or in the
#   same direction on purpose: `draft` and `publish` keep their status,
#   `private` and `future` stay refused BY NAME, an explicit publish/draft
#   decision still writes, identity (id, URL, author, price) does not move,
#   and an amount whose commission is already whole is unchanged. A
#   measurement that only ever looks at the broken case cannot tell a fix
#   from a feature that stopped working (`alpha.46`).
#
#   And ONE no-money WooCommerce order end to end, because «ذخیرهٔ موفق
#   اثبات محاسبه نیست»: three units of ۱۰۱ تومان at ۱۲٫۵٪, the line's five
#   money columns, the ledger lines behind it and the unit on each.
#
# ⛔ THE BASE PACKAGE HAS TO BE THE INSTALLED ONE. Check `0a` asserts it:
# running this script with a LATER package installed reports that round's
# fixes as this round's failures, which `CLAUDE.md` records as a rule after it
# happened twice. The database schema is this tree's (33) in both halves on
# purpose — the same database, two sets of code bytes — and `alpha.66`'s code
# simply does not read the two columns migration 33 adds.
#
# ⛔ Dokan is NOT activated and cannot be in this environment (`alpha.53`).
# Nothing here needs it.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha66-reproduction.sh docs/evidence/alpha66-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha66-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
BASE_REF="${TMC_BASE_REF:-}"
PROBE_SRC="$REPO/tests/Support/alpha66-probe.php"
PROBE="$WP_PATH/alpha66-probe.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha66-XXXXXX)"

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
# inside this script.
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
  # Given back whatever happened, on the tree that is going back: a run that
  # left a fixture behind makes the NEXT run measure somebody else's state
  # and report it as a finding about this one (`alpha.33`).
  if [[ -f "$PROBE" ]]; then
    # THE OPERATIONAL KEY FIRST, and off, whatever it was when we arrived.
    # `alpha.66`'s own rule, and this script broke it: the trial switch was
    # never put in a known state, so the gate answered `decisions_open` on
    # BOTH trees and thirteen checks reported a defect about a build that
    # worked. Restoring it to OFF rather than to «whatever it was» is
    # deliberate — a key this script turned on must not survive it, and a key
    # somebody left on by hand is not a state to preserve.
    wpx --exec='define("WP_ENVIRONMENT_TYPE","local");' \
      eval-file "$PROBE" trial-off >/dev/null 2>&1
    wpx eval-file "$PROBE" money-reset >/dev/null 2>&1
    wpx eval-file "$PROBE" reset >/dev/null 2>&1
  fi
  rm -f "$PROBE"
}
trap restore EXIT

# --------------------------------------------------------------- the trees
# ⛔ THREE TREES, NOT TWO, and the difference cost a whole run. `$KEEP/src` is
# whatever was installed when this script STARTED, kept only so the machine is
# left as it was found. The AFTER half is taken from `$REPO/src` — THIS
# CHECKOUT — and never from the install, because «what is installed» and «what
# this checkout says» are two things: the first run of this script measured a
# stale copy of an earlier commit, found the OLD rounded figures, and reported
# eleven failures about code that was already fixed. `alpha.32`'s trap, one
# level up: a script that derives the tree under test from the live site
# answers about the site.
cp -a "$INSTALLED/src" "$KEEP/src"
ORIGINAL_HASH="$(digest "$KEEP")"
NOW_HASH="$(digest "$REPO")"
cp "$PROBE_SRC" "$PROBE"

if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(sed -n 's/^0\.1\.0-alpha\.66 *\([0-9a-f]\{7,\}\).*$/\1/p' "$REPO/dist/DELIVERED.txt" | head -1)"
fi
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(cd "$REPO" && git log --format=%H --grep='^alpha\.66:' -1)"
fi
say "=== the trees ==="
say "base_ref=$BASE_REF"
say "this_checkout_src_digest=$NOW_HASH"
say "installed_src_digest=$ORIGINAL_HASH"
if [[ -z "$BASE_REF" ]]; then
  say "FAIL  0a the alpha.66 commit could not be resolved"
  exit 1
fi
mkdir -p "$KEEP/base"
(cd "$REPO" && git archive "$BASE_REF" src) | tar -x -C "$KEEP/base" || {
  say "FAIL  0a the alpha.66 tree could not be checked out"
  exit 1
}
BASE_HASH="$(digest "$KEEP/base")"
say "base_src_digest=$BASE_HASH"
# ⛔ THE BASE HAS TO BE WHAT IS INSTALLED. Without this the script reports a
# later round's fixes as this round's failures.
check "0a the installed package is this round's base (alpha.66)" "$BASE_HASH" "$ORIGINAL_HASH"
check "0b this checkout differs from the base" "differ" \
  "$([[ "$NOW_HASH" == "$BASE_HASH" ]] && echo same || echo differ)"

use_tree() {
  rm -rf "$INSTALLED/src"
  cp -a "$1/src" "$INSTALLED/src"
}

# =========================================================== BEFORE (alpha.66)
use_tree "$KEEP/base"
check "0c the installed tree is now alpha.66's" "$BASE_HASH" "$(digest "$INSTALLED")"

say ""
say "=== BEFORE · §1 the five statuses, through the whole path ==="
probe reset >/dev/null
B_ARR="$(probe status-arrange)"; printf '%s\n' "$B_ARR" >>"$RUN"
check "1a woocommerce holds a pending product" "pending" "$(val "$B_ARR" shop_status_pending)"
B_JUD="$(probe status-judge)"; printf '%s\n' "$B_JUD" >>"$RUN"
check "1b pending is taken in" "import:" "$(val "$B_JUD" judge_pending)"
check "1c private is refused BY NAME" "skip:status_not_expressible" "$(val "$B_JUD" judge_private)"
check "1d future is refused BY NAME" "skip:status_not_expressible" "$(val "$B_JUD" judge_future)"
check "1e the map collapses pending into draft" "draft" "$(val "$B_JUD" map_pending)"
B_INT="$(probe status-intake)"; printf '%s\n' "$B_INT" >>"$RUN"
check "1f the intake leaves the shop alone" "pending" "$(val "$B_INT" shop_status_pending)"
check "1g …and the row says draft" "draft" "$(val "$B_INT" row_status_pending)"
check "1h …and the filter still shows the shop's own status" \
  "pending|mgmt:observed" "$(val "$B_INT" facet_pending)"
B_ACC="$(probe status-accept pending)"; printf '%s\n' "$B_ACC" >>"$RUN"
check "1i accepting management leaves the shop alone" "pending" "$(val "$B_ACC" shop_status_after)"
B_SAV="$(probe status-save pending)"; printf '%s\n' "$B_SAV" >>"$RUN"
check "1j an ordinary save succeeds" "product_saved" "$(val "$B_SAV" save_code)"
check "1k …and leaves the shop alone" "pending" "$(val "$B_SAV" shop_status_after)"
B_PRJ="$(probe status-project pending)"; printf '%s\n' "$B_PRJ" >>"$RUN"
check "1l the first projection succeeds" "projected" "$(val "$B_PRJ" project_code)"
# ⛔ THE FINDING.
check "1m THE DEFECT: pending became draft" "draft" "$(val "$B_PRJ" shop_status_after)"
check "1n …and the filter says draft too" "draft|mgmt:ours" "$(val "$B_PRJ" facet_after)"
check "1o …and it left the catalogue and search" \
  "exclude-from-catalog,exclude-from-search" "$(val "$B_PRJ" catalog_visibility_after)"
check "1p nothing records that the status was held" "__no_such_key__" "$(val "$B_PRJ" status_held_meta)"
check "1q the id did not move" "$(val "$B_ARR" product_pending)" "$(val "$B_PRJ" id)"
check "1r the author did not move" "$(val "$B_ARR" vendor_user_id)" "$(val "$B_PRJ" author)"
check "1s the price did not move" "450000" "$(val "$B_PRJ" regular_price)"

say ""
say "=== BEFORE · §1 controls — draft and publish ==="
probe status-accept draft >/dev/null
B_PD="$(probe status-project draft)"; printf '%s\n' "$B_PD" >>"$RUN"
check "2a a draft stays a draft" "draft" "$(val "$B_PD" shop_status_after)"
probe status-accept publish >/dev/null
B_PP="$(probe status-project publish)"; printf '%s\n' "$B_PP" >>"$RUN"
check "2b a published product stays published" "publish" "$(val "$B_PP" shop_status_after)"

say ""
say "=== BEFORE · §2 the rounding, and what it costs ==="
B_SC="$(probe money-scale)"; printf '%s\n' "$B_SC" >>"$RUN"
check "3a there is no derived precision at all" "__absent__" "$(val "$B_SC" commission_precision_class)"
B_EX="$(probe money-example)"; printf '%s\n' "$B_EX" >>"$RUN"
check "3b and no exact commission to ask for" "__absent__" "$(val "$B_EX" exact_commission)"
# THE KEY, IN A KNOWN STATE, before anything asks the gate. Given back in
# `restore()`.
B_TR="$(probel trial-on)"; printf '%s\n' "$B_TR" >>"$RUN"
check "3b-i the trial key is honoured on a declared non-production site" "yes" "$(val "$B_TR" active)"
B_ORD="$(probel money-order)"; printf '%s\n' "$B_ORD" >>"$RUN"
check "3c the order gate opens with the trial key" "yes" "$(val "$B_ORD" gate_ready)"
BASE_COMMISSION="$(val "$B_ORD" line_commission_minor)"
BASE_SHARE="$(val "$B_ORD" line_share_minor)"
BASE_BASE="$(val "$B_ORD" line_base_minor)"
say "base_line_base_minor=$BASE_BASE"
say "base_line_commission_minor=$BASE_COMMISSION"
say "base_line_share_minor=$BASE_SHARE"
# ⛔ THE FINDING: ۳۰۳ تومان at ۱۲٫۵٪ owes ۳۷٫۸۷۵ and the row holds ۳۸.
check "3d THE DEFECT: the commission was rounded to a whole تومان" "38" "$BASE_COMMISSION"
check "3e …and the share with it" "265" "$BASE_SHARE"
check "3f the row carries no unit" "__none__" "$(val "$B_ORD" line_exponent)"
B_BAL="$(probe money-balance)"; printf '%s\n' "$B_BAL" >>"$RUN"
check "3g the balance does not say what unit it is in" "__no_key__" "$(val "$B_BAL" balance_exponent)"

# ============================================================ AFTER (this tree)
say ""
use_tree "$REPO"
check "0d the installed tree is this checkout's" "$NOW_HASH" "$(digest "$INSTALLED")"
probe money-reset >/dev/null
probe reset >/dev/null

say ""
say "=== AFTER · §1 the same path, the same five statuses ==="
A_ARR="$(probe status-arrange)"; printf '%s\n' "$A_ARR" >>"$RUN"
A_JUD="$(probe status-judge)"; printf '%s\n' "$A_JUD" >>"$RUN"
check "4a pending is still taken in" "import:" "$(val "$A_JUD" judge_pending)"
check "4b private is still refused BY NAME" "skip:status_not_expressible" "$(val "$A_JUD" judge_private)"
check "4c future is still refused BY NAME" "skip:status_not_expressible" "$(val "$A_JUD" judge_future)"
check "4d the lifecycle value is still draft" "draft" "$(val "$A_JUD" map_pending)"
A_INT="$(probe status-intake)"; printf '%s\n' "$A_INT" >>"$RUN"
check "4e the intake leaves the shop alone" "pending" "$(val "$A_INT" shop_status_pending)"
check "4f the filter shows the shop's own status" \
  "pending|mgmt:observed" "$(val "$A_INT" facet_pending)"
A_ACC="$(probe status-accept pending)"; printf '%s\n' "$A_ACC" >>"$RUN"
check "4g accepting management leaves the shop alone" "pending" "$(val "$A_ACC" shop_status_after)"
A_SAV="$(probe status-save pending)"; printf '%s\n' "$A_SAV" >>"$RUN"
check "4h an ordinary save succeeds" "product_saved" "$(val "$A_SAV" save_code)"
check "4i …and leaves the shop alone" "pending" "$(val "$A_SAV" shop_status_after)"
A_PRJ="$(probe status-project pending)"; printf '%s\n' "$A_PRJ" >>"$RUN"
check "4j the first projection still succeeds" "projected" "$(val "$A_PRJ" project_code)"
check "4k THE FIX: pending is still pending" "pending" "$(val "$A_PRJ" shop_status_after)"
check "4l …and the filter says so" "pending|mgmt:ours" "$(val "$A_PRJ" facet_after)"
check "4m …and it is still in the catalogue and in search" "__none__" \
  "$(val "$A_PRJ" catalog_visibility_after)"
check "4n …and the hold is a fact on disk" "1" "$(val "$A_PRJ" status_held_meta)"
check "4o …and the projection reports it" "yes" "$(val "$A_PRJ" project_status_held)"
check "4p the id did not move" "$(val "$A_ARR" product_pending)" "$(val "$A_PRJ" id)"
check "4q the author did not move" "$(val "$A_ARR" vendor_user_id)" "$(val "$A_PRJ" author)"
check "4r the price did not move" "450000" "$(val "$A_PRJ" regular_price)"

say ""
say "=== AFTER · §1 controls — draft, publish, and an EXPLICIT decision ==="
probe status-accept draft >/dev/null
A_PD="$(probe status-project draft)"; printf '%s\n' "$A_PD" >>"$RUN"
check "5a a draft is still a draft" "draft" "$(val "$A_PD" shop_status_after)"
probe status-accept publish >/dev/null
A_PP="$(probe status-project publish)"; printf '%s\n' "$A_PP" >>"$RUN"
check "5b a published product is still published" "publish" "$(val "$A_PP" shop_status_after)"
A_EP="$(probe status-explicit pending publish)"; printf '%s\n' "$A_EP" >>"$RUN"
check "5c an explicit decision clears the hold" "__null__" "$(val "$A_EP" mirrored_after_decision)"
check "5d …and the status IS written" "publish" "$(val "$A_EP" shop_status_after)"
A_ED="$(probe status-explicit publish draft)"; printf '%s\n' "$A_ED" >>"$RUN"
check "5e an explicit draft takes a live product down" "draft" "$(val "$A_ED" shop_status_after)"

say ""
say "=== AFTER · §2 the derivation, read off the rate's own denominator ==="
A_SC="$(probe money-scale)"; printf '%s\n' "$A_SC" >>"$RUN"
check "6a the rate denominator" "10000" "$(val "$A_SC" rate_denominator)"
check "6b the extra scale is derived from it" "4" "$(val "$A_SC" extra_scale)"
check "6c …and the derivation is checked" "yes" "$(val "$A_SC" derivation_matches_denominator)"
check "6d تومان needs four places" "4" "$(val "$A_SC" exponent_for_0)"
check "6e a two-place currency needs six" "6" "$(val "$A_SC" exponent_for_2)"
check "6f three places is REFUSED, not rounded" "__refused__" "$(val "$A_SC" exponent_for_3)"
check "6g the recorded policy says exact" "exact@v2" "$(val "$A_SC" rounding_policy)"

say ""
say "=== AFTER · §2 the owner's worked example ==="
A_EX="$(probe money-example)"; printf '%s\n' "$A_EX" >>"$RUN"
check "7a ۱۰۱ at ۱۲٫۵٪ owes exactly ۱۲٫۶۲۵" "12.6250" "$(val "$A_EX" 'commission_101@12.5%')"
check "7b …and leaves exactly ۸۸٫۳۷۵" "88.3750" "$(val "$A_EX" 'share_101@12.5%')"
check "7c …and the two add up to the base exactly" "yes" "$(val "$A_EX" 'sums_exactly_101@12.5%')"
check "7d the Persian spelling of the rate gives the same answer" "4" "$(val "$A_EX" 'exponent_101@12.5%')"
check "7e an amount whose commission is whole is exact too" "125.0000" "$(val "$A_EX" 'commission_1000@12.5%')"
check "7f three units: ۳۷٫۸۷۵" "37.8750" "$(val "$A_EX" 'commission_303@12.5%')"
check "7g a discount comes off first: ۳۱٫۲۵" "31.2500" "$(val "$A_EX" 'commission_250@12.5%')"
check "7h …and that one sums exactly as well" "yes" "$(val "$A_EX" 'sums_exactly_250@12.5%')"

say ""
say "=== AFTER · §2 one no-money WooCommerce order, end to end ==="
A_TR="$(probel trial-on)"; printf '%s\n' "$A_TR" >>"$RUN"
check "8a-i the trial key is honoured on this tree too" "yes" "$(val "$A_TR" active)"
A_ORD="$(probel money-order)"; printf '%s\n' "$A_ORD" >>"$RUN"
check "8a the order gate opens with the trial key" "yes" "$(val "$A_ORD" gate_ready)"
check "8b three units of ۱۰۱ تومان" "303" "$(val "$A_ORD" order_total)"
check "8c the row records its own unit" "4" "$(val "$A_ORD" line_exponent)"
# The shop's OWN currency, read from the order rather than typed: the first
# run of this script expected `IRR` and this catalogue sells in `IRT`, which
# is a failure about the script and not about the code.
check "8d …and its currency" "$(val "$A_ORD" order_currency)" "$(val "$A_ORD" line_currency)"
check "8e the base is the same money in the finer unit" "3030000" "$(val "$A_ORD" line_base_minor)"
check "8f THE FIX: the commission is exact" "378750" "$(val "$A_ORD" line_commission_minor)"
check "8g …and so is the share" "2651250" "$(val "$A_ORD" line_share_minor)"
check "8h …and they add up to the base exactly" "yes" "$(val "$A_ORD" line_sums_exactly)"
check "8i what a person reads for the commission" "۳۷٫۸۷۵" "$(val "$A_ORD" line_commission_text)"
check "8j …and for the share" "۲۶۵٫۱۲۵" "$(val "$A_ORD" line_share_text)"
check "8k …and for the base, with no trailing zeros" "۳۰۳" "$(val "$A_ORD" line_base_text)"
check "8l the rate came from the product" "product" "$(val "$A_ORD" line_rate_source)"
check "8m …at twelve and a half per cent" "1250" "$(val "$A_ORD" line_rate_bp)"
note "8n no payment gateway was involved" "available_gateways=$(val "$A_ORD" available_gateways)"

A_LED="$(probe money-ledger)"; printf '%s\n' "$A_LED" >>"$RUN"
check "9a the event balances to nought" "yes" "$(val "$A_LED" event_zero_sum)"
check "9b every line of it in ONE unit" "4" "$(val "$A_LED" event_exponents)"
note "9c the units this vendor's ledger holds" "$(val "$A_LED" ledger_exponents_for_vendor)"

say ""
say "=== AFTER · §2 the balance, and a legacy row beside a new one ==="
A_LEG="$(probe money-legacy)"; printf '%s\n' "$A_LEG" >>"$RUN"
check "10a a row in the OLD shape was written" "90000" "$(val "$A_LEG" legacy_share_minor)"
check "10b …with no unit on it, exactly as alpha.66 wrote one" "__none__" "$(val "$A_LEG" legacy_exponent)"
A_BAL="$(probe money-balance)"; printf '%s\n' "$A_BAL" >>"$RUN"
check "10c the balance says what unit it is in" "4" "$(val "$A_BAL" balance_exponent)"
check "10d …and that the unit is a fact, not a fallback" "no" "$(val "$A_BAL" balance_unit_unreadable)"
# ⛔ THE DELTA, NOT THE TOTAL. This shop is borrowed and already carries six
# unitless lines from earlier rounds; asserting the whole total made three
# checks fail about somebody else's fixtures on a tree that was right. What
# this fixture owns is the one row it wrote, so the claim is what that row
# adds: ۹۰٬۰۰۰ تومان of share, read as the unit it means and expressed in the
# finer one — exactly ×10000, never divided by it.
check "10e the old row adds exactly ۹۰٬۰۰۰، in the finer unit" \
  "$(( 90000 * 10000 ))" "$(val "$A_LEG" earned_delta)"
check "10f …and the balance was already in that unit before it" "4" "$(val "$A_LEG" exponent_before)"
check "10g …and after it" "4" "$(val "$A_LEG" exponent_after)"
check "10h exactly one line without a unit was added" "1" "$(val "$A_LEG" unitless_lines_added)"
check "10i and the vendor has lines with a unit too" "yes" \
  "$([[ "$(val "$A_BAL" lines_with_a_unit)" -ge 1 ]] && echo yes || echo no)"
check "10j the text is printed at the balance's own scale" "yes" \
  "$([[ -n "$(val "$A_BAL" earned_text)" ]] && echo yes || echo no)"
note "10k the balance of this borrowed shop, in its own unit" \
  "$(val "$A_BAL" balance_earned) e$(val "$A_BAL" balance_exponent) = $(val "$A_BAL" earned_text)"

say ""
say "=== result ==="
say "pass=$PASS fail=$FAIL"
say "base_ref=$BASE_REF"
say "base_src_digest=$BASE_HASH"
say "this_tree_src_digest=$NOW_HASH"
# The hash is checked AFTER the restore and from the bytes on disk, not from
# `git diff` (`alpha.32`).
restore
check "11a the installed bytes are back to what was there" "$ORIGINAL_HASH" "$(digest "$INSTALLED")"
say "pass=$PASS fail=$FAIL"
[[ "$FAIL" -eq 0 ]]
