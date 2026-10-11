<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;

/**
 * The purchase-quantity rule in WooCommerce's own product editor — visible,
 * and editable.
 *
 * §4 asks for both. «Visible» is the part the owner measured as missing: the
 * quantity fields in the shop's editor were empty because nothing had ever
 * written them. «Editable» is the part that needs saying out loud, because
 * editing here is not the same act as editing in the vendor form:
 *
 *  - the MARKETPLACE row is the source of truth, and the vendor form writes
 *    it (ADR-008 puts a vendor's selling rules on the marketplace side, as it
 *    does price);
 *  - these three rows are the SHOP'S COPY, projected from it;
 *  - a manager who changes one here is doing exactly what a manager who
 *    changes a title here is doing: taking that field. The next projection
 *    leaves it alone and reports it (`ProjectedQuantity::CONFLICT_META`) —
 *    the `alpha.44` rule, which is why this panel needs no ownership
 *    machinery of its own.
 *
 * The panel says all of that on screen, because a field that silently stops
 * tracking the vendor's own number is worse than no field.
 *
 * Only on the marketplace's own products. A shop's own product and Dokan's
 * keep WooCommerce's editor exactly as it was — no tab, no fields, no meta.
 */
final class QuantityPanel
{
    public const NONCE = 'tmc_quantity_panel';

