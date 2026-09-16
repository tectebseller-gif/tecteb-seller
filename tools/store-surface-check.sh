#!/usr/bin/env bash
# What surrounds the public store page: a cache, the bundled Persian font, the
# URLs a Dokan shop already had, and the line in WordPress's own sitemap.
#
# Every check here answers a question a live probe asked first, and two of them
# exist because the live probe FAILED:
#
#   * the sitemap provider was named `tmc-stores`, which core cannot route —
#     the index advertised it and the URL answered `200 text/html`;
#   * the first cache probe reported `hit` on what looked like a cold start,
#     because the flush had deleted the on/off OPTION and not the body.
#
#   tools/store-surface-check.sh [evidence-dir]
set -u
EV="${1:-docs/evidence/store-surface}"
SITE="${SITE:-http://127.0.0.1:8080}"
WPROOT="${WPROOT:-/home/user/wp-disposable}"
PHPBIN="${PHPBIN:-/opt/php81/bin/php}"
WPCLI="${WPCLI:-/usr/local/bin/wp}"
mkdir -p "$EV"; EV="$(cd "$EV" && pwd)"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'ok:   %-62s %s\n' "$1" "$3";
  else fail=$((fail+1)); printf 'FAIL: %-62s expected=%s actual=%s\n' "$1" "$2" "$3"; fi; }
wp() { (cd "$WPROOT" && "$PHPBIN" "$WPCLI" --allow-root "$@" 2>/dev/null); }
hdr() { curl -sI "$1" | tr -d '\r' | grep -i "^$2:" | head -1 | cut -d' ' -f2-; }
code() { curl -s -o /dev/null -w '%{http_code}' "$1"; }
# Pulls `name=value` out of a fixture's one-line report.
field() { grep -oE "(^| )$1=[^ ]*" | head -1 | cut -d= -f2-; }

