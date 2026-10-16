<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;

/**
 * WHERE THE OWNER'S COMMISSION FOR ONE SELLABLE THING IS READ FROM.
 *
 * A port and not a repository: the rule lives on the WooCommerce product,
 * because that is where the owner decided it is set — «روی صفحهٔ ویرایش محصول
 * ووکامرس» — and Application may not call WordPress (the rule this repository
 * has enforced with an architecture test since `alpha.9`).
 *
 * `forItem()` answers for the thing that was actually sold, which for a
 * variable product is a VARIATION. «اگر تنظیم در سطح تنوع وجود ندارد، تنظیم
 * محصول والد مبناست و snapshot باید بگوید از کجا آمده» — so the answer carries
 * its own provenance and the caller never has to guess which of the two it got.
 */
interface TectebCommissionSourceInterface
{
    /** The variation's own rule, when it has one. */
    public const FROM_VARIATION = 'variation';
    /** The parent product's rule — the basis when the variation has none. */
    public const FROM_PRODUCT = 'product';
    /** Nobody has set one anywhere. A complete answer, and not a blocker. */
    public const FROM_NONE = 'none';

    /**
     * @param int $wcProductId the parent (or simple) product
     * @param int $variationId 0 for a simple product
     * @return array{rule:TectebCommission, source:string} source is one of the three above
     */
    public function forItem(int $wcProductId, int $variationId = 0): array;
}
