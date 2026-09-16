#!/usr/bin/env bash
# The WooCommerce refund record, interrupted in the only window that can lose
# it, and then retried.
#
# The owner's instruction: «ثبت refund ووکامرس را با قطع اجرا پس از ساخته‌شدن
# refund و پیش از ثبت شناسهٔ آن در TMC آزمایش کن؛ تلاش دوباره نباید رکورد
# تکراری، موجودی اضافی یا ثبت مالی دوم بسازد.» So the retry is measured against
# all three: how many refunds WooCommerce holds, what the shelf says, and how
# many lines the ledger has.
#
#   tools/refund-crash-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/refund-crash}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-64s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-64s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
ret() { wp eval-file return-state.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }

cp "$(dirname "$0")/refund-crash-probe.php" "$WPROOT/wp-content/mu-plugins/"
cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true
wp option delete tmc_probe_crash_refund > /dev/null 2>&1 || true

{
echo "=== the refund record across a crash, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version
ret scope
} | tee "$EV/00-header.txt"

# ---------------------------------------------------------------------------
# A fresh line, taken all the way to «refunded in our own books». That is the
# state `recordWooCommerceRefund()` starts from, and nothing before it is what
# this script is testing.
# ---------------------------------------------------------------------------
read -r TMC_A _REST <<< "$(wp eval '$c = Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
$p = $c->get(Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)->projected();
echo implode(" ", array_map(fn($x) => (int) $x->wcProductId, $p));')"

# A REAL WooCommerce order, not `return-state.php seed-line`. That one invents
# an order id and never creates the order — fine for the ledger, which needs
# only the marketplace's own rows, and useless here: a WooCommerce refund is
# recorded AGAINST an order, and `wc_get_order()` on an invented id is false.
SEEDED="$(wp eval-file engagement-state.php seed-real-return "$TMC_A" | tee "$EV/01-seed.txt")"
echo "    $SEEDED"
ITEM="$(echo "$SEEDED" | field item)"
RETURN_ID="$(echo "$SEEDED" | field return)"
[ -n "$ITEM" ] && [ "$ITEM" != "0" ] || { echo "could not capture a real order line" >&2; exit 2; }
LINE="$(ret show "$ITEM")"
WC_PRODUCT="$(echo "$LINE" | field wc_product)"
stock() { wp eval "\$p = wc_get_product($WC_PRODUCT); echo \$p && \$p->get_manage_stock() ? (int) \$p->get_stock_quantity() : 'unmanaged';"; }
ledger() { ret ledger-count | field lines; }

check "the ledger half is done before WooCommerce is asked"  refunded "$(ret show-return "$RETURN_ID" | field status)"
check "…and WooCommerce holds no refund on this order yet"   0 "$(ret wc-refunds "$ITEM" | field count)"

BASE_STOCK="$(stock)"; BASE_LEDGER="$(ledger)"
echo "    baseline: stock=${BASE_STOCK} ledger_lines=${BASE_LEDGER} item=${ITEM} return=${RETURN_ID}"

# ---------------------------------------------------------------------------
# 1. The crash. Not an exception — `wc_create_refund()` catches those and calls
#    `$refund->delete(true)`, which would tidy away the very thing that has to
#    survive. A killed process leaves the row behind; that is the whole test.
# ---------------------------------------------------------------------------
echo
echo "--- 1. killed after the refund is written, before its id reaches TMC ---"
wp option update tmc_probe_crash_refund 1 > /dev/null
CRASH_CODE=0
(cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root eval-file return-state.php wc-refund "$RETURN_ID") \
  > "$EV/02-crash-stdout.txt" 2> "$EV/02-crash-stderr.txt" || CRASH_CODE=$?
echo "    exit=${CRASH_CODE}  $(head -1 "$EV/02-crash-stderr.txt")"
check "the process really died, it did not return a failure"  9 "$CRASH_CODE"
check "…and said nothing on the way out"                      0 "$(wc -c < "$EV/02-crash-stdout.txt" | tr -d ' ')"
check "…with the probe spent, so the retry runs unmodified"   "" "$(wp option get tmc_probe_crash_refund)"