# `wp eval-file` reads from the WordPress root, not from this repo, so every
# evidence script copies its own helpers in. A stale copy there answers
# `usage:` to a subcommand this version added, which reads like «not a
# feature» rather than «old file».
cp "$(dirname "$0")"/*.php "$WPROOT/" 2>/dev/null || true

{
echo "=== the public store surface, on the plugin installed from the ZIP ==="
wp plugin list --fields=name,status,version | grep -E 'tecteb|dokan'
} | tee "$EV/00-header.txt"

VENDOR="$(wp eval 'echo (int) (Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container()
  ->get(Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class)
  ->approvedVendorUserIds(1, 0)[0] ?? 0);')"
URL="${SITE}/?tmc_store=${VENDOR}"
echo "    vendor=${VENDOR}  url=${URL}"
[ "${VENDOR}" = "0" ] && { echo "FAIL: no approved vendor to measure"; exit 1; }

echo
echo "--- 1. the cache serves the same bytes, and a bump makes them unreachable ---"
# The version bump is what invalidates. Deleting the on/off option does NOT —
# that reads as «cache enabled» and leaves every stored body exactly where it
# was, which is how the first probe of this feature reported `hit` three times
# running and looked like a pass.
wp eval "Tecteb\\Marketplace\\Modules\\Vendor\\Infrastructure\\WordPress\\StorePageCache::forget(${VENDOR});" >/dev/null
check "a cold page is a miss"                             miss "$(hdr "$URL" X-TMC-Store-Cache)"
curl -s "$URL" > "$EV/01-fresh.html"
check "the next one is a hit"                             hit  "$(hdr "$URL" X-TMC-Store-Cache)"
curl -s "$URL" > "$EV/02-cached.html"
check "…and the third is still a hit"                     hit  "$(hdr "$URL" X-TMC-Store-Cache)"
if cmp -s "$EV/01-fresh.html" "$EV/02-cached.html"; then SAME=yes; else SAME=no; fi
check "the cached bytes are the fresh bytes"              yes "$SAME"
check "…and there are some of them"                       yes \
  "$([ "$(wc -c < "$EV/01-fresh.html")" -gt 1000 ] && echo yes || echo no)"

wp eval "Tecteb\\Marketplace\\Modules\\Vendor\\Infrastructure\\WordPress\\StorePageCache::forget(${VENDOR});" >/dev/null
check "one bump and it is cold again"                     miss "$(hdr "$URL" X-TMC-Store-Cache)"

echo
echo "--- 2. publishing a product invalidates without anybody calling forget() ---"
wp eval "Tecteb\\Marketplace\\Modules\\Vendor\\Infrastructure\\WordPress\\StorePageCache::forget(${VENDOR});" >/dev/null
curl -s -o /dev/null "$URL"                       # warm it
check "warm before the change"                            hit  "$(hdr "$URL" X-TMC-Store-Cache)"
TOUCHED="$(wp eval-file store-surface-state.php touch-product "$VENDOR")"
echo "    ${TOUCHED:-<no output>}"
# The fixture's own success is checked BEFORE what it was supposed to cause.
# Its first run fataled on a changed signature and, with stderr suppressed,
# printed nothing at all — which is indistinguishable from «there was no
# product» unless something asserts the fixture actually did its job.
check "the fixture really saved a product"                1 \
  "$(echo "$TOUCHED" | grep -c 'result=saved')"
check "a product change made it cold"                     miss "$(hdr "$URL" X-TMC-Store-Cache)"

echo
echo "--- 3. a closed shop is never served from cache ---"
wp eval-file store-page-state.php close "$VENDOR" > "$EV/03-closed.txt"
check "closure skips the cache entirely"                  skip "$(hdr "$URL" X-TMC-Store-Cache)"
wp eval-file store-page-state.php reopen "$VENDOR" > "$EV/04-reopened.txt"
check "reopening is immediate, not up to ten minutes"     miss "$(hdr "$URL" X-TMC-Store-Cache)"

echo
echo "--- 4. the Persian font travels with the plugin and is actually reachable ---"
FONT="${SITE}/wp-content/plugins/tecteb-marketplace-core/assets/fonts/vazirmatn-variable.woff2"
check "the bundled font is served"                        200 "$(code "$FONT")"
check "…as a font, not as HTML"                           font/woff2 "$(hdr "$FONT" Content-Type)"
check "…and it is the real file, not a stub"              yes \
  "$([ "$(curl -s "$FONT" | wc -c)" -gt 50000 ] && echo yes || echo no)"
curl -s "$URL" > "$EV/05-page.html"
check "the page declares the face"                        1 "$(grep -c '@font-face' "$EV/05-page.html")"
check "…pointing at the bundled file"                     1 \
  "$(grep -c 'assets/fonts/vazirmatn-variable.woff2' "$EV/05-page.html")"
check "…and no font is fetched from a CDN"                0 \
  "$(grep -cE 'fonts\.googleapis|fonts\.gstatic|cdn\.jsdelivr|cdnjs' "$EV/05-page.html")"
check "the licence ships beside it"                       200 \
  "$(code "${SITE}/wp-content/plugins/tecteb-marketplace-core/assets/fonts/Vazirmatn-OFL.txt")"

echo
echo "--- 5. the sitemap line, which core must be able to route ---"
curl -s "${SITE}/wp-sitemap.xml" > "$EV/06-sitemap-index.xml"
ADVERTISED="$(grep -oE 'http[^<]*wp-sitemap-tmc[^<]*\.xml' "$EV/06-sitemap-index.xml" | head -1)"
echo "    advertised: ${ADVERTISED:-<none>}"
check "the index advertises our sitemap"                  1 \
  "$(grep -cE 'wp-sitemap-tmcstores-1\.xml' "$EV/06-sitemap-index.xml")"
# The whole point: the URL the index PRINTS has to be the URL core RESOLVES.
# `tmc-stores` printed fine and resolved to the theme's 404 page, because
# core's rule captures the provider name as `([a-z]+?)` — no hyphen.
check "…and that exact URL answers"                       200 "$(code "$ADVERTISED")"
check "…as XML, not as the theme's HTML"                  yes \
  "$(hdr "$ADVERTISED" Content-Type | grep -q 'xml' && echo yes || echo no)"
curl -s "$ADVERTISED" > "$EV/07-sitemap-stores.xml"
check "…listing the approved shop"                        1 \
  "$(grep -c "tmc_store=${VENDOR}" "$EV/07-sitemap-stores.xml")"

# A filter with nothing to filter is not a filter. Give it a shop to leave out.
SUSPENDED="$(wp eval-file store-surface-state.php suspended-vendor)"
echo "    $SUSPENDED"
SUSP_ID="$(echo "$SUSPENDED" | grep -oE 'vendor=[0-9]+' | cut -d= -f2)"
curl -s "$ADVERTISED" > "$EV/08-sitemap-with-suspended.xml"
check "a suspended shop is NOT advertised to crawlers"    0 \
  "$(grep -c "tmc_store=${SUSP_ID}" "$EV/08-sitemap-with-suspended.xml")"
check "…and its page really is a 404"                     404 "$(code "${SITE}/?tmc_store=${SUSP_ID}")"
check "the approved one is still there"                   1 \
  "$(grep -c "tmc_store=${VENDOR}" "$EV/08-sitemap-with-suspended.xml")"
wp eval-file store-surface-state.php drop-suspended > "$EV/09-cleanup.txt"

echo
echo "--- 6. the old Dokan URL, with Dokan still installed ---"
NICE="$(wp eval "echo get_userdata(${VENDOR})->user_nicename;")"
BASE="$(wp eval 'echo Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\DokanUrlRedirects::storeBase();')"
OLD="${SITE}/${BASE}/${NICE}/"
echo "    ${OLD}  (dokan-lite active; this shop no longer sells through it)"
check "the store base comes from Dokan's own setting"     store "$BASE"

# This is the case a real migration hits FIRST, and the one `is_404()` alone
# cannot see: Dokan stays installed for the shops that have not moved, so it
# owns the `store/…` rewrite and `is_404()` is false at `template_redirect` —
# it only answers 404 later, from inside its template.
# «Dokan claimed this URL and would refuse it» is two facts, and neither is
# visible from the response any more — because we now answer first. So they
# are read at the source: Dokan owns the rewrite, and this shop is not one of
# its sellers. Together they are exactly the state `is_404()` could not see.
check "Dokan owns the store rewrite rule"                 1 \
  "$(wp eval 'echo (int) (bool) preg_grep("#^store/#", array_keys((array) get_option("rewrite_rules")));')"
check "…and this shop is not one of its sellers"        no \
  "$(wp eval "echo function_exists('dokan_is_user_seller') && dokan_is_user_seller(${VENDOR}) ? 'yes' : 'no';")"
check "so the bookmarked URL still gets somewhere"        301 \
  "$(curl -s -o /dev/null -w '%{http_code}' --max-redirs 0 "$OLD")"
check "…namely this shop's page here"                   "?tmc_store=${VENDOR}" \
  "$(curl -s -o /dev/null -w '%{redirect_url}' "$OLD" | sed 's|^.*/||')"
