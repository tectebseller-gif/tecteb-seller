<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;

/**
 * «کمیسیون تک‌طب» in WooCommerce's own product editor — the ONE place the
 * owner's share of a product is set, and the one place it can be written.
 *
 * Two controls and nothing else, because that is what was asked for: «نوع
 * کمیسیون تک‌طب» (بدون کمیسیون / درصدی / مبلغ ثابت) and «مقدار», which is only
 * meaningful for the two types that have one. A percentage is a share of what
 * was actually paid for the line after discount; a fixed amount is **per
 * unit** and multiplies by the quantity — ۲۰٬۰۰۰ × ۳ = ۶۰٬۰۰۰.
 *
 * **What this panel refuses to do.**
 *
 *  - It does not block anything. A product with no commission is approvable,
 *    publishable and sellable: «نبود نرخ مانع تأیید، انتشار یا فروش نیست».
 *  - It does not correct an impossible input. `۱۲۰٪` and `۲۰٬۰۰۰ تومان و نیم
 *    ریال` are refused BY NAME and nothing is written — `alpha.45`'s rule
 *    about the purchase window, now about somebody's share of a sale.
 *  - It does not write on a save it did not come from. Its nonce is checked
 *    first, so a bulk edit, a REST write or another plugin saving the product
 *    leaves the rule exactly as it is rather than resetting it to the
 *    defaults of a form that was never rendered (`alpha.46`).
 *  - It does not let a vendor near it. The capability below is the
 *    marketplace's financial-management permission, held by the administrator
 *    role and by nobody this plugin creates; and no other writer of these two
 *    meta keys exists anywhere in the plugin, which `CommissionOwnershipTest`
 *    proves by searching for one rather than by trusting this sentence.
 *    ⛔ That is a fact about THIS plugin and not about the two keys:
 *    WooCommerce's REST API and CSV importer write arbitrary product meta on
 *    `edit_products`. `TectebCommissionMetaGuard` refuses those; this panel
 *    is one door of the four بند ۵-هـ names, not all four.
 */
final class TectebCommissionPanel
{
    public const NONCE = 'tmc_commission_panel';

    /** The financial-management permission. Not `edit_post`, and not a vendor's. */
    public const CAPABILITY = Capabilities::REVIEW_WITHDRAWALS;

    private const TYPE_FIELD = 'tmc_commission_type';
    private const VALUE_FIELD = 'tmc_commission_value';
    private const NOTICE_PREFIX = 'tmc_commission_notice_';

