#!/usr/bin/env bash
# The unpaid-order guard, asked the four questions a general claim did not answer.
#
# The owner named them: do not treat the restore note as proof of a hold; do
# not delete that note before the move is confirmed; do not skip rows while
# paging a query you are mutating; and measure stock instead of saying it comes
# back. Each section below fails on the code this delivery replaced.
#
#   tools/guard-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/guard}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
BULK="${BULK:-210}"        # more than one page of the guard's own query (200)
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
g() { wp eval-file guard-state.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }

# The injected failure lives OUTSIDE the plugin, like any third-party plugin
# refusing a write. A failure our own code knows how to produce would prove our
# branch, not the shop's behaviour.
install -d "$WPROOT/wp-content/mu-plugins"
cp "$(dirname "$0")/order-fail-probe.php" "$WPROOT/wp-content/mu-plugins/"
cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

{
echo "=== the unpaid-order guard, measured ==="
wp plugin list --fields=name,status,version
} | tee "$EV/00-header.txt"

g probe-off > /dev/null
g forget-all > /dev/null
# Its own fixtures from a previous run, and only those: two hundred leftover
# orders make every count in this run a different number than it looks.
g cleanup > /dev/null
wp eval-file purchase-block-state.php resume > /dev/null
read -r TMC_A _REST <<< "$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
echo implode(" ", array_map(fn($x) => (int) $x->wcProductId, $p));')"
SHOP="$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} p WHERE p.post_type = \"product\" AND p.post_status = \"publish\" AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = \"_tmc_product_id\") AND p.post_author = 1 ORDER BY p.ID ASC LIMIT 1");')"
# Stock has to be big enough that 200+ orders cannot exhaust it; a product that
# runs out mid-run would look like a guard failure and be a fixture failure.
wp eval "\$p = wc_get_product($TMC_A); \$p->set_manage_stock(true); \$p->set_stock_quantity(5000); \$p->save();" > /dev/null
wp eval "\$p = wc_get_product($SHOP); \$p->set_manage_stock(true); \$p->set_stock_quantity(5000); \$p->save();" > /dev/null
echo "    marketplace product ${TMC_A} · the shop's own ${SHOP}"

echo
echo "--- 1. storage refuses every save of one order ---"
# `WC_Order::save()` catches its own exceptions and returns normally, so the
# guard cannot learn anything from the call succeeding. What it does instead —
# re-read the order and look at the status — is the only thing that survives
# that, and this is the case that proves it.
ORDER="$(g seed 1 "$TMC_A" pending | field first)"
g probe "$ORDER" hold_all > /dev/null
HOLD="$(g hold 'probe_hold_all')"
echo "    $HOLD"
STATE="$(g order "$ORDER")"
echo "    $STATE"
check "the order did not move"                            pending "$(echo "$STATE" | field status)"
check "…and nothing was written to it either"             '-' "$(echo "$STATE" | field note)"
check "…and the failure is REPORTED, not swallowed"       "$ORDER" \
  "$(echo "$HOLD" | field stuck | tr ',' '\n' | grep -c "^${ORDER}$" | sed "s/^1$/${ORDER}/;s/^0$/-/")"
check "…so the order is still listed as payable"          yes \
  "$(g payable | field count | awk '{print ($1 > 0) ? "yes" : "no"}')"
g probe-off > /dev/null
RETRY="$(g hold 'retry')"
echo "    retry: $RETRY"
check "a retry with the failure gone holds it"            on-hold "$(g order "$ORDER" | field status)"

echo
echo "--- 2. the note is written and the MOVE fails ---"
# This is the one the old code could not come back from: the note existed, so
# every later pass called the order handled and skipped it forever.
ORDER2="$(g seed 1 "$TMC_A" pending | field first)"
g probe "$ORDER2" hold_status > /dev/null
HOLD2="$(g hold 'probe_hold_status')"
echo "    $HOLD2"
STATE2="$(g order "$ORDER2")"
echo "    $STATE2"
check "the order is still payable"                        pending "$(echo "$STATE2" | field status)"
check "…and it carries a note that is NOT a hold"         pending "$(echo "$STATE2" | field note)"
check "…so «هنوز قابل پرداخت» still counts it"           yes \
  "$(g payable | field count | awk '{print ($1 > 0) ? "yes" : "no"}')"
check "…and the stop reports it stuck"                    "$ORDER2" \
  "$(echo "$HOLD2" | field stuck | tr ',' '\n' | grep -c "^${ORDER2}$" | sed "s/^1$/${ORDER2}/;s/^0$/-/")"