check "…and it is permanent, so the link keeps its value" 200 \
  "$(curl -sL -o /dev/null -w '%{http_code}' "$OLD")"

echo "    narrowing: deeper Dokan views have no equivalent here"
for DEEPER in toc page/2 section/5; do
  check "  /${BASE}/${NICE}/${DEEPER} is left at its 404"  404 \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-redirs 0 "${SITE}/${BASE}/${NICE}/${DEEPER}/")"
done
check "  an unknown shop is left at its 404"             404 \
  "$(curl -s -o /dev/null -w '%{http_code}' --max-redirs 0 "${SITE}/${BASE}/nobody-by-that-name/")"

echo "    narrowing: a shop Dokan WILL serve is not ours to take"
SELLER="$(wp eval-file store-surface-state.php dokan-seller)"
echo "    $SELLER"
check "the fixture made a real Dokan seller"              1 "$(echo "$SELLER" | grep -c 'is_seller=yes')"
SELLER_NICE="$(echo "$SELLER" | grep -oE 'nicename=[^ ]+' | cut -d= -f2)"
check "…who is not a vendor of this marketplace"        1 "$(echo "$SELLER" | grep -c 'tmc_status=none')"
check "Dokan's own store page is served, not redirected"  200 \
  "$(curl -s -o /dev/null -w '%{http_code}' --max-redirs 0 "${SITE}/${BASE}/${SELLER_NICE}/")"
wp eval-file store-surface-state.php drop-dokan-seller > "$EV/10-cleanup-seller.txt"

curl -s -D "$EV/11-redirect-headers.txt" -o /dev/null --max-redirs 0 "$OLD"
echo "    (the full cutover — Dokan deactivated — is tools/cutover-check.sh)"

echo "--- 7. the shop inside the site's own theme ---"
render() { hdr "$1" X-TMC-Store-Render; }
wp option delete tmc_store_page_theme >/dev/null 2>&1 || true
THEME="$(wp eval 'echo get_stylesheet();')"
echo "    theme=${THEME}"
check "auto picks the theme when it has header/footer"    theme "$(render "$URL")"

