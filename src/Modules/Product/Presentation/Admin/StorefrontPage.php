<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Application\TrialUnlock;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontStop;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;
use Tecteb\Marketplace\Modules\Product\Application\UnpaidOrderGuardInterface;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WcUnpaidOrderGuard;
use Tecteb\Marketplace\Modules\Product\Presentation\PurchaseMessages;

/**
 * «وضعیت فروش بازارگاه» — the manager's side of every refusal a shopper sees.
 *
 * This page and PurchaseMessages are two halves of one instruction. The
 * shopper gets one short sentence about the product; everything that sentence
 * leaves out is here: which gate refused, which commission rate resolved,
 * which decisions are still open, how many products are out of the shop and
 * why each one would not go back.
 *
 * It is also where a rollback is prepared. «اول فروشنده را تعلیق کنید» was an
 * instruction with no button behind it; this is the button. One click takes
 * every marketplace product out of the shop without deleting anything, so the
 * package can be replaced with nothing left on sale that the next version
 * cannot guard.
 */
final class StorefrontPage
{
    public const SLUG = 'tmc-storefront';
    public const CAPABILITY = Capabilities::MANAGE_STOREFRONT;
    private const NONCE = 'tmc_storefront';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('وضعیت فروش بازارگاه', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $notice = $this->handleAction(Request::capture());
        $switch = $this->container->get(StorefrontSwitch::class);
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(str_starts_with($notice, 'ok:') ? 'success' : 'error', substr($notice, strpos($notice, ':') + 1));
        }

        $this->renderSwitchPanel($switch, $fa);
        $this->renderGatePanel($fa);
        $this->renderProductPanel($fa);

