#!/usr/bin/env bash
# A CLEAN WordPress, built to be walked through — not seeded.
#
# The disposable site this repository has used since `alpha.8` carries every
# fixture every phase ever wrote: 35 products, 197 orders, four applications,
# a commission rule, Dokan. That is right for evidence and wrong for two
# questions the owner asked:
#
#   - does the install guide work when somebody only clicks what is written,
#     with no developer preparation hidden underneath it?
#   - what does a person actually SEE, in Persian, on a phone and a desktop?
#
# Neither can be answered on a site that was prepared. So this builds a second
# WordPress from nothing — its own database, its own port, no fixtures, no
# seeding tool allowed anywhere near it — and the demo data is then created by
# following the guide's own clicks in a browser. If a click in the guide is
# wrong, the walkthrough fails there; nothing else can paper over it.
#
# It never touches the disposable site, and it cannot touch a real one: the
# database name is fixed here and the site is bound to 127.0.0.1.
#
#   bash tools/demo-site.sh build|start|status|reset
set -u
ROOT="${TMC_DEMO_ROOT:-/home/user/wp-demo}"
SRC="${TMC_DISPOSABLE_ROOT:-/home/user/wp-disposable}"
DB='tmc_wp_demo'
DBUSER='tmc_wp'
DBPASS='tmc_wp_disposable'
PORT="${TMC_DEMO_PORT:-8081}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
LOG="/tmp/claude-0/wp-demo-server.log"
THEME='twentytwentyone'

ADMIN_USER='tmcowner'
ADMIN_PASS="${TMC_DEMO_ADMIN_PASS:-demo-owner-2026}"

