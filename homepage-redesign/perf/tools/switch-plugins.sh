#!/usr/bin/env bash
# پوشهٔ دو افزونه را در وردپرس محلی عوض می‌کند و سرور PHP را از نو راه می‌اندازد.
#
#   bash perf/tools/switch-plugins.sh <header-dir> <homepage-dir>
#
# راه‌اندازی دوباره لازم است: هر worker ِ سرور PHP مسیر واقعی symlink را تا realpath_cache_ttl (۱۲۰ ثانیه) در حافظه
# نگه می‌دارد، پس درست پس از عوض‌کردن symlink بخشی از درخواست‌ها هنوز نسخهٔ قبلی را می‌خوانند و صفحه آمیخته‌ای از دو
# نسخه رندر می‌شود (اندازه‌گیری‌شده: HTML ِ لوگوی PNG ِ 0.2.4 کنار ?ver=0.2.5). فقط روی وردپرس یکبارمصرف محلی.
set -euo pipefail
P=/home/user/wp/core/wp-content/plugins
ln -sfn "$1" "$P/tecteb-header"; ln -sfn "$2" "$P/tecteb-homepage"
pkill -f "php .*-S 127.0.0.1:8090" 2>/dev/null || true
for _ in $(seq 1 50); do pgrep -f "php .*-S 127.0.0.1:8090" >/dev/null || break; sleep 0.1; done
(cd /home/user/wp && PHP_CLI_SERVER_WORKERS=6 setsid nohup php -d opcache.enable=0 -S 127.0.0.1:8090 -t /home/user/wp/core /home/user/wp/router.php >/dev/null 2>&1 &)
until curl -s -o /dev/null http://127.0.0.1:8090/; do sleep 0.2; done
wp --allow-root --path=/home/user/wp/core plugin list --name=tecteb-header,tecteb-homepage --fields=name,version,status 2>/dev/null || \
  wp --allow-root --path=/home/user/wp/core plugin list --fields=name,version,status 2>/dev/null | grep tecteb
