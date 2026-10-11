<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Support\PersianDigits;
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

    /** The named «hand these rules back» control. */
    public const RESET = 'tmc_quantity_reset';

    /** Where the outcome of the last panel save waits for its own author. */
    private const NOTICE_PREFIX = 'tmc_quantity_notice_';

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
            if (!$request->hasPost(self::NONCE)
                || !wp_verify_nonce($request->postText(self::NONCE), self::NONCE)) {
                return;
            }
            if (!current_user_can('edit_post', $id)) {
                return;
            }

            // ------------------------------------------------ back to automatic
            //
            // A NAMED CONTROL, because the promise needs one. `alpha.45` told
            // the manager to «leave the fields empty» and emptying them wrote
            // `1 / no maximum / 1` and left the record deleted — so the
            // product became permanently manager-owned at an UNLIMITED
            // ceiling, which is the opposite of handing it back. Measured:
            // `rows_after_reset=1/empty/1`, `back_to_automatic=NO`.
            //
            // AND IT HANDS BACK BY WRITING, not by deleting. `alpha.46`
            // removed the three rows and waited for the next projection, and
            // between the two there was no rule at all: absence reads as
            // «minimum 1, no maximum, step 1», so the reset RAISED what could
            // be bought until somebody saved the product again. Measured on
            // `alpha.46` in a fresh request, marketplace rule `2/6/2`:
            // `input_args=1/40/1`, and 1, 3 and 8 all `allowed` in the
            // classic cart and through the Store API.
            if ($request->postChecked(self::RESET)) {
                self::handBack($id, $policy());
                return;
            }

            $min = trim($request->postText('tmc_min_purchase'));
            $max = trim($request->postText('tmc_max_purchase'));
            $step = trim($request->postText('tmc_purchase_step'));

            // ------------------------------------------------------ refuse first
            //
            // THE SAME RULE the vendor form and the importer ask. `alpha.45`
            // coerced here instead of refusing: `min 5, max 2` came out
            // `min 6, no maximum` — all three rows rewritten, the ceiling
            // removed and the ownership record deleted, from a request that
            // should have changed nothing.
            $wantMin = $min === '' ? 1 : (int) $min;
            $wantMax = $max === '' ? null : (int) $max;
            $wantStep = $step === '' ? 1 : (int) $step;
            $reason = PurchaseQuantity::validate($wantMin, $wantMax, $wantStep);
            if ($reason !== null) {
                self::remember($id, 'refused', $reason, [
                    'min' => $min,
                    'max' => $max,
                    'step' => $step,
                ]);
                return;                 // NOTHING is written. Not one row.
            }

            // ------------------------------------------- only what really moved
            //
            // Written per key, and the ownership record loses ONLY the keys
            // that moved. Two separate failures of `alpha.45` live here:
            //
            //  - it rewrote all three rows on every save, so a manager who
            //    changed the maximum froze the minimum and the step too;
            //  - it deleted the whole record UNCONDITIONALLY — and its nonce
            //    is in the editor on every render, so saving only the title
            //    handed all three rules to the manager for ever. Measured on
            //    `alpha.45`: after a title-only save the vendor's legitimate
            //    change to the maximum stopped applying
            //    (`vendor_change_applied=NO`, `conflicts=_tmc_max_purchase`).
            $wanted = ProjectedQuantity::values(
                PurchaseQuantity::of($wantMin, $wantMax, $wantStep)
            );
            $moved = [];
            foreach ($wanted as $key => $value) {
                $current = metadata_exists('post', $id, $key)
                    ? (string) get_post_meta($id, $key, true)
                    : null;
                if ($current === $value) {
                    continue;           // this rule did not move
                }
                $moved[$key] = $value;
            }
            if ($moved === []) {
                return;                 // nothing changed; the record stands
            }

            // --------------------------------------------- write, then re-read
            //
            // `update_post_meta()` answers `false` both for a failure and for
            // «it was already this» (`alpha.44`), so the answer it gives is
            // not read at all: the row is read BACK and compared. A row that
            // did not store is reported rather than counted as success, and
            // its key is NOT removed from the ownership record — because the
            // manager's value is not there, so the marketplace's is still the
            // one in force.
            $stored = [];
            $failed = [];
            foreach ($moved as $key => $value) {
                update_post_meta($id, $key, $value);
                if ((string) get_post_meta($id, $key, true) === $value) {
                    $stored[] = $key;
                    continue;
                }
                $failed[] = $key;
            }
            if ($stored !== []) {
                self::releaseKeys($id, $stored);
            }
            self::remember(
                $id,
                $failed === [] ? 'saved' : 'partly_saved',
                '',
                ['stored' => implode(',', $stored), 'failed' => implode(',', $failed)]
            );
        }, 20, 1);

        // The outcome of the last panel save, once, to the manager who made
        // it. A refusal that is silent is a refusal the manager reads as a
        // save.
        add_action('admin_notices', static function (): void {
            $notice = get_transient(self::noticeKey());
            if (!is_array($notice)) {
                return;
            }
            delete_transient(self::noticeKey());
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html below
            echo self::notice($notice);
        }, 20);
    }

    /**
     * The refusal a manager sees for an impossible window, with the two ways
     * out — the second only when it exists. Separate from the vendor's own
     * message because this one reads the numbers back out of the transient
     * the save left, not out of an `OperationResult`.
     *
     * @param array<string,mixed> $context
     * @param callable(mixed):string $fa
     */
    private static function unreachableNotice(array $context, callable $fa): string
    {
        [$workableMax, $workableMin] = PurchaseQuantity::workableWindow(
            (int) ($context['min'] ?? 1),
            (int) ($context['max'] ?? 0),
            (int) ($context['step'] ?? 1)
        );
        if ($workableMin !== null) {
            return sprintf(
                /* translators: 1: step, 2: minimum, 3: maximum, 4: a maximum that works, 5: a minimum that works */
                __('با گام %1$s، بین حداقل %2$s و حداکثر %3$s هیچ تعداد مجازی وجود ندارد. حداکثر را %4$s بگذارید، یا حداقل را %5$s. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
                $fa($context['step'] ?? ''),
                $fa($context['min'] ?? ''),
                $fa($context['max'] ?? ''),
                $fa($workableMax),
                $fa($workableMin)
            );
        }
        return sprintf(
            /* translators: 1: step, 2: minimum, 3: maximum, 4: a maximum that works */
            __('با گام %1$s، بین حداقل %2$s و حداکثر %3$s هیچ تعداد مجازی وجود ندارد. حداکثر را %4$s بگذارید. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
            $fa($context['step'] ?? ''),
            $fa($context['min'] ?? ''),
            $fa($context['max'] ?? ''),
            $fa($workableMax)
        );
    }

    /**
     * Hand the three rules back to the marketplace — IN THIS REQUEST.
     *
     * The rule is read first and written second, and nothing is ever deleted.
     * That order is the whole fix: `alpha.46` deleted the rows and left the
     * restoring to the next projection, so between the manager's click and
     * the next save of that product there was no rule on the shop at all.
     *
     * What is written is the MARKETPLACE ROW's rule, which is the approved
     * one and cannot be a proposal: the three fields are on
     * `SensitiveChange::IMMEDIATE`, so a vendor's change to them goes straight
     * into that row and never waits in `tmc_product_revisions` (the reasoning
     * is written out in `PurchaseGuard::marketplaceProduct()`).
     *
     * Three refusals, each stated rather than emergent:
     *
     *  - **the rule cannot be read** — nothing is written and nothing is
     *    deleted, so the shop keeps exactly the restriction it had, and the
     *    manager is told. A reset that cannot find the rule it is restoring
     *    must not fall back to «no rule»; that is the one answer that sells
     *    more than before.
     *  - **a row will not store** — the row keeps its old value, which is a
     *    restriction rather than an absence, and the failing key is NAMED.
     *    `update_post_meta()` answers `false` both for a failure and for «it
     *    was already this» (the `alpha.44` rule), so its answer is not read:
     *    every row is read BACK and compared.
     *  - **the ownership record** gets a fingerprint only for a row that
     *    really stored. A key whose write failed keeps whatever the record
     *    already said about it, because the manager's value is still the one
     *    in force there.
     *
     * The one thing this does widen, said out loud: if the marketplace's own
     * rule has no maximum, restoring it removes a ceiling the manager had
     * typed. That is not a gap — it is the marketplace's declared rule, which
     * is exactly what the manager asked to go back to.
     */
    private static function handBack(int $id, PurchasePolicy $policy): void
    {
        $rule = self::marketplaceRule($policy, $id);
        if ($rule === null) {
            // Read nothing, so write nothing and delete nothing.
            self::remember($id, 'hand_back_unavailable', '', []);
            return;
        }
        $record = ProjectedQuantity::normalise(get_post_meta($id, ProjectedQuantity::OWNED_META, true));
        $conflicts = get_post_meta($id, ProjectedQuantity::CONFLICT_META, true);
        $conflicts = is_array($conflicts) ? array_values(array_filter($conflicts, 'is_string')) : [];

        $restored = [];
        $failed = [];
        foreach (ProjectedQuantity::values($rule) as $key => $value) {
            update_post_meta($id, $key, $value);
            if (metadata_exists('post', $id, $key) && (string) get_post_meta($id, $key, true) === $value) {
                $restored[] = $key;
                $record[$key] = ProjectedQuantity::fingerprint($value);
                continue;
            }
            $failed[] = $key;
        }

        // A conflict about a row we just took back is a conflict nobody can
        // act on; one about a row that would not store is still real.
        $stillConflicting = array_values(array_diff($conflicts, $restored));
        if ($stillConflicting === []) {
            delete_post_meta($id, ProjectedQuantity::CONFLICT_META);
        } else {
            update_post_meta($id, ProjectedQuantity::CONFLICT_META, $stillConflicting);
        }
        // The contradiction flag is a cache of «WooCommerce's own setting
        // disagrees with this rule», and `QuantityGuard` rewrites it on the
        // next read if it still does. Dropping it here costs nothing and
        // stops a stale sentence being shown about a rule that just changed.
        delete_post_meta($id, QuantityGuard::CONTRADICTION_META);

        $recordProblem = '';
        if ($restored !== []) {
            $recordProblem = self::storeRecord($id, $record);
        }

        self::remember(
            $id,
            $failed === [] && $recordProblem === '' ? 'handed_back' : 'hand_back_failed',
            '',
            [
                'restored' => implode(',', $restored),
                'failed' => implode(',', $failed),
                'record' => $recordProblem,
            ]
        );
    }

    /**
     * The marketplace row's rule for this shop product, or null when it
     * cannot be read.
     *
     * `null` is deliberately the answer for every unreadable case — the
     * product is not the marketplace's, the row is gone, the read threw —
     * because the caller does the same thing in all of them: nothing.
     */
    private static function marketplaceRule(PurchasePolicy $policy, int $id): ?PurchaseQuantity
    {
        if (!function_exists('wc_get_product')) {
            return null;
        }
        try {
            $product = wc_get_product($id);
            if (!is_object($product)) {
                return null;
            }
            $row = PurchaseGuard::marketplaceProduct($policy, $product);
        } catch (\Throwable) {
            return null;
        }
        return $row?->details->purchaseQuantity();
    }

    /**
     * Store the ownership record and say what went wrong, or `''`.
     *
     * Read back and compared, because `update_post_meta()` cannot tell a
     * failure from a no-op — and a record that did not store is DELETED
     * rather than left stale: an absent record makes every row fall to
     * `ProjectedQuantity::merge()`'s conservative test, while a stale one is
     * believed (the `alpha.44` rule).
     *
     * @param array<string,string> $record
     */
    private static function storeRecord(int $id, array $record): string
    {
        if ($record === []) {
            delete_post_meta($id, ProjectedQuantity::OWNED_META);
            return '';
        }
        update_post_meta($id, ProjectedQuantity::OWNED_META, $record);
        if (ProjectedQuantity::normalise(get_post_meta($id, ProjectedQuantity::OWNED_META, true)) === $record) {
            return '';
        }
        delete_post_meta($id, ProjectedQuantity::OWNED_META);
        return ProjectedQuantity::RECORD_NOT_STORED;
    }

    /**
     * Drop only these keys from the ownership record.
     *
     * The record is `key => fingerprint`; removing one key hands that rule to
     * the manager and leaves the others tracking the vendor. A record that
     * empties out is deleted rather than stored as `[]`, so
     * `ProjectedQuantity::normalise()` reads «no record» the same way either
     * way — and the write is READ BACK, because `update_post_meta()` cannot
     * tell a failure from a no-op.
     *
     * @param list<string> $keys
     */
    private static function releaseKeys(int $id, array $keys): void
    {
        $record = ProjectedQuantity::normalise(get_post_meta($id, ProjectedQuantity::OWNED_META, true));
        if ($record === []) {
            return;                     // nothing recorded; nothing to release
        }
        foreach ($keys as $key) {
            unset($record[$key]);
        }
        if ($record === []) {
            delete_post_meta($id, ProjectedQuantity::OWNED_META);
            return;
        }
        update_post_meta($id, ProjectedQuantity::OWNED_META, $record);
        if (ProjectedQuantity::normalise(get_post_meta($id, ProjectedQuantity::OWNED_META, true)) !== $record) {
            // The record did not store. Deleted rather than left stale: an
            // absent record is decided conservatively, a stale one is
            // believed (`alpha.44`).
            delete_post_meta($id, ProjectedQuantity::OWNED_META);
        }
    }

    private static function noticeKey(): string
    {
        return self::NOTICE_PREFIX . (int) get_current_user_id();
    }

    /** @param array<string,string|int> $context */
    private static function remember(int $id, string $outcome, string $reason, array $context): void
    {
        set_transient(self::noticeKey(), [
            'product' => $id,
            'outcome' => $outcome,
            'reason' => $reason,
            'context' => $context,
        ], 60);
    }

    /** @param array<string,mixed> $notice */
    private static function notice(array $notice): string
    {
        $outcome = (string) ($notice['outcome'] ?? '');
        $context = is_array($notice['context'] ?? null) ? $notice['context'] : [];
        $fa = static fn (mixed $v): string => PersianDigits::toPersian((string) $v);

        if ($outcome === 'refused') {
            $reason = (string) ($notice['reason'] ?? '');
            $text = match ($reason) {
                PurchaseQuantity::NO_MULTIPLE => self::unreachableNotice($context, $fa),
                PurchaseQuantity::INVALID_MAX => sprintf(
                    /* translators: 1: maximum, 2: minimum */
                    __('حداکثر خرید (%1$s) از حداقل خرید (%2$s) کمتر است. برای «بدون سقف» کادر حداکثر را خالی بگذارید؛ ۰ پذیرفته نمی‌شود. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
                    $fa($context['max'] ?? ''),
                    $fa($context['min'] ?? '')
                ),
                PurchaseQuantity::INVALID_STEP => __('گام تعداد دست‌کم ۱ است. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
                default => __('حداقل خرید دست‌کم ۱ است. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
            };
            return '<div class="notice notice-error"><p>' . esc_html($text) . '</p></div>';
        }

        $text = match ($outcome) {
            // Says what IS in force now, not what will be after some later
            // save: the rule was restored in the same request.
            'handed_back' => __('قواعد تعداد خرید به حالت خودکار برگشت و همین حالا مقدارهای بازارگاه روی محصول نوشته شدند. از این پس تغییر فروشنده دوباره اعمال می‌شود.', 'tecteb-marketplace-core'),
            'hand_back_failed' => __('بازگرداندن به حالت خودکار کامل نشد. مقدارهایی که نوشته نشدند همان قاعدهٔ قبلی را نگه داشته‌اند — محصول بی‌سقف نشده — ولی هنوز مال شماست. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'hand_back_unavailable' => __('بازگرداندن به حالت خودکار انجام نشد: قاعدهٔ بازارگاه برای این محصول خوانده نشد. هیچ مقداری عوض نشد و محدودیت فعلی سر جایش است. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'partly_saved' => __('بخشی از قواعد تعداد خرید ذخیره نشد. آنچه ذخیره نشده همچنان از بازارگاه می‌آید؛ دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'saved' => __('قواعد تعداد خرید ذخیره شد. از این پس همین مقدارها مال شماست و همگام‌سازی دستشان نمی‌زند.', 'tecteb-marketplace-core'),
            default => '',
        };
        if ($text === '') {
            return '';
        }
        $class = in_array($outcome, ['hand_back_failed', 'hand_back_unavailable', 'partly_saved'], true)
            ? 'notice-warning'
            : 'notice-success';
        return '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($text) . '</p></div>';
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
        // THE STORED VALUES, raw. `alpha.45` rendered the COERCED rule, so a
        // product holding an impossible `10 / 11 / 4` showed the manager
        // `12 / 12 / 4` — the one screen that could have let them see and fix
        // the contradiction hid it, and showed a ceiling the vendor never set.
        $meta = [];
        foreach (ProjectedQuantity::keys() as $key) {
            $meta[$key] = metadata_exists('post', $postId, $key)
                ? (string) get_post_meta($postId, $key, true)
                : '';
        }
        $storedMin = $meta[ProjectedQuantity::META_MIN];
        $storedMax = $meta[ProjectedQuantity::META_MAX];
        $storedStep = $meta[ProjectedQuantity::META_STEP];
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
                'این سه مقدار از پنل فروشنده در بازارگاه می‌آیند. اگر همین‌جا تغییرشان بدهید، از آن پس مال شماست: همگام‌سازی بعدی دستشان نمی‌زند و تغییر فروشنده روی آن‌ها اعمال نمی‌شود. برای برگرداندن، چک‌باکس «بازگرداندن به حالت خودکار» را در پایین همین تب بزنید — خالی‌گذاشتن این فیلدها قاعده را برنمی‌گرداند، بلکه «بدون سقف» می‌نویسد.',
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

        // An impossible combination already on the row: named, with two
        // maxima that would work, and NOT quietly reinterpreted. Until it is
        // fixed the product cannot be bought — the only reading of
        // contradictory data that cannot increase what may be bought.
        if (!$rule->isSatisfiable()) {
            [$workableMax, $workableMin] = PurchaseQuantity::workableWindow(
                (int) ($storedMin === '' ? 1 : $storedMin),
                (int) $storedMax,
                (int) ($storedStep === '' ? 1 : $storedStep)
            );
            // Each number is the smallest change to ONE field that makes the
            // other field's STORED value work, and the second is printed only
            // when it exists — naming a value that is itself refused would be
            // worse than naming none.
            $warning = $workableMin !== null
                ? sprintf(
                    /* translators: 1: a maximum that works, 2: a minimum that works */
                    __('هشدار: این سه مقدار با هم ناسازگارند — با این گام هیچ تعداد مجازی بین حداقل و حداکثر نیست، پس تا اصلاح، خرید این کالا ممکن نیست. حداکثر را %1$s بگذارید، یا حداقل را %2$s.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $workableMax),
                    PersianDigits::toPersian((string) $workableMin)
                )
                : sprintf(
                    /* translators: 1: a maximum that works */
                    __('هشدار: این سه مقدار با هم ناسازگارند — با این گام هیچ تعداد مجازی بین حداقل و حداکثر نیست، پس تا اصلاح، خرید این کالا ممکن نیست. حداکثر را %1$s بگذارید.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $workableMax)
                );
            $html .= '<p style="padding:0 12px;"><strong>' . esc_html($warning) . '</strong></p>';
        }

        $html .= $field(
            'tmc_min_purchase',
            __('حداقل خرید', 'tecteb-marketplace-core'),
            $storedMin,
            __('خالی یا ۰ یعنی ۱.', 'tecteb-marketplace-core')
        );
        $html .= $field(
            'tmc_max_purchase',
            __('حداکثر خرید', 'tecteb-marketplace-core'),
            $storedMax,
            __('خالی یعنی بدون سقف. روی مجموع همین کالا در سبد اعمال می‌شود. سقف شما هرگز بالا برده نمی‌شود.', 'tecteb-marketplace-core')
        );
        $html .= $field(
            'tmc_purchase_step',
            __('گام تعداد', 'tecteb-marketplace-core'),
            $storedStep,
            __('تعداد باید مضربی از این عدد باشد. ۱ یعنی هر تعدادی.', 'tecteb-marketplace-core')
        );

        // THE NAMED CONTROL. Emptying the boxes is not «hand it back» and
        // never was: it is «no minimum, no maximum, no step», which is a rule
        // of its own and a wider one. This removes the three rows and the
        // ownership record, so the next synchronisation writes the
        // marketplace's values again and owns them.
        $html .= '<p class="form-field tmc_quantity_reset_field">'
            . '<label for="tmc-quantity-reset">'
            . esc_html__('بازگرداندن به حالت خودکار', 'tecteb-marketplace-core') . '</label>'
            . '<input type="checkbox" class="checkbox" id="tmc-quantity-reset"'
            . ' name="' . esc_attr(self::RESET) . '" value="1">'
            . '<span class="description">' . esc_html__(
                'با ذخیره، همین حالا مقدارهای پنل فروشنده روی محصول نوشته می‌شوند و سابقهٔ مالکیت شما برداشته می‌شود؛ از آن پس تغییر فروشنده دوباره اعمال می‌شود. مقدارهای بالا نادیده گرفته می‌شوند.',
                'tecteb-marketplace-core'
            ) . '</span>'
            . '</p>';

        return $html . wp_nonce_field(self::NONCE, self::NONCE, true, false) . '</div></div>';
    }
}
