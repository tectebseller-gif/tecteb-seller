#!/usr/bin/env bash
# Phase-1 acceptance gates on a DISPOSABLE WordPress with synthetic data.
# Reproduces docs/evidence/acceptance/. See docs/installation.md §4.
# Usage: run-gates.sh <php-binary> <evidence-dir> <package-zip>
set -o pipefail
PHPBIN="$1"; EV="$2"; PKG="$3"
export WP_CLI_PHP="$PHPBIN"
export SITE="http://127.0.0.1:8080"
export WPROOT="/home/user/wp-disposable"
# Invoke the phar THROUGH the chosen interpreter: WP_CLI_PHP is honoured by the
# `wp` bash wrapper, not by the phar's own shebang, so calling /usr/local/bin/wp
# directly would silently run the default PHP. (Observed: the web SAPI was 8.1
# while WP-CLI was still 8.4 — exactly what gate G-09 exists to catch.)
wpx() { "$PHPBIN" /usr/local/bin/wp --allow-root --path="$WPROOT" "$@"; }
mark() { : > "$WPROOT/wp-content/debug.log"; }
slice() { cp "$WPROOT/wp-content/debug.log" "$EV/$1-debug.log" 2>/dev/null || : > "$EV/$1-debug.log"; }
errs() { grep -Ei 'fatal|warning|notice|deprecated' "$EV/$1-debug.log" 2>/dev/null | grep -i tecteb > "$EV/$1-plugin-errors.txt"; wc -l < "$EV/$1-plugin-errors.txt"; }
rm -rf "$EV"; mkdir -p "$EV"; cp "$PKG" "$EV/tecteb-marketplace-core.zip"

# ---------- reset the disposable site ----------
wpx plugin deactivate --all >/dev/null 2>&1
rm -rf "$WPROOT/wp-content/plugins/tecteb-marketplace-core" "$WPROOT/wp-content/debug.log"
mariadb --socket=/run/mysqld/mysqld.sock -uroot -e "DROP DATABASE IF EXISTS tmc_wp_test; CREATE DATABASE tmc_wp_test DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
wpx core install --url="$SITE" --title="Tecteb Disposable Acceptance Site" --admin_user=tmcadmin \
    --admin_password="$(cat /root/.wp_pass)" --admin_email=acceptance@example.test --skip-email >/dev/null 2>&1
wpx user create tmc_none none@example.test --role=subscriber --user_pass='TmcNone!2026' --porcelain >/dev/null 2>&1

# ---------- environment record ----------
printf '<?php echo PHP_VERSION,"|",PHP_SAPI;' > "$WPROOT/tmc-probe.php"; WEB=$(curl -s "$SITE/tmc-probe.php"); rm -f "$WPROOT/tmc-probe.php"
{ echo "site_url        = $SITE  (disposable; synthetic data only)"
  echo "wordpress       = $(wpx core version)  (git tag 7.1, github.com/WordPress/WordPress)"
  echo "php_cli         = $($PHPBIN -r 'echo PHP_VERSION;') / cli   [$PHPBIN]"
  echo "php_web         = $WEB"
  echo "php_wp_cli      = $(wpx eval 'echo PHP_VERSION;' 2>/dev/null | tail -1)"
  echo "mariadb         = $(mariadb --socket=/run/mysqld/mysqld.sock -uroot -N -B -e 'SELECT VERSION()')"
  echo "wp_cli          = $("$PHPBIN" /usr/local/bin/wp --allow-root --version)"
  echo "woocommerce_zip = $(sha256sum /home/user/woocommerce-11.0.1.zip | cut -d' ' -f1)"
  echo "package_sha256  = $(sha256sum "$EV/tecteb-marketplace-core.zip" | cut -d' ' -f1)"
  echo "generated_at    = $(date -u +%Y-%m-%dT%H:%M:%SZ)"; } > "$EV/00-environment.txt"
{ wpx config get WP_DEBUG; wpx config get WP_DEBUG_LOG; } > "$EV/00-debug-flags.txt" 2>&1
curl -s -c "$EV/cookies-admin.txt" -o /dev/null --data-urlencode "log=tmcadmin" --data-urlencode "pwd@/root/.wp_pass" -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
curl -s -c "$EV/cookies-none.txt"  -o /dev/null --data-urlencode "log=tmc_none" --data-urlencode "pwd=TmcNone!2026" -d "wp-submit=Log+In&testcookie=1&redirect_to=/wp-admin/" "$SITE/wp-login.php"
{ echo "admin=$(curl -s -b "$EV/cookies-admin.txt" -o /dev/null -w '%{http_code}' "$SITE/wp-admin/profile.php")"
  echo "none=$(curl -s -b "$EV/cookies-none.txt" -o /dev/null -w '%{http_code}' "$SITE/wp-admin/profile.php")"; } > "$EV/00-session-check.txt"

