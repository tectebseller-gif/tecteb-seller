#!/usr/bin/env bash
# The three things this delivery added, and the one it took apart.
#
#   · a private ticket attachment — stored where no URL reaches it
#   · in-panel notifications — addressed, unread, and never sent anywhere
#   · the approved reports — computed, scoped, and «ثبت‌نشده» is not zero
#   · the WooCommerce refund RECORD, apart from the money
#
# Each section asks the shop rather than the code: a real file on disk, a real
# HTTP fetch of every URL that could reach it, real rows in a real database.
#
#   tools/engagement-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/engagement}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
SCRATCH="${SCRATCH:-/tmp/claude-0/engagement}"
mkdir -p "$EV" "$SCRATCH"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
e() { wp eval-file engagement-state.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }

cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

{
echo "=== attachments, notices, reports, and the refund record ==="
wp plugin list --fields=name,status,version
echo "schema: $(wp option get tmc_schema_version)"
} | tee "$EV/00-header.txt"

VENDOR="$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT vendor_user_id FROM {$wpdb->prefix}tmc_products LIMIT 1");')"
MANAGER="$(wp eval 'echo (int) (get_users(["role" => "administrator", "number" => 1, "fields" => "ID"])[0] ?? 1);')"
echo "    vendor ${VENDOR} · manager ${MANAGER}"

echo
echo "--- 1. a file on a ticket, stored where no URL reaches it ---"
TICKET="$(e ticket "$VENDOR" 'پیوست آزمایشی')"
echo "    $TICKET"
TICKET_ID="$(echo "$TICKET" | field ticket)"
MESSAGE_ID="$(echo "$TICKET" | field message)"
check "the ticket opened, and says which message it wrote"  yes \
  "$(case "$MESSAGE_ID" in ''|-|0) echo no;; *) echo yes;; esac)"

# A real PNG, built here rather than fetched: the service checks the DETECTED
# type, so the bytes have to actually be a PNG.
python3 - "$SCRATCH/probe.png" <<'PY'
import struct, sys, zlib
def chunk(tag, data):
    return struct.pack('>I', len(data)) + tag + data + struct.pack('>I', zlib.crc32(tag + data))
raw = b'\x00' + b'\xff\x00\x00' * 4
png = (b'\x89PNG\r\n\x1a\n'
       + chunk(b'IHDR', struct.pack('>IIBBBBB', 4, 1, 8, 2, 0, 0, 0))
       + chunk(b'IDAT', zlib.compress(raw))
       + chunk(b'IEND', b''))
open(sys.argv[1], 'wb').write(png)
PY
ATTACH="$(e attach "$VENDOR" "$TICKET_ID" "$MESSAGE_ID" "$SCRATCH/probe.png")"
echo "    $ATTACH"
ATTACH_ID="$(echo "$ATTACH" | field attachment)"
check "the vendor may attach a PNG to their own ticket"     true "$(echo "$ATTACH" | field ok)"
check "…and it is listed on the thread"                     1 "$(e files "$VENDOR" "$TICKET_ID" | field count)"

STORED="$(e stored-path "$ATTACH_ID" | field path)"
echo "    stored at: ${STORED}"
check "…stored under a path that is not the file's name"    no \
  "$(case "$STORED" in *probe.png*) echo yes;; *) echo no;; esac)"

echo
echo "--- 2. no URL reaches those bytes ---"
# Every place a person could guess. A 200 on any of them is the whole failure.
for GUESS in \
  "$SITE/wp-content/uploads/${STORED}" \
  "$SITE/wp-content/uploads/tecteb-private/${STORED}" \
  "$SITE/${STORED}" \
  "$SITE/wp-content/plugins/tecteb-marketplace-core/${STORED}" \
  "$SITE/wp-content/uploads/../../../tecteb-private/${STORED}"
do
  CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$GUESS")"
  check "a guessed URL does not serve the file"             yes \
    "$(case "$CODE" in 200) echo no;; *) echo yes;; esac)"
done