    public static function register(ContainerInterface $container): void
    {
        add_filter('woocommerce_product_data_tabs', static function (mixed $tabs): mixed {
            if (!is_array($tabs) || !self::mayEdit(self::currentPostId())) {
                return $tabs;
            }
            $tabs['tmc_commission'] = [
                'label' => __('کمیسیون تک‌طب', 'tecteb-marketplace-core'),
                'target' => 'tmc_commission_panel',
                'class' => [],
                'priority' => 68,
            ];
            return $tabs;
        }, 20);

        add_action('woocommerce_product_data_panels', static function (): void {
            $id = self::currentPostId();
            if (!self::mayEdit($id)) {
                return;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* below
            echo self::panel($id);
        }, 20);

        add_action('woocommerce_process_product_meta', static function (mixed $postId) use ($container): void {
            self::save((int) $postId, $container);
        }, 20, 1);

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
     * The save, in the order that makes each refusal mean something.
     *
     * Nonce, then capability, then the value — so a request that did not come
     * from this form changes nothing at all, a manager without the financial
     * permission is refused before their input is even read, and an
     * impossible value is named rather than coerced.
     */
    public static function save(int $postId, ContainerInterface $container): void
    {
        if ($postId <= 0) {
            return;
        }
        $request = Request::capture();
        if (!$request->hasPost(self::NONCE)
            || !wp_verify_nonce($request->postText(self::NONCE), self::NONCE)) {
            return;
        }
        if (!self::mayEdit($postId)) {
            // A form that reached here without the permission is either a
            // stale page from before the permission was removed or a crafted
            // request. Either way nothing is written, and it is recorded:
            // «فروشنده یا پرسنل بدون مجوز مدیریت مالی نباید بتواند … از طریق
            // فرم، CSV، درخواست دستکاری‌شده یا API تغییر دهد».
            self::audit($container, $postId, 'refused', 'not_permitted', []);
            return;
        }

        $type = trim($request->postText(self::TYPE_FIELD));
        $value = trim($request->postText(self::VALUE_FIELD));
        ['rule' => $rule, 'reason' => $reason] = TectebCommission::fromInput($type, $value);
        if ($rule === null) {
            self::remember($postId, 'refused', $reason, ['type' => $type, 'value' => $value]);
            self::audit($container, $postId, 'refused', $reason, ['type' => $type]);
            return;     // NOTHING is written. Not one row.
        }

        // ONE UNIT OF WORK, because the type and the amount are a pair.
        // `alpha.60` wrote them with two `update_post_meta()` calls and
        // compared afterwards; a failure on the second left `fixed/12.50`
        // stored — neither rule — under a message saying the previous value
        // was intact. `CommissionRuleWriter` locks the two rows, writes them
        // inside one transaction, verifies from the ROWS rather than the
        // cache, and reports only what it measured.
        $writer = self::writer($container);
        if ($writer === null) {
            self::remember($postId, CommissionRuleWriter::NOT_ATOMIC, '', []);
            self::audit($container, $postId, CommissionRuleWriter::NOT_ATOMIC, '', ['type' => $rule->type]);
            return;
        }

        $written = $writer->write($postId, $rule);
        $context = [
            'type' => $rule->type,
            'value' => $rule->displayValue(),
            'previous_type' => (string) $written['previous_type'],
            'previous_value' => (string) $written['previous_value'],
            'stored_type' => (string) $written['stored_type'],
            'stored_value' => (string) $written['stored_value'],
            'rolled_back' => $written['rolled_back'] ? '1' : '0',
        ];
        self::remember($postId, (string) $written['outcome'], '', $context);
        // Every financial change is in the trail: «تغییرات مالی ممیزی شود» —
        // and so is every financial change that did NOT happen, with what is
        // stored instead, because that is the row somebody has to go and fix.
        self::audit($container, $postId, (string) $written['outcome'], '', $context);
    }

    /**
     * The writer, or `null` when this build cannot make the pair one unit.
     *
     * Asked from the container rather than constructed, so the gateway is the
     * one every other write in the request uses — a second object over the
     * same connection counts its own nesting and would turn an inner `begin()`
     * into a real transaction (`alpha.56`).
     */
    private static function writer(ContainerInterface $container): ?CommissionRuleWriter
    {
        try {
            $db = $container->get(DatabaseInterface::class);
        } catch (\Throwable) {
            return null;
        }
        return $db instanceof DatabaseInterface ? new CommissionRuleWriter($db) : null;
    }

    /**
     * Whether the person at this screen may both edit the product and decide
     * money for it.
     *
     * BOTH, not either: `edit_post` is WordPress's question about the post and
     * the capability is the marketplace's question about money, and a shop
     * manager who can edit every product is exactly the person this field is
     * not for.
     */
    private static function mayEdit(int $postId): bool
    {
        return $postId > 0
            && current_user_can(self::CAPABILITY)
            && current_user_can('edit_post', $postId);
    }

    /** @see QuantityPanel::currentPostId() for why this reads the global and not the query string. */
    private static function currentPostId(): int
    {
        $post = $GLOBALS['post'] ?? null;
        return is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
    }

    private static function panel(int $postId): string
    {
        ['rule' => $rule, 'source' => $source] = (new WpTectebCommissionSource())->forItem($postId, 0);
        $types = [
            TectebCommission::NONE => __('بدون کمیسیون', 'tecteb-marketplace-core'),
            TectebCommission::PERCENT => __('درصدی', 'tecteb-marketplace-core'),
            TectebCommission::FIXED => __('مبلغ ثابت به ازای هر عدد', 'tecteb-marketplace-core'),
        ];

        $html = '<div id="tmc_commission_panel" class="panel woocommerce_options_panel"><div class="options_group">'
            . '<p style="padding:0 12px;">' . esc_html__(
                'کمیسیون تک‌طب سهم تک‌طب از فروش این محصول است. تعیین‌نکردنش مانع تأیید، انتشار یا فروش محصول نیست و یعنی این محصول کمیسیون ندارد. مبلغ کمیسیون هنگام ثبت سفارش در همان قلم ذخیره می‌شود، پس تغییر بعدی این مقدار سفارش‌های قبلی را عوض نمی‌کند.',
                'tecteb-marketplace-core'
            ) . '</p>';

        if ($source === WpTectebCommissionSource::FROM_NONE) {
            $html .= '<p style="padding:0 12px;">' . esc_html__(
                'برای این محصول هنوز چیزی تعیین نشده است.',
                'tecteb-marketplace-core'
            ) . '</p>';
        }

        $html .= '<p class="form-field tmc_commission_type_field">'
            . '<label for="tmc-commission-type">' . esc_html__('نوع کمیسیون تک‌طب', 'tecteb-marketplace-core') . '</label>'
            . '<select id="tmc-commission-type" name="' . esc_attr(self::TYPE_FIELD) . '" class="select short">';
        foreach ($types as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"'
                . ($value === $rule->type ? ' selected' : '') . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select></p>';

        // ONE value box for both kinds, because a manager sets one kind at a
        // time and two boxes would be two places the same answer could be
        // half-filled. It is visible with JavaScript off — the hint says what
        // it is ignored for — and the small script below only hides it for
        // «بدون کمیسیون», which is the «فقط در صورت نیاز» the instruction
        // asks for without making the field unreachable when the script does
        // not run.
        $html .= '<p class="form-field tmc_commission_value_field" id="tmc-commission-value-row">'
            . '<label for="tmc-commission-value">' . esc_html__('مقدار', 'tecteb-marketplace-core') . '</label>'
            . '<input type="text" class="short" id="tmc-commission-value" dir="ltr" inputmode="decimal"'
            . ' name="' . esc_attr(self::VALUE_FIELD) . '" value="' . esc_attr($rule->displayValue()) . '">'
            . '<span class="description">' . esc_html__(
                'برای «درصدی» عددی بین ۰ تا ۱۰۰ بنویسید (مثلاً ۱۲٫۵) و برای «مبلغ ثابت» مبلغ هر عدد را به واحد پول فروشگاه. در «بدون کمیسیون» این کادر نادیده گرفته می‌شود. صفر یعنی بدون کمیسیون و با خالی‌بودن فرقی در نتیجه ندارد.',
                'tecteb-marketplace-core'
            ) . '</span></p>';

        // WRITTEN OUT rather than `wp_nonce_field()`, and the reason is an id
        // collision only a browser could find: that helper gives the input an
        // `id` equal to its NAME, and the name here is `tmc_commission_panel`
        // — which is also the id of the panel `<div>` WooCommerce's tab points
        // at. Two elements with one id is invalid HTML, and the first thing it
        // breaks is anything that addresses the panel by id. The POST key is
        // unchanged, so `save()` reads exactly what it read before.
        $html .= '<input type="hidden" id="tmc-commission-nonce"'
            . ' name="' . esc_attr(self::NONCE) . '"'
            . ' value="' . esc_attr(wp_create_nonce(self::NONCE)) . '">';

        $html .= '<script>(function(){var t=document.getElementById("tmc-commission-type"),'
            . 'r=document.getElementById("tmc-commission-value-row");if(!t||!r){return;}'
            . 'function s(){r.style.display=t.value==="' . esc_js(TectebCommission::NONE) . '"?"none":"";}'
            . 't.addEventListener("change",s);s();})();</script>';

        return $html . '</div></div>';
    }

    private static function noticeKey(): string
    {
        return self::NOTICE_PREFIX . (int) get_current_user_id();
    }

    /** @param array<string,string> $context */
    private static function remember(int $postId, string $outcome, string $reason, array $context): void
    {
        set_transient(self::noticeKey(), [
            'product' => $postId,
            'outcome' => $outcome,
            'reason' => $reason,
            'context' => $context,
        ], 60);
    }

    /** @param array<string,string> $context */
    private static function audit(ContainerInterface $container, int $postId, string $outcome, string $reason, array $context): void
    {
        try {
            $logger = $container->get(AuditLogger::class);
        } catch (\Throwable) {
            return;
        }
        if (!$logger instanceof AuditLogger) {
            return;
        }
        $logger->log(AuditEventCatalog::COMMISSION_RULE_SET, 0, 'product', (string) $postId, [
            'outcome' => $outcome,
            'reason' => $reason,
        ] + $context);
    }

    /**
     * One stored pair, in words a manager can act on.
     *
     * «تعیین‌نشده» and «بدون کمیسیون» are deliberately different sentences:
     * the first is a product nobody has decided about and the second is a
     * decision somebody made, and the money is the same either way
     * (`TectebCommission` reads both as no commission) while what to tell a
     * person is not.
     */
    private static function describePair(string $type, string $value): string
    {
        if ($type === '__absent__' && $value === '__absent__') {
            return __('تعیین‌نشده', 'tecteb-marketplace-core');
        }
        if ($type === '__unknown__' || $value === '__unknown__') {
            return __('نامعلوم', 'tecteb-marketplace-core');
        }
        $shown = $value === '__absent__' ? '' : $value;
        return match ($type) {
            TectebCommission::PERCENT => sprintf(
                /* translators: %s: a percentage, as stored. */
                __('درصدی %s٪', 'tecteb-marketplace-core'),
                $shown
            ),
            TectebCommission::FIXED => sprintf(
                /* translators: %s: a fixed amount per unit, as stored. */
                __('مبلغ ثابت %s به ازای هر عدد', 'tecteb-marketplace-core'),
                $shown
            ),
            TectebCommission::NONE => __('بدون کمیسیون', 'tecteb-marketplace-core'),
            default => sprintf(
                /* translators: 1: the stored type, 2: the stored value. */
                __('نوع ناشناختهٔ «%1$s» با مقدار «%2$s»', 'tecteb-marketplace-core'),
                $type,
                $shown
            ),
        };
    }

    /** @param array<string,mixed> $notice */
    private static function notice(array $notice): string
    {
        $outcome = (string) ($notice['outcome'] ?? '');
        $reason = (string) ($notice['reason'] ?? '');
        $context = is_array($notice['context'] ?? null) ? $notice['context'] : [];

        if ($outcome === 'refused') {
            $text = match ($reason) {
                TectebCommission::BAD_PERCENT => __('درصد کمیسیون باید عددی بین ۰ تا ۱۰۰ باشد (حداکثر دو رقم اعشار). هیچ مقداری ذخیره نشد و کمیسیون قبلی سر جایش است.', 'tecteb-marketplace-core'),
                TectebCommission::BAD_FIXED => __('مبلغ ثابت کمیسیون باید عددی غیرمنفی باشد. هیچ مقداری ذخیره نشد و کمیسیون قبلی سر جایش است.', 'tecteb-marketplace-core'),
                'not_permitted' => __('تغییر کمیسیون تک‌طب نیازمند مجوز مدیریت مالی است. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
                default => __('نوع کمیسیون شناخته نشد. هیچ مقداری ذخیره نشد و کمیسیون قبلی سر جایش است.', 'tecteb-marketplace-core'),
            };
            return '<div class="notice notice-error"><p>' . esc_html($text) . '</p></div>';
        }
        // The two halves of «ذخیره نشد», and the difference between them is
        // whether the previous pair was found back IN THE ROWS. The first
        // sentence is the one `alpha.60` printed unconditionally and it was
        // false exactly when it mattered most.
        if ($outcome === CommissionRuleWriter::RESTORED) {
            return '<div class="notice notice-warning"><p>' . esc_html(sprintf(
                /* translators: %s: the commission that is still stored. */
                __(
                    'کمیسیون تک‌طب ذخیره نشد و هیچ تغییری اعمال نشد. کمیسیون این محصول همان قبلی است (%s)؛ دوباره تلاش کنید.',
                    'tecteb-marketplace-core'
                ),
                self::describePair((string) ($context['previous_type'] ?? ''), (string) ($context['previous_value'] ?? ''))
            )) . '</p></div>';
        }
        if ($outcome === CommissionRuleWriter::UNRESTORED) {
            return '<div class="notice notice-error"><p>' . esc_html(sprintf(
                /* translators: 1: what is stored now, 2: what was stored before. */
                __(
                    'کمیسیون تک‌طب ذخیره نشد و بازگرداندن مقدار قبلی هم انجام نشد. آنچه الان روی این محصول است «%1$s» است و پیش از این «%2$s» بود. تا این را درست نکنید، کمیسیون سفارش‌های تازهٔ این محصول از همین مقدار حساب می‌شود.',
                    'tecteb-marketplace-core'
                ),
                self::describePair((string) ($context['stored_type'] ?? ''), (string) ($context['stored_value'] ?? '')),
                self::describePair((string) ($context['previous_type'] ?? ''), (string) ($context['previous_value'] ?? ''))
            )) . '</p></div>';
        }
        if ($outcome === CommissionRuleWriter::ROWS_DUPLICATED) {
            return '<div class="notice notice-error"><p>' . esc_html__(
                'کمیسیون تک‌طب ذخیره نشد، چون این محصول در دیتابیس بیش از یک ردیف کمیسیون دارد و معلوم نیست کدام معتبر است. هیچ چیزی نوشته نشد؛ این مورد را به توسعه‌دهنده بگویید.',
                'tecteb-marketplace-core'
            ) . '</p></div>';
        }
        if ($outcome === CommissionRuleWriter::NOT_ATOMIC) {
            return '<div class="notice notice-error"><p>' . esc_html__(
                'کمیسیون تک‌طب ذخیره نشد، چون این نصب نمی‌تواند نوع و مقدار را با هم بنویسد و نوشتن یکی بدون دیگری قاعده‌ای می‌سازد که کسی نخواسته. هیچ چیزی نوشته نشد و کمیسیون قبلی دست نخورد.',
                'tecteb-marketplace-core'
            ) . '</p></div>';
        }
        if ($outcome === CommissionRuleWriter::STORAGE_FAILED) {
            return '<div class="notice notice-error"><p>' . esc_html__(
                'کمیسیون تک‌طب ذخیره نشد و خواندن وضعیت فعلی هم ممکن نبود، پس گفته نمی‌شود چه چیزی روی این محصول است. صفحه را تازه کنید و زبانهٔ کمیسیون را ببینید.',
                'tecteb-marketplace-core'
            ) . '</p></div>';
        }
        // `alpha.60`'s own outcome name, kept only because its transient can
        // still be waiting when this build takes over mid-minute.
        if ($outcome === 'not_stored') {
            return '<div class="notice notice-warning"><p>' . esc_html__(
                'کمیسیون تک‌طب ذخیره نشد؛ زبانهٔ کمیسیون را ببینید و دوباره تلاش کنید.',
                'tecteb-marketplace-core'
            ) . '</p></div>';
        }
        if ($outcome !== 'saved') {
            return '';
        }
        $text = ($context['type'] ?? '') === TectebCommission::NONE
            ? __('کمیسیون تک‌طب این محصول برداشته شد. از این پس فروش این محصول کمیسیونی ندارد؛ سفارش‌های قبلی عوض نمی‌شوند.', 'tecteb-marketplace-core')
            : __('کمیسیون تک‌طب ذخیره شد. از این پس روی سفارش‌های تازهٔ این محصول اعمال می‌شود؛ سفارش‌های قبلی عوض نمی‌شوند.', 'tecteb-marketplace-core');
        return '<div class="notice notice-success"><p>' . esc_html($text) . '</p></div>';
    }
}