save_settings() { # jar commission delay staff env out [nonce]
  local jar="$1" c="$2" d="$3" s="$4" e="$5" out="$6" nov="${7:-}"
  curl -s -b "$jar" -o "$EV/$out-form.html" "$SITE/wp-admin/admin.php?page=tmc-settings"
  local n o r
  n=$(grep -oE 'name="_wpnonce" value="[^"]+"' "$EV/$out-form.html" | head -1 | sed 's/.*value="//;s/"//')
  o=$(grep -oE "name='option_page' value='[^']+'" "$EV/$out-form.html" | head -1 | sed "s/.*value='//;s/'//")
  r=$(grep -oE 'name="_wp_http_referer" value="[^"]+"' "$EV/$out-form.html" | head -1 | sed 's/.*value="//;s/"//')
  [ -n "$nov" ] && n="$nov"
  curl -s -b "$jar" -c "$jar" -o "$EV/$out-response.html" -w '%{http_code}' -L \
    --data-urlencode "option_page=$o" --data-urlencode "action=update" --data-urlencode "_wpnonce=$n" --data-urlencode "_wp_http_referer=$r" \
    --data-urlencode "tmc_settings[default_commission_rate]=$c" --data-urlencode "tmc_settings[settlement_delay_days]=$d" \
    --data-urlencode "tmc_settings[max_staff]=$s" --data-urlencode "tmc_settings[environment_override]=$e" "$SITE/wp-admin/options.php"
}

echo "########## G-01 ##########"
{ echo "-- plugin list --"; wpx plugin list --format=csv 2>&1 | grep -v wp_update
  echo "-- tmc_schema_version --"; wpx option get tmc_schema_version 2>&1; echo "exit=$?"
  echo "-- audit table --"; wpx db query "SHOW TABLES LIKE '$(wpx db prefix)tmc_audit_events'" 2>&1
  echo "-- woocommerce active? --"; wpx plugin is-active woocommerce 2>&1; echo "exit=$?"; } > "$EV/G-01-preconditions.txt" 2>&1
