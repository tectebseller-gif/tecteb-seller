<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

/**
 * «DOES DEACTIVATING THE PLUGIN ALSO TAKE THE SHELF DOWN?» — one stored
 * answer, default no, and the owner's to change with a button.
 *
 * ### Why this gate exists
 *
 * Until this round `Deactivator::deactivate()` called the storefront stop in
 * its straight-line body: no option, no capability, no filter. Deactivating
 * the plugin drafted and hid every projected marketplace product, held every
 * unpaid marketplace order at `on-hold` (which moves stock, because
 * WooCommerce reduces on that transition), and reactivating put none of it
 * back — a human had to press resume.
 *
 * The §6 instruction is explicit, and says it twice:
 *
 *   «خاموش‌کردن یا ارتقای عادی افزونه نباید بدون تصمیم مستقل مالک وضعیت
 *   منتشرشده محصولات را تغییر دهد … موجودی را جابه‌جا کند»
 *
 *   «توقف فروش باید یک عملیات مستقل، محدود، قابل ممیزی و دارای برنامه بازگشت
 *   باشد؛ با غیرفعال‌سازی فنی افزونه یکی نباشد.»
 *
 * ### The cost of the default, stated rather than hidden
 *
 * The old behaviour was not tidiness and the class it lived in argued for it:
 * a projected product is a published WooCommerce post, and with this plugin
 * off, the guard that decides whether it may be bought and the code that
 * records the money are both gone. A sale made in that window is a sale
 * nobody can attribute and a vendor owed an amount with no ledger line.
 *
 * That cost is real and it does not disappear here. Three things make it
 * affordable:
 *
 *  1. it is **visible**, not silent — deactivation now counts what it left on
 *     sale, writes it where the next admin page reads it, and puts it in the
 *     audit row;
 *  2. it is **detectable afterwards** — `ReconcileOrders` already names an
 *     order carrying a marketplace line with no financial document, so a sale
 *     made while the plugin was off shows up as a named row rather than as
 *     nothing;
 *  3. it is **reversible by a button** — the manager's stop on the storefront
 *     page is unchanged, and the cutover runbook uses it instead of relying on
 *     deactivation.
 *
 * And the opposite failure was not affordable: a plugin update that a hosting
 * panel performs as deactivate-then-reactivate would have taken 184 products
 * off sale with nobody deciding anything, and left them off.
 *
 * ⛔ **A normal UPDATE never reached this code either way.** WordPress
 * deactivates silently during an upgrade (`class-plugin-upgrader.php:581-583`
 * with `$silent`, and `plugin.php:780,817` skip the hooks), so ZIP-over-ZIP
 * has always left products published. That is why this gate changes the
 * DEACTIVATION path only, and why «deactivate first to stop selling safely»
 * was never a thing an update did.
 */
final class DeactivationStopPolicy
{
    /**
     * The owner's stored answer. Absent means no, which is the §6 default.
     *
     * Deliberately NOT derived from anything: «is the shelf down» already has
     * `StorefrontSwitch::OPTION` and this is a different question — what
     * deactivation should DO, asked before it does it.
     */
    public const OPTION = 'tmc_stop_on_deactivate';

    /**
     * Where deactivation writes what it left on sale, for the notice and for
     * whoever reactivates. A count, not a list: the ids are knowable here, but
     * the thing an operator needs at that moment is «N products are still
     * buyable and nothing is recording the money».
     */
    public const LEFT_ON_SALE_OPTION = 'tmc_deactivation_left_on_sale';

    /**
     * Does deactivation also stop selling?
     *
     * Only an explicit, recognised yes counts. A missing row, a blank, a
     * string nobody wrote on purpose, an array left by some other plugin —
     * all of them are no, because the §6 default is that deactivation changes
     * nothing, and a value nobody can account for is not a decision.
     */
    public static function decide(mixed $stored): bool
    {
        if (is_bool($stored)) {
            return $stored;
        }
        if (is_int($stored)) {
            return $stored === 1;
        }
        if (is_string($stored)) {
            return in_array(strtolower(trim($stored)), ['1', 'yes', 'true', 'on'], true);
        }
        return false;
    }
}
