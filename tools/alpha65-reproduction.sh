#!/usr/bin/env bash
# `alpha.66` on the disposable site: the three things the owner's manual
# staging test ran into, each reproduced on the bytes of the DELIVERED
# `alpha.65` package and then measured again on this tree.
#
# WHAT IS MEASURED, and why each one is a before/after rather than a claim
#
#   §2 — «۱۲٫۵» in the commission box.
#        `TectebCommission::readPercent()` ends in `DecimalAmount::toMinor()`,
#        whose pattern is ASCII, and `readFixed()`'s is too — so the spelling
#        the panel's OWN hint gives as the example («مثلاً ۱۲٫۵») was refused.
#        Measured through the real panel: its nonce, its capability, its two
#        meta rows.
#
#   §1 — a product with no price, and a product WooCommerce calls `pending`.
#        `WpDokanReader::productsAfter()` read `post_status IN ('publish',
#        'draft')`, so the owner's thirteen pending products were never read
#        at all; it read `(int) round((float) …'_price')`, which turned ABSENT
#        into nought and rounded `450000.50` up; and
#        `ImportFromDokan::judgeProduct()` then made `price_minor <= 0` a
#        CONFLICT, which is the owner's thirty-four.
#        And the one with teeth: `WooCommerceProjector` wrote
#        `set_regular_price('0')` unconditionally, so accepting management of a
#        PUBLISHED product that had no price put «۰ تومان» on it.
#
#   §3 — the two sentences on one screen. The green banner came from the sales
#        switch alone, four sections above a table saying the order gate was
#        shut; and that table printed a «نرخ کمیسیون عمومی» which `alpha.57`
#        had already stopped consulting.
#
#   Plus FOUR CONTROLS, each of which must answer the SAME on both trees: an
#   ASCII `12.5` still saves, a product that HAS a price still gets it
#   projected, the gate's reason does not move when the general rate is set,
#   and a product the marketplace does not own is untouched. A measurement
#   that only ever looks at the broken case cannot tell a fix from a feature
#   that stopped working (`alpha.46`).
#
#   And ONE no-money order, end to end: the Persian-typed commission, a real
#   WooCommerce order of three units, and the ledger lines behind it — because
#   «ذخیرهٔ موفق سه حالت کمیسیون اثبات محاسبهٔ درست روی سفارش نیست» (§4).
#
# The script swaps `src/` under the installed plugin, measures, and puts the
# original bytes back in a `trap` with an absolute path (`alpha.37`) — and
# checks the hash afterwards, not `git diff` (`alpha.32`).
#
# ⛔ Dokan itself is NOT activated and cannot be: the copy on this machine is
# the owner's, whose first line `require_once`s a SourceGuardian-encrypted
# `abzarwp/abzarwp.php` and needs a loader this environment does not have
# (`alpha.53`). What the fixture builds is exactly what
# `WpDokanReader::isAvailable()` asks for — the `dokan_orders` table — plus a
# user with the `seller` role, so OUR reader and OUR plan run. Dokan is
# `Not Run` and that is said out loud rather than implied by a green line.
#
# Usage: WP_PATH=/home/user/wp-demo WPCLI=/usr/local/bin/wp \
#          bash tools/alpha65-reproduction.sh docs/evidence/alpha65-reproduction
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/docs/evidence/alpha65-reproduction}"
[[ "$OUT" = /* ]] || OUT="$REPO/$OUT"
WP_PATH="${WP_PATH:-/home/user/wp-demo}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PHPBIN="${PHPBIN:-php}"
# The commit of the DELIVERED package, read from the delivery ledger rather
# than typed: a number written here would pin this script to one round
# (`alpha.34`).
BASE_REF="${TMC_BASE_REF:-}"
PROBE_SRC="$REPO/tests/Support/alpha65-probe.php"
PROBE="$WP_PATH/alpha65-probe.php"
INSTALLED="$WP_PATH/wp-content/plugins/tecteb-marketplace-core"
KEEP="$(mktemp -d /tmp/tmc-alpha65-XXXXXX)"

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
# The trial switch and the cart-side gate need the environment DECLARED, the
# way every round since `alpha.7` has exercised them. The switch is asked for
# and given back inside this script.
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
  # The trial switch is given back whatever happened, on the tree that is
  # going back: a run that left it ON made the NEXT run's gate measurements
  # answer `trial_refused` — a true sentence about a site somebody else had
  # changed, reported as a finding about this one (`alpha.33`).
  [[ -f "$PROBE" ]] && wpx --exec='define("WP_ENVIRONMENT_TYPE","local");' \
    eval-file "$PROBE" trial-off >/dev/null 2>&1
  rm -f "$PROBE"
}
trap restore EXIT

# --------------------------------------------------------------- the trees
cp -a "$INSTALLED/src" "$KEEP/src"
NOW_HASH="$(digest "$KEEP")"
cp "$PROBE_SRC" "$PROBE"

if [[ -z "$BASE_REF" ]]; then
  # The commit the delivery ledger records for the previous package.
  BASE_REF="$(sed -n 's/^0\.1\.0-alpha\.65 *\([0-9a-f]\{7,\}\).*$/\1/p' "$REPO/dist/DELIVERED.txt" | head -1)"
fi
if [[ -z "$BASE_REF" ]]; then
  BASE_REF="$(cd "$REPO" && git log --format=%H --grep='^alpha\.65:' -1)"
fi
say "=== the trees ==="
say "base_ref=$BASE_REF"
say "this_tree_src_digest=$NOW_HASH"
if [[ -z "$BASE_REF" ]]; then
  say "FAIL  0a the alpha.65 commit could not be resolved"
  exit 1
fi
mkdir -p "$KEEP/base"
(cd "$REPO" && git archive "$BASE_REF" src) | tar -x -C "$KEEP/base" || {
  say "FAIL  0a the alpha.65 tree could not be checked out"
  exit 1
}
BASE_HASH="$(digest "$KEEP/base")"
say "base_src_digest=$BASE_HASH"
check "0a the two trees differ" "differ" "$([[ "$BASE_HASH" == "$NOW_HASH" ]] && echo same || echo differ)"

use_tree() {
  rm -rf "$INSTALLED/src"
  cp -a "$1/src" "$INSTALLED/src"
}

# =========================================================== BEFORE (alpha.65)
use_tree "$KEEP/base"
check "0b the installed tree is now alpha.65's" "$BASE_HASH" "$(digest "$INSTALLED")"

say ""
# The switch is put in a KNOWN position before anything is measured, for the
# reason `restore()` gives: a gate asked while somebody else's trial request
# is still stored answers about their site, not this measurement.
probel trial-off >/dev/null
say "=== BEFORE · §2 the commission box, nine spellings ==="
B0="$(probe commission-input)"
printf '%s\n' "$B0" >>"$RUN"
check "1a ascii 12.5 is accepted" "percent/12.50" "$(val "$B0" percent_ascii)"
check "1b the panel's OWN example is refused" \
  "refused:commission_percent_invalid" "$(val "$B0" percent_persian)"
check "1c …and so is the Arabic-Indic spelling" \
  "refused:commission_percent_invalid" "$(val "$B0" percent_arabic)"
check "1d …and a fixed amount in Persian digits" \
  "refused:commission_fixed_invalid" "$(val "$B0" fixed_persian)"

say ""
say "=== BEFORE · §2 through the real panel, on a real product ==="
probe reset >/dev/null
probe price-arrange >>"$RUN"
B1="$(probe commission-save 12.5 percent)"
printf '%s\n' "$B1" >>"$RUN"
check "2a an ascii value lands" "saved" "$(val "$B1" outcome)"
check "2b …and reads back" "percent/12.50" "$(val "$B1" reads_back_as)"
B2="$(probe commission-save "۱۲٫۵" percent)"
printf '%s\n' "$B2" >>"$RUN"
check "2c the same number in Persian digits is refused" "refused" "$(val "$B2" outcome)"
check "2d …by name" "commission_percent_invalid" "$(val "$B2" reason)"
check "2e …and the previous pair is kept" "12.50" "$(val "$B2" after_value)"

say ""
say "=== BEFORE · §1 the Dokan reader and the plan ==="
probe dokan-arrange >>"$RUN"
B3="$(probe dokan-plan)"
printf '%s\n' "$B3" >>"$RUN"
check "3a the reader is available without Dokan running" "yes" "$(val "$B3" dokan_reader_available)"
check "3b Dokan itself is NOT active" "no" "$(printf '%s\n' "$(probe dokan-arrange)" | sed -n 's/^dokan_plugin_active=//p' | head -1)"
check "3c a PENDING product is never read" "no" "$(val "$B3" read_dk-pending-nopr)"
check "3d an ABSENT price reads as nought" "0" "$(val "$B3" read_price_minor_dk-publish-nopr)"
check "3e …with no way to tell absent from nought" "__absent__" "$(val "$B3" read_price_recorded_dk-publish-nopr)"
check "3f 450000.50 is ROUNDED to 450001" "450001" "$(val "$B3" read_price_minor_dk-publish-frac)"
# The id comes from the arrange output, whose keys carry the suffix with
# hyphens — a needle typed with underscores finds nothing and reports it as
# a missing verdict (`alpha.23`, one layer over).
BID="$(printf '%s\n' "$(probe dokan-arrange)" | sed -n 's/^product_dk-publish-nopr=//p' | head -1)"
check "3g a product with no price is a CONFLICT" \
  "conflict:no_price_recorded" "$(val "$B3" "plan_$BID")"
BEFORE_NOPRICE="$(printf '%s\n' "$B3" | sed -n 's/^plan_count_conflict:no_price_recorded=//p' | head -1)"
check "3h …and so is every one of them" "2" "${BEFORE_NOPRICE:-0}"
# TWO clean imports on this tree: the product that really has a price, and
# the one whose `450000.50` was silently rounded to `450001` — which is the
# worse half of the finding, a fabricated price that passes.
check "3i the rounded one is planned as a clean IMPORT, beside the priced one" "2" \
  "$(printf '%s\n' "$B3" | sed -n 's/^plan_count_import:=//p' | head -1)"

say ""
say "=== BEFORE · §1 a published product with no price, projected ==="
probe reset >/dev/null
probe price-arrange >>"$RUN"
B4="$(probe price-judge)"
printf '%s\n' "$B4" >>"$RUN"
check "4a a PRIVATE product is taken in" "import:" "$(val "$B4" judge_private-priced)"
check "4b …and so is a SCHEDULED one" "import:" "$(val "$B4" judge_future-priced)"
B5="$(probe price-intake)"
printf '%s\n' "$B5" >>"$RUN"
check "4c a PENDING product becomes a plain draft row" "draft" "$(val "$B5" row_status_pending-nopr)"
check "4d a PRIVATE product becomes a plain draft row too" "draft" "$(val "$B5" row_status_private-priced)"
check "4e the no-price row carries nought" "0" "$(val "$B5" row_price_minor_publish-nopr)"
B6="$(probe price-project)"
printf '%s\n' "$B6" >>"$RUN"
check "4f the shop had no price at all" "__absent__" "$(val "$B6" shop_regular_price_before)"
check "4g …and the buyer saw nothing" "__none__" "$(val "$B6" price_html_before)"
check "4h acceptance is not refused" "ownership_taken" "$(val "$B6" take_code)"
check "4i ONE projection writes a nought" "0" "$(val "$B6" shop_regular_price_after)"
check "4j …and the buyer now sees «۰ تومان»" "0&nbsp;&#x062A;&#x0648;&#x0645;&#x0627;&#x0646;" "$(val "$B6" price_html_after)"
B7="$(probe price-project private-priced)"
printf '%s\n' "$B7" >>"$RUN"
check "4k a PRIVATE product's own status is published-private" "private" "$(val "$B7" shop_status_before)"
check "4l …and one projection makes it a draft" "draft" "$(val "$B7" shop_status_after)"

say ""
say "=== BEFORE · §3 the two sentences on one screen ==="
BP="$(probe priceless-report)"
printf '%s\n' "$BP" >>"$RUN"
check "4m …and nothing reports it" "__absent__" "$(val "$BP" reported)"

B8="$(probe gate-chain)"
printf '%s\n' "$B8" >>"$RUN"
check "5a the gate is shut on the owner's two decisions" "decisions_open" "$(val "$B8" gate_reason)"
check "5b …and names them" "awaiting DEC-02, DEC-04" "$(val "$B8" gate_detail)"
check "5c the general rate IS set on this site" "yes" "$(val "$B8" general_rate_is_set)"
check "5d …and the gate's answer does not move for it" "no" "$(val "$B8" gate_reason_is_rate_unset)"
B9="$(probe gate-screen)"
printf '%s\n' "$B9" >>"$RUN"
check "6a the banner says sales are active" "yes" "$(val "$B9" says_sales_active)"
check "6b …four sections above «دروازهٔ سفارش»" "yes" "$(val "$B9" says_order_gate)"
check "6c …which is shut" "yes" "$(val "$B9" says_decisions_open)"
check "6d and the banner is a green tick" "yes" "$(val "$B9" banner_is_success)"
check "6e the table prints a general commission rate" "yes" "$(val "$B9" says_general_commission_rate)"
check "6f …and the purchase table blames the rate" "yes" "$(val "$B9" buyer_row_blames_rate)"
BA="$(probe estimate-share)"
printf '%s\n' "$BA" >>"$RUN"
check "7a step 4 estimates from the SCOPED general rate" "general" "$(val "$BA" estimate_rate_source)"
check "7b …on a product whose own commission is absent" "__absent__" "$(val "$BA" shop_commission_type)"

# =========================================================== AFTER (this tree)
use_tree "$KEEP"
say ""
say "=== the fix is installed ==="
check "8a the installed tree is this checkout's" "$NOW_HASH" "$(digest "$INSTALLED")"

say ""
say "=== AFTER · §2 one result for three spellings ==="
A0="$(probe commission-input)"
printf '%s\n' "$A0" >>"$RUN"
check "9a ascii" "percent/12.50" "$(val "$A0" percent_ascii)"
check "9b Persian digits" "percent/12.50" "$(val "$A0" percent_persian)"
check "9c Arabic-Indic digits" "percent/12.50" "$(val "$A0" percent_arabic)"
check "9d a mixed spelling" "percent/12.50" "$(val "$A0" percent_mixed)"
check "9e a fixed amount in Persian digits" "fixed/20000" "$(val "$A0" fixed_persian)"
check "9f out of range is STILL refused, now for the right reason" \
  "refused:commission_percent_invalid" "$(val "$A0" percent_over)"
check "9g a grouping separator is refused, not guessed" \
  "refused:commission_fixed_invalid" "$(val "$A0" fixed_grouped)"

say ""
say "=== AFTER · §2 through the real panel ==="
probe reset >/dev/null
probe price-arrange >>"$RUN"
A1="$(probe commission-save 12.5 percent)"
printf '%s\n' "$A1" >>"$RUN"
check "10a the control: ascii still lands" "saved" "$(val "$A1" outcome)"
A2="$(probe commission-save "۱۲٫۵" percent)"
printf '%s\n' "$A2" >>"$RUN"
check "10b the panel's own example lands" "saved" "$(val "$A2" outcome)"
check "10c …and reads back as the same rule" "percent/12.50" "$(val "$A2" reads_back_as)"
A3="$(probe commission-save "۲۰٬۰۰۰" fixed)"
printf '%s\n' "$A3" >>"$RUN"
check "10d a grouped amount is refused" "refused" "$(val "$A3" outcome)"
check "10e …and the previous pair is kept" "12.50" "$(val "$A3" after_value)"

say ""
say "=== AFTER · §1 the Dokan reader and the plan ==="
probe dokan-arrange >>"$RUN"
A4="$(probe dokan-plan)"
printf '%s\n' "$A4" >>"$RUN"
check "11a the PENDING product is read" "yes" "$(val "$A4" read_dk-pending-nopr)"
check "11b …and says which status it is" "pending" "$(val "$A4" read_status_dk-pending-nopr)"
check "11c an absent price is named as absent" "no" "$(val "$A4" read_price_recorded_dk-publish-nopr)"
check "11d …and is not called inexact either" "no" "$(val "$A4" read_price_not_exact_dk-publish-nopr)"
check "11e 450000.50 is NOT rounded" "yes" "$(val "$A4" read_price_not_exact_dk-publish-frac)"
check "11f …and is refused by name" "1" \
  "$(printf '%s\n' "$A4" | sed -n 's/^plan_count_conflict:price_not_exact_for_this_currency=//p' | head -1)"
check "11g a product with no price is IMPORTED, with the reason said" "3" \
  "$(printf '%s\n' "$A4" | sed -n 's/^plan_count_import:imported_without_a_price=//p' | head -1)"
check "11h …and no product is blocked for want of a price" "" \
  "$(printf '%s\n' "$A4" | sed -n 's/^plan_count_conflict:no_price_recorded=//p' | head -1)"
check "11i the control: a priced product still imports cleanly" "1" \
  "$(printf '%s\n' "$A4" | sed -n 's/^plan_count_import:=//p' | head -1)"

say ""
say "=== AFTER · §1 a published product with no price ==="
probe reset >/dev/null
probe price-arrange >>"$RUN"
A5="$(probe price-judge)"
printf '%s\n' "$A5" >>"$RUN"
check "12a a PRIVATE product is refused by name" \
  "skip:status_not_expressible" "$(val "$A5" judge_private-priced)"
check "12b …and so is a SCHEDULED one" \
  "skip:status_not_expressible" "$(val "$A5" judge_future-priced)"
check "12c the control: a draft with no price is still taken in" "import:" "$(val "$A5" judge_draft-nopr)"
check "12d …and so is a pending one" "import:" "$(val "$A5" judge_pending-nopr)"
A6="$(probe price-intake)"
printf '%s\n' "$A6" >>"$RUN"
check "12e the private product has no row at all now" "__none__" "$(val "$A6" row_private-priced)"
check "12f …nor the scheduled one" "__none__" "$(val "$A6" row_future-priced)"
A7="$(probe price-project)"
printf '%s\n' "$A7" >>"$RUN"
check "13a the shop still has no price" "__absent__" "$(val "$A7" shop_regular_price_after)"
check "13b …and the buyer still sees nothing" "__none__" "$(val "$A7" price_html_after)"
check "13c …and the product is still published" "publish" "$(val "$A7" shop_status_after)"
AG="$(probe priceless-report)"
printf '%s\n' "$AG" >>"$RUN"
check "13d §1-هـ the published priceless product is REPORTED" "yes" "$(val "$AG" listed_publish-nopr)"
check "13e …and the priced one is not" "no" "$(val "$AG" listed_publish-priced)"
check "13f …and the report says the shop has no price either" \
  "__absent__/marketplace" "$(val "$AG" "priceless_$(val "$A7" wc_product_id)")"

A8="$(probe price-project publish-priced)"
printf '%s\n' "$A8" >>"$RUN"
check "14a the control: a priced product keeps its price" "450000" "$(val "$A8" shop_regular_price_after)"
check "14b …and the buyer still sees it" "450,000&nbsp;&#x062A;&#x0648;&#x0645;&#x0627;&#x0646;" "$(val "$A8" price_html_after)"

say ""
say "=== AFTER · §3 the share estimate, on a product that HAS a commission ==="
# Set again, because the §1 block reset the fixture — and in PERSIAN DIGITS,
# so everything below is downstream of the §2 fix rather than beside it.
AP="$(probe commission-save "۱۲٫۵" percent)"
printf '%s\n' "$AP" >>"$RUN"
check "14c the Persian-typed rule is what the product carries" "percent/12.50" "$(val "$AP" reads_back_as)"

say ""
say "=== AFTER · §3 one screen, one story ==="
A9="$(probe gate-chain)"
printf '%s\n' "$A9" >>"$RUN"
check "15a the control: the gate is still shut on the owner's decisions" \
  "decisions_open" "$(val "$A9" gate_reason)"
check "15b …and still names them" "awaiting DEC-02, DEC-04" "$(val "$A9" gate_detail)"
check "15c the control: the general rate is still set and still irrelevant" \
  "no" "$(val "$A9" gate_reason_is_rate_unset)"
AA="$(probe gate-screen)"
printf '%s\n' "$AA" >>"$RUN"
check "16a the green «فروش فعال است» banner is gone" "no" "$(val "$AA" says_sales_active)"
check "16b …replaced by the effective state" "yes" "$(val "$AA" says_switch_on_gate_shut)"
check "16c …as a warning rather than a tick" "yes" "$(val "$AA" banner_is_warning)"
check "16d the stale general-rate row is gone" "no" "$(val "$AA" says_general_commission_rate)"
check "16e …and says what the model IS instead" "yes" "$(val "$AA" says_commission_model)"
check "16f the purchase table no longer blames the rate" "no" "$(val "$AA" buyer_row_blames_rate)"
AB="$(probe estimate-share)"
printf '%s\n' "$AB" >>"$RUN"
check "17a step 4 estimates from the PRODUCT's own rule" "product" "$(val "$AB" estimate_rate_source)"
check "17b …at the rate the panel stored" "1250" "$(val "$AB" estimate_rate_bp)"
check "17c …which is 12.5% of 450,000" "56250" "$(val "$AB" estimate_commission_minor)"

say ""
say "=== AFTER · §3-و one WooCommerce order, no payment gateway ==="
AC="$(probel trial-on)"
printf '%s\n' "$AC" >>"$RUN"
check "18a the trial switch is honoured on a declared non-production site" "yes" "$(val "$AC" active)"
check "18b …and says the decisions are still open" "ready_on_trial" "$(val "$AC" gate_reason)"
AD="$(probel gate-screen)"
printf '%s\n' "$AD" >>"$RUN"
check "18c the control: with the gate OPEN the banner is a tick again" "yes" "$(val "$AD" banner_is_success)"
AE="$(probel trial-order)"
printf '%s\n' "$AE" >>"$RUN"
check "19a the order carries no payment method" "__none__" "$(val "$AE" payment_method)"
check "19b three units of a 450,000 product" "1350000" "$(val "$AE" order_total)"
check "19c ONE marketplace line was recorded" "1" "$(val "$AE" marketplace_lines)"
check "19d …with the quantity" "3" "$(val "$AE" line_quantity)"
check "19e …the base" "1350000" "$(val "$AE" line_base_minor)"
check "19f …the commission the Persian-typed rule gives" "168750" "$(val "$AE" line_commission_minor)"
check "19g …the vendor's share" "1181250" "$(val "$AE" line_vendor_share_minor)"
check "19h …read off the PRODUCT's rule" "product" "$(val "$AE" line_rate_source)"
check "19i and the ledger carries the money in" "1350000/IRT/0" "$(val "$AE" ledger_item_paid)"
check "19j …the commission out" "-168750/IRT/0" "$(val "$AE" ledger_commission_due)"
check "19k …and the vendor's share out" "-1181250/IRT/0" "$(val "$AE" ledger_vendor_earned)"
AF="$(probel trial-off)"
printf '%s\n' "$AF" >>"$RUN"
check "20a the trial switch is given back" "no" "$(val "$AF" active)"
check "20b …and the gate is shut again" "decisions_open" "$(val "$AF" gate_reason)"

# ------------------------------------------------- giving back what we spent
say ""
say "=== giving back what this run spent ==="
probe reset >/dev/null
R="$(probe report)"
printf '%s\n' "$R" >>"$RUN"
check "21a the fixture's products are gone" "0" "$(val "$R" marketplace_rows_for_fixture)"
check "21b …including the published one" "0" "$(val "$R" product_publish-nopr)"

restore
trap - EXIT
check "21c the installed bytes are back" "$NOW_HASH" "$(digest "$INSTALLED")"
rm -rf "$KEEP"

note "dokan" "Dokan Lite 5.1.1 was NOT activated — Not Run, not «passed»"
note "browser" "no browser checkout was run — Not Run"
note "php81" "PHP 8.1 was not available in this environment — Not Run"

say ""
say "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
