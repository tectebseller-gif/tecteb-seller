<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductCategoryPickerView;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * Suggestions while somebody types a category name. Reads only.
 *
 * It is `admin-ajax.php` for the same reason the autosave endpoint is: the
 * vendor area answers in HTML through a POST-redirect-GET, and a suggestion
 * that redirected would take the half-filled form with it. `wp_ajax_` without
 * the `nopriv` twin refuses a logged-out caller before any of our code runs.
 *
 * **It never writes.** No product, no draft, no term — the whole endpoint is
 * one `search()` against `product_cat`. That matters for more than tidiness:
 * the search button this replaces IS a save (it submits the form so the
 * typing survives), and a vendor exploring the catalogue should not be
 * creating a product row per keystroke.
 *
 * **It answers with the list itself, already rendered.** Not with rows for a
 * second renderer in JavaScript: there is one view, `ProductCategoryPickerView`,
 * and both the first paint and every keystroke after it go through the same
 * method. Two renderers of the same list are two lists, and the day they
 * disagree the one nobody tested wins — including about which rows are
 * guesses, which is the one thing this list must not get wrong.
 *
 * **`seq` is echoed back untouched.** Two requests in flight will not come
 * back in the order they left, and a slow answer to «آمبو» landing after the
 * answer to «آمبوبگ» would replace a narrow list with a wide one under the
 * vendor's hands. The browser compares and drops the stale one; the server
 * only has to be honest about which question it answered.
 */
final class CategorySuggest
{
    public const ACTION = 'tmc_category_suggest';

    public const NONCE = 'tmc_category_suggest';

    /** A cap of its own: the picker shows 40, and nobody reads 1,070 rows. */
    public const MAX = 40;

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
        if (!$this->mayPickCategories()) {
            wp_send_json_error(['code' => 'forbidden'], 403);
        }

        $request = Request::capture();
        $query = $request->postText('q');
        $seq = $request->postInt('seq');

        /** @var ProductCategoryDirectoryInterface $directory */
        $directory = $this->container->get(ProductCategoryDirectoryInterface::class);
        $results = $directory->search($query, self::MAX);
        // What the browser says is currently chosen, resolved here rather than
        // trusted: the list must leave that row out exactly as the first paint
        // does, and «the row the client called selected» is not the same
        // statement as «a category that exists».
        $selected = $directory->find($request->postText('selected'));
        $matched = $directory->countMatches($query);

        wp_send_json_success([
            'seq' => $seq,
            'query' => $query,
            'matched' => $matched,
            'total' => $directory->total(),
            'html' => ProductCategoryPickerView::results($results, $selected, $query, $matched),
            'summary' => $this->summary($matched, count($results)),
        ]);
    }

    /** One sentence for the live region: how many, and whether it was capped. */
    private function summary(int $matched, int $shown): string
    {
        if ($matched === 0) {
            return __('دسته‌ای با این نام پیدا نشد.', 'tecteb-marketplace-core');
        }
        if ($shown < $matched) {
            return sprintf(
                /* translators: 1: shown 2: total matches */
                __('%1$s مورد از %2$s مورد یافته نمایش داده شد.', 'tecteb-marketplace-core'),
                number_format_i18n($shown),
                number_format_i18n($matched)
            );
        }
        return sprintf(
            /* translators: %s: number of categories found */
            __('%s دسته پیدا شد.', 'tecteb-marketplace-core'),
            number_format_i18n($matched)
        );
    }

    /**
     * Who may read the category list.
     *
     * A vendor who may edit a product in their own shop, or a manager who
     * reviews products — the manager needs the same picker to correct a
     * category the vendor filed wrongly. Nobody else, even though the terms
     * are on the storefront anyway: an endpoint that answers everybody is an
     * endpoint whose limits nobody checks the day it answers something else.
     */
    private function mayPickCategories(): bool
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