g probe-off > /dev/null
g hold 'retry' > /dev/null
check "a retry holds it — the note never blocked it"      on-hold "$(g order "$ORDER2" | field status)"

echo
echo "--- 3. a save that fails while MOVING BACK ---"
g probe "$ORDER2" release_status > /dev/null
REL="$(g release)"
echo "    $REL"
STATE3="$(g order "$ORDER2")"
echo "    $STATE3"
check "the order is still held"                           on-hold "$(echo "$STATE3" | field status)"
check "…and the restore note SURVIVED the failure"        pending "$(echo "$STATE3" | field note)"
check "…and the failure is reported"                      "$ORDER2" \
  "$(echo "$REL" | field stuck | tr ',' '\n' | grep -c "^${ORDER2}$" | sed "s/^1$/${ORDER2}/;s/^0$/-/")"
g probe-off > /dev/null
g release > /dev/null
check "a retry puts it back exactly where it was"         pending "$(g order "$ORDER2" | field status)"

echo
echo "--- 4. the move back works and DROPPING THE NOTE fails ---"
g hold 'again' > /dev/null
g probe "$ORDER2" release_meta > /dev/null
REL2="$(g release)"
echo "    $REL2"
STATE4="$(g order "$ORDER2")"
echo "    $STATE4"
check "the order really is back"                          pending "$(echo "$STATE4" | field status)"
check "…the leftover note is the only damage"             pending "$(echo "$STATE4" | field note)"
g probe-off > /dev/null
g release > /dev/null
check "…and the next release simply forgets it"           '-' "$(g order "$ORDER2" | field note)"
check "…without moving the order again"                   pending "$(g order "$ORDER2" | field status)"

echo
echo "--- 5. more than one page of eligible orders ---"
# The guard reads 200 per page. Paging a query while moving rows OUT of it
# skipped whole pages; the ids are snapshotted first now. Anything less than
# ALL of them left behind is the bug.
g forget-all > /dev/null
wp eval "\$p = wc_get_product($TMC_A); \$p->set_stock_quantity(5000); \$p->save();" > /dev/null
BEFORE="$(g census | field pending)"
g seed "$BULK" "$TMC_A" pending > "$EV/05-seed.txt"
cat "$EV/05-seed.txt"
PAYABLE="$(g payable | field count)"
echo "    payable now: ${PAYABLE}"
check "the fixture really is bigger than one page"        yes \
  "$([ "$PAYABLE" -gt 200 ] && echo yes || echo no)"
BULK_HOLD="$(g hold 'bulk')"
echo "    $BULK_HOLD"
check "every eligible order was held in ONE pass"         "$PAYABLE" "$(echo "$BULK_HOLD" | field held)"
check "…none stuck"                                       '-' "$(echo "$BULK_HOLD" | field stuck)"
check "…and nothing is left payable"                      0 "$(g payable | field count)"
BULK_REL="$(g release)"
echo "    $BULK_REL"
check "and every one of them comes back in one pass"      "$PAYABLE" "$(echo "$BULK_REL" | field released)"
check "…none stuck on the way back"                       '-' "$(echo "$BULK_REL" | field stuck)"
check "…and no restore note is left behind"               0 "$(g census | field with_note)"
{ echo "=== bulk ==="; echo "$BULK_HOLD"; echo "$BULK_REL"; g census; } > "$EV/05-bulk.txt"