ORPHANED="$(ret wc-refunds "$ITEM" | tee "$EV/03-orphan.txt")"
echo "    $ORPHANED"
ORPHAN_ID="$(echo "$ORPHANED" | field ids | cut -d: -f1)"
ORPHAN_AMOUNT="$(echo "$ORPHANED" | field ids | cut -d: -f3)"
REMAINING="$(echo "$ORPHANED" | field remaining)"
check "WooCommerce kept the refund the dead process made"     1 "$(echo "$ORPHANED" | field count)"
check "…stamped with the return it belongs to"                1 "$(echo "$ORPHANED" | field stamped)"
check "…and the marketplace row has never heard of it"        -  "$(ret wc-refund-link "$RETURN_ID" | field wc_refund_id)"

# ---------------------------------------------------------------------------
# 2. The retry. Three things must NOT happen, and one must.
#
#    The honesty check first: a duplicate has to have been POSSIBLE. If the
#    orphan had used up the order's remaining refundable amount, WooCommerce
#    would refuse a second refund by itself and this script would be measuring
#    WooCommerce's arithmetic instead of our idempotency key.
# ---------------------------------------------------------------------------
echo
echo "--- 2. the retry adopts the orphan instead of making a second one ---"
# Not «remaining is above zero» — «remaining is still enough for the SAME
# refund again». Anything weaker and WooCommerce's own arithmetic could be
# doing the work this check credits to the idempotency key.
check "a duplicate of this exact amount was still possible"   yes \
  "$(wp eval "echo ((float) '$REMAINING' >= (float) '$ORPHAN_AMOUNT') ? 'yes' : 'no';")"

RETRY="$(ret wc-refund "$RETURN_ID" | tee "$EV/04-retry.txt")"
echo "    $RETRY"
check "the retry succeeds"                                    true "$(echo "$RETRY" | field ok)"
check "…and says it RECOVERED rather than recorded"           refund_recovered "$(echo "$RETRY" | field code)"
check "…adopting the very id the dead process left"           "$ORPHAN_ID" "$(echo "$RETRY" | field wc_refund_id)"
check "…and still refuses to claim the money moved"           false "$(echo "$RETRY" | field did_money)"

AFTER="$(ret wc-refunds "$ITEM" | tee "$EV/05-after-retry.txt")"
echo "    $AFTER"
check "no duplicate record: WooCommerce still holds one"      1 "$(echo "$AFTER" | field count)"
check "no extra stock: the shelf is where the crash left it"  "$BASE_STOCK" "$(stock)"
check "no second financial line: the ledger did not grow"     "$BASE_LEDGER" "$(ledger)"
check "…and the row now points at it"                         "$ORPHAN_ID" "$(ret wc-refund-link "$RETURN_ID" | field wc_refund_id)"

# ---------------------------------------------------------------------------
# 3. A third attempt. Different question, different answer: the row knows now,
#    so this is «already done», not «recovered». Flattening the two would hide
#    that a crash ever happened.
# ---------------------------------------------------------------------------
echo
echo "--- 3. asked a third time, the answer changes ---"
THIRD="$(ret wc-refund "$RETURN_ID" | tee "$EV/06-third.txt")"
echo "    $THIRD"
check "a settled return refuses a further record"             refund_already_recorded "$(echo "$THIRD" | field code)"
check "…and made nothing"                                     1 "$(ret wc-refunds "$ITEM" | field count)"
check "…and still no extra stock"                             "$BASE_STOCK" "$(stock)"

# ---------------------------------------------------------------------------
# 4. The stamp is what does the work, not «the order has a refund». An unstamped
#    refund on the same order — a manager's own, a gateway plugin's — must not
#    be adopted by anybody.
# ---------------------------------------------------------------------------
echo
echo "--- 4. only OUR stamp is adopted ---"
SECOND_RETURN="$(ret open "$ITEM" 1 'مرجوعی دوم' | field return)"
ret decide "$SECOND_RETURN" approved > /dev/null
ret decide "$SECOND_RETURN" received 1 > /dev/null
ret refund "$SECOND_RETURN" > /dev/null
# Its own restock has already happened; the question from here is whether
# RECORDING the refund moves the shelf again, so the baseline is taken now.
STOCK_4="$(stock)"; LEDGER_4="$(ledger)"
FOREIGN="$(wp eval "\$o = wc_get_order((int) '$(ret show "$ITEM" | field order)');
\$r = wc_create_refund(['order_id' => \$o->get_id(), 'amount' => 1, 'reason' => 'somebody else', 'refund_payment' => false, 'restock_items' => false]);
echo is_wp_error(\$r) ? 'error' : (int) \$r->get_id();" | tee "$EV/07-foreign-refund.txt")"
echo "    a refund made outside the marketplace: ${FOREIGN}"
check "the order now carries a refund that is not ours"       1 \
  "$(ret wc-refunds "$ITEM" | field ids | tr ',' '\n' | grep -c "^${FOREIGN}:-:")"
