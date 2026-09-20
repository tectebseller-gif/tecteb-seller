#!/usr/bin/env bash
# The report cache: is it a cache, can it go stale, and can it leak?
#
# Three questions, measured on the DISPOSABLE WordPress with the plugin
# installed from the ZIP. The financial ones are measured as DELTAS and the
# fixture puts back what it spends, so a second run measures the feature
# rather than the first run's leftovers.
#
#   tools/report-cache-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/report-cache}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-58s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-58s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
gt() { if [ "$3" -gt "$2" ] 2>/dev/null; then pass=$((pass+1)); printf 'ok:   %-58s %s>%s\n' "$1" "$3" "$2";
  else fail=$((fail+1)); printf 'FAIL: %-58s %s is not > %s\n' "$1" "$3" "$2"; fi; }
lt() { if [ "$3" -lt "$2" ] 2>/dev/null; then pass=$((pass+1)); printf 'ok:   %-58s %s<%s\n' "$1" "$3" "$2";
  else fail=$((fail+1)); printf 'FAIL: %-58s %s is not < %s\n' "$1" "$3" "$2"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
stage() { grep -E "^stage=$1 " "$EV/01-run.txt" | head -1; }
ok_of() { stage "$1" | grep -oE ' ok=[a-z]+' | head -1 | cut -d= -f2; }
field() { stage "$1" | grep -oE " $2=[^ ]*" | head -1 | cut -d= -f2-; }

cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

{
echo "=== report cache, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version | grep -E 'tecteb|woocommerce'
} | tee "$EV/00-header.txt"

wp eval-file report-cache-state.php run > "$EV/01-run.txt" 2>&1
sed -n 's/^stage=/  /p' "$EV/01-run.txt"
echo

echo "--- 1. it is a cache, not a decoration ---"
check "two admitted shops with figures exist"           true "$(ok_of two_shops_with_figures)"
gt    "a cold read does real work"                      0 "$(field a_cold_read_computes queries)"
lt    "a warm read costs less"                          "$(field a_warm_read_is_cheaper cold)" "$(field a_warm_read_is_cheaper warm)"
check "…and says exactly the same thing"              true "$(ok_of and_says_the_same_thing)"

echo
echo "--- 2. no shop sees another shop's figures ---"
# Warm entry compared against a COLD recomputation of the SAME shop: if one
# shop's entry were served for the other, this is where it shows.
check "each shop's warm answer matches its own cold one" true "$(ok_of each_shop_gets_its_own_figures)"
check "the two shops do not share an answer"            true "$(ok_of the_two_shops_do_not_share_an_answer)"
check "…and cannot: their namespaces differ"          true "$(ok_of their_namespaces_differ)"

echo
echo "--- 3. a figure cannot outlive the row it came from ---"
check "a real write lands, through the repository"      true "$(ok_of a_real_write_lands)"
check "the write bumps THAT shop's version"             true "$(ok_of the_write_bumps_that_shop)"
check "…and leaves the other shop's version alone"    true "$(ok_of and_leaves_the_other_shop_alone)"
check "the next read shows the new figure"              true "$(ok_of the_next_read_shows_the_new_figure)"
check "…and the old entry is no longer asked for"     true "$(ok_of the_old_entry_is_unreachable)"

echo
echo "--- 4. the fixture can run again ---"
check "the probed line is put back"                     true "$(ok_of the_fixture_puts_the_line_back)"
check "no stage failed"                                 0 "$(field summary failures)"

echo
echo "pass=${pass} fail=${fail}"
{ echo "pass=${pass} fail=${fail}"; date -u +'generated=%Y-%m-%dT%H:%M:%SZ'; } > "$EV/99-summary.txt"
[ "$fail" -eq 0 ] || exit 1
