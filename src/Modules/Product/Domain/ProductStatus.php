<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/** §4.2: پیش‌نویس ← ارسال برای بررسی ← نیازمند اصلاح/تأیید ← منتشرشده ← تعلیق/بایگانی */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ChangesRequested = 'changes_requested';
    case Published = 'published';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /** Whether the vendor may still edit the product itself (not its stock). */
    public function isEditableByVendor(): bool
    {
        return $this === self::Draft || $this === self::ChangesRequested;
    }

    /** Whether buyers can see it at all. */
    public function isLive(): bool
    {
        return $this === self::Published;
    }

    /**
     * WHAT A WOOCOMMERCE `post_status` BECOMES WHEN A PRODUCT IS TAKEN IN —
     * and `null` for every status this marketplace cannot express.
     *
     * ⛔ Until `alpha.66` the intake wrote
     * `$status === 'publish' ? Published : Draft`, which is the blind copy
     * §1-د names: every status but `publish` collapsed into «پیش‌نویس», with
     * no record that it had been anything else — and for two of them that
     * collapse is itself a public change. `project()` writes our status to the
     * shop unconditionally, so a `private` product taken in as «پیش‌نویس»
     * becomes `draft` at the first projection, which removes it even from the
     * roles that could see it; and a `future` product loses its schedule the
     * same way. §1-ج says an intake must not change the public product, so
     * the answer is to refuse a state this marketplace cannot hold rather
     * than to pick the nearest one.
     *
     * So the map is TOTAL and the refusals are named:
     *
     *   * `publish` → منتشرشده. The only status that keeps a live product
     *     live, which is also why mirroring matters: a row created as a draft
     *     would take a selling product off sale at the first projection.
     *   * `draft` → پیش‌نویس.
     *   * `pending` → پیش‌نویس, deliberately NOT «ارسال‌شده». «ارسال برای
     *     بررسی» is an act of the vendor and leaves a row in
     *     `tmc_product_decisions`; an intake has no such row, so a product
     *     marked submitted would sit in the review queue with an identity of
     *     `s0.r0.b0` — a decision nobody asked for and a badge count nobody
     *     can clear. The product's real WooCommerce status is still shown, on
     *     its own facet, which is what §1-الف asks for.
     *   * `private`, `future` → **refused**. This marketplace has no field for
     *     «visible to logged-in roles only» or «goes live on a date», and the
     *     honest answer to a state we cannot hold is to say so rather than to
     *     pick the nearest one.
     *   * `trash`, `auto-draft` → **refused**. A deleted product and a row
     *     WordPress left behind when somebody opened «افزودن محصول» and walked
     *     away are not catalogue entries.
     *   * anything else → **refused**, because `post_status` is extensible and
     *     a status another plugin registered is one this file has never seen.
     */
    public static function forIntakeOf(string $postStatus): ?self
    {
        return match (trim($postStatus)) {
            'publish' => self::Published,
            'draft', 'pending' => self::Draft,
            default => null,
        };
    }
}
