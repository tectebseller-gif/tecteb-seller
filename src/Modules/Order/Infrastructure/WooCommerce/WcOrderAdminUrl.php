<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Order\Application\OrderAdminUrlInterface;
use Tecteb\Marketplace\Modules\Order\Domain\OrderAdminPath;

/**
 * Asks WooCommerce where its orders live, once, and builds addresses after.
 *
 * **Two sources, in order of authority.** `OrderUtil::get_order_admin_edit_url()`
 * is WooCommerce's own answer and is preferred wherever it exists: it is
 * correct in both storage modes by construction and will stay correct if a
 * third shape ever arrives. Where that method is not available the storage
 * question is asked directly and `OrderAdminPath` builds the address — the
 * same question the health page already asks, so the two cannot disagree
 * about this installation.
 *
 * **Memoised per instance, not `static`.** A `static` cache answers for the
 * whole process, so a test that changes the site's storage mode and asks
 * again gets yesterday's answer — the trap `alpha.67` paid for.
 *
 * **No WooCommerce at all ⇒ `null`.** Not a guessed `post.php`: that guess is
 * exactly the defect this class exists to close, and a wrong guess sends a
 * manager to «Invalid post ID» while looking like a working link.
 */
final class WcOrderAdminUrl implements OrderAdminUrlInterface
{
    private const UTIL = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';

    /** `null` until asked; then true, false, or `null` for «cannot tell». */
    private ?bool $hpos = null;

    private bool $asked = false;

    public function editUrl(int $wcOrderId): ?string
    {
        if (!OrderAdminPath::editable($wcOrderId)) {
            return null;
        }
        if (!class_exists('WooCommerce', false) && !function_exists('WC')) {
            return null;
        }
        // WooCommerce's own answer first.
        if (class_exists(self::UTIL, false) && method_exists(self::UTIL, 'get_order_admin_edit_url')) {
            try {
                $url = call_user_func([self::UTIL, 'get_order_admin_edit_url'], $wcOrderId);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            } catch (\Throwable) {
                // Fall through to the storage question. An exception here is
                // not a reason to report no link at all.
            }
        }
        $hpos = $this->storesOrdersItself();
        if ($hpos === null) {
            return null;
        }
        return admin_url(OrderAdminPath::of($hpos, $wcOrderId));
    }

    /** True for HPOS, false for posts, `null` when WooCommerce will not say. */
    private function storesOrdersItself(): ?bool
    {
        if ($this->asked) {
            return $this->hpos;
        }
        $this->asked = true;
        if (!class_exists(self::UTIL, false) || !method_exists(self::UTIL, 'custom_orders_table_usage_is_enabled')) {
            // A WooCommerce old enough to lack `OrderUtil` has no HPOS, so
            // the post shape is the ONLY shape it can have — a fact rather
            // than a guess.
            $this->hpos = false;
            return false;
        }
        try {
            $this->hpos = (bool) call_user_func([self::UTIL, 'custom_orders_table_usage_is_enabled']);
        } catch (\Throwable) {
            $this->hpos = null;
        }
        return $this->hpos;
    }
}