curl -s "$URL" > "$EV/12-in-theme.html"
check "the site's header is on the page"                  1 "$(grep -c 'site-header' "$EV/12-in-theme.html")"
check "…and its footer"                                 1 "$(grep -c 'site-footer' "$EV/12-in-theme.html")"
check "…and the shop itself"                            1 \
  "$(grep -c '<header class=.tmc-store__head.' "$EV/12-in-theme.html")"
# One title, not two. `head()` carries one for the standalone document, and
# the theme prints its own from `pre_get_document_title`; both would be
# invalid, and which one a browser keeps depends on hook order.
check "exactly one <title>"                               1 "$(grep -c '<title>' "$EV/12-in-theme.html")"
check "the canonical survived"                            1 "$(grep -c 'rel=\"canonical\"' "$EV/12-in-theme.html")"
check "the Schema.org block survived"                     1 "$(grep -c 'ld+json' "$EV/12-in-theme.html")"
check "the Open Graph card survived"                      1 "$(grep -c 'og:title' "$EV/12-in-theme.html")"
check "the bundled font survived"                         1 "$(grep -c '@font-face' "$EV/12-in-theme.html")"
check "the page is not styled as the blog index"          0 \
  "$(grep -oE '<body class=\"[^\"]*\"' "$EV/12-in-theme.html" | grep -cE '(^| )(home|blog)( |\")')"
check "…but carries our own class"                      1 \
  "$(grep -oE '<body class=\"[^\"]*\"' "$EV/12-in-theme.html" | grep -c 'tmc-store')"

echo "    the private fields are still absent — the wrapper changed, not the rule"
# Real values, so their absence means something: a page that omits an EMPTY
# warehouse proves nothing. And each value's PRESENCE is checked first — the
# first run of this section skipped all five silently, because `field` was not
# defined here and an unset value makes a check vanish rather than fail.
PRIVATE="$(wp eval-file store-page-state.php seed "$VENDOR")"
echo "    ${PRIVATE:-<no output>}"
wp eval "Tecteb\\Marketplace\\Modules\\Vendor\\Infrastructure\\WordPress\\StorePageCache::forget(${VENDOR});" >/dev/null
curl -s "$URL" > "$EV/13-in-theme-seeded.html"
# The literals `store-page-state.php seed` writes, named here rather than
# parsed out of its report — that report says `warehouse=set`, a STATUS word,
# and the first version of this loop grepped the page for «set» and found it
# 28 times. It also reports nothing at all for the other four.
check "  the fixture ran"                                 1 "$(echo "$PRIVATE" | grep -c 'warehouse=set')"
leaked() { grep -c -- "$1" "$EV/13-in-theme-seeded.html"; }
check "  NOT the dispatch warehouse"                      0 "$(leaked 'انبار خصوصی خیابان')"
check "  NOT the bank account"                            0 "$(leaked 'IR12')"
check "  NOT the applicant's e-mail"                      0 "$(leaked 'private-store@example')"
check "  NOT the applicant's mobile"                      0 "$(leaked '09121112233')"
check "  NOT the applicant's own address"                 0 "$(leaked 'نشانی شخصی متقاضی')"
check "  the published address is the city and no more"   1 "$(leaked '\"addressLocality\"')"
check "  …with no street in it"                         0 "$(leaked '\"streetAddress\"')"

echo "    a manager can force either mode"
wp option update tmc_store_page_theme standalone >/dev/null 2>&1
check "forced standalone is standalone"                   standalone "$(render "$URL")"
curl -s "$URL" > "$EV/14-standalone.html"
check "…and that really is a document of its own"       1 "$(grep -c '<!DOCTYPE html>' "$EV/14-standalone.html")"
check "…with no theme header"                           0 "$(grep -c 'site-header' "$EV/14-standalone.html")"
check "…and still exactly one <title>"                  1 "$(grep -c '<title>' "$EV/14-standalone.html")"

# The two modes are different bytes from the same shop and page number, so a
# shared cache key would serve one for the other — a bare fragment with no
# document around it. The key carries the mode.
check "the standalone body is not the theme body"         yes \
  "$(cmp -s "$EV/12-in-theme.html" "$EV/14-standalone.html" && echo no || echo yes)"
wp option update tmc_store_page_theme theme >/dev/null 2>&1
check "switching back does not serve the other body"      1 \
  "$(curl -s "$URL" | grep -c 'site-header')"
wp option delete tmc_store_page_theme >/dev/null 2>&1 || true

echo
echo "=== pass=${pass} fail=${fail} ==="
echo "pass=${pass} fail=${fail}" > "$EV/99-summary.txt"
[ "$fail" -eq 0 ]
