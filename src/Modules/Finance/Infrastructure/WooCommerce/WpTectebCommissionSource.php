<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Finance\Application\TectebCommissionSourceInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;

/**
 * The owner's commission rule, read off the WooCommerce product's own meta.
 *
 * **Two post meta keys, and no table.** The rule belongs to the product and is
 * set on the product's editor, so the product is where it lives: a table of
 * our own would be a second place the same fact is written, and the first
 * person to duplicate a product in WooCommerce would get a copy whose rule
 * pointed at the original. It also means this round changes no data structure
 * — «تغییر ساختار داده فقط با ضرورت اثبات‌شده».
 *
 * **Reading is all this class can do.** There is no setter: the ONE writer is
 * `TectebCommissionPanel`, behind the financial-management capability and its
 * own nonce, and «فروشنده یا پرسنل بدون مجوز مدیریت مالی نباید بتواند کمیسیون
 * مدیر را … تغییر دهد» is enforced here by the absence of a method rather than
 * by a guard somebody can forget to call — the shape `alpha.55` used for the
 * catalogue reader and `alpha.9` for the Dokan reader.
 */
final class WpTectebCommissionSource implements TectebCommissionSourceInterface
{
    public const TYPE_META = '_tmc_commission_type';
    public const VALUE_META = '_tmc_commission_value';

    /**
     * @return array{rule:TectebCommission, source:string}
     */
    public function forItem(int $wcProductId, int $variationId = 0): array
    {
        // The variation first, because it is the thing that was sold. Asked
        // with `metadata_exists()` as well as read, so «a variation whose rule
        // is explicitly «بدون کمیسیون»» stays distinguishable from «a
        // variation nobody has decided about» — the first is the variation's
        // own answer and must NOT fall through to the parent.
        if ($variationId > 0 && $this->hasRule($variationId)) {
            return [
                'rule' => $this->read($variationId),
                'source' => self::FROM_VARIATION,
            ];
        }
        if ($wcProductId > 0 && $this->hasRule($wcProductId)) {
            return [
                'rule' => $this->read($wcProductId),
                'source' => self::FROM_PRODUCT,
            ];
        }
        // Nothing anywhere, and nothing inherited from a vendor, a category or
        // a site-wide default: «نرخ عمومی یا سطح فروشنده نباید بی‌صدا جای
        // کمیسیون خالی محصول را بگیرد». No commission is the answer, and the
        // sale goes ahead.
        return ['rule' => TectebCommission::none(), 'source' => self::FROM_NONE];
    }

    private function hasRule(int $postId): bool
    {
        return metadata_exists('post', $postId, self::TYPE_META)
            && trim((string) get_post_meta($postId, self::TYPE_META, true)) !== '';
    }

    private function read(int $postId): TectebCommission
    {
        return TectebCommission::fromStored(
            get_post_meta($postId, self::TYPE_META, true),
            get_post_meta($postId, self::VALUE_META, true)
        );
    }
}
