<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Brand;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductBrandFieldView;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * The names suggested while somebody types a brand. Reads only.
 *
 * **Why it exists when the datalist already works.** The first paint seeds the
 * datalist with `ProductBrandFieldView::SUGGEST` names and the browser filters
 * them as you type, with no script at all. On a shop with more brands than
 * that, the names past the cap would be silently unreachable — a truncated
 * list that looks exactly like a complete one, which is the shape of defect
 * this repository keeps paying for. So the script widens the list to what has
 * actually been typed, and the no-script path keeps working unchanged.
 *
 * **It answers with the NAMES, not with markup.** A `<datalist>` option
 * carries exactly one datum, so the browser builds its nodes with one
 * assignment each and no HTML crosses the wire to be assigned to `innerHTML`.
 * That is not the `alpha.26` trap of two renderers for one list: the server
 * picks the names — once, here and on the first paint, through the same
 * `search()` — and HTML markup and DOM nodes are two spellings of the same
 * strings rather than two opinions about them.
 *
 * **It never writes.** Not a term, not a product, not a draft: the endpoint is
 * one `search()` against `product_brand`. A brand is only ever created by a
 * manager's decision (`BrandDirectoryInterface::register()`), and nothing here
 * can reach it.
 *
 * `seq` is echoed back untouched, for the `alpha.27` reason: two requests in
 * flight do not come back in the order they left, and a slow answer to «آمبو»
 * landing after the answer to «آمبوبگ» would widen the list under the vendor's
 * hands. The browser compares; the server is only honest about which question
 * it answered.
 */
final class BrandSuggest
{
    public const ACTION = 'tmc_brand_suggest';

    public const NONCE = 'tmc_brand_suggest';

    /** The same cap as the field's own datalist. */
    public const MAX = ProductBrandFieldView::SUGGEST;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['code' => 'bad_nonce'], 403);
        }
        if (get_current_user_id() === 0) {
            wp_send_json_error(['code' => 'not_logged_in'], 401);
        }
        if (!$this->mayReadBrands()) {
            wp_send_json_error(['code' => 'forbidden'], 403);
        }

        $request = Request::capture();
        /** @var BrandDirectoryInterface $directory */
        $directory = $this->container->get(BrandDirectoryInterface::class);
        $results = $directory->search($request->postText('q'), self::MAX);

        wp_send_json_success([
            'seq' => $request->postInt('seq'),
            'query' => $request->postText('q'),
            'count' => count($results),
            'names' => array_map(static fn (Brand $brand): string => $brand->name, $results),
        ]);
    }

    /**
     * Who may read the brand list: a vendor who may edit a product in their
     * own shop, or a manager who reviews products — the manager's own brand
     * field on the review screen asks the same endpoint.
     *
     * The same gate as the category suggestions, and for the same reason: the
     * terms are on the storefront anyway, but an endpoint that answers
     * everybody is an endpoint whose limits nobody checks the day it answers
     * something else.
     */
    private function mayReadBrands(): bool
    {
        /** @var CapabilityCheckerInterface $caps */
        $caps = $this->container->get(CapabilityCheckerInterface::class);
        if ($caps->can(Capabilities::REVIEW_PRODUCTS) || $caps->can(Capabilities::MANAGE_SPEC_TEMPLATES)) {
            return true;
        }
        $userId = get_current_user_id();
        /** @var StaffAccess $access */
        $access = $this->container->get(StaffAccess::class);
        $vendorUserId = $access->storeFor($userId);
        return $vendorUserId !== null
            && $access->can($userId, $vendorUserId, StaffArea::Product, StaffLevel::Edit);
    }
}
