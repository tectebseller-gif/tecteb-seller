#!/usr/bin/env bash
# Does a trial import from Dokan change how Dokan's shop behaves?
#
# The previous evidence answered with a SHA-256 of Dokan's data before and
# after, and the owner rejected that as insufficient: «یکسان‌بودن هش داده‌ها
# به‌تنهایی ثابت نمی‌کند رفتار خرید دکان تغییر نکرده». Measuring it proved them
# right — nothing of Dokan's had been written, the hash was identical, and
# Dokan's own vendor product had become unpurchasable, because the imported row
# made every "is this ours?" question answer yes.
#
# So this script asks the only question that settles it: **can the product
# still be bought?** — before the import, after it, with the marketplace
# stopped, and with this plugin deactivated. Then it transfers ownership on
# purpose and shows that the behaviour DOES change, because that is what an
# explicit transfer is for.
#
#   tools/dokan-ownership-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/dokan-ownership}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
SCRATCH="${SCRATCH:-/tmp/claude-0/dokan-ownership}"
mkdir -p "$EV" "$SCRATCH"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-64s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-64s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
mig() { wp eval-file dokan-migration.php "$@"; }
state() { wp eval-file purchase-block-state.php "$@"; }
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }
# The only question that settles it: would a guest get this in their basket?
sells() {
  local id="$1" jar="$SCRATCH/jar-$1-$2.txt"; rm -f "$jar"
  curl -s -c "$jar" -b "$jar" -L "$SITE/?add-to-cart=${id}" -o /dev/null
  curl -s -c "$jar" -b "$jar" -L "$SITE/cart/" | grep -qE 'woocommerce-cart-form__cart-item' && echo yes || echo no
}

DOKAN="$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = \"_dokan_seeded\" LIMIT 1");')"
{
echo "=== a Dokan product, through a trial import and a stop ==="
wp plugin list --fields=name,status,version
echo "the Dokan vendor's product: ${DOKAN}"
} | tee "$EV/00-header.txt"

state resume > /dev/null

echo
echo "--- 0. before anything: the Dokan vendor's product sells ---"
check "the marketplace says it is none of its business"      not_ours "$(state decide "$DOKAN" | field decision)"
check "WooCommerce says it is purchasable"                   true "$(state decide "$DOKAN" | field wc_purchasable)"
check "and a guest can actually buy it"                      yes "$(sells "$DOKAN" a)"

echo
echo "--- 1. the trial import maps it ---"
mig plan > "$EV/01-plan.txt"
IMPORTED="$(mig import | tee "$EV/02-import.txt")"
echo "    $IMPORTED"
RUN_ID="$(echo "$IMPORTED" | field run)"
OBSERVED="$(mig observed | tee "$EV/03-observed.txt")"
echo "$OBSERVED" | head -3
check "the imported row is mapped, not owned"                observed \
  "$(echo "$OBSERVED" | grep "wc=${DOKAN} " | grep -oE 'ownership=[a-z]+' | cut -d= -f2 | head -1)"
check "…so the marketplace still says «not ours»"            not_ours "$(state decide "$DOKAN" | field decision)"
check "…WooCommerce still says purchasable"                  true "$(state decide "$DOKAN" | field wc_purchasable)"
check "…and a guest can STILL buy it"                        yes "$(sells "$DOKAN" b)"

echo
echo "--- 2. the marketplace stops selling — the hardest moment ---"
STOPPED="$(state stop | tee "$EV/04-stop.txt")"
echo "    $STOPPED"
check "the stop did not touch the Dokan product"             publish "$(wp post get "$DOKAN" --field=post_status)"
check "…it is still purchasable"                             true "$(state decide "$DOKAN" | field wc_purchasable)"
check "…and a guest can still buy it with the shop shut"     yes "$(sells "$DOKAN" c)"

echo
echo "--- 3. …and with this plugin DEACTIVATED ---"
wp plugin deactivate tecteb-marketplace-core > /dev/null
check "the Dokan product is still published"                 publish "$(wp post get "$DOKAN" --field=post_status)"
check "…and still sells"                                     yes "$(sells "$DOKAN" d)"
wp plugin activate tecteb-marketplace-core > /dev/null

echo
echo "--- 4. an EXPLICIT transfer is the only thing that changes this ---"
TMC_ROW="$(echo "$OBSERVED" | grep "wc=${DOKAN} " | grep -oE 'tmc=[0-9]+' | cut -d= -f2 | head -1)"
TAKEN="$(mig take-ownership "$TMC_ROW" | tee "$EV/05-transfer.txt")"
echo "    $TAKEN"
check "the manager can take ownership on purpose"            true "$(echo "$TAKEN" | field ok)"
check "…and only then does the marketplace claim it"         yes \
  "$(case "$(state decide "$DOKAN" | field decision)" in not_ours) echo no;; *) echo yes;; esac)"
state stop > /dev/null
check "…so now a stop DOES take it off sale"                 draft "$(wp post get "$DOKAN" --field=post_status)"
echo "    (this is the behaviour change an explicit transfer buys — and why it is explicit)"

echo
echo "--- 5. giving it back puts Dokan's shop back ---"
state resume > /dev/null
GIVEN="$(mig give-back-ownership "$TMC_ROW" | tee -a "$EV/05-transfer.txt")"
echo "    $GIVEN"
check "ownership can be handed back"                         true "$(echo "$GIVEN" | field ok)"
check "…the marketplace says «not ours» again"               not_ours "$(state decide "$DOKAN" | field decision)"
check "…and the product sells again"                         yes "$(sells "$DOKAN" e)"

echo
echo "--- 6. the trial import can still be undone completely ---"
ROLLED="$(mig rollback "$RUN_ID" | tee "$EV/06-rollback.txt")"
echo "    $ROLLED"
check "the rollback succeeds"                                true "$(echo "$ROLLED" | field ok)"
check "nothing is left mapped"                               0 "$(mig observed | field count)"
check "and the Dokan product is exactly where it started"    publish "$(wp post get "$DOKAN" --field=post_status)"
check "…still selling"                                       yes "$(sells "$DOKAN" f)"

{
echo "=== final state ==="
mig observed
state decide "$DOKAN"
} > "$EV/07-final-state.txt"

echo
printf 'dokan ownership — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
