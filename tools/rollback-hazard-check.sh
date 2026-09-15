#!/usr/bin/env bash
# Measures what happens to a projected product when the package goes BACK from
# a version that guards purchases to one that does not — and whether the
# documented remedy actually works.
#
# The hazard is specific and worth measuring rather than reasoning about: a
# product alpha.7 pushed into WooCommerce is a published WooCommerce post, and
# rolling back does not delete it. alpha.6 has no PurchaseGuard and no order
# capture, so that post stays on sale with nothing recording the money — the
# very thing the financial lock exists to prevent.
#
# Runs on a DISPOSABLE WordPress only. Nothing here touches any real site.
#
#   tools/rollback-hazard-check.sh <wc-product-id> <vendor-user-id> <old-zip> <new-zip>
set -u
WC_PRODUCT="${1:?WooCommerce product id of a marketplace product}"
VENDOR="${2:?vendor user id}"
OLD="$(readlink -f "${3:?the package being rolled back TO}")"
NEW="$(readlink -f "${4:?the package being rolled back FROM}")"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
SITE="${SITE:-http://127.0.0.1:8080}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
SHOP_PRODUCT="${SHOP_PRODUCT:-37}"
SCRATCH="${SCRATCH:-/tmp/claude-0/rollback-hazard}"
mkdir -p "$SCRATCH"
cd "$WPROOT" || exit 2
wp() { "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null; }
say() { echo; echo "== $* =="; }

install_package() {
  rm -rf "$WPROOT/wp-content/plugins/tecteb-marketplace-core"
  unzip -q "$1" -d "$WPROOT/wp-content/plugins/"
  wp plugin list --fields=name,version --name=tecteb-marketplace-core 2>/dev/null | tail -1
}

# Whether a guest can put this product in an empty basket — asked of the shop,
# not of the code.
purchasable() {
  local id="$1" jar="$SCRATCH/jar-$1-$2.txt"
  rm -f "$jar"
  curl -s -c "$jar" -b "$jar" -L "$SITE/?add-to-cart=${id}" -o /dev/null
  curl -s -c "$jar" -b "$jar" -L "$SITE/cart/" \
    | grep -qE 'woocommerce-cart-form__cart-item|wc-block-cart-items__row' && echo yes || echo no
}

status_line() {
  echo "marketplace product $WC_PRODUCT purchasable=$(purchasable "$WC_PRODUCT" "$1") | shop product $SHOP_PRODUCT purchasable=$(purchasable "$SHOP_PRODUCT" "$1")"
}

say "on the new package, with the marketplace's products live"
install_package "$NEW"
wp eval-file purchase-block-state.php resume 2>&1 | tail -1
status_line a

say "the documented remedy: stop selling first, so the products leave the shop"
wp eval-file purchase-block-state.php stop 'manager_stopped' 2>&1 | tail -1
wp post get "$WC_PRODUCT" --field=post_status 2>&1 | sed "s/^/wc $WC_PRODUCT status=/"
status_line b

say "…and only then roll the package back"
install_package "$OLD"
wp post get "$WC_PRODUCT" --field=post_status 2>&1 | sed "s/^/wc $WC_PRODUCT status=/"
status_line c

say "the hazard, measured: the same rollback WITHOUT stopping first"
install_package "$NEW" >/dev/null
# Selling has to be ON for the hazard to exist at all — which is itself the
# point. From alpha.8 onward the storefront stop survives a reactivation, so
# the naive rollback only reaches the hazard when somebody has deliberately
# resumed selling first.
wp eval-file purchase-block-state.php resume 2>&1 | tail -1
wp post get "$WC_PRODUCT" --field=post_status 2>&1 | sed "s/^/wc $WC_PRODUCT status=/"
install_package "$OLD"
echo "marketplace product $WC_PRODUCT purchasable=$(purchasable "$WC_PRODUCT" d)  <-- on sale with nothing recording the money"
wp db query "SELECT COUNT(*) AS recorded_order_lines FROM \`$(wp db prefix --allow-root 2>/dev/null || echo wp_)tmc_order_items\`" 2>&1 | tail -2

say "back to the new package"
install_package "$NEW"
