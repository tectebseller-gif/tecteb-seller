<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpCapabilities;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontStop;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;

/**
 * Deactivation stops this plugin's own scheduled tasks (there are none yet;
 * the list below is the single place to add them) — and, since the catalogue
 * reached WooCommerce, takes the marketplace's own products out of sale.
 *
 * That last part is not tidiness. A projected product is a published
 * WooCommerce post, and deactivating this plugin removes the guard that
 * decides whether it may be bought and the code that records the money when
 * it is. Leaving them on sale would mean orders nobody can attribute and
 * vendors owed amounts with no ledger line behind them. So the shelf is
 * emptied first, in the one moment we still have code running.
 *
 * Nothing is deleted: products go to `draft`, exactly as a suspension does,
 * and every row on this side stays. Options, capabilities and the audit table
 * are untouched (CORE-01, CORE-08), and nothing that is not the marketplace's
 * own product is read or written — the shop's own catalogue and Dokan's are
 * not part of this at all.
 */
final class Deactivator
{
    /** @var list<string> own cron hooks to clear on deactivation */
    public const OWN_SCHEDULED_HOOKS = [];

    public static function deactivate(): void
    {
        foreach (self::OWN_SCHEDULED_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        delete_transient(Activator::NOTICE_TRANSIENT);
        $withdrawn = self::stopSellingMarketplaceProducts();
        try {
            /** @var AuditLogger $audit */
            $audit = Bootstrap::container()->get(AuditLogger::class);
            $audit->log(
                AuditEventCatalog::PLUGIN_DEACTIVATED,
                (new WpCapabilities())->currentUserId(),
                'plugin',
                'tecteb-marketplace-core',
                ['plugin_version' => Bootstrap::pluginVersion(), 'withdrawn' => $withdrawn]
            );
        } catch (\Throwable) {
            // Best effort only: deactivation must never fail because of audit.
        }

        // Leaves no /vendor/ rules behind once the plugin is off.
        flush_rewrite_rules(false);
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
