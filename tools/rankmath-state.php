<?php
/**
 * The shops in an SEO plugin's world: its sitemap, its canonical, its schema.
 *
 * **What this is, and what it is not.** Rank Math cannot be installed in this
 * build environment — wordpress.org and github.com are both refused by the
 * egress policy — so what runs here is a STAND-IN: the two filters Rank Math
 * fires (`rank_math/sitemap/providers`, `rank_math/json_ld`) are fired by this
 * fixture, and the three methods Rank Math calls on a provider are called by
 * this fixture. It proves the provider answers the contract; it does not prove
 * Rank Math is happy. That second thing stays `Not Run` and is labelled so.
 *
 * It is still worth much more than «a provider is registered with core»: Rank
 * Math switches core's sitemaps OFF, so a core provider on a Rank Math site is
 * a sitemap nobody serves. This measures the other side.
 *
 * The canonical and schema half needs no stand-in at all: whether this
 * plugin's head prints a second canonical beside somebody else's is a question
 * about this plugin's own output.
 *
 *   wp eval-file tools/rankmath-state.php run
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\RankMathStoreSitemap;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\SeoHandover;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\StorePage;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\StoreSitemapProvider;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Presentation\StorePageView;

// --- refuses to run anywhere but the disposable install -------------------
//
// This file touches WordPress and writes. On the owner's site that is not a
// tool, it is damage, and a docblock saying «disposable» stops nobody who
// pastes the command at the wrong shell. Two independent facts, the same
// pair tools/disposable-site.sh trusts — NOT wp_get_environment_type(),
// which reports `production` on the disposable container itself.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


$c = Bootstrap::container();
$say = static function (string $stage, bool $ok, array $fields): void {
    $line = 'stage=' . $stage . ' ok=' . ($ok ? 'true' : 'false');
    foreach ($fields as $k => $v) {
        $line .= ' ' . $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
    }
    echo $line . "\n";
};
$fail = 0;
$check = static function (string $stage, bool $ok, array $fields) use ($say, &$fail): void {
    if (!$ok) {
        $fail++;
    }
    $say($stage, $ok, $fields);
};

// --- the stand-in ----------------------------------------------------------
//
// Both markers are filters this plugin owns precisely so that «an SEO plugin
// is here» can be answered by something other than a class that cannot be
// installed. On a real site the same code path is reached by `class_exists`.
add_filter('tmc_rank_math_is_active', '__return_true');
add_filter('tmc_seo_plugin_owns_head', '__return_true');

$check('the_stand_in_is_in_place',
    SeoHandover::rankMathIsActive() && SeoHandover::ownsHead(), [
    'rank_math_active' => SeoHandover::rankMathIsActive(),
    'owns_head' => SeoHandover::ownsHead(),
]);

// --- 1. the provider Rank Math would collect --------------------------------
RankMathStoreSitemap::register($c);
$providers = apply_filters('rank_math/sitemap/providers', []);
$ours = null;
foreach ((array) $providers as $provider) {
    if (is_object($provider) && method_exists($provider, 'handles_type')
        && $provider->handles_type(RankMathStoreSitemap::TYPE)) {
        $ours = $provider;
        break;
    }
}
$check('a_provider_joins_rank_maths_list', $ours !== null, [
    'providers' => count((array) $providers),
    'type' => RankMathStoreSitemap::TYPE,
]);
if ($ours === null) {
    $say('summary', false, ['failures' => ++$fail]);
    return;
}

$check('it_claims_only_its_own_type',
    $ours->handles_type(RankMathStoreSitemap::TYPE) && !$ours->handles_type('post'), [
    'ours' => $ours->handles_type(RankMathStoreSitemap::TYPE),
    'post' => $ours->handles_type('post'),
]);

// --- 2. the index entry, and the file it points at --------------------------
$vendors = $c->get(VendorRepositoryInterface::class);
$approved = $vendors->countListableVendors();
$indexLinks = $ours->get_index_links(RankMathStoreSitemap::PER_PAGE);
$check('the_index_carries_one_entry_per_file', count($indexLinks) >= 1, [
    'entries' => count($indexLinks),
    'listable_shops' => $approved,
]);
$indexLoc = (string) ($indexLinks[0]['loc'] ?? '');
$check('and_the_entry_points_at_a_sitemap_file',
    str_ends_with($indexLoc, RankMathStoreSitemap::TYPE . '-sitemap.xml'), [
    'loc' => $indexLoc,
]);
// The same rule core's hyphen taught: a name the router cannot match puts a
// URL in an index that answers HTML.
$check('the_type_is_routable', preg_match('/^[a-z]+$/', RankMathStoreSitemap::TYPE) === 1, [
    'type' => RankMathStoreSitemap::TYPE,
]);

$links = $ours->get_sitemap_links(RankMathStoreSitemap::TYPE, RankMathStoreSitemap::PER_PAGE, 1);
$locs = array_map(static fn (array $l): string => (string) $l['loc'], $links);
$check('the_file_lists_the_listable_shops', count($locs) === min($approved, RankMathStoreSitemap::PER_PAGE), [
    'urls' => count($locs),
    'listable' => $approved,
]);
$check('no_url_appears_twice', count($locs) === count(array_unique($locs)), [
    'unique' => count(array_unique($locs)),
]);
$check('another_type_gets_nothing', $ours->get_sitemap_links('post', 200, 1) === [], []);

// The URLs themselves, written out so the shell half can fetch them and see
// what a crawler would see.
foreach ($locs as $i => $loc) {
    echo 'sitemap_url index=' . $i . ' loc=' . $loc . "\n";
}

// --- 3. exactly one sitemap system is live ---------------------------------
//
// Registering the core provider TOO would not double the sitemap — Rank Math
// switches core's off — but it would make «the shops are in the sitemap» true
// of a sitemap nobody serves, which is the claim the owner refused.
// Measured as an ATTEMPT, not as the registry's contents.
//
// The registry already carries `tmcstores` on this run, and that is an
// artefact of simulating: the plugin booted and registered it on the real
// `init`, minutes before this fixture switched the stand-in on. On a site
// that actually has Rank Math, `class_exists('RankMath')` is true from
// `plugins_loaded`, so the first `init` already takes the other branch.
//
// What CAN be measured here is the decision itself: with the stand-in in
// place, a fresh registration adds nothing. `wp_sitemaps_add_provider` is the
// filter core fires inside `add_provider()`, so it sees every attempt.
$attempted = [];
add_filter('wp_sitemaps_add_provider', static function ($provider, $name) use (&$attempted) {
    $attempted[] = (string) $name;
    return $provider;
}, 10, 2);
StoreSitemapProvider::register($c);
do_action('init');
$check('a_fresh_core_registration_is_refused',
    !in_array(StoreSitemapProvider::NAME, $attempted, true), [
    'attempted' => $attempted === [] ? '(none)' : implode(',', $attempted),
    'registry_from_boot' => function_exists('wp_get_sitemap_providers')
        ? implode(',', array_keys((array) wp_get_sitemap_providers())) : '(none)',
]);

// --- 4. one canonical, one schema block ------------------------------------
$vendorUserId = (int) ($vendors->listableVendorUserIds(1, 0)[0] ?? 0);
$check('an_approved_shop_exists_to_look_at', $vendorUserId > 0, ['vendor' => $vendorUserId]);
if ($vendorUserId > 0) {
    $store = $c->get(StoreRepositoryInterface::class)->find($vendorUserId);
    if ($store === null) {
        $check('the_shop_has_settings_to_describe', false, ['vendor' => $vendorUserId]);
        $say('summary', false, ['failures' => $fail]);
        return;
    }
    $canonical = StorePage::url($vendorUserId);

    $standalone = StorePageView::head($store, [], $canonical, false);
    $handedOver = StorePageView::head($store, [], $canonical, true);

    $count = static fn (string $needle, string $hay): int => substr_count($hay, $needle);

    $check('the_standalone_document_prints_its_own_head',
        $count('rel="canonical"', $standalone) === 1
        && $count('application/ld+json', $standalone) === 1, [
        'canonical' => $count('rel="canonical"', $standalone),
        'json_ld' => $count('application/ld+json', $standalone),
    ]);
    $check('with_an_seo_plugin_present_we_print_none',
        $count('rel="canonical"', $handedOver) === 0
        && $count('application/ld+json', $handedOver) === 0
        && $count('og:url', $handedOver) === 0, [
        'canonical' => $count('rel="canonical"', $handedOver),
        'json_ld' => $count('application/ld+json', $handedOver),
        'og_url' => $count('og:url', $handedOver),
    ]);
    // …and the title still comes out, because every SEO plugin prints it
    // through `document_title_parts` and the standalone document has no other
    // source for one.
    $check('but_the_title_is_still_there', $count('<title>', $handedOver) === 1, [
        'title' => $count('<title>', $handedOver),
    ]);

    // --- 5. standing down is a hand-over, not a disappearance --------------
    $values = StorePageView::seoValues($store, [], $canonical);
    SeoHandover::describe(
        (string) $values['canonical'],
        (string) $values['title'],
        (string) $values['description'],
        (string) $values['image']
    );
    SeoHandover::describeSchema((array) $values['schema']);
    SeoHandover::register($c);

    $theirs = home_url('/');
    $check('rank_math_is_handed_our_canonical',
        apply_filters('rank_math/frontend/canonical', $theirs) === $canonical, [
        'theirs' => $theirs,
        'after_filter' => apply_filters('rank_math/frontend/canonical', $theirs),
    ]);
    $check('and_our_open_graph_url',
        apply_filters('rank_math/opengraph/url', $theirs) === $canonical, []);

    $merged = apply_filters('rank_math/json_ld', ['WebPage' => ['@type' => 'WebPage']], null);
    $check('our_store_node_joins_their_one_block',
        is_array($merged) && isset($merged['tmcStore']['@type'])
        && $merged['tmcStore']['@type'] === 'Store' && isset($merged['WebPage']), [
        'nodes' => is_array($merged) ? implode(',', array_keys($merged)) : '-',
    ]);

    // And the filters are silent on every other page, which is the whole
    // safety of hooking a global filter.
    SeoHandover::forget();
    $check('and_silent_on_any_other_page',
        apply_filters('rank_math/frontend/canonical', $theirs) === $theirs, [
        'untouched' => apply_filters('rank_math/frontend/canonical', $theirs),
    ]);

    echo 'shop_url vendor=' . $vendorUserId . ' loc=' . $canonical . "\n";
}

$say('summary', $fail === 0, ['failures' => $fail]);