mark
wpx plugin install "$EV/tecteb-marketplace-core.zip" > "$EV/G-01-install.txt" 2>&1; echo "exit=$?" >> "$EV/G-01-install.txt"
wpx plugin activate tecteb-marketplace-core > "$EV/G-01-activate.txt" 2>&1; echo "exit=$?" >> "$EV/G-01-activate.txt"
wpx option get tmc_schema_version > "$EV/G-01-schema.txt" 2>&1
wpx option get tmc_settings --format=json > "$EV/G-01-settings.txt" 2>&1
wpx db query "SELECT id,event_type,actor_id,object_id FROM $(wpx db prefix)tmc_audit_events" > "$EV/G-01-audit.txt" 2>&1
wpx cap list administrator 2>/dev/null | grep tmc_ > "$EV/G-01-caps-admin.txt"
for r in subscriber editor author contributor customer shop_manager seller; do echo "$r: $(wpx cap list "$r" 2>/dev/null | grep -c tmc_)"; done > "$EV/G-01-caps-other.txt"
for p in tmc-dashboard tmc-health tmc-settings tmc-modules; do
  code=$(curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-01-page-$p.html" -w '%{http_code}' "$SITE/wp-admin/admin.php?page=$p")
  printf '%s http=%s bytes=%s persian=%s dir_rtl=%s php_error=%s\n' "$p" "$code" "$(wc -c < "$EV/G-01-page-$p.html")" \
    "$(grep -c 'بازارگاه تک‌طب' "$EV/G-01-page-$p.html")" "$(grep -c 'dir="rtl"' "$EV/G-01-page-$p.html")" \
    "$(grep -Eic 'fatal error|<b>Warning</b>|<b>Notice</b>' "$EV/G-01-page-$p.html")"
done > "$EV/G-01-pages.txt"
grep -c 'WooCommerce فعال نیست' "$EV/G-01-page-tmc-dashboard.html" > "$EV/G-01-wc-notice.txt"
slice G-01; echo "G-01 plugin errors: $(errs G-01)"
cat "$EV/G-01-pages.txt"

echo "########## G-02 ##########"
save_settings "$EV/cookies-admin.txt" 12.34 9 33 staging G-02-seed > "$EV/G-02-seed-http.txt"
wpx option get tmc_settings --format=json > "$EV/G-02-before-settings.json"
wpx db query "SELECT id,event_type,correlation_id FROM $(wpx db prefix)tmc_audit_events ORDER BY id" > "$EV/G-02-before-audit.txt"
B=$(wpx db query "SELECT COUNT(*) FROM $(wpx db prefix)tmc_audit_events" --skip-column-names)
BT=$(wpx db query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()" --skip-column-names)
mark
wpx plugin deactivate tecteb-marketplace-core > "$EV/G-02-deactivate.txt" 2>&1
wpx plugin activate   tecteb-marketplace-core > "$EV/G-02-activate.txt" 2>&1
slice G-02
wpx option get tmc_settings --format=json > "$EV/G-02-after-settings.json"
diff "$EV/G-02-before-settings.json" "$EV/G-02-after-settings.json" > "$EV/G-02-diff.txt"
wpx db query "SELECT id,event_type,correlation_id FROM $(wpx db prefix)tmc_audit_events ORDER BY id" > "$EV/G-02-after-audit.txt"
comm -23 <(sort "$EV/G-02-before-audit.txt") <(sort "$EV/G-02-after-audit.txt") > "$EV/G-02-lost-rows.txt"
A=$(wpx db query "SELECT COUNT(*) FROM $(wpx db prefix)tmc_audit_events" --skip-column-names)
AT=$(wpx db query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()" --skip-column-names)
{ echo "settings_diff_lines=$(wc -l < "$EV/G-02-diff.txt")"; echo "lost_rows=$(wc -l < "$EV/G-02-lost-rows.txt")"
  echo "audit_count=$B -> $A (expect +2)"; echo "tables=$BT -> $AT (expect equal)"
  echo "schema_version=$(wpx option get tmc_schema_version)"; echo "plugin_errors=$(errs G-02)"; } | tee "$EV/G-02-summary.txt"

echo "########## G-03 ##########"
for who in guest none admin; do
  case $who in guest) C="/dev/null";; none) C="$EV/cookies-none.txt";; admin) C="$EV/cookies-admin.txt";; esac
  for p in tmc-dashboard tmc-health tmc-settings tmc-modules; do
    out="$EV/G-03-$who-$p.html"
    code=$(curl -s -b "$C" -o "$out" -w '%{http_code}' "$SITE/wp-admin/admin.php?page=$p")
    printf '%-5s %-14s http=%s leak_content=%s leak_capname=%s\n' "$who" "$p" "$code" "$(grep -c 'tmc-admin' "$out")" "$(grep -c 'tmc_view_\|tmc_manage_' "$out")"
  done
done | tee "$EV/G-03-matrix.txt"

echo "########## G-04 ##########"
mark
wpx option get tmc_settings --format=json > "$EV/G-04-before.json"
AB=$(wpx db query "SELECT COUNT(*) FROM $(wpx db prefix)tmc_audit_events WHERE event_type='settings.updated'" --skip-column-names)
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' "$EV/G-03-admin-tmc-settings.html" | cut -d'"' -f4)
post() { curl -s -b "$2" -o "$EV/G-04-$1.html" -w "$1 http=%{http_code}\n" -d "option_page=tmc_settings_group&action=update&_wpnonce=$3" -d "tmc_settings[max_staff]=77" "$SITE/wp-admin/options.php"; }
{ post guest /dev/null "$NONCE"; post none "$EV/cookies-none.txt" "$NONCE"; post badnonce "$EV/cookies-admin.txt" forged; post ok "$EV/cookies-admin.txt" "$NONCE"; } | tee "$EV/G-04-http.txt"
wpx option get tmc_settings --format=json > "$EV/G-04-after.json"
wpx db query "SELECT COUNT(*) FROM $(wpx db prefix)tmc_audit_events WHERE event_type='settings.updated'" --skip-column-names > "$EV/G-04-audit-count.txt"
wpx db query "SELECT payload FROM $(wpx db prefix)tmc_audit_events WHERE event_type='settings.updated' ORDER BY id DESC LIMIT 1" --skip-column-names > "$EV/G-04-audit-payload.txt"
slice G-04
{ echo "settings_before=$(cat "$EV/G-04-before.json")"; echo "settings_after =$(cat "$EV/G-04-after.json")"
  echo "settings.updated rows: $AB -> $(cat "$EV/G-04-audit-count.txt") (expect +1)"
  echo "latest payload: $(cat "$EV/G-04-audit-payload.txt")"; echo "plugin_errors=$(errs G-04)"; } | tee "$EV/G-04-summary.txt"