SECOND="$(ret wc-refund "$SECOND_RETURN" | tee "$EV/08-second-return.txt")"
echo "    $SECOND"
check "…and the second return did not adopt it"               refund_recorded "$(echo "$SECOND" | field code)"
check "…it made its own, which is a different id"             yes \
  "$([ "$(echo "$SECOND" | field wc_refund_id)" != "$FOREIGN" ] && echo yes || echo no)"
check "…and its own is stamped, the stranger's is not"        2 "$(ret wc-refunds "$ITEM" | field stamped)"
check "…so the order holds three refunds, each written once"  3 "$(ret wc-refunds "$ITEM" | field count)"
check "…and recording refunds moved no stock at all"          "$STOCK_4" "$(stock)"
check "…nor wrote a financial line"                           "$LEDGER_4" "$(ledger)"

# ---------------------------------------------------------------------------
# 5. The EARLIER window — the one the stamp cannot cover.
#
#    The claim this section replaces was that the refund's id and our stamp are
#    «one insert». They are not, on either storage backend. This site runs
#    HPOS, so the path is `OrdersTableDataStore::persist_save()`:
#    persist_order_to_db() → update_order_meta() → save_meta_data(), with our
#    stamp in the third. The legacy-posts path has the same three-step shape.
#    Nothing wraps them in a transaction and nothing in the row names the
#    return, so the store in use is PRINTED below rather than assumed.
#
#    So a crash between step 1 and step 3 leaves a refund this build cannot
#    recognise. The stamp alone would then make a SECOND one. The intent marker
#    is what closes it: the retry stops and hands the ids to a person.
# ---------------------------------------------------------------------------
echo
echo "--- 5. killed BEFORE the stamp: the retry refuses to guess ---"
# WooCommerce's own source, read at run time rather than quoted from memory.
STEPS="$(wp eval-file refund-storage-probe.php | tee "$EV/10-storage-path.txt")"
echo "    $STEPS"
check "the row is one write…"                                 1 "$(echo "$STEPS" | field row_write)"
check "…the internal props another…"                          1 "$(echo "$STEPS" | field prop_write)"
check "…and OUR stamp a third"                                1 "$(echo "$STEPS" | field stamp_write)"
check "…with nothing wrapping them in a transaction"          0 "$(echo "$STEPS" | field transaction)"
check "…and the row itself names no return"                   0 "$(echo "$STEPS" | field excerpt_carries_reason)"

# A FRESH order and return, not the line section 1 used. That line's two units
# are already spent — one returned in section 1, one in section 4 — and a third
# `open` is refused by the quantity rule, which would make this section test
# nothing while looking like it passed.
SEED_5="$(wp eval-file engagement-state.php seed-real-return "$TMC_A" | tee "$EV/09b-seed.txt")"
echo "    $SEED_5"
ITEM_5="$(echo "$SEED_5" | field item)"
THIRD_RETURN="$(echo "$SEED_5" | field return)"
[ -n "$THIRD_RETURN" ] && [ "$THIRD_RETURN" != "0" ] || { echo "could not seed a second real return" >&2; exit 2; }
check "the fresh return is refunded in our books"             refunded \
  "$(ret show-return "$THIRD_RETURN" | field status)"
BEFORE_5="$(ret wc-refunds "$ITEM_5")"
COUNT_5="$(echo "$BEFORE_5" | field count)"
STAMPED_5="$(echo "$BEFORE_5" | field stamped)"
STOCK_5="$(stock)"; LEDGER_5="$(ledger)"