COMMAND="${1:-status}"
wp() { (cd "$ROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
# --noproxy: this container exports HTTPS_PROXY, and without it curl hands a
# loopback request to the proxy, which answers «Unsupported SSL request». The
# site reads as DOWN while it is serving perfectly. Cost an hour once.
up() { curl -s -o /dev/null --noproxy '*' --max-time 5 "http://127.0.0.1:${PORT}/"; }

start_db() {
  pgrep -f 'mariadbd --basedir' > /dev/null || service mariadb start > /dev/null 2>&1
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    pgrep -f 'mariadbd --basedir' > /dev/null && return 0
    sleep 1
  done
  echo "could not start MariaDB" >&2; exit 1
}

start_web() {
  up && return 0
  mkdir -p "$(dirname "$LOG")"
  nohup "$PHPBIN" -S "127.0.0.1:${PORT}" -t "$ROOT" "${ROOT}/tmc-router.php" > "$LOG" 2>&1 &
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    up && return 0
    sleep 1
  done
  echo "demo site did not come up on ${PORT}; see ${LOG}" >&2; exit 1
}

case "$COMMAND" in
build|reset)
  [ -d "$SRC" ] || { echo "no source WordPress at ${SRC}" >&2; exit 2; }
  start_db
  # Dropped and recreated on purpose: «clean» has to mean clean, and a
  # half-cleared database is the one state that would make the walkthrough
  # lie about what a fresh install does.
  mysql <<SQL
DROP DATABASE IF EXISTS \`${DB}\`;
CREATE DATABASE \`${DB}\` DEFAULT CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '${DBUSER}'@'localhost' IDENTIFIED BY '${DBPASS}';
CREATE USER IF NOT EXISTS '${DBUSER}'@'127.0.0.1' IDENTIFIED BY '${DBPASS}';
GRANT ALL ON \`${DB}\`.* TO '${DBUSER}'@'localhost';
GRANT ALL ON \`${DB}\`.* TO '${DBUSER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

  rm -rf "$ROOT"
  mkdir -p "$ROOT/wp-content/plugins" "$ROOT/wp-content/themes" "$ROOT/wp-content/uploads"
  # Core only. wp-content is rebuilt by hand below so nothing from the
  # prepared site can ride along — including the three mu-plugin probes,
  # which deliberately break things and have no business on a demo.
  for f in "$SRC"/*; do
    case "$(basename "$f")" in
      wp-content|wp-config.php|*.php) continue ;;
    esac
    cp -r "$f" "$ROOT/"
  done
  cp "$SRC/index.php" "$SRC/wp-login.php" "$SRC/wp-settings.php" "$SRC/wp-load.php" \
     "$SRC/wp-blog-header.php" "$SRC/wp-cron.php" "$SRC/wp-links-opml.php" \
     "$SRC/wp-mail.php" "$SRC/wp-signup.php" "$SRC/wp-trackback.php" \
     "$SRC/wp-activate.php" "$SRC/wp-comments-post.php" "$SRC/xmlrpc.php" "$ROOT/" 2>/dev/null
  cp "$SRC/tmc-router.php" "$ROOT/"
  cp -r "$SRC/wp-content/plugins/woocommerce" "$ROOT/wp-content/plugins/"
  cp -r "$SRC/wp-content/themes/${THEME}" "$ROOT/wp-content/themes/"
  cp "$SRC/wp-content/index.php" "$ROOT/wp-content/" 2>/dev/null || true

  cat > "$ROOT/wp-config.php" <<PHP
<?php
define( 'DB_NAME', '${DB}' );
define( 'DB_USER', '${DBUSER}' );
define( 'DB_PASSWORD', '${DBPASS}' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
// This box cannot reach api.wordpress.org; without this every admin page
// waits on an update check that will never answer.
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'DISALLOW_FILE_MODS', false );
// Deliberately ABSENT: WP_ENVIRONMENT_TYPE and TMC_ENVIRONMENT. A fresh
// install declares nothing, so the plugin must resolve «production» and
// refuse the trial switch — which is the first thing the guide has to walk
// the reader out of. Setting it here would hide the step being tested.
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
  # Private vendor documents must land outside every web root. On this box the
  # demo root's parent is shared, so the directory is named explicitly rather
  # than discovered — the same thing the guide tells a host to do.
  mkdir -p /home/user/wp-demo-private
  printf "define( 'TMC_PRIVATE_DIR', '%s' );\n" /home/user/wp-demo-private >> "$ROOT/wp-config.php"
  sed -i "s|^require_once ABSPATH|require_once ABSPATH|" "$ROOT/wp-config.php"

  start_web
  wp core install --url="http://127.0.0.1:${PORT}" --title='بازارگاه تک‌طب — محیط نمایشی' \
     --admin_user="${ADMIN_USER}" --admin_password="${ADMIN_PASS}" \
     --admin_email='owner@example.invalid' --skip-email > /dev/null
  wp rewrite structure '/%postname%/' --hard > /dev/null
  wp theme activate "${THEME}" > /dev/null
  wp plugin activate woocommerce > /dev/null
  wp option update woocommerce_store_address 'خیابان نمونه ۱' > /dev/null
  wp option update woocommerce_store_city 'تهران' > /dev/null
  wp option update woocommerce_default_country 'IR:THR' > /dev/null
  wp option update woocommerce_currency 'IRT' > /dev/null
  wp option update blogdescription 'تجهیزات پزشکی — دادهٔ نمایشی' > /dev/null
  # Persian admin, RTL. The whole point is to look at Persian UI.
  # fa_IR, the way it actually works on a box with no network: the .mo files
  # are copied in and WPLANG is written directly. `wp language core install`
  # wants api.wordpress.org, and `wp site switch-language` refuses a language
  # it has no record of installing — so both fail here and leave the admin in
  # English, which is the one thing this demo cannot be.
  mkdir -p "$ROOT/wp-content/languages"
  cp "$SRC"/wp-content/languages/*.mo "$ROOT/wp-content/languages/" 2>/dev/null || true
  wp option update WPLANG fa_IR > /dev/null
  echo "demo site built: http://127.0.0.1:${PORT}  admin=${ADMIN_USER}"
  ;;
start)
  start_db
  start_web
  echo "demo site: http://127.0.0.1:${PORT}"
  ;;
status)
  pgrep -f 'mariadbd --basedir' > /dev/null && echo "database: up" || echo "database: DOWN"
  up && echo "site: up on http://127.0.0.1:${PORT}" || echo "site: DOWN"
  [ -d "$ROOT" ] && wp plugin list --fields=name,status,version
  ;;
*)
  echo "usage: tools/demo-site.sh build|start|status|reset" >&2; exit 2 ;;
esac
