#!/usr/bin/env bash
# canonical, schema, sitemap and old-URL redirects — nothing duplicated, no
# invalid destination.
#
# Two halves, and they are labelled apart on purpose:
#
#  * The **measured** half runs against the live disposable site with no SEO
#    plugin: one canonical, one Schema block, one sitemap entry per shop, and
#    an old Dokan URL that lands somewhere real in one hop.
#  * The **stand-in** half (`tools/rankmath-state.php`) exercises the Rank Math
#    contract points. Rank Math itself cannot be installed here — wordpress.org
#    and github.com are both refused by this environment's egress policy — so
#    «works with Rank Math» stays Not Run and is reported as such.
#
#   tools/seo-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/seo}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-58s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-58s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
stage() { grep -E "^stage=$1 " "$EV/01-rankmath.txt" | head -1; }
ok_of() { stage "$1" | grep -oE ' ok=[a-z]+' | head -1 | cut -d= -f2; }
field() { stage "$1" | grep -oE " $2=[^ ]*" | head -1 | cut -d= -f2-; }

cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

{
echo "=== canonical, schema, sitemap and redirects ==="
wp plugin list --fields=name,status,version | grep -E 'tecteb|dokan|seo'
echo "rank math installed: $(wp plugin list --field=name | grep -c rank-math || true)"
} | tee "$EV/00-header.txt"

wp eval-file rankmath-state.php run > "$EV/01-rankmath.txt" 2>&1
sed -n 's/^stage=/  /p' "$EV/01-rankmath.txt"
echo

echo "--- 1. Rank Math's contract, against a stand-in (the plugin itself: Not Run) ---"
check "the stand-in is in place"                       true "$(ok_of the_stand_in_is_in_place)"
check "a provider joins Rank Math's list"              true "$(ok_of a_provider_joins_rank_maths_list)"
check "…and claims only its own type"                true "$(ok_of it_claims_only_its_own_type)"
check "the index carries an entry per file"            true "$(ok_of the_index_carries_one_entry_per_file)"
check "…pointing at a sitemap file, not an HTML page" true "$(ok_of and_the_entry_points_at_a_sitemap_file)"
check "…with a type the router can match"            true "$(ok_of the_type_is_routable)"
check "the file lists every listable shop"             true "$(ok_of the_file_lists_the_listable_shops)"
check "…each exactly once"                           true "$(ok_of no_url_appears_twice)"
# Rank Math switches core's sitemaps off, so registering there as well would
# make «the shops are in the sitemap» true of a sitemap nobody serves.
check "the core provider stands down when it is active" true "$(ok_of a_fresh_core_registration_is_refused)"

echo
echo "--- 2. one canonical, one Schema block ---"
check "the standalone document prints its own head"    true "$(ok_of the_standalone_document_prints_its_own_head)"
check "with an SEO plugin present, we print none"      true "$(ok_of with_an_seo_plugin_present_we_print_none)"
check "…but the title survives"                      true "$(ok_of but_the_title_is_still_there)"
check "standing down is a hand-over: canonical"        true "$(ok_of rank_math_is_handed_our_canonical)"
check "…Open Graph url"                              true "$(ok_of and_our_open_graph_url)"
check "…and the Store node joins their one block"    true "$(ok_of our_store_node_joins_their_one_block)"
check "the filters are silent on every other page"     true "$(ok_of and_silent_on_any_other_page)"

echo
echo "--- 3. the live page, with no SEO plugin ---"
SHOP="$(grep -E '^shop_url ' "$EV/01-rankmath.txt" | head -1 | sed 's/.*loc=//')"
curl -sS "$SHOP" -o "$EV/10-shop.html" -D "$EV/10-shop.headers" 2>/dev/null || true
check "the shop page answers 200"                      200 "$(head -1 "$EV/10-shop.headers" | grep -oE '[0-9]{3}' | head -1)"
check "exactly one canonical"                          1 "$(grep -o 'rel="canonical"' "$EV/10-shop.html" | wc -l | tr -d ' ')"
check "exactly one JSON-LD block"                      1 "$(grep -o 'application/ld+json' "$EV/10-shop.html" | wc -l | tr -d ' ')"
check "exactly one og:url"                             1 "$(grep -o 'og:url' "$EV/10-shop.html" | wc -l | tr -d ' ')"
check "exactly one <title>"                            1 "$(grep -o '<title>' "$EV/10-shop.html" | wc -l | tr -d ' ')"
# The canonical must be THIS page, not the home page: a query-var route is one
# an unaware plugin canonicalises to the front page.
CANON="$(grep -oE 'rel="canonical" href="[^"]+"' "$EV/10-shop.html" | head -1 | sed 's/.*href="//; s/"$//' | sed 's/&#038;/\&/g')"
check "…and it is this page"                         "$SHOP" "$CANON"

echo
echo "--- 4. every sitemap URL a crawler would follow actually resolves ---"
bad=0
while read -r loc; do
  code="$(curl -sS -o /dev/null -w '%{http_code}' "$loc" 2>/dev/null || echo 000)"
  echo "    $code  $loc"
  [ "$code" = "200" ] || bad=$((bad+1))
done < <(grep -E '^sitemap_url ' "$EV/01-rankmath.txt" | sed 's/.*loc=//')
check "no listed shop URL is a dead end"               0 "$bad"

echo
echo "--- 5. an old Dokan URL lands somewhere real, in one hop ---"
DOKAN_SLUG="$(wp db query "SELECT user_nicename FROM \$(wp db prefix --allow-root 2>/dev/null)users WHERE ID = 4" 2>/dev/null | tail -1)"
DOKAN_SLUG="${DOKAN_SLUG:-tmcvendor}"
OLD="${SITE}/store/${DOKAN_SLUG}/"
curl -sS -o /dev/null -D "$EV/20-old-url.headers" "$OLD" 2>/dev/null || true
check "the old URL answers with a redirect"            301 "$(head -1 "$EV/20-old-url.headers" | grep -oE '[0-9]{3}' | head -1)"
check "…exactly one Location header"                 1 "$(grep -ci '^location:' "$EV/20-old-url.headers" | tr -d ' ')"
DEST="$(grep -i '^location:' "$EV/20-old-url.headers" | head -1 | sed 's/^[Ll]ocation: *//' | tr -d '\r')"
echo "    → $DEST"
check "…and the destination answers 200"             200 "$(curl -sS -o /dev/null -w '%{http_code}' "$DEST" 2>/dev/null || echo 000)"
# A redirect that lands on another redirect is a chain a crawler charges for.
check "…in one hop, not a chain"                     0 "$(curl -sS -o /dev/null -w '%{num_redirects}' "$DEST" 2>/dev/null || echo 9)"

echo
echo "--- 6. the whole pass ---"
check "no stand-in stage failed"                       0 "$(field summary failures)"

echo
echo "pass=${pass} fail=${fail}"
{ echo "pass=${pass} fail=${fail}"; date -u +'generated=%Y-%m-%dT%H:%M:%SZ';
  echo 'rank-math-itself=Not Run (cannot be downloaded in this environment)'; } > "$EV/99-summary.txt"
[ "$fail" -eq 0 ] || exit 1