    public static function register(ContainerInterface $container): void
    {
        $policy = static fn (): PurchasePolicy => $container->get(PurchasePolicy::class);

        add_filter('woocommerce_product_data_tabs', static function (mixed $tabs) use ($policy): mixed {
            if (!is_array($tabs) || !self::editingOurProduct($policy())) {
                return $tabs;
            }
            $tabs['tmc_quantity'] = [
                'label' => __('تعداد خرید (بازارگاه)', 'tecteb-marketplace-core'),
                'target' => 'tmc_quantity_panel',
                'class' => [],
                'priority' => 65,
            ];
            return $tabs;
        }, 20);

        add_action('woocommerce_product_data_panels', static function () use ($policy): void {
            if (!self::editingOurProduct($policy())) {
                return;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* below
            echo self::panel(self::currentPostId());
        }, 20);

        add_action('woocommerce_process_product_meta', static function (mixed $postId) use ($policy): void {
            $id = (int) $postId;
            if ($id <= 0 || !self::ourProduct($policy(), $id)) {
                return;
            }
            // THROUGH THE ONE READER. `SecurityRulesTest` allows exactly one
            // file to touch a superglobal, and it is the one whose whole job
            // is sanitising them — a rule this class tried to break and the
            // suite caught.
            $request = Request::capture();

            // The panel's own nonce, checked before anything is read. Absent
            // means this save did not come from the panel — a bulk edit, a
            // REST write, another plugin — and then the rows are left exactly
            // as they are rather than reset to the defaults of an empty form.
            // That is the `alpha.41` lesson about scope: a request that says
            // nothing about a field must not be read as saying «empty».
            if (!$request->hasPost(self::NONCE)
                || !wp_verify_nonce($request->postText(self::NONCE), self::NONCE)) {
                return;
            }
            if (!current_user_can('edit_post', $id)) {
                return;
            }
            $min = trim($request->postText('tmc_min_purchase'));
            $max = trim($request->postText('tmc_max_purchase'));
            $step = trim($request->postText('tmc_purchase_step'));

            // Coerced through the DOMAIN, never written raw. A manager who
            // types «max 2, min 5» here gets the same answer the vendor form
            // gives — `of()` drops an unhonourable maximum — rather than a
            // third interpretation living in an admin screen.
            $rule = PurchaseQuantity::of(
                $min === '' ? 1 : (int) $min,
                $max === '' ? null : (int) $max,
                $step === '' ? 1 : (int) $step
            );
            foreach (ProjectedQuantity::values($rule) as $key => $value) {
                update_post_meta($id, $key, $value);
            }
            // The manager has taken these rows. Their record is dropped, so
            // the next projection finds «a row with no record», sees values
            // that differ from the marketplace's, and leaves them alone —
            // instead of this panel having to write a second ownership flag
            // that could disagree with the first.
            delete_post_meta($id, ProjectedQuantity::OWNED_META);
        }, 20, 1);
    }

    /** Whether the editor currently open is one of ours. */
    private static function editingOurProduct(PurchasePolicy $policy): bool
    {
        $postId = self::currentPostId();
        return $postId > 0 && self::ourProduct($policy, $postId);
    }

    /**
     * The post the editor is open on.
     *
     * Read from `$GLOBALS['post']` rather than from the query string: the
     * metabox hooks run with the post already set up by WordPress, so this
     * reads the object the editor is actually rendering rather than
     * re-deriving it from a superglobal this class is not allowed to touch.
     *
     * (Spelling the query-string variable out even inside this comment makes
     * `SecurityRulesTest` fail — it scans bytes, and bytes do not tell code
     * from prose. That is the `alpha.22` rule about counters that work on
     * text, noted here rather than fixed by editing a security guard in the
     * same round as the code it is guarding.)
     */
    private static function currentPostId(): int
    {
        $post = $GLOBALS['post'] ?? null;
        return is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
    }

    private static function ourProduct(PurchasePolicy $policy, int $postId): bool
    {
        return $policy->isOurs($postId);
    }

    private static function panel(int $postId): string
    {
        $meta = [];
        foreach (ProjectedQuantity::keys() as $key) {
            $meta[$key] = (string) get_post_meta($postId, $key, true);
        }
        $rule = ProjectedQuantity::ruleFrom($meta);
        $held = get_post_meta($postId, ProjectedQuantity::CONFLICT_META, true);
        $heldKeys = is_array($held) ? $held : [];

        $field = static function (string $name, string $label, string $value, string $hint): string {
            $id = 'tmc-' . $name;
            return '<p class="form-field ' . esc_attr($id) . '_field">'
                . '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
                . '<input type="number" min="0" step="1" class="short" id="' . esc_attr($id) . '"'
                . ' name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'
                . '<span class="description">' . esc_html($hint) . '</span>'
                . '</p>';
        };

        $html = '<div id="tmc_quantity_panel" class="panel woocommerce_options_panel"><div class="options_group">'
            . '<p style="padding:0 12px;">' . esc_html__(
                'این سه مقدار از پنل فروشنده در بازارگاه می‌آیند. اگر همین‌جا تغییرشان بدهید، از آن پس مال شماست: همگام‌سازی بعدی دستشان نمی‌زند و تغییر فروشنده روی آن‌ها اعمال نمی‌شود. برای برگرداندن به حالت خودکار، مقدارها را در بازارگاه عوض کنید و این فیلدها را خالی بگذارید.',
                'tecteb-marketplace-core'
            ) . '</p>';

        if ($heldKeys !== []) {
            $html .= '<p style="padding:0 12px;"><strong>' . esc_html__(
                'توجه: دست‌کم یکی از این مقدارها همین حالا به‌دست مدیر تغییر کرده و بازارگاه دیگر آن را به‌روز نمی‌کند.',
                'tecteb-marketplace-core'
            ) . '</strong></p>';
        }

        $contradiction = (string) get_post_meta($postId, QuantityGuard::CONTRADICTION_META, true);
        if ($contradiction === 'sold_individually') {
            $html .= '<p style="padding:0 12px;"><strong>' . esc_html__(
                'توجه: این محصول «فروش تک‌واحدی» است، پس ووکامرس تعداد را روی ۱ نگه می‌دارد و قاعده تعداد خرید اعمال نمی‌شود. یکی از این دو را بردارید.',
                'tecteb-marketplace-core'
            ) . '</strong></p>';
        }

        $html .= $field(
            'tmc_min_purchase',
            __('حداقل خرید', 'tecteb-marketplace-core'),
            (string) $rule->min,
            __('خالی یا ۰ یعنی ۱.', 'tecteb-marketplace-core')
        );
        $html .= $field(
            'tmc_max_purchase',
            __('حداکثر خرید', 'tecteb-marketplace-core'),
            $rule->max === null ? '' : (string) $rule->max,
            __('خالی یعنی بدون سقف. روی مجموع همین کالا در سبد اعمال می‌شود.', 'tecteb-marketplace-core')
        );
        $html .= $field(
            'tmc_purchase_step',
            __('گام تعداد', 'tecteb-marketplace-core'),
            (string) $rule->step,
            __('تعداد باید مضربی از این عدد باشد. ۱ یعنی هر تعدادی.', 'tecteb-marketplace-core')
        );

        return $html . wp_nonce_field(self::NONCE, self::NONCE, true, false) . '</div></div>';
    }
}