echo
echo "--- 6. stock, for every shape of order ---"
# Not «موجودی برمی‌گردد» and not «محدودیت مستند» either: the pair has to
# CANCEL OUT, including for an order that arrived already reduced. Every
# sub-case is isolated, because `hold()` holds every payable order there is
# and a leftover from the case before makes the delta a different number than
# it looks.
# Truly alone: release what is held, then DELETE every marketplace order that
# is still unpaid — including ones other evidence runs left behind. Without
# that, `hold()` correctly moves several orders at once and every «exactly one
# unit» reading below is a different number than it looks.
isolate() { g release > /dev/null; g purge > /dev/null; g forget-all > /dev/null; }
{
echo "=== stock, measured ==="

# 6a. an ordinary pending order
isolate
S0="$(g stock "$TMC_A" | field "$TMC_A")"
O_A="$(g seed 1 "$TMC_A" pending | field first)"
g hold 'stock_pending' > /dev/null
S1="$(g stock "$TMC_A" | field "$TMC_A")"
g release > /dev/null
S2="$(g stock "$TMC_A" | field "$TMC_A")"
echo "pending: ${S0} ${S1} ${S2}"

# 6b. the SAME order, stopped and released a second time
g hold 'stock_repeat' > /dev/null
S3="$(g stock "$TMC_A" | field "$TMC_A")"
g release > /dev/null
S4="$(g stock "$TMC_A" | field "$TMC_A")"
echo "repeat: ${S2} ${S3} ${S4}"

# 6c. an order that starts FAILED
isolate
S5="$(g stock "$TMC_A" | field "$TMC_A")"
O_F="$(g seed 1 "$TMC_A" failed | field first)"
g hold 'stock_failed' > /dev/null
S6="$(g stock "$TMC_A" | field "$TMC_A")"
g release > /dev/null
S7="$(g stock "$TMC_A" | field "$TMC_A")"
BACK_TO="$(g order "$O_F" | field status)"
echo "failed: ${S5} ${S6} ${S7}"

# 6d. THE ONE THAT USED TO LEAK: already reduced before the hold
isolate
O_P="$(g seed-prereduced "$TMC_A" | field order)"
S8="$(g stock "$TMC_A" | field "$TMC_A")"
g hold 'stock_prereduced' > /dev/null
S9="$(g stock "$TMC_A" | field "$TMC_A")"
PRE_TRAIL="$(g trail "$O_P")"
g release > /dev/null
S10="$(g stock "$TMC_A" | field "$TMC_A")"
echo "prereduced: ${S8} ${S9} ${S10}"

# 6e. the same one, a SECOND cycle — a leak would compound here
g hold 'stock_prereduced_again' > /dev/null
S11="$(g stock "$TMC_A" | field "$TMC_A")"
g release > /dev/null
S12="$(g stock "$TMC_A" | field "$TMC_A")"
echo "prereducedagain: ${S10} ${S11} ${S12}"

# 6f. a MIXED order: a marketplace line and the shop's own goods together
isolate
M0_T="$(g stock "$TMC_A" | field "$TMC_A")"
M0_S="$(g stock "$SHOP" | field "$SHOP")"
O_M="$(g seed-mixed "$TMC_A" "$SHOP" | field order)"
g hold 'stock_mixed' > /dev/null
M1_T="$(g stock "$TMC_A" | field "$TMC_A")"
M1_S="$(g stock "$SHOP" | field "$SHOP")"
g release > /dev/null
M2_T="$(g stock "$TMC_A" | field "$TMC_A")"
M2_S="$(g stock "$SHOP" | field "$SHOP")"
echo "mixedtmc: ${M0_T} ${M1_T} ${M2_T}"
echo "mixedshop: ${M0_S} ${M1_S} ${M2_S}"

# 6g. A SALE happens between the hold and the release. The release must not
#     undo it — which is exactly what restoring a remembered total would do.
isolate
C0="$(g stock "$TMC_A" | field "$TMC_A")"
g seed 1 "$TMC_A" pending > /dev/null
g hold 'stock_concurrent' > /dev/null
g sell-more "$TMC_A" 3 > /dev/null
C1="$(g stock "$TMC_A" | field "$TMC_A")"
g release > /dev/null
C2="$(g stock "$TMC_A" | field "$TMC_A")"
echo "concurrent: ${C0} ${C1} ${C2}"

echo "failedwentbackto: ${BACK_TO}"
echo "pretrail: ${PRE_TRAIL}"
} | tee "$EV/06-stock.txt"

STOCKLOG="$EV/06-stock.txt"
# Three plain numbers per line, in order: before, after the hold, after the
# release. Written that way on purpose — an earlier version put «->» between
# them and the parser read every arrow as a number.
num() { grep "^$1:" "$STOCKLOG" | cut -d: -f2; }
read -r P0 P1 P2 <<< "$(num pending)"
read -r R2 R3 R4 <<< "$(num repeat)"
read -r F5 F6 F7 <<< "$(num failed)"
read -r Q8 Q9 Q10 <<< "$(num prereduced)"
read -r Q10b Q11 Q12 <<< "$(num prereducedagain)"
read -r T0 T1 T2 <<< "$(num mixedtmc)"
read -r H0 H1 H2 <<< "$(num mixedshop)"
read -r C0 C1 C2 <<< "$(num concurrent)"
FBACK="$(num failedwentbackto | tr -d ' ')"

