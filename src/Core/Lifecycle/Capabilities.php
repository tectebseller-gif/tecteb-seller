<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Lifecycle;

/**
 * The capabilities this plugin CREATES, all of them added to the
 * administrator role and to no other (CORE-03).
 *
 * Adding one to this list is enough: Bootstrap re-checks the list against a
 * stored signature on every admin request, because replacing a plugin's files
 * does not re-run activation — the same hole UpgradeGate exists to close.
 *
 * `tmc_apply_vendor` is deliberately absent: every signed-in customer needs
 * it, and granting it would mean editing roles the site already has —
 * including Dokan's. It is answered at runtime instead, by
 * Modules\Vendor\Infrastructure\WordPress\VendorCapabilityPolicy, so
 * deactivating the plugin takes it away again with nothing left behind.
 */
final class Capabilities
{
    public const VIEW_DASHBOARD = 'tmc_view_dashboard';
    public const VIEW_HEALTH = 'tmc_view_health';
    public const MANAGE_SETTINGS = 'tmc_manage_settings';
    public const VIEW_MODULES = 'tmc_view_modules';
    /** Vendor phase: decide on applications, and define required documents. */
    public const REVIEW_VENDOR = 'tmc_review_vendor';
    public const MANAGE_VENDOR_DOCUMENTS = 'tmc_manage_vendor_documents';
    /** Product phase: decide on products and revisions, and shape category templates. */
    public const REVIEW_PRODUCTS = 'tmc_review_products';
    public const MANAGE_SPEC_TEMPLATES = 'tmc_manage_spec_templates';
    /** Catalogue and settlement: stop or resume selling, and decide on withdrawals. */
    public const MANAGE_STOREFRONT = 'tmc_manage_storefront';
    public const REVIEW_WITHDRAWALS = 'tmc_review_withdrawals';
    /** Reviews and ratings: «moderation و گزارش» is the manager's (UX §12). */
    public const MODERATE_REVIEWS = 'tmc_moderate_reviews';
    /**
     * Reading the audit trail is its own permission, and deliberately not part
     * of any other. The trail records what every manager did, so somebody who
     * can approve a vendor should not automatically be able to read who else
     * approved which — that is the one page whose readers a site owner may want
     * to be a shorter list than its actors.
     */
    public const VIEW_AUDIT = 'tmc_view_audit';
    /** Running, retrying and cancelling queued work. */
    public const MANAGE_JOBS = 'tmc_manage_jobs';
    /** Issuing API keys and naming event endpoints. Not the same as using them. */
    public const MANAGE_API = 'tmc_manage_api';
    /**
     * READING ONE SHOP'S OWN FIGURES, as a manager, after choosing it.
     *
     * Its own permission and deliberately not part of any other, for the same
     * reason `VIEW_AUDIT` is: «دسترسی مشاهده با مجوز مشخص مدیریت … باشد»
     * (§4), and a site owner may well want the list of people who can read
     * inside a particular shop to be shorter than the list who can approve
     * products. It grants READING and nothing else — a manager who chooses a
     * shop cannot edit it, cannot act as the vendor and does not become one:
     * «انتخاب فروشگاه به‌تنهایی نباید اجازهٔ ویرایش یا اقدام به‌جای فروشنده
     * بدهد» و «هویت حساب مدیر تغییر نکند».
     */
    public const VIEW_VENDOR_SHOPS = 'tmc_view_vendor_shops';

    /**
     * READING ONE SHOP'S MONEY, which is not the same as listing shops.
     *
     * Split out when the shop list stopped asking «who has an approved
     * application» and started asking «who is a shop» — measured on the
     * disposable database, 40 profiles against 14 listed rows. Widening WHICH
     * shops a manager can see must not widen WHAT they can see inside one,
     * and a list scope and a money scope answer different questions, so they
     * are different permissions: «اطلاعات مالی فقط با مجوز مدیریتی مناسب
     * نمایش داده شوند و تغییر دامنه فهرست نباید مجوز دسترسی مالی را گسترش
     * دهد».
     *
     * Deliberately NOT `REVIEW_WITHDRAWALS`: that one also permits ACTING on
     * a withdrawal, and somebody who should read a balance need not be able
     * to approve a payout. Here the two consequences differ, so the
     * permissions differ — the exact opposite of the imported-shop decision,
     * which writes the same column as approving an application and therefore
     * shares `REVIEW_VENDOR` rather than minting a second key to the same
     * door.
     *
     * The cost is stated rather than hidden: a manager hand-granted only
     * `tmc_view_vendor_shops` stops seeing figures until this is granted too.
     * That is the conservative direction and the point of the decision.
     */
    public const VIEW_VENDOR_FINANCE = 'tmc_view_vendor_finance';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::VIEW_DASHBOARD,
            self::VIEW_HEALTH,
            self::MANAGE_SETTINGS,
            self::VIEW_MODULES,
            self::REVIEW_VENDOR,
            self::MANAGE_VENDOR_DOCUMENTS,
            self::REVIEW_PRODUCTS,
            self::MANAGE_SPEC_TEMPLATES,
            self::MANAGE_STOREFRONT,
            self::REVIEW_WITHDRAWALS,
            self::MODERATE_REVIEWS,
            self::VIEW_AUDIT,
            self::MANAGE_JOBS,
            self::MANAGE_API,
            self::VIEW_VENDOR_SHOPS,
            self::VIEW_VENDOR_FINANCE,
        ];
    }

    /** Role that receives the capabilities on single-site installs. */
    public const TARGET_ROLE = 'administrator';
}
