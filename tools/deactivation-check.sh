#!/usr/bin/env bash
# What happens to the marketplace's products when the plugin is switched off —
# the case a reminder in a document cannot cover.
#
# Deactivating this plugin removes the guard that decides whether a projected
# product may be bought and the code that records the money when it is. So
# deactivation has to empty the shelf itself, in the one moment code still runs.
#
#   tools/deactivation-check.sh <evidence-dir>
set -u
EV="${1:-docs/evidence/safe-stop}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
# The ids are DISCOVERED, not hard-coded: every re-seed makes new WooCommerce
# posts, and a stale constant here reports a guard failure that is really a
# fixture that moved.
wp0() { (cd "${WPROOT:-/home/user/wp-disposable}" && "${PHPBIN:-/opt/php81/bin/php}" "${WPCLI:-/usr/local/bin/wp}" --allow-root "$@" 2>/dev/null); }
discover() { wp0 eval "\$c = Tecteb\\Marketplace\\Infrastructure\\WordPress\\Bootstrap::container();
\$p = \$c->get(Tecteb\\Marketplace\\Modules\\Product\\Application\\ProductRepositoryInterface::class)->projected();
echo implode(' ', array_map(fn(\$x) => (int) \$x->wcProductId, \$p));"; }
read -r FOUND_A FOUND_B _REST <<< "$(discover)"
TMC_A="${TMC_A:-${FOUND_A}}"
TMC_B="${TMC_B:-${FOUND_B}}"
SHOP="${SHOP:-$(wp0 eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} p WHERE p.post_type = \"product\" AND p.post_status = \"publish\" AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = \"_tmc_product_id\") AND p.post_author = 1 ORDER BY p.ID ASC LIMIT 1");')}"
DOKAN="${DOKAN:-$(wp0 eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = \"_dokan_seeded\" LIMIT 1");')}"
SCRATCH="${SCRATCH:-/tmp/claude-0/deactivation}"
mkdir -p "$EV" "$SCRATCH"
EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-58s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-58s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
sells() {
  local id="$1" jar="$SCRATCH/jar-$1-$2.txt"; rm -f "$jar"
  curl -s -c "$jar" -b "$jar" -L "$SITE/?add-to-cart=${id}" -o /dev/null
  curl -s -c "$jar" -b "$jar" -L "$SITE/cart/" | grep -qE 'woocommerce-cart-form__cart-item' && echo yes || echo no
}
status_of() { wp post get "$1" --field=post_status; }
rows() { wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_products");'; }

echo "=== switching the plugin off, and on again ==="
wp eval-file purchase-block-state.php resume > /dev/null
check "before: the marketplace product sells"        yes "$(sells "$TMC_A" a)"
check "before: it is published in the shop"          publish "$(status_of "$TMC_A")"
before_rows="$(rows)"

echo
echo "--- deactivate ---"
wp plugin deactivate tecteb-marketplace-core > /dev/null
check "the marketplace product left the shop"        draft "$(status_of "$TMC_A")"
check "…and so cannot be bought with the guard gone" no "$(sells "$TMC_A" b)"
check "the shop's own product still sells"           yes "$(sells "$SHOP" b)"
check "the Dokan vendor's product still sells"       yes "$(sells "$DOKAN" b)"
check "nothing was deleted: the rows are all there"  "$before_rows" "$(rows)"
check "the withdrawal is recorded in the audit log"  1 \
  "$(wp eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_audit_events WHERE event_type = \"storefront.stopped\"") > 0 ? 1 : 0;')"

echo
echo "--- activate again ---"
wp plugin activate tecteb-marketplace-core > /dev/null
check "reactivation does NOT put it back on sale"    draft "$(status_of "$TMC_A")"
check "…and it still cannot be bought"               no "$(sells "$TMC_A" c)"
check "the marketplace says it is still stopped"     true \
  "$(wp option get tmc_storefront_stop --format=json >/dev/null 2>&1 && echo true || echo false)"

echo
echo "--- and only a deliberate resume brings it back ---"
wp eval-file purchase-block-state.php resume > "$EV/06-resume-after-reactivation.txt"
check "after an explicit resume it is on sale again" publish "$(status_of "$TMC_A")"
check "…and can be bought"                           yes "$(sells "$TMC_A" d)"

{
echo "=== deactivation and reactivation, measured ==="
wp plugin list --fields=name,status,version
echo
cat "$EV/06-resume-after-reactivation.txt"
} > "$EV/07-deactivation.txt"

echo
printf 'deactivation — %d checks, %d failures\n' "$((pass+fail))" "$fail"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
