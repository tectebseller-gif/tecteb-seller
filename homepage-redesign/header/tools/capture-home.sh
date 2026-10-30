#!/usr/bin/env bash
# خروجی واقعی [tecteb_home] ِ افزونهٔ ۰٫۲٫۰ را از وردپرس یکبارمصرف محلی می‌گیرد و
# صفحه‌های نمونهٔ هدر را از نو می‌سازد. فقط محلی: گاردِ seed و state روی DB_NAME.
#
#   bash homepage-redesign/header/tools/capture-home.sh
#
# دسته‌ها و محصولات دادهٔ آزمایشی محلی‌اند؛ نشانی‌ها همان‌اند که مالک اعلام کرد:
# تعمیرات /https-tecteb-com-repair-request/ · همکاری /landing-seller/ · فروشگاه از ووکامرس.
# «همهٔ دسته‌ها»، «شرایط همکاری»، جست‌وجوهای پیشنهادی و مراحل همکاری خالی می‌مانند
# (مقصد یا متن تأییدشده ندارند) — همان چیزی که پس از نصب دیده می‌شود.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="$HERE/../plugin"
W="wp --allow-root --path=${WP_PATH:-/home/user/wp/core}"
OUT="$(mktemp)"
cd "$PLUGIN"
$W eval-file tests/fixtures/seed.php seed >/dev/null
$W eval-file tests/fixtures/state.php full >/dev/null
$W eval '
$o = get_option( "tecteb_home_settings" );
$o["links"] = array( "repair" => "/https-tecteb-com-repair-request/", "supplier" => "/landing-seller/", "supplier_terms" => "", "all_categories" => "" );
$o["chips"] = array();
$o["supplier_steps"] = array();
update_option( "tecteb_home_settings", $o );'
$W eval 'echo do_shortcode( "[tecteb_home]" );' > "$OUT"
python3 "$HERE/tools/build-pages.py" "$OUT"
rm -f "$OUT"
