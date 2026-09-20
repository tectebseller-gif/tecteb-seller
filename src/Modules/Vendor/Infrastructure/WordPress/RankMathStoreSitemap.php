<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * The approved shops, in **Rank Math's** sitemap — which is a different thing
 * from being in WordPress core's.
 *
 * Rank Math switches core's sitemaps off (`wp_sitemaps_enabled` → false) and
 * serves its own at `sitemap_index.xml`. A provider registered with
 * `wp_register_sitemap_provider()` therefore renders nothing at all on a site
 * that runs Rank Math: the shops are in a sitemap nobody serves. The owner
 * named exactly this — «ثبت provider در sitemap هسته به‌تنهایی اثبات حضور در
 * sitemap فعال Rank Math نیست».
 *
 * So this registers a second provider, on Rank Math's own extension point.
 * Only one of the two ever renders, because only one sitemap system is ever
 * serving: `StoreSitemapProvider` stands down when Rank Math is active, and
 * this one does nothing when it is not.
 *
 * **Duck typing on purpose.** Rank Math's `Sitemap\Providers\Provider`
 * interface does not exist when Rank Math is absent, and a class that
 * `implements` a missing interface is a fatal error at autoload time — on
 * every request, not just sitemap ones. Rank Math calls these three methods
 * without a type check (`Sitemap\Sitemap_XML` iterates the providers and asks
 * `handles_type()` first), so an anonymous object with the right methods is
 * both correct and the only safe shape.
 *
 * **Status: built, and NOT tested against the real plugin.** Rank Math cannot
 * be downloaded in this build environment (wordpress.org and github.com are
 * both refused by the egress policy), so what has been measured is a stand-in
 * that implements the same three methods and the same two filters. That is
 * more than «the provider is registered» and less than «it works in Rank
 * Math». It stays `Not Run` until it is run on a site that has it.
 */
final class RankMathStoreSitemap
{
    /**
     * The sitemap type, which becomes the URL: `<base>/tmcstore-sitemap.xml`.
     *
     * Rank Math builds the filename from the type, so a type with characters
     * its router will not match is the same class of bug core's hyphen was.
     * Lowercase letters only, same rule, same reason.
     */
    public const TYPE = 'tmcstore';

    /** How many shop URLs go in one sitemap file. Rank Math's own default. */
    public const PER_PAGE = 200;

    public static function register(ContainerInterface $container): void
    {
        if (!SeoHandover::rankMathIsActive()) {
            return;
        }
        add_filter('rank_math/sitemap/providers', static function ($providers) use ($container) {
            if (!is_array($providers)) {
                return $providers;
            }
            $providers[] = self::provider($container);
            return $providers;
        }, 20);
        // Rank Math asks which post types and taxonomies to exclude; ours is
        // neither, so there is nothing to answer there — the provider alone
        // puts the entry in the index.
    }

    /** The three methods Rank Math asks a provider for, and nothing else. */
    public static function provider(ContainerInterface $container): object
    {
        return new class ($container) {
            public function __construct(private readonly ContainerInterface $container)
            {
            }

            public function handles_type(string $type): bool
            {
                return $type === RankMathStoreSitemap::TYPE;
            }

            /**
             * One entry per sitemap FILE, for the index.
             *
             * @return list<array{loc:string, lastmod:string}>
             */
            public function get_index_links(int $maxEntries): array
            {
                $total = $this->approvedCount();
                if ($total === 0) {
                    // No entry rather than an empty file. An index that points
                    // at a sitemap with no URLs in it is a fetch a crawler
                    // makes for nothing.
                    return [];
                }
                $perPage = max(1, min($maxEntries ?: RankMathStoreSitemap::PER_PAGE, RankMathStoreSitemap::PER_PAGE));
                $pages = (int) ceil($total / $perPage);
                $links = [];
                for ($page = 1; $page <= $pages; $page++) {
                    $links[] = [
                        'loc' => RankMathStoreSitemap::fileUrl($page),
                        'lastmod' => gmdate('c'),
                    ];
                }
                return $links;
            }

            /**
             * The URLs inside one file.
             *
             * @return list<array{loc:string, mod:string}>
             */
            public function get_sitemap_links(string $type, int $maxEntries, int $currentPage): array
            {
                if (!$this->handles_type($type)) {
                    return [];
                }
                $perPage = max(1, min($maxEntries ?: RankMathStoreSitemap::PER_PAGE, RankMathStoreSitemap::PER_PAGE));
                $offset = max(0, ($currentPage - 1) * $perPage);
                $out = [];
                foreach ($this->approvedIds($perPage, $offset) as $vendorUserId) {
                    $out[] = [
                        'loc' => StorePage::url((int) $vendorUserId),
                        'mod' => gmdate('c'),
                    ];
                }
                return $out;
            }

            private function approvedCount(): int
            {
                return (int) $this->container->get(VendorRepositoryInterface::class)->countListableVendors();
            }

            /** @return list<int> */
            private function approvedIds(int $limit, int $offset): array
            {
                // Not `approvedVendorUserIds()`: a shop can be approved and
                // still have no settings row, and its page then answers 404.
                return $this->container->get(VendorRepositoryInterface::class)
                    ->listableVendorUserIds($limit, $offset);
            }
        };
    }

    /**
     * The URL of one shop sitemap file.
     *
     * Built through Rank Math's own router when it is there, because the base
     * depends on its settings (a site can serve `sitemap_index.xml` from a
     * subdirectory). The fallback is only for the stand-in harness.
     */
    public static function fileUrl(int $page = 1): string
    {
        $name = self::TYPE . '-sitemap' . ($page > 1 ? (string) $page : '') . '.xml';
        if (class_exists('\\RankMath\\Sitemap\\Router')) {
            /** @var callable $router */
            $router = ['\\RankMath\\Sitemap\\Router', 'get_base_url'];
            return (string) $router($name);
        }
        return home_url('/' . $name);
    }
}
