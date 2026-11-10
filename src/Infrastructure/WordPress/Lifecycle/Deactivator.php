<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpCapabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\WpJobScheduler;
use Tecteb\Marketplace\Modules\Product\Application\DeactivationStopPolicy;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontStop;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;

/**
 * Deactivation stops this plugin's own scheduled tasks (there are none yet;
 * the list below is the single place to add them) and records that it
 * happened. Whether it ALSO takes the marketplace's shelf down is now a
 * stored decision, default no — see `DeactivationStopPolicy` for the
 * instruction behind that and for what the default costs.
 *
 * ⛔ Up to `alpha.101` it stopped selling unconditionally: every projected
 * product to `draft` and `hidden`, every unpaid marketplace order to
 * `on-hold`, and reactivation put none of it back. The reasoning was sound —
 * with this plugin off, nothing guards the purchase or records the money —
 * but §6 asks for the two to be separate acts, and an unconditional stop made
 * a hosting panel's deactivate-and-reactivate into a catalogue outage nobody
 * chose.
 *
 * So the shelf is left alone by default and what was left on sale is COUNTED,
 * written where the next admin page reads it, and put in the audit row. The
 * manager's stop on the storefront page is unchanged and is what the cutover
 * runbook uses.
 *
 * Nothing is deleted on either path: a stop sends products to `draft`, exactly
 * as a suspension does, and every row on this side stays. Options,
 * capabilities and the audit table are untouched (CORE-01, CORE-08), and
 * nothing that is not the marketplace's own product is read or written — the
 * shop's own catalogue and Dokan's are not part of this at all.
 */
final class Deactivator
{
    /** @var list<string> own cron hooks to clear on deactivation */
    /**
     * Ours to clear on the way out, because a scheduled hook whose callback no
     * longer exists fires for ever against nothing. The queue's own rows stay:
     * a pending import is data, and deactivation has never deleted data here.
     */
    public const OWN_SCHEDULED_HOOKS = [WpJobScheduler::HOOK];

    public static function deactivate(): void
    {
        foreach (self::OWN_SCHEDULED_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        WpJobScheduler::unregister();
        delete_transient(Activator::NOTICE_TRANSIENT);
        $alsoStop = DeactivationStopPolicy::decide(get_option(DeactivationStopPolicy::OPTION, false));
        $withdrawn = 0;
        $leftOnSale = 0;
        if ($alsoStop) {
            $withdrawn = self::stopSellingMarketplaceProducts();
        } else {
            $leftOnSale = self::noteWhatIsLeftOnSale();
        }
        try {
            /** @var AuditLogger $audit */
            $audit = Bootstrap::container()->get(AuditLogger::class);
            $audit->log(
                AuditEventCatalog::PLUGIN_DEACTIVATED,
                (new WpCapabilities())->currentUserId(),
                'plugin',
                'tecteb-marketplace-core',
                [
                    'plugin_version' => Bootstrap::pluginVersion(),
                    'withdrawn' => $withdrawn,
                    // Both are recorded on both paths, so a reader of the row
                    // can tell «the shelf came down» from «the shelf was left
                    // up» without knowing what the option said that day.
                    'stopped_selling' => $alsoStop ? 'yes' : 'no',
                    'left_on_sale' => $leftOnSale,
                ]
            );
        } catch (\Throwable) {
            // Best effort only: deactivation must never fail because of audit.
        }

        // Leaves no /vendor/ rules behind once the plugin is off.
        flush_rewrite_rules(false);
    }

    /**
     * Counts what deactivation is about to leave buyable, and writes it down.
     *
     * This is the whole price of the default in one number. With the plugin
     * off these products stay published and purchasable while nothing records
     * the vendor's share — so the count goes into an option the next admin
     * page turns into a notice, and into the audit row above.
     *
     * ⛔ Wrapped whole and returning 0 on any failure, for the same reason the
     * stop is: WordPress gives a plugin no veto over its own deactivation, so
     * nothing here may raise. A 0 therefore means «none, or I could not
     * count» — the notice says so rather than claiming the shelf was empty.
     *
     * @return int projected marketplace products still on sale
     */
    private static function noteWhatIsLeftOnSale(): int
    {
        try {
            $container = Bootstrap::container();
            /** @var \Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface $products */
            $products = $container->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);
            $left = $products->countProjected();
            $left = is_int($left) && $left >= 0 ? $left : 0;
            update_option(DeactivationStopPolicy::LEFT_ON_SALE_OPTION, $left, false);
            return $left;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Empties the marketplace's shelf, and records that it is empty.
     *
     * Wrapped whole: a storefront that refuses to answer must not stop a
     * person deactivating a plugin. The flag is still set in that case (it is
     * written by StorefrontStop before this returns), so a reactivation finds
     * the marketplace stopped and waits to be told to resume.
     *
     * @return int products actually taken out of the shop
     */
    private static function stopSellingMarketplaceProducts(): int
    {
        $actorId = (new WpCapabilities())->currentUserId() ?? 0;
        try {
            $container = Bootstrap::container();
            /** @var StorefrontStop $stop */
            $stop = $container->get(StorefrontStop::class);
            $outcome = $stop->stop(StorefrontSwitch::REASON_DEACTIVATED, $actorId);
            // WordPress gives a plugin no way to refuse deactivation, so a
            // product that would not leave the shop cannot be turned into a
            // blocked action here. StorefrontStop has already recorded the ids
            // — in the audit log and in StorefrontSwitch::markStuck(), which
            // the next admin page reads and turns into a notice.
            return (int) $outcome['withdrawn'];
        } catch (\Throwable) {
            // The withdrawal could not run — no storefront, no database, a
            // module that never registered. The FLAG still has to be set:
            // without it a later reactivation would treat this site as one
            // that was selling happily and put everything back on sale.
            self::markStoppedAnyway($actorId);
            return 0;
        }
    }

    /** Last resort: write the flag with nothing but the option store. */
    private static function markStoppedAnyway(int $actorId): void
    {
        try {
            $container = Bootstrap::container();
            $container->get(StorefrontSwitch::class)->markStopped(StorefrontSwitch::REASON_DEACTIVATED, $actorId);
        } catch (\Throwable) {
            // Nothing else is available to try, and deactivation must not fail.
        }
    }
}
