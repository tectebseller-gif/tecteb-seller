<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * The approved shops, in WordPress's own sitemap.
 *
 * **A sitemap is not SEO content generation, and the distinction is the point
 * of this file.** Master A.5 says a vendor has no SEO fields and that SEO
 * belongs to the manager — so this plugin writes no titles, no descriptions,
 * no keywords and no copy for anybody. What it does is tell a crawler that a
 * URL exists. Those are different acts: one invents text about a shop, the
 * other lists an address that is already public. Only the second is here.
 *
 * Registered as a provider on core's `wp_sitemaps` rather than as a file of
 * our own, so it inherits the site's own sitemap settings — if the manager
 * has sitemaps switched off, or a plugin like Rank Math has taken them over,
 * nothing here fights it.
 *
 * Only APPROVED shops are listed. A suspended shop's page 404s, and a sitemap
 * that advertised it would be telling a crawler to come and find a 404.
 */
final class StoreSitemapProvider
{
    /**
     * The provider name — and it may contain NOTHING but lowercase letters.
     *
     * Core routes a sitemap with `^wp-sitemap-([a-z]+?)-(\d+?)\.xml$`
     * (`wp-includes/sitemaps/class-wp-sitemaps.php`), so the name capture
     * admits no hyphen and no digit. This was `tmc-stores` first, and the
     * damage was invisible from the index: `get_sitemap_url()` builds the
     * advertised URL by string concatenation, so the index happily listed
     * `wp-sitemap-tmc-stores-1.xml` while the rewrite rules read it as
     * provider «tmc», subtype «stores» — a provider nobody registered. The URL
     * fell through to the theme and answered `200 text/html`.
     *
     * A sitemap index pointing at an HTML page is worse than no sitemap at
     * all: a crawler reports it as a fetch error against the whole index
     * rather than ignoring one entry. `isRoutableName()` below holds the rule,
     * and a test asserts it against core's own regex.
     */
    public const NAME = 'tmcstores';

    /** Core's own constraint on a provider name, kept where it is checkable. */
    public static function isRoutableName(string $name): bool
    {
        return $name !== '' && preg_match('/^[a-z]+$/', $name) === 1;
    }

    public static function register(ContainerInterface $container): void
    {
        add_action('init', static function () use ($container): void {
            if (!function_exists('wp_register_sitemap_provider') || !class_exists('\\WP_Sitemaps_Provider')) {
                return;                         // sitemaps off, or an older core
            }
            if (!self::isRoutableName(self::NAME)) {
                // Registering anyway would put a URL in the index that core
                // cannot route. No sitemap beats a broken one.
                return;
            }
            wp_register_sitemap_provider(self::NAME, self::provider($container));
        }, 30);
    }

    private static function provider(ContainerInterface $container): object
    {
        return new class ($container) extends \WP_Sitemaps_Provider {
            public function __construct(private readonly ContainerInterface $container)
            {
                $this->name = StoreSitemapProvider::NAME;
                $this->object_type = 'tmc_store';
            }

            /** @return list<array{loc:string}> */
            public function get_url_list($page_num, $object_subtype = ''): array
            {
                $perPage = (int) wp_sitemaps_get_max_urls($this->object_type);
                $vendors = $this->container->get(VendorRepositoryInterface::class)
                    ->approvedVendorUserIds($perPage, max(0, ((int) $page_num - 1) * $perPage));
                $out = [];
                foreach ($vendors as $vendorUserId) {
                    $out[] = ['loc' => StorePage::url((int) $vendorUserId)];
                }
                return $out;
            }

            public function get_max_num_pages($object_subtype = ''): int
            {
                $perPage = max(1, (int) wp_sitemaps_get_max_urls($this->object_type));
                $total = $this->container->get(VendorRepositoryInterface::class)->countApprovedVendors();
                return max(1, (int) ceil($total / $perPage));
            }
        };
    }
}