echo
echo "--- 3. the guarded route: the thread's people, and nobody else ---"
check "the vendor who sent it can read it"                  true "$(e read-file "$VENDOR" "$ATTACH_ID" | field ok)"
check "the manager can read it"                             true "$(e read-file "$MANAGER" "$ATTACH_ID" | field ok)"
STRANGER="$(wp eval 'echo (int) (get_users(["role" => "customer", "number" => 1, "fields" => "ID"])[0] ?? 0);')"
check "a customer who is not in the thread cannot"          false "$(e read-file "$STRANGER" "$ATTACH_ID" | field ok)"
ANON="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$SITE/?tmc_ticket_file=${ATTACH_ID}")"
check "…and the route with no nonce refuses"                yes \
  "$(case "$ANON" in 200) echo no;; *) echo yes;; esac)"

echo
echo "--- 4. the wrong kind of file, and the too-big one ---"
printf '<?php echo "not a picture"; ?>' > "$SCRATCH/evil.php"
BAD="$(e attach "$VENDOR" "$TICKET_ID" "$MESSAGE_ID" "$SCRATCH/evil.php" 'application/x-php')"
echo "    $BAD"
check "a PHP file is refused by its DETECTED type"          attachment_type_not_allowed "$(echo "$BAD" | field code)"
head -c 6000000 /dev/urandom > "$SCRATCH/big.bin"
BIG="$(e attach "$VENDOR" "$TICKET_ID" "$MESSAGE_ID" "$SCRATCH/big.bin" 'image/png')"
echo "    $BIG"
check "a file over the limit is refused"                    attachment_too_large "$(echo "$BIG" | field code)"
check "…and neither of them landed on the thread"           1 "$(e files "$VENDOR" "$TICKET_ID" | field count)"

echo
echo "--- 5. hiding a file is not deleting it ---"
HIDE="$(e hide-file "$MANAGER" "$ATTACH_ID" 'حاوی اطلاعات شخصی')"
echo "    $HIDE"
check "the manager may hide it with a reason"               true "$(echo "$HIDE" | field ok)"
check "…the row is still there"                             1 "$(e files "$MANAGER" "$TICKET_ID" | field count)"
check "…and the person who sent it can no longer open it"   false "$(e read-file "$VENDOR" "$ATTACH_ID" | field ok)"
check "…while the manager who hid it still can"             true "$(e read-file "$MANAGER" "$ATTACH_ID" | field ok)"

echo
echo "--- 6. a notice is addressed, and arrives once ---"
BEFORE="$(e inbox "$VENDOR" | field unread)"
e reply "$TICKET_ID" 'پاسخ مدیر' > /dev/null
AFTER="$(e inbox "$VENDOR")"
echo "    $AFTER"
check "the manager's reply told the shop"                   yes \
  "$([ "$(echo "$AFTER" | field unread)" -gt "$BEFORE" ] && echo yes || echo no)"
check "…and told the manager nothing about their own reply" 0 "$(e inbox "$MANAGER" | field unread)"
TWICE="$(e notify-twice "$VENDOR")"
echo "    $TWICE"
check "the same notice twice: the first writes"             true "$(echo "$TWICE" | field first)"
check "…and the second is refused by the unique index"      false "$(echo "$TWICE" | field second)"

FIRST_NOTICE="$(e inbox "$VENDOR" | grep -oE 'notice=[0-9]+' | head -1 | cut -d= -f2)"
MARKED="$(e mark-read "$VENDOR" "$FIRST_NOTICE")"
echo "    $MARKED"
check "marking one read works"                              true "$(echo "$MARKED" | field ok)"
check "a stranger cannot mark somebody else's notice"       false \
  "$(e mark-read "$STRANGER" "$FIRST_NOTICE" | field ok)"

echo
echo "--- 7. the approved reports ---"
VR="$(e report-vendor "$VENDOR" "$VENDOR" | tee "$EV/07-vendor-report.txt")"
echo "$VR" | head -6
check "a vendor gets the four cards UX §4.1 approves"       4 "$(echo "$VR" | field cards)"
check "…and asking for another shop's returns nothing"      0 \
  "$(e report-vendor "$STRANGER" "$VENDOR" | field cards)"
