#!/usr/bin/env bash
# Brings the DISPOSABLE WordPress back up after a fresh container.
#
# The build container is rebuilt between sessions. The files under
# /home/user/wp-disposable survive, and so does the MariaDB data directory —
# but the database USER does not, and neither does the running web server. The
# symptom is «Error establishing a database connection» from every wp-cli call,
# which reads like a lost database and is not one. That cost real time once;
# this is so it costs none again.
#
# Nothing here touches a real site. It is refused outright unless the target
# really is the disposable install: the database must be named `tmc_wp_test`.
#
#   tools/disposable-site.sh [start|status|install-zip <zip>]
set -u
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
PORT="${PORT:-8080}"
LOG="${LOG:-/tmp/claude-0/wp-server.log}"
COMMAND="${1:-start}"

config() { sed -n "s/.*'$1', *'\([^']*\)'.*/\1/p" "${WPROOT}/wp-config.php" | head -1; }
DB_NAME="$(config DB_NAME)"
DB_USER="$(config DB_USER)"
DB_PASS="$(config DB_PASSWORD)"

if [ "$DB_NAME" != "tmc_wp_test" ]; then
  echo "refusing: ${WPROOT} does not point at the disposable database (DB_NAME=${DB_NAME})" >&2
  exit 2
fi

wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }

if [ "$COMMAND" = "install-zip" ]; then
  # Installs a built package onto the disposable site.
  #
  # `rm -rf` then `cp -r`, and NEVER `rsync -a`. The build normalises every
  # file's timestamp so two builds of the same source are byte-identical, and
  # rsync's quick check is size+mtime — so a file whose CONTENT changed but
  # whose size did not is considered identical and skipped. Measured: the
  # evidence ran against alpha.10 while reporting alpha.11, because the main
  # plugin file is the same length in both.
  ZIP="${2:-}"
  [ -f "$ZIP" ] || { echo "usage: tools/disposable-site.sh install-zip <zip>" >&2; exit 2; }
  STAGE="$(mktemp -d)"
  unzip -q "$ZIP" -d "$STAGE"
  rm -rf "${WPROOT}/wp-content/plugins/tecteb-marketplace-core"
  cp -r "${STAGE}/tecteb-marketplace-core" "${WPROOT}/wp-content/plugins/"
  rm -rf "$STAGE"
  cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true
  wp plugin list --fields=name,status,version | grep tecteb
  exit 0
fi

if [ "$COMMAND" = "status" ]; then
  pgrep -f 'mariadbd --basedir' > /dev/null && echo "database: up" || echo "database: DOWN"
  curl -s -o /dev/null --max-time 5 "http://127.0.0.1:${PORT}/" \
    && echo "site: up on ${PORT}" || echo "site: DOWN"
  wp plugin list --fields=name,status,version
  exit 0
fi

# 1. The database server.
if ! pgrep -f 'mariadbd --basedir' > /dev/null; then
  service mariadb start > /dev/null 2>&1
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    pgrep -f 'mariadbd --basedir' > /dev/null && break
    sleep 1
  done
fi
pgrep -f 'mariadbd --basedir' > /dev/null || { echo "could not start MariaDB" >&2; exit 1; }

# 2. The database and its user. The data directory usually survives; the user
#    usually does not, so this is CREATE IF NOT EXISTS on both and never a DROP.
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# 3. The web server. The router file is what makes pretty permalinks work under
#    the built-in SAPI; without it every /vendor/ and /product/ URL 404s.
if ! curl -s -o /dev/null --max-time 5 "http://127.0.0.1:${PORT}/"; then
  mkdir -p "$(dirname "$LOG")"
  nohup "$PHPBIN" -S "127.0.0.1:${PORT}" -t "$WPROOT" "${WPROOT}/tmc-router.php" > "$LOG" 2>&1 &
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    curl -s -o /dev/null --max-time 2 "http://127.0.0.1:${PORT}/" && break
    sleep 1
  done
fi

# 4. The tools the evidence scripts run with `wp eval-file` live beside the
#    site, not in the plugin, so a fresh container needs them copied over.
cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

echo "=== disposable site ==="
curl -s -o /dev/null --max-time 5 "http://127.0.0.1:${PORT}/" \
  && echo "site: up on http://127.0.0.1:${PORT}" || echo "site: DOWN"
wp plugin list --fields=name,status,version
printf 'products=%s orders=%s schema=%s\n' \
  "$(wp post list --post_type=product --post_status=any --format=count)" \
  "$(wp eval 'echo count(wc_get_orders(["limit" => -1, "return" => "ids", "status" => "any"]));')" \
  "$(wp option get tmc_schema_version)"