check "pending: the hold takes exactly one unit out"      "$((P0 - 1))" "$P1"
check "pending: the release puts back exactly that"       "$P0" "$P2"
check "repeat: a second cycle takes the same one out"     "$((R2 - 1))" "$R3"
check "repeat: …and does not drift"                       "$R2" "$R4"
check "failed: the hold takes stock out"                  "$((F5 - 1))" "$F6"
check "failed: …and it comes back"                        "$F5" "$F7"
check "failed: …to the status it really came from"        failed "$FBACK"
check "pre-reduced: the hold reduces NOTHING more"        "$Q8" "$Q9"
check "pre-reduced: …AND THE RELEASE ADDS NOTHING"       "$Q8" "$Q10"
check "pre-reduced: …a second cycle does not compound"   "$Q10b" "$Q12"
check "mixed: the marketplace line came back level"       "$T0" "$T2"
check "mixed: the SHOP's own goods were moved too"        "$((H0 - 1))" "$H1"
check "mixed: …and came back level as well"               "$H0" "$H2"
# The sale took three units and the hold's own one comes back, so the end
# state is exactly «three fewer than we started». A release that restored a
# remembered total would read $C0 here and silently undo the sale.
check "a sale during the hold survives the release"       "$((C0 - 3))" "$C2"
check "…so the release restored no remembered total"      yes \
  "$([ "$C2" -lt "$C0" ] && echo yes || echo no)"
check "the trail says the order arrived already reduced"  true \
  "$(grep '^pretrail:' "$STOCKLOG" | grep -oE 'was_reduced=[a-z]+' | cut -d= -f2)"
check "…and that our own hold moved nothing"              false \
  "$(grep '^pretrail:' "$STOCKLOG" | grep -oE 'moved=[a-z]+' | cut -d= -f2)"
check "nothing was left needing a person"                 0 "$(g reconcile-list | field count)"

echo
echo "--- 6h. somebody pays the order while it is held ---"
# Their decision outranks ours: the release must leave the status alone, take
# only its own notes off, and report it apart from «released».
isolate
O_PAID="$(g seed 1 "$TMC_A" pending | field first)"
PAID_START="$(g stock "$TMC_A" | field "$TMC_A")"
g hold 'paid_midway' > /dev/null
g move-order "$O_PAID" completed > /dev/null
REL_PAID="$(g release)"
echo "    $REL_PAID"
check "the paid order is reported as moved on, not released" "$O_PAID" \
  "$(echo "$REL_PAID" | field moved_on | tr ',' '\n' | grep -c "^${O_PAID}$" | sed "s/^1$/${O_PAID}/;s/^0$/-/")"
check "…its status is left exactly as the person set it"  completed "$(g order "$O_PAID" | field status)"
check "…and the stop's own notes are gone"                '-' "$(g order "$O_PAID" | field note)"
# The order SOLD: WooCommerce reduced on the way into on-hold and `completed`
# keeps it reduced. One unit fewer is the truth, and a correction from us on
# top would be the second movement for one decision.
check "…and its stock shows the sale, corrected by nobody" "$((PAID_START - 1))" "$(g stock "$TMC_A" | field "$TMC_A")"

echo
echo "--- 6i. somebody CANCELS the order while it is held ---"
isolate
O_CAN="$(g seed 1 "$TMC_A" pending | field first)"
g hold 'cancelled_midway' > /dev/null
CAN_START="$(g stock "$TMC_A" | field "$TMC_A")"
g move-order "$O_CAN" cancelled > /dev/null
CAN_AFTER_CANCEL="$(g stock "$TMC_A" | field "$TMC_A")"
REL_CAN="$(g release)"
echo "    $REL_CAN"
check "the cancelled order is reported as moved on"       "$O_CAN" \
  "$(echo "$REL_CAN" | field moved_on | tr ',' '\n' | grep -c "^${O_CAN}$" | sed "s/^1$/${O_CAN}/;s/^0$/-/")"
check "…and WooCommerce's own cancel movement stands"     "$CAN_AFTER_CANCEL" "$(g stock "$TMC_A" | field "$TMC_A")"
# CAN_START is read AFTER the hold, so the unit is already out. Cancelling is
# a releasing status, so WooCommerce puts it back — one MORE than CAN_START,
# not the same. The first version of this check asserted equality and was
# simply reading the arrow backwards.
check "…which put the cancelled order's unit back"       "$((CAN_START + 1))" "$CAN_AFTER_CANCEL"

echo
echo "--- 7. an order with nothing of ours is never touched ---"
g forget-all > /dev/null
SHOP_ONLY="$(g seed 1 "$SHOP" pending | field first)"
g hold 'scope' > /dev/null
SHOP_STATE="$(g order "$SHOP_ONLY")"
echo "    $SHOP_STATE"
check "the shop's own order kept its status"              pending "$(echo "$SHOP_STATE" | field status)"
check "…and was never marked"                             '-' "$(echo "$SHOP_STATE" | field note)"
g release > /dev/null
g probe-off > /dev/null

{ echo "=== final census ==="; g census; g payable; } > "$EV/07-final.txt"
g cleanup > /dev/null

echo
printf 'unpaid-order guard — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