echo "########## G-05 ##########"
REST="$SITE/wp-json/tmc/v1/health"
RN=$(curl -s -b "$EV/cookies-admin.txt" "$SITE/wp-admin/admin-ajax.php?action=rest-nonce")
RNN=$(curl -s -b "$EV/cookies-none.txt" "$SITE/wp-admin/admin-ajax.php?action=rest-nonce")
{ curl -s -o "$EV/G-05-guest.json"    -w 'guest      http=%{http_code}\n' "$REST"
  curl -s -b "$EV/cookies-none.txt"  -o "$EV/G-05-none.json"     -w 'none       http=%{http_code}\n' -H "X-WP-Nonce: $RNN" "$REST"
  curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-05-nononce.json"  -w 'no-nonce   http=%{http_code}\n' "$REST"
  curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-05-badnonce.json" -w 'bad-nonce  http=%{http_code}\n' -H 'X-WP-Nonce: forged' "$REST"
  curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-05-ok.json" -D "$EV/G-05-ok.headers" -w 'authorized http=%{http_code}\n' -H "X-WP-Nonce: $RN" "$REST"; } | tee "$EV/G-05-http.txt"
grep -i 'cache-control' "$EV/G-05-ok.headers" > "$EV/G-05-cache-control.txt"
python3 -c "
import json;d=json.load(open('$EV/G-05-ok.json'))
assert d['schema_version']=='1' and d['outbound']=={'tmc':'blocked','other_plugins':'unknown'}
print('keys:', sorted(d)); print('hpos.enabled =', repr(d['dependencies']['hpos']['enabled'])); print('OK')" > "$EV/G-05-schema.txt" 2>&1
grep -Eic 'wp-content|/var/|/home/|Stack trace|PHP [0-9]|user_email' "$EV/G-05-ok.json" > "$EV/G-05-leak.txt"
{ cat "$EV/G-05-cache-control.txt"; cat "$EV/G-05-schema.txt"; echo "leaks=$(cat "$EV/G-05-leak.txt")"; } | tee -a "$EV/G-05-http.txt"

# ---------- G-07: the three HPOS modes, set for real ----------
# Reading woocommerce_custom_orders_table_enabled is NOT enough: WooCommerce
# only treats HPOS as effective once the tables exist and sync is resolved, so
# the effective state is read back from WooCommerce itself (installation.md
# G-07). The owner reports HPOS ENABLED on staging, which is why this gate
# runs on every package now.
echo "########## G-07 ##########"
wpx plugin activate woocommerce > "$EV/G-07-wc-activate.txt" 2>&1
effective() {
  wpx eval 'echo "hpos_enabled=" . (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "1" : "0") . "\n";'
  wpx eval 'echo "sync_enabled=" . (get_option("woocommerce_custom_orders_table_data_sync_enabled") === "yes" ? "1" : "0") . "\n";'
  wpx eval 'echo "table_exists=" . ($GLOBALS["wpdb"]->get_var("SHOW TABLES LIKE \"" . $GLOBALS["wpdb"]->prefix . "wc_orders\"") ? "1" : "0") . "\n";'
}
wpx wc hpos compatibility-info > "$EV/G-07-compatibility-info.txt" 2>&1 || :
for mode in hpos-sync-on hpos-sync-off legacy; do
  case "$mode" in
    # `compatibility-mode` takes a SUBCOMMAND, not a flag: `--enable` was
    # accepted silently as a usage message and left sync off, so both modes
    # looked identical in the first run. Verified against `wp help`.
    hpos-sync-on)  wpx wc hpos enable  > "$EV/G-07-$mode-apply.txt" 2>&1
                   wpx wc hpos compatibility-mode enable  >> "$EV/G-07-$mode-apply.txt" 2>&1 ;;
    hpos-sync-off) wpx wc hpos enable  > "$EV/G-07-$mode-apply.txt" 2>&1
                   wpx wc hpos compatibility-mode disable >> "$EV/G-07-$mode-apply.txt" 2>&1 ;;
    legacy)        wpx wc hpos disable > "$EV/G-07-$mode-apply.txt" 2>&1 ;;
  esac
  effective > "$EV/G-07-$mode-effective.txt" 2>&1
  mark
  curl -s -b "$EV/cookies-admin.txt" -H "X-WP-Nonce: $RN" "$REST" -o "$EV/G-07-$mode-health.json"
  python3 -c "