        echo Components::shellClose();
    }

    private function renderSwitchPanel(StorefrontSwitch $switch, callable $fa): void
    {
        $stopped = $switch->isStopped();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('کلید فروش', 'tecteb-marketplace-core') . '</h2>';
        echo Components::notice(
            $stopped ? 'warning' : 'success',
            $stopped
                ? sprintf(
                    /* translators: 1: reason, 2: date */
                    __('فروش بازارگاه متوقف است. علت: %1$s — از %2$s. محصولات بازارگاه از فروشگاه بیرون رفته‌اند و هیچ‌کدام حذف نشده‌اند.', 'tecteb-marketplace-core'),
                    esc_html(self::reasonLabel($switch->reason())),
                    esc_html($fa($switch->stoppedAt()))
                )
                : __('فروش بازارگاه فعال است. محصولات تأییدشده در فروشگاه قابل خریدند.', 'tecteb-marketplace-core')
        );
        echo '<p class="tmc-field__desc">'
            . esc_html__('توقف فروش هیچ داده‌ای را پاک نمی‌کند: محصول‌های بازارگاه به «پیش‌نویس» می‌روند و از دید خریدار خارج می‌شوند، درست مثل تعلیق فروشنده. محصولات خود فروشگاه و دکان اصلاً لمس نمی‌شوند.', 'tecteb-marketplace-core')
            . '</p>';
        // What a shopper would find, asked of WooCommerce itself. This is the
        // line that must be believed over the flag above it: the flag records
        // an intention, and these two ask what actually happened.
        $stillOnSale = $this->stillOnSale();
        if ($stillOnSale !== []) {
            echo Components::notice('error', sprintf(
                /* translators: 1: count, 2: WooCommerce product ids */
                __('هشدار: %1$s محصول بازارگاه هنوز روی فروشگاه است (شناسهٔ ووکامرس: %2$s). تا خارج‌شدنشان بستهٔ افزونه را عوض یا غیرفعال نکنید — بدون افزونه چیزی جلوی فروششان را نمی‌گیرد.', 'tecteb-marketplace-core'),
                esc_html($fa((string) count($stillOnSale))),
                esc_html($fa(implode('، ', array_map('strval', $stillOnSale))))
            ));
        }
        $payable = $this->container->get(StorefrontStop::class)->payableOrders();
        if ($payable !== []) {
            echo Components::notice($stopped ? 'error' : 'info', sprintf(
                /* translators: 1: count, 2: order ids */
                __('%1$s سفارش پرداخت‌نشده هست که قلم بازارگاه دارد و هنوز قابل پرداخت است (شماره: %2$s). با توقف فروش، این سفارش‌ها «در انتظار» نگه داشته می‌شوند تا ووکامرس خودش پرداخت تازه‌ای رویشان نپذیرد — حتی اگر این افزونه غیرفعال شود. هیچ سفارشی لغو، خالی یا بازپرداخت نمی‌شود و وضعیت قبلی‌شان ثبت می‌ماند.', 'tecteb-marketplace-core'),
                esc_html($fa((string) count($payable))),
                esc_html($fa(implode('، ', array_map('strval', $payable))))
            ));
        }
        echo '<p class="tmc-field__desc"><strong>'
            . esc_html__('پیش از بازگرداندن بستهٔ قبلی همین دکمه را بزنید.', 'tecteb-marketplace-core')
            . '</strong> '
            . esc_html__('نسخه‌های قدیمی‌تر نه گاردِ خرید دارند و نه ثبت سفارش، پس محصولی که روی فروشگاه بماند بدون هیچ خط دفترکلی فروخته می‌شود.', 'tecteb-marketplace-core')
            . '</p>';

        echo '<form method="post">';
        wp_nonce_field(self::NONCE, 'tmc_storefront_nonce');
        if ($stopped) {
            // Three separate buttons on purpose, and each says exactly what it
            // opens. Rolling back to a previous package means dealing with what
            // the stop left open, and none of that may require reopening the
            // shop first — but «بازگرداندن سفارش‌های نگه‌داشته» is NOT a
            // no-consequence tidy-up either: it puts those orders back to
            // pending or failed, which is precisely where WooCommerce takes
            // money, and it keeps taking it once this plugin is gone. Calling
            // it «فروش بسته می‌ماند» was a contradiction of this plugin's own
            // reason for holding them, and the owner named it.
            echo '<p class="tmc-field__desc">'
                . esc_html__('اگر توقف کامل نشده بود، «تلاش دوباره» را بزنید؛ فروش بسته می‌ماند و فقط آنچه جا مانده بسته می‌شود.', 'tecteb-marketplace-core')
                . '</p>'
                . '<p><button type="submit" name="storefront_action" value="retry_stop" class="tmc-button">'
                . esc_html__('تلاش دوبارهٔ توقف (فروش بسته می‌ماند)', 'tecteb-marketplace-core') . '</button></p>';
            echo '<p class="tmc-field__desc tmc-field__desc--warn">'
                . esc_html__('هشدار: بازگرداندن سفارش‌های نگه‌داشته، همان سفارش‌ها را به وضعیت «در انتظار پرداخت» یا «ناموفق» برمی‌گرداند — یعنی دوباره قابل پرداخت می‌شوند، و اگر این افزونه بعداً غیرفعال شود هم قابل پرداخت می‌مانند. محصولی به فروشگاه برنمی‌گردد، ولی این کار «بی‌اثر» نیست: تصمیم تجاری است، نه مرتب‌کردن.', 'tecteb-marketplace-core')
                . '</p>'
                . '<p class="tmc-field__desc">'
                . esc_html__('برای بازگشت به بستهٔ قبلی، این دکمه لازم نیست و توصیه هم نمی‌شود؛ سفارش‌ها را نگه‌داشته رها کنید تا تعیین تکلیف شوند.', 'tecteb-marketplace-core')
                . ' ' . esc_html(WcUnpaidOrderGuard::stockNote())
                . '</p>'
                . '<p><button type="submit" name="storefront_action" value="release_orders" class="tmc-button">'
                . esc_html__('بازگرداندن سفارش‌های نگه‌داشته (پرداختشان دوباره باز می‌شود)', 'tecteb-marketplace-core') . '</button></p>';
            echo '<p class="tmc-field__desc">'
                . esc_html__('با «از سرگیری»، فروش دوباره باز می‌شود و فقط محصولاتی برمی‌گردند که هنوز شرایطشان برقرار است: فروشنده مجاز به فروش باشد، محصول منتشر باشد و کامل باشد. بقیه با ذکر علت بیرون می‌مانند.', 'tecteb-marketplace-core')
                . '</p>'
                . '<p><button type="submit" name="storefront_action" value="resume" class="tmc-button tmc-button--primary">'
                . esc_html__('از سرگیری فروش بازارگاه', 'tecteb-marketplace-core') . '</button></p>';
        } else {
            echo '<p><button type="submit" name="storefront_action" value="stop" class="tmc-button">'
                . esc_html__('توقف فروش بازارگاه و خارج‌کردن محصول‌ها از فروشگاه', 'tecteb-marketplace-core') . '</button></p>';
        }
        echo '</form></section>';
        $this->renderReconcilePanel($fa);
    }

    /**
     * The orders a person has to look at — and the one control that closes one.
     *
     * Its own panel, below the buttons, because it is the only thing on this
     * screen that cannot be fixed by pressing something again. A reconcile mark
     * survives every retry and every release; nothing clears it but a decision
     * recorded here, with a note that is written onto the WooCommerce order
     * itself so it outlives this plugin.
     */
    private function renderReconcilePanel(callable $fa): void
    {
        $orders = $this->container->get(StorefrontStop::class)->needingReconciliation();
        if ($orders === []) {
            return;
        }
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('سفارش‌هایی که باید دستی بررسی شوند', 'tecteb-marketplace-core') . '</h2>';
        echo Components::notice('error', sprintf(
            /* translators: 1: how many, 2: the WooCommerce order numbers */
            __('%1$s سفارش (شمارهٔ %2$s) نگه داشته و برگردانده شدند، ولی موجودی‌شان سرِ جای اولش برنگشت یا این بسته نمی‌داند توقف با موجودی چه کرد. این بازارگاه هیچ عدد موجودی‌ای را خودسرانه بازنویسی نمی‌کند، پس تا تصمیم شما این نشانه سرِ جایش می‌ماند — «بازگرداندن» دوباره هم پاکش نمی‌کند.', 'tecteb-marketplace-core'),
            esc_html($fa((string) count($orders))),
            esc_html($fa(implode('، ', array_map('strval', $orders))))
        ));
        echo '<p class="tmc-field__desc">'
            . esc_html__('موجودی هر محصول این سفارش‌ها را در ووکامرس ببینید. اگر عددی جا مانده بود، همان‌جا درستش کنید؛ اگر اختلاف پذیرفتنی بود، همین را بنویسید. در هر دو حالت یادداشتتان روی خود سفارش ثبت می‌شود.', 'tecteb-marketplace-core')
            . '</p>';
        echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('سفارش‌های نیازمند بررسی', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('سفارش', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آنچه می‌دانیم', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('ثبت تصمیم', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        $guard = $this->container->get(UnpaidOrderGuardInterface::class);
        foreach ($orders as $orderId) {
            $trail = $guard->stockTrail((int) $orderId);
            echo '<tr>'
                . '<th scope="row" data-label="' . esc_attr__('سفارش', 'tecteb-marketplace-core') . '">'
                . esc_html($fa((string) $orderId)) . '</th>'
                . '<td data-label="' . esc_attr__('آنچه می‌دانیم', 'tecteb-marketplace-core') . '">'
                . esc_html(self::trailSentence($trail)) . '</td>'
                . '<td data-label="' . esc_attr__('ثبت تصمیم', 'tecteb-marketplace-core') . '">'
                . '<form method="post" class="tmc-inline">'
                . wp_nonce_field(self::NONCE, 'tmc_storefront_nonce', true, false)
                . '<input type="hidden" name="reconcile_order" value="' . esc_attr((string) $orderId) . '">'
                . '<label class="tmc-field"><span class="tmc-field__label">'
                . esc_html__('چه کردید؟', 'tecteb-marketplace-core') . '</span>'
                . '<textarea name="reconcile_note" rows="2" class="large-text" required></textarea></label>'
                . '<button type="submit" name="storefront_action" value="resolve_reconcile" class="tmc-button">'
                . esc_html__('ثبت و بستن', 'tecteb-marketplace-core') . '</button>'
                . '</form></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    /**
     * What the guard recorded about one order, as a sentence.
     *
     * `null` is «we do not know», and it is said as that rather than as «no».
     * An order held by a build that predates the stock trail is exactly the
     * case this distinction exists for.
     *
     * @param array{held:bool, was_reduced:?bool, moved:?bool, reconcile:string} $trail
     */
    private static function trailSentence(array $trail): string
    {
        $unknown = __('نامعلوم', 'tecteb-marketplace-core');
        $yesNo = static fn (?bool $v): string => $v === null
            ? $unknown
            : ($v ? __('بله', 'tecteb-marketplace-core') : __('خیر', 'tecteb-marketplace-core'));
        $why = $trail['reconcile'] === 'stock_state_unknown'
            ? __('این سفارش پیش از ثبت ردِ موجودی نگه داشته شده بود، پس نمی‌دانیم توقف موجودی را کم کرد یا نه.', 'tecteb-marketplace-core')
            : __('موجودی پس از بازگرداندن، همان چیزی نشد که پیش از توقف بود.', 'tecteb-marketplace-core');
        return $why . ' ' . sprintf(
            /* translators: 1: whether stock was already reduced before the hold, 2: whether the hold moved it */
            __('پیش از توقف کم شده بود: %1$s · خودِ توقف موجودی را تکان داد: %2$s', 'tecteb-marketplace-core'),
            $yesNo($trail['was_reduced']),
            $yesNo($trail['moved'])
        );
    }

    /** The gate's reasons, in full — the half PurchaseMessages keeps back. */
    private function renderGatePanel(callable $fa): void
    {
        $gate = $this->container->get(OrderOperationsGate::class);
        $answer = $gate->check();
        $rates = $this->container->get(ResolveCommissionRate::class);
        $general = $rates->forItem([]);

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('دلیل‌های داخلی (فقط برای مدیر)', 'tecteb-marketplace-core') . '</h2>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('خریدار هیچ‌کدام از این‌ها را نمی‌بیند؛ به او فقط یک جملهٔ کوتاه دربارهٔ همان کالا نشان داده می‌شود.', 'tecteb-marketplace-core')
            . '</p>';
        echo '<table class="tmc-table"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('دروازهٔ سفارش', 'tecteb-marketplace-core') . '</th><td>'
            . ($answer['ready']
                ? '<strong>' . esc_html__('باز', 'tecteb-marketplace-core') . '</strong>'
                : '<strong>' . esc_html__('بسته', 'tecteb-marketplace-core') . '</strong>')
            . ' — ' . Components::code($answer['reason']) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('جزئیات دروازه', 'tecteb-marketplace-core') . '</th><td>'
            . esc_html($answer['detail']) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('نرخ کمیسیون عمومی', 'tecteb-marketplace-core') . '</th><td>'
            . ($general['rate']->isSet()
                ? esc_html(sprintf(__('%s درصد', 'tecteb-marketplace-core'), $fa((string) $general['rate']->percent())))
                    . ' — ' . Components::code($general['source'])
                : '<strong>' . esc_html__('تعیین‌نشده (صفر نیست)', 'tecteb-marketplace-core') . '</strong>')
            . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('تصمیم‌های باز', 'tecteb-marketplace-core') . '</th><td>'
            . esc_html(implode('، ', OrderOperationsGate::OPEN_DECISIONS)) . '</td></tr>';
        echo '</tbody></table>';
        unset($fa);
        $this->renderTrialSwitch();
        echo '</section>';
    }

    /**
     * The one control that can open the order module while DEC-02 and DEC-04
     * are still open — and the one that says so in the same breath.
     *
     * `OPEN_DECISIONS` is a constant, not a setting: nothing on this site can
     * close DEC-02 or DEC-04, so while they are listed there is exactly one
     * way for orders to run at all, and it is this. Leaving it terminal-only
     * meant the panel above could say «بسته» for ever with no next step —
     * a reason without a remedy, which is the failure mode this whole page
     * exists to avoid.
     *
     * The button appears only where the switch would actually be honoured.
     * On a production-resolved site it is replaced by the refusal and the
     * place to change it, because offering a control that silently does
     * nothing is worse than offering none.
     */
    private function renderTrialSwitch(): void
    {
        if (OrderOperationsGate::OPEN_DECISIONS === []) {
            return;   // nothing to waive; the switch would be noise
        }
        $trial = $this->container->get(TrialUnlock::class);
        $requested = $trial->isRequested();
        $permitted = $trial->isPermitted();

        echo '<h3 class="tmc-card__subtitle">'
            . esc_html__('حالت آزمایشی سفارش', 'tecteb-marketplace-core') . '</h3>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('این کلید فقط شرطِ «تصمیم‌های باز» را کنار می‌گذارد. نرخ کمیسیون و نوشتنی‌بودن دفترکل همچنان لازم‌اند، همان خطوط دفترکل نوشته می‌شود، و هر صفحهٔ سفارش می‌گوید روی قواعد نمونه کار می‌کند.', 'tecteb-marketplace-core')
            . '</p>';

        if (!$permitted) {
            // Not a failure of the switch — a fact about this site. Say which
            // environment was resolved and where it is chosen, so the next
            // click is obvious instead of being guessed at.
            echo Components::notice('warning', sprintf(
                /* translators: %s: the resolved environment name */
                __('این کلید روی محیطی که «%s» تشخیص داده شده پذیرفته نمی‌شود. فقط staging، development و local پذیرفته‌اند — و محیطِ اعلام‌نشده، «اصلی» حساب می‌شود. محیط را در «بازارگاه تک‌طب ← تنظیمات» انتخاب کنید، یا ثابت TMC_ENVIRONMENT را در wp-config.php بگذارید (ثابت مقدم است).', 'tecteb-marketplace-core'),
                esc_html($trial->environmentName())
            ));
            if ($requested) {
                echo '<p class="tmc-field__desc">'
                    . esc_html__('کلید روشن است ولی همین‌جا رد می‌شود؛ روشن‌بودنش به‌تنهایی هیچ سفارشی را باز نمی‌کند.', 'tecteb-marketplace-core')
                    . '</p>';
            }
            return;
        }

        echo '<form method="post">';
        wp_nonce_field(self::NONCE, 'tmc_storefront_nonce');
        if ($requested) {
            echo '<p><button type="submit" name="storefront_action" value="trial_off" class="tmc-button">'
                . esc_html__('خاموش‌کردن حالت آزمایشی سفارش', 'tecteb-marketplace-core') . '</button></p>';
        } else {
            echo '<p><button type="submit" name="storefront_action" value="trial_on" class="tmc-button tmc-button--primary">'
                . esc_html__('روشن‌کردن حالت آزمایشی سفارش', 'tecteb-marketplace-core') . '</button></p>';
        }
        echo '</form>';
    }

    /** Every projected product, and what the catalogue would answer about it. */
    private function renderProductPanel(callable $fa): void
    {
        $products = $this->container->get(ProductRepositoryInterface::class)->projected();
        $policy = $this->container->get(PurchasePolicy::class);

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('محصولات بازارگاه روی فروشگاه', 'tecteb-marketplace-core') . '</h2>';
        if ($products === []) {
            echo '<p>' . esc_html__('هنوز هیچ محصولی به فروشگاه فرستاده نشده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('شناسهٔ ووکامرس', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت خرید', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آنچه مدیر می‌بیند', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آنچه خریدار می‌بیند', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($products as $product) {
            $decision = $policy->decide((int) $product->wcProductId)['decision'];
            $allowed = $decision === PurchasePolicy::ALLOWED;
            echo '<tr><td>' . esc_html($product->details->title) . '</td>'
                . '<td>' . Components::code((string) $product->wcProductId) . '</td>'
                . '<td>' . ($allowed
                    ? esc_html__('قابل خرید', 'tecteb-marketplace-core')
                    : '<strong>' . esc_html__('متوقف', 'tecteb-marketplace-core') . '</strong>') . '</td>'
                . '<td>' . esc_html(PurchaseMessages::manager($decision)) . '</td>'
                . '<td>' . ($allowed ? '—' : esc_html(PurchaseMessages::shopper($decision))) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="tmc-field__desc">'
            . esc_html(sprintf(
                /* translators: %s: number of products */
                __('%s محصول بازارگاه روی فروشگاه است. محصولات خود فروشگاه و دکان در این فهرست نیستند و بازارگاه دربارهٔ آن‌ها تصمیمی نمی‌گیرد.', 'tecteb-marketplace-core'),
                $fa((string) count($products))
            ))
            . '</p></section>';
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('storefront_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_storefront_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $stop = $this->container->get(StorefrontStop::class);
        $actorId = get_current_user_id();
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        $action = $request->postKey('storefront_action');

        if ($action === 'trial_on' || $action === 'trial_off') {
            $trial = $this->container->get(TrialUnlock::class);
            $wanted = $action === 'trial_on';
            // Asked again, not taken from the form: a button rendered before
            // somebody changed the environment in another tab would otherwise
            // write a request this site cannot honour, and report success.
            if ($wanted && !$trial->isPermitted()) {
                return 'err:' . sprintf(
                    /* translators: %s: the resolved environment name */
                    __('محیط «%s» این کلید را نمی‌پذیرد، پس چیزی تغییر نکرد.', 'tecteb-marketplace-core'),
                    $trial->environmentName()
                );
            }
            if (!$trial->request($wanted)) {
                return 'err:' . __('ذخیرهٔ این کلید انجام نشد.', 'tecteb-marketplace-core');
            }
            $this->container->get(AuditLogger::class)->log(
                AuditEventCatalog::ORDER_TRIAL_CHANGED,
                $actorId,
                'storefront',
                TrialUnlock::OPTION,
                [
                    'requested' => $wanted ? '1' : '0',
                    'permitted' => $trial->isPermitted() ? '1' : '0',
                    'environment' => $trial->environmentName(),
                ]
            );
            return 'ok:' . ($wanted
                ? __('حالت آزمایشی سفارش روشن شد. تصمیم‌های باز کنار گذاشته می‌شوند؛ نرخ کمیسیون و دفترکل همچنان بررسی می‌شوند.', 'tecteb-marketplace-core')
                : __('حالت آزمایشی سفارش خاموش شد. تا بسته‌شدن تصمیم‌های باز، ماژول سفارش اجرا نمی‌شود.', 'tecteb-marketplace-core'));
        }

        if ($action === 'release_orders') {
            $result = $stop->releaseOrders($actorId);
            // Two different failures, two different sentences. «برنگشت» asks
            // for a retry; «موجودی جور درنیامد» asks for a person, and telling
            // somebody to press the button again would be the wrong advice.
            if (!$result->ok && $result->code === 'orders_need_reconciliation') {
                return 'err:' . sprintf(
                    /* translators: 1: released count, 2: order numbers to check by hand */
                    __('%1$s سفارش برگشت، ولی موجودیِ سفارش(های) %2$s سرِ جای اولش برنگشت و این بازارگاه آن را خودسرانه درست نمی‌کند. موجودی همین محصول‌ها را در ووکامرس دستی بررسی کنید. هیچ سفارشی لغو یا خالی نشده و هیچ عدد موجودی‌ای بازنویسی نشده است.', 'tecteb-marketplace-core'),
                    $fa((string) $result->context['released']),
                    $fa((string) $result->context['reconcile'])
                );
            }
            if (!$result->ok) {
                return 'err:' . sprintf(
                    /* translators: 1: released count, 2: order numbers still held */
                    __('%1$s سفارش برگشت، ولی سفارش(های) %2$s برنگشتند. وضعیتشان را در ووکامرس بررسی کنید؛ هیچ‌کدام لغو یا خالی نشده‌اند.', 'tecteb-marketplace-core'),
                    $fa((string) $result->context['released']),
                    $fa((string) $result->context['stuck'])
                );
            }
            $movedOn = (int) ($result->context['moved_on'] ?? 0);
            return 'ok:' . sprintf(
                /* translators: %s: number of orders */
                __('%s سفارش نگه‌داشته به وضعیت قبلی‌شان برگشتند و دوباره قابل پرداخت‌اند. فروش بازارگاه همچنان بسته است.', 'tecteb-marketplace-core'),
                $fa((string) $result->context['released'])
            ) . ($movedOn > 0 ? ' ' . sprintf(
                /* translators: %s: how many orders somebody else had already moved */
                __('%s سفارش دیگر را در همین فاصله کسی پرداخت یا لغو کرده بود؛ آن‌ها دست‌نخورده ماندند و فقط نشانهٔ توقف از رویشان برداشته شد.', 'tecteb-marketplace-core'),
                $fa((string) $movedOn)
            ) : '');
        }

        if ($action === 'resolve_reconcile') {
            $result = $stop->resolveReconciliation(
                $request->postInt('reconcile_order'),
                $actorId,
                $request->postTextarea('reconcile_note')
            );
            if (!$result->ok) {
                return 'err:' . ($result->code === 'reconcile_note_required'
                    ? __('بنویسید چه کردید. یادداشتِ خالی یعنی نفر بعدی یک پرچمِ پاک‌شده می‌بیند و نمی‌داند چه اتفاقی افتاده.', 'tecteb-marketplace-core')
                    : __('ثبت نشد. سفارش را در ووکامرس بررسی کنید؛ نشانهٔ بررسی هنوز سرِ جایش است.', 'tecteb-marketplace-core'));
            }
            return 'ok:' . sprintf(
                /* translators: %s: the WooCommerce order number */
                __('سفارش %s بسته شد. یادداشت شما روی خود سفارش در ووکامرس هم ثبت شد، پس بعداً هرکس آن سفارش را ببیند دلیلش را می‌خواند.', 'tecteb-marketplace-core'),
                $fa((string) $result->context['order_id'])
            );
        }

        if ($action === 'resume') {
            $outcome = $stop->resume($actorId);
            $refused = count($outcome['refused']);
            return 'ok:' . sprintf(
                /* translators: 1: published count, 2: refused count, 3: released pay links */
                __('فروش از سر گرفته شد. %1$s محصول به فروشگاه برگشت، %2$s محصول چون شرایطش برقرار نبود بیرون ماند و لینک پرداخت %3$s سفارش پرداخت‌نشده دوباره کار می‌کند.', 'tecteb-marketplace-core'),
                $fa((string) $outcome['published']),
                $fa((string) $refused),
                $fa((string) $outcome['orders_released'])
            );
        }
        // «توقف» and «تلاش دوباره» are the same operation; the second name
        // exists so a manager in the middle of a rollback can run it without
        // wondering whether pressing «توقف» again will reopen anything.
        $result = $stop->stopAsResult(StorefrontSwitch::REASON_MANAGER, $actorId);
        if (!$result->ok) {
            // A partial stop is a failure, and the screen says so in the words
            // that matter: these products are STILL ON SALE, and the person
            // reading this was about to replace the package.
            // Only the clause that has ids in it: «سفارش(های) —» is noise on
            // a screen whose whole job is to be read in a hurry.
            $open = [];
            if ((string) $result->context['stuck'] !== '') {
                $open[] = sprintf(
                    /* translators: %s: marketplace product ids */
                    __('محصول بازارگاه شمارهٔ %s هنوز روی فروشگاه قابل خرید است', 'tecteb-marketplace-core'),
                    $fa((string) $result->context['stuck'])
                );
            }
            if ((string) $result->context['orders_stuck'] !== '') {
                $open[] = sprintf(
                    /* translators: %s: WooCommerce order numbers */
                    __('لینک پرداخت سفارش شمارهٔ %s هنوز کار می‌کند', 'tecteb-marketplace-core'),
                    $fa((string) $result->context['orders_stuck'])
                );
            }
            return 'err:' . sprintf(
                /* translators: 1: open items, 2: withdrawn count */
                __('توقف کامل نشد: %1$s. %2$s محصول بیرون رفت. تا بسته‌شدن این موارد، بستهٔ افزونه را عوض یا غیرفعال نکنید — بدون افزونه چیزی جلوی فروش یا پرداختشان را نمی‌گیرد.', 'tecteb-marketplace-core'),
                implode(__('؛ ', 'tecteb-marketplace-core'), $open),
                $fa((string) $result->context['withdrawn'])
            );
        }
        return 'ok:' . sprintf(
            /* translators: 1: withdrawn count, 2: retired pay links */
            __('فروش بازارگاه متوقف شد: هر %1$s محصول از فروشگاه بیرون رفت و لینک پرداخت %2$s سفارش پرداخت‌نشده بازنشسته شد. هیچ‌چیز حذف یا لغو نشد.', 'tecteb-marketplace-core'),
            $fa((string) $result->context['withdrawn']),
            $fa((string) $result->context['orders_held'])
        );
    }

    /**
     * Marketplace products that are still `publish` in the shop.
     *
     * Asked of WooCommerce, not of our own flag: the flag says what we
     * intended, this says what a shopper would find.
     *
     * @return list<int> WooCommerce product ids
     */
    private function stillOnSale(): array
    {
        if (!function_exists('get_post_status')) {
            return [];
        }
        $stuck = [];
        foreach ($this->container->get(ProductRepositoryInterface::class)->projected() as $product) {
            if (get_post_status((int) $product->wcProductId) === 'publish') {
                $stuck[] = (int) $product->wcProductId;
            }
        }
        return $stuck;
    }

    private static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            StorefrontSwitch::REASON_DEACTIVATED => __('غیرفعال‌شدن افزونه', 'tecteb-marketplace-core'),
            StorefrontSwitch::REASON_MANAGER => __('تصمیم مدیر', 'tecteb-marketplace-core'),
            '' => __('نامشخص', 'tecteb-marketplace-core'),
            default => $reason,
        };
    }
}
