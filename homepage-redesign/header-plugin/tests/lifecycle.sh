#!/usr/bin/env bash
# چرخهٔ عمر از خودِ ZIP، روی وردپرس یکبارمصرفِ محلی: نصب، فعال‌سازی، جایگزینی، غیرفعال‌سازی
# (= بازگشت به هدر Elementor)، فعال‌سازی دوباره، حذف کامل — و اینکه هیچ دادهٔ ووکامرس، دسته،
# محصول، فهرست، افزونهٔ صفحهٔ اصلی یا فوتر تکان نخورد.
#
#   bash homepage-redesign/header-plugin/tests/lifecycle.sh [zip]
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="${1:-$HERE/dist/tecteb-header-0.2.1.zip}"
WPP=/home/user/wp/core
W="wp --allow-root --path=$WPP"
SITE=http://127.0.0.1:8090
pass=0; fail=0
ok() { if eval "$2"; then pass=$((pass+1)); echo "ok    $1"; else fail=$((fail+1)); echo "FAIL  $1"; fi; }
count() { curl -s "$SITE$1" | grep -o "$2" | wc -l; }
snap() { $W eval 'global $wpdb; foreach(["terms","term_taxonomy","termmeta","term_relationships","posts","postmeta"] as $t){ echo md5(json_encode($wpdb->get_results("SELECT * FROM {$wpdb->$t}", ARRAY_N))), " "; } echo md5(json_encode(get_option("tecteb_home_settings"))), md5(json_encode(get_option("woocommerce_shop_page_id")));'; }
opt_rows() { $W db query "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '%tecteb_header%'" --skip-column-names 2>/dev/null; }

$W eval-file "$HERE/tests/fixtures/seed.php" seed >/dev/null
$W plugin deactivate tecteb-header >/dev/null 2>&1 || true
rm -f "$WPP/wp-content/plugins/tecteb-header"
$W db query "DELETE FROM wp_options WHERE option_name LIKE '%tecteb_header%'" >/dev/null
BEFORE="$(snap)"
HOME_FILES="$(cd "$HERE/../plugin/tecteb-homepage" && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum)"

$W option update tthh_rocket_calls 0 >/dev/null
$W plugin install "$ZIP" --activate >/dev/null 2>&1
ok "activation with nothing configured purges nothing" '[[ "$($W option get tthh_rocket_calls)" == 0 ]]'
ok "installed from ZIP and active" '[[ "$($W plugin get tecteb-header --field=status)" == active && ! -L $WPP/wp-content/plugins/tecteb-header ]]'
ok "activation stores nothing" '[[ "$(opt_rows)" == 0 ]]'
ok "default: Elementor header 2652 still shown, plugin header absent" '[[ $(count /tthh-about/ data-tthh-original) == 1 && $(count /tthh-about/ "data-tth-header") == 0 ]]'

$W eval-file "$HERE/tests/fixtures/seed.php" configure on >/dev/null
for p in / /tthh-about/ /shop/ /cart/ /my-account/ "/?s=x&post_type=product"; do
  ok "replace on $p: one plugin header, no 2652, footer once" '[[ $(count "$p" "data-tth-header") == 1 && $(count "$p" data-tthh-original) == 0 && $(count "$p" data-tthh-footer) == 1 ]]'
done
ok "product category archive page: one header" '[[ $(count "/product-category/%d9%82%d9%84%d8%a8-%d9%88-%d8%b9%d8%b1%d9%88%d9%82/" "data-tth-header") == 1 ]]'

$W option update tthh_rocket_calls 0 >/dev/null
$W plugin deactivate tecteb-header >/dev/null
ok "deactivate with replacement on purges page cache once (WP Rocket)" '[[ "$($W option get tthh_rocket_calls)" == 1 ]]'
ok "deactivate = rollback: 2652 back on every page, plugin header gone" '[[ $(count / data-tthh-original) == 1 && $(count / "data-tth-header") == 0 && $(count /shop/ data-tthh-original) == 1 ]]'
ok "deactivate keeps settings for a later re-enable" '[[ "$(opt_rows)" -ge 1 ]]'
$W plugin activate tecteb-header >/dev/null
ok "re-activate: plugin header again with the same settings" '[[ $(count / "data-tth-header") == 1 && $(count / data-tthh-original) == 0 ]]'

$W plugin uninstall tecteb-header --deactivate >/dev/null 2>&1
ok "uninstall: plugin files removed" '[[ ! -e $WPP/wp-content/plugins/tecteb-header ]]'
ok "uninstall: own options/transients removed" '[[ "$(opt_rows)" == 0 ]]'
ok "uninstall: 2652 back, footer intact" '[[ $(count / data-tthh-original) == 1 && $(count / data-tthh-footer) == 1 ]]'
ok "no change to terms, term meta, posts, post meta, menus, homepage settings" '[[ "$(snap)" == "$BEFORE" ]]'
ok "tecteb-homepage 0.2.0 still active, files unchanged" '[[ "$($W plugin get tecteb-homepage --field=status)" == active && "$($W plugin get tecteb-homepage --field=version)" == 0.2.0 && "$(cd "$HERE/../plugin/tecteb-homepage" && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum)" == "$HOME_FILES" ]]'
ok "WooCommerce still active" '[[ "$($W plugin get woocommerce --field=status)" == active ]]'

# بازگرداندن محیط توسعه
ln -sfn "$HERE/tecteb-header" "$WPP/wp-content/plugins/tecteb-header"
$W plugin activate tecteb-header >/dev/null
echo; echo "$pass passed, $fail failed"
[[ $fail == 0 ]]