import json
d = json.load(open('$EV/G-07-$mode-health.json'))
h = d['dependencies']['hpos']
print('reported.enabled =', repr(h['enabled']))
print('reported.tested  =', repr(h.get('tested')))
" > "$EV/G-07-$mode-reported.txt" 2>&1
  slice "G-07-$mode"; errs "G-07-$mode" > /dev/null
  { echo "--- $mode ---"; cat "$EV/G-07-$mode-effective.txt"; cat "$EV/G-07-$mode-reported.txt";
    echo "plugin_errors=$(wc -l < "$EV/G-07-$mode-plugin-errors.txt")"; } | tee -a "$EV/G-07-summary.txt"
done

echo "########## G-08 ##########"
for p in edit.php index.php plugins.php options-general.php users.php; do
  code=$(curl -s -b "$EV/cookies-admin.txt" -o "$EV/G-08-$p.html" -w '%{http_code}' "$SITE/wp-admin/$p")
  printf 'admin %-22s http=%s tmc_assets=%s\n' "$p" "$code" "$(grep -c 'tmc-admin\.\(css\|js\)' "$EV/G-08-$p.html")"
done > "$EV/G-08-assets.txt"
code=$(curl -s -o "$EV/G-08-home.html" -w '%{http_code}' "$SITE/")
printf 'front  %-22s http=%s tmc_assets=%s\n' "/" "$code" "$(grep -c 'tmc-admin\.\(css\|js\)' "$EV/G-08-home.html")" >> "$EV/G-08-assets.txt"
printf 'plugin %-22s tmc_assets=%s\n' "tmc-dashboard" "$(grep -c 'tmc-admin\.\(css\|js\)' "$EV/G-03-admin-tmc-dashboard.html")" >> "$EV/G-08-assets.txt"
cat "$EV/G-08-assets.txt"

echo "########## G-09 ##########"
{ echo "cli_php=$($PHPBIN -r 'echo PHP_VERSION;')"
  echo "cli_wp_php=$(wpx eval 'echo PHP_VERSION;' 2>/dev/null | tail -1)"
  echo "web_php=$WEB"; } | tee "$EV/G-09-php.txt"
curl -s -b "$EV/cookies-admin.txt" "$SITE/wp-admin/site-health.php?tab=debug" -o "$EV/G-09-site-health.html"
grep -A3 -i 'php_version' "$EV/G-09-site-health.html" | sed 's/<[^>]*>//g' | tr -s ' \n' ' \n' | grep -m1 -E "[0-9]+\.[0-9]+\.[0-9]+" | tee -a "$EV/G-09-php.txt"
rm -f "$EV/cookies-admin.txt" "$EV/cookies-none.txt" 2>/dev/null
# ---------- G-06: data survives deactivation AND deletion ----------
# Destructive on purpose and therefore LAST: it deletes the plugin (which runs
# uninstall.php) and then reinstalls it so the site is left usable.
echo "########## G-06 ##########"
snapshot() {
  { echo "--- settings ---"; wpx option get tmc_settings --format=json
    echo "--- schema ---";   wpx option get tmc_schema_version
    echo "--- caps ---";     wpx cap list administrator | grep tmc_ | sort
    echo "--- audit ---";    wpx db query "SELECT id,event_type,correlation_id FROM $(wpx db prefix)tmc_audit_events ORDER BY id" --skip-column-names
    echo "--- counts ---";   wpx post list --format=count; wpx user list --format=count; } 2>&1
}
snapshot > "$EV/G-06-before.txt"
wpx plugin deactivate tecteb-marketplace-core > "$EV/G-06-deactivate.txt" 2>&1
wpx plugin delete tecteb-marketplace-core     > "$EV/G-06-delete.txt" 2>&1
snapshot > "$EV/G-06-after.txt"
diff "$EV/G-06-before.txt" "$EV/G-06-after.txt" > "$EV/G-06-diff.txt" || :
comm -23 <(sort "$EV/G-06-before.txt") <(sort "$EV/G-06-after.txt") > "$EV/G-06-lost.txt"
{ echo "lost_lines=$(wc -l < "$EV/G-06-lost.txt")   (expected 0)"
  echo "added_lines=$(grep -c '^>' "$EV/G-06-diff.txt" || true)   (expected 1: the plugin.deactivated audit row)"
  cat "$EV/G-06-lost.txt"; } | tee "$EV/G-06-summary.txt"
wpx plugin install "$PKG" --force > "$EV/G-06-reinstall.txt" 2>&1
wpx plugin activate tecteb-marketplace-core >> "$EV/G-06-reinstall.txt" 2>&1

echo "########## gates G-01..G-09 finished ##########"