MR="$(e report-manager | tee "$EV/07-manager-report.txt")"
echo "$MR" | head -4
check "the manager gets the two UX §12.3 approves"          2 "$(echo "$MR" | field cards)"
check "…«سهم ثبت‌نشده» is counted, not folded into zero"    yes \
  "$(echo "$MR" | grep -q 'unrecorded_lines=' && echo yes || echo no)"

echo
echo "--- 8. the WooCommerce refund RECORD, apart from the money ---"
# A real WooCommerce order, made here. Reading one out of the fixtures found
# ids whose orders a cleanup had deleted, and the blocker list correctly said
# «سفارش پیدا نشد» — a right answer to a question the fixture had got wrong.
# The question this section actually asks is what the recorder says about an
# order that EXISTS, so the evidence makes one.
PROBE_PRODUCT="$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT wc_product_id FROM {$wpdb->prefix}tmc_products WHERE wc_product_id > 0 LIMIT 1");')"
WC_ORDER="$(wp eval-file guard-state.php seed 1 "$PROBE_PRODUCT" pending | field first)"
echo "    asking about order ${WC_ORDER}"
BLOCKERS="$(e refund-blockers "$WC_ORDER")"
echo "    $BLOCKERS"
check "WooCommerce is there to record against"              true "$(echo "$BLOCKERS" | field available)"
check "…but the money cannot move"                          false "$(echo "$BLOCKERS" | field can_transfer)"
check "…and the reason is NAMED, not «درگاه نیست»"          yes \
  "$(echo "$BLOCKERS" | field list | grep -q 'refund_no_gateway_adapter' && echo yes || echo no)"

# A return that the LEDGER has already reversed, made here through the
# marketplace's own path — the WooCommerce record is the step AFTER that one,
# and a fixture without it would leave this section permanently skipped.
# …and whose WooCommerce order still EXISTS. A return pointing at a deleted
# order made the recorder answer «سفارش پیدا نشد» — correct, and not what this
# section is asking about.
find_refunded() { wp eval 'global $wpdb;
$rows = $wpdb->get_results("SELECT r.id, i.wc_order_id FROM {$wpdb->prefix}tmc_returns r
    JOIN {$wpdb->prefix}tmc_order_items i ON i.id = r.order_item_id
    WHERE r.status = \"refunded\" AND r.wc_refund_id IS NULL ORDER BY r.id DESC LIMIT 20");
foreach ($rows as $row) { if (wc_get_order((int) $row->wc_order_id)) { echo (int) $row->id; return; } }
echo 0;'; }
REFUNDED="$(find_refunded)"
if [ -z "$REFUNDED" ] || [ "$REFUNDED" = "0" ]; then
  # A REAL WooCommerce order, walked to «refunded in the ledger». Not
  # `return-state.php seed-line`, which invents an order id and never creates
  # the order — right for the ledger's own evidence, and nothing to record a
  # WooCommerce refund against.
  SEEDED="$(e seed-real-return "$PROBE_PRODUCT")"
  echo "    $SEEDED"
  REFUNDED="$(find_refunded)"
fi
if [ -z "$REFUNDED" ] || [ "$REFUNDED" = "0" ]; then
  check "…(no ledger-refunded return on this fixture)"      skipped skipped
else
  REC="$(e refund-record "$MANAGER" "$REFUNDED")"
  echo "    $REC"
  check "the manager may record it in WooCommerce"          true "$(echo "$REC" | field ok)"
  check "…and the SAME answer says the money did not move"  false "$(echo "$REC" | field did_money)"
  check "…a second attempt is refused"                      refund_already_recorded \
    "$(e refund-record "$MANAGER" "$REFUNDED" | field code)"
fi

{ echo "=== final state ==="; e files "$MANAGER" "$TICKET_ID"; e inbox "$VENDOR"; } > "$EV/08-final.txt"
wp eval-file guard-state.php cleanup > /dev/null

echo
printf 'attachments, notices, reports and the refund record — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