wp option update tmc_probe_crash_unstamped 1 > /dev/null
CRASH2=0
(cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root eval-file return-state.php wc-refund "$THIRD_RETURN") \
  > "$EV/11-crash-unstamped-stdout.txt" 2> "$EV/11-crash-unstamped-stderr.txt" || CRASH2=$?
echo "    exit=${CRASH2}  $(head -1 "$EV/11-crash-unstamped-stderr.txt")"
check "the process died before the stamp was written"         8 "$CRASH2"

ORPHAN2="$(ret wc-refunds "$ITEM_5" | tee "$EV/12-after-crash.txt")"
echo "    $ORPHAN2"
check "WooCommerce kept a refund all the same"                "$((COUNT_5 + 1))" "$(echo "$ORPHAN2" | field count)"
# MEASURED, not assumed, and it is better news than the design allowed for:
# under HPOS this plugin's stamp is written BEFORE `_refund_type`, so by the
# time the earliest reachable meta hook fires the refund is already
# recognisable. The window is therefore narrower than the three-step shape
# suggests — but it is not zero, because the row insert and the first meta
# insert are still separate statements with nothing between them.
check "…and on THIS backend it was already stamped"          "$((STAMPED_5 + 1))" \
  "$(echo "$ORPHAN2" | field stamped)"
RETRY_STAMPED="$(ret wc-refund "$THIRD_RETURN" | tee "$EV/13-retry-after-crash.txt")"
echo "    $RETRY_STAMPED"
check "…so the retry recovers it automatically"              refund_recovered \
  "$(echo "$RETRY_STAMPED" | field code)"

# ---------------------------------------------------------------------------
# 6. The state the stamp CANNOT cover, staged directly.
#
#    A refund that exists with no stamp is what the un-transactioned window
#    leaves — and it is also exactly what a manager's own wp-admin refund
#    leaves. From the plugin's side the two are indistinguishable, which is
#    precisely why it must not adopt one by resemblance.
# ---------------------------------------------------------------------------
echo
echo "--- 6. an unstamped refund plus a known attempt: the retry refuses ---"
SEED_6="$(wp eval-file engagement-state.php seed-real-return "$TMC_A" | tee "$EV/14-seed.txt")"
echo "    $SEED_6"
ITEM_6="$(echo "$SEED_6" | field item)"
RETURN_6="$(echo "$SEED_6" | field return)"
STOCK_6="$(stock)"; LEDGER_6="$(ledger)"
STAGED="$(ret stage-orphan "$RETURN_6" | tee "$EV/15-orphan.txt")"
echo "    $STAGED"
AFTER_STAGE="$(ret wc-refunds "$ITEM_6")"
echo "    $AFTER_STAGE"
check "the order carries a refund with no stamp"              1 \
  "$(echo "$AFTER_STAGE" | field ids | tr ',' '\n' | grep -c ':-:' || true)"
check "…and the return still says it has none"                - \
  "$(ret wc-refund-link "$RETURN_6" | field wc_refund_id)"

RETRY3="$(ret wc-refund "$RETURN_6" | tee "$EV/16-retry-orphan.txt")"
echo "    $RETRY3"
check "the retry refuses rather than guessing"                refund_reconcile_required \
  "$(echo "$RETRY3" | field code)"
check "…and made no second refund"                            "$(echo "$AFTER_STAGE" | field count)" \
  "$(ret wc-refunds "$ITEM_6" | field count)"
check "…and moved no stock"                                   "$STOCK_6" "$(stock)"
check "…and wrote no financial line"                          "$LEDGER_6" "$(ledger)"
check "…and left the return honestly unlinked"                - \
  "$(ret wc-refund-link "$RETURN_6" | field wc_refund_id)"
check "…and named the id a person has to look at"             yes \
  "$([ "$(echo "$RETRY3" | field candidates)" != "-" ] && echo yes || echo no)"

{
echo "=== final state ==="
ret wc-refunds "$ITEM"
ret list "$ITEM"
ret scope
} > "$EV/09-final-state.txt"

wp option delete tmc_probe_crash_refund > /dev/null 2>&1 || true
echo
printf 'refund record across a crash — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
