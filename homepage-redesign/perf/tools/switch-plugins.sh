#!/usr/bin/env bash
# پوشهٔ دو افزونه را در وردپرس محلی عوض می‌کند و سرور PHP را از نو راه می‌اندازد.
#
#   bash perf/tools/switch-plugins.sh <header-dir> <homepage-dir>
#
# راه‌اندازی دوباره لازم است: هر worker ِ سرور PHP مسیر واقعی symlink را تا realpath_cache_ttl (۱۲۰ ثانیه) در حافظه
# نگه می‌دارد، پس درست پس از عوض‌کردن symlink بخشی از درخواست‌ها هنوز نسخهٔ قبلی را می‌خوانند و صفحه آمیخته‌ای از دو
# نسخه رندر می‌شود (اندازه‌گیری‌شده: HTML ِ لوگوی PNG ِ 0.2.4 کنار ?ver=0.2.5). سرور تازه با realpath_cache_size=0
# راه می‌افتد تا جایگزینی پوشهٔ افزونه در وسط یک آزمون (ارتقا/بازگشت) هم بی‌درنگ دیده شود. فقط روی وردپرس یکبارمصرف محلی.
set -euo pipefail
# WPDIR و PORT: وردپرس یکبارمصرف (پیش‌فرض /home/user/wp روی 8090؛ بستر Elementor ِ واقعی: WPDIR=/home/user/wp2 PORT=8092).
WPDIR="${WPDIR:-/home/user/wp}"; PORT="${PORT:-8090}"
P=$WPDIR/core/wp-content/plugins
ln -sfn "$1" "$P/tecteb-header"; ln -sfn "$2" "$P/tecteb-homepage"
pkill -f "php .*-S 127.0.0.1:$PORT" 2>/dev/null || true
for _ in $(seq 1 50); do pgrep -f "php .*-S 127.0.0.1:$PORT" >/dev/null || break; sleep 0.1; done
(cd $WPDIR && PHP_CLI_SERVER_WORKERS=6 setsid nohup php -d opcache.enable=0 -d realpath_cache_size=0 -S 127.0.0.1:$PORT -t $WPDIR/core $WPDIR/router.php >/dev/null 2>&1 &)
until curl -s -o /dev/null http://127.0.0.1:$PORT/; do sleep 0.2; done
wp --allow-root --path=$WPDIR/core plugin list --name=tecteb-header,tecteb-homepage --fields=name,version,status 2>/dev/null || \
  wp --allow-root --path=$WPDIR/core plugin list --fields=name,version,status 2>/dev/null | grep tecteb
