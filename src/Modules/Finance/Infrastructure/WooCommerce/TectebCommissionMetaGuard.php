<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce;

/**
 * The two commission meta keys, guarded where every door passes through.
 *
 * **Why this class exists, and why the panel was not enough.** The panel is
 * the only writer *in this plugin* — `CommissionOwnershipTest` proves that by
 * searching for a second one — and `WpTectebCommissionSource` has no setter at
 * all. Both sentences are true, and both are about **our** code. They say
 * nothing about WooCommerce's, which writes whatever product meta it is handed:
 *
 *  - `WC_REST_Products_Controller` passes a request's `meta_data` array
 *    straight to `WC_Data::update_meta_data()`, and its permission is
 *    `edit_products` / `manage_woocommerce` — a `shop_manager` has those and
 *    does **not** have the marketplace's financial permission.
 *  - `WC_Product_CSV_Importer` turns any `meta:<key>` column into the same
 *    call, from the admin importer, with the same permission.
 *
 * So «فروشنده یا پرسنل بدون مجوز مدیریت مالی نباید بتواند کمیسیون را از طریق
 * فرم، CSV، درخواست دست‌کاری‌شده یا API تغییر دهد» (بند ۵-هـ) was enforced on
 * ONE of the four doors it names. This is the rule of `alpha.48` in its own
 * clothes: a write that happens «somewhere else» does not pass the guard that
 * is not on that path. The fix is not a fifth guard on a fifth door — it is
 * one guard at the single layer all of them end in, WordPress's own metadata
 * short-circuit filters (the mechanism `alpha.47` used to inject a write
 * failure, here used to refuse one).
 *
 * **A refusal, not a correction.** The filters answer `false`, which is what
 * `update_post_meta()` already means by «نوشته نشد» — so the panel, which reads
 * back after writing (`alpha.44`), would report its own write being refused
 * rather than claim success. Nothing is rewritten and nothing is silently
 * «fixed» to a default.
 *
 * **And the limit is stated, not papered over: a request with no logged-in
 * user is NOT refused.** WP-CLI, cron and a migration have no actor to
 * authorise, and refusing them would break this plugin's own reset tools while
 * stopping nobody — every door clause (هـ) names arrives with a user, REST key
 * authentication and application passwords included, because WooCommerce
 * resolves those to one before the write. Somebody who can run PHP on the
 * server does not need a meta key.
 */
final class TectebCommissionMetaGuard
{
    public static function register(): void
    {
        // The three verbs, with the same first three arguments in the same
        // positions: ($check, $objectId, $metaKey).
        add_filter('add_post_metadata', [self::class, 'decide'], 10, 3);
        add_filter('update_post_metadata', [self::class, 'decide'], 10, 3);
        add_filter('delete_post_metadata', [self::class, 'decide'], 10, 3);
    }

    public static function decide(mixed $check, mixed $objectId, mixed $metaKey): mixed
    {
        // Somebody earlier already decided — including another guard refusing.
        // We never overturn that; a guard that answers «yes» over a «no» is
        // not a guard.
        if ($check !== null) {
            return $check;
        }
        if (!self::ours((string) $metaKey)) {
            return $check;
        }

        return self::mayWrite() ? $check : false;
    }

    private static function ours(string $metaKey): bool
    {
        return $metaKey === WpTectebCommissionSource::TYPE_META
            || $metaKey === WpTectebCommissionSource::VALUE_META;
    }

    private static function mayWrite(): bool
    {
        if (!function_exists('get_current_user_id') || (int) get_current_user_id() === 0) {
            return true; // No actor. See the class docblock — stated, not claimed.
        }

        return function_exists('current_user_can')
            && current_user_can(TectebCommissionPanel::CAPABILITY);
    }
}
