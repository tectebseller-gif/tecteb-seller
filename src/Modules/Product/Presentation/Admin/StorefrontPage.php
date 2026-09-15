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
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontStop;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;
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
        echo '<p class="tmc-field__desc"><strong>'
            . esc_html__('پیش از بازگرداندن بستهٔ قبلی همین دکمه را بزنید.', 'tecteb-marketplace-core')
            . '</strong> '
            . esc_html__('نسخه‌های قدیمی‌تر نه گاردِ خرید دارند و نه ثبت سفارش، پس محصولی که روی فروشگاه بماند بدون هیچ خط دفترکلی فروخته می‌شود.', 'tecteb-marketplace-core')
            . '</p>';

        echo '<form method="post">';
        wp_nonce_field(self::NONCE, 'tmc_storefront_nonce');
        if ($stopped) {
            echo '<p class="tmc-field__desc">'
                . esc_html__('با «از سرگیری»، فقط محصولاتی برمی‌گردند که هنوز شرایطشان برقرار است: فروشنده مجاز به فروش باشد، محصول منتشر باشد و کامل باشد. بقیه با ذکر علت بیرون می‌مانند.', 'tecteb-marketplace-core')
                . '</p>'
                . '<p><button type="submit" name="storefront_action" value="resume" class="tmc-button tmc-button--primary">'
                . esc_html__('از سرگیری فروش بازارگاه', 'tecteb-marketplace-core') . '</button></p>';
        } else {
            echo '<p><button type="submit" name="storefront_action" value="stop" class="tmc-button">'
                . esc_html__('توقف فروش بازارگاه و خارج‌کردن محصول‌ها از فروشگاه', 'tecteb-marketplace-core') . '</button></p>';
        }
        echo '</form></section>';
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
        echo '</section>';
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

        if ($request->postKey('storefront_action') === 'resume') {
            $outcome = $stop->resume($actorId);
            $refused = count($outcome['refused']);
            return 'ok:' . sprintf(
                /* translators: 1: published count, 2: refused count */
                __('فروش از سر گرفته شد. %1$s محصول به فروشگاه برگشت و %2$s محصول چون شرایطش برقرار نبود بیرون ماند.', 'tecteb-marketplace-core'),
                $fa((string) $outcome['published']),
                $fa((string) $refused)
            );
        }
        $outcome = $stop->stop(StorefrontSwitch::REASON_MANAGER, $actorId);
        return 'ok:' . sprintf(
            /* translators: 1: withdrawn count, 2: failed count */
            __('فروش بازارگاه متوقف شد. %1$s محصول از فروشگاه بیرون رفت و %2$s محصول خطا داد؛ هیچ‌کدام حذف نشدند.', 'tecteb-marketplace-core'),
            $fa((string) $outcome['withdrawn']),
            $fa((string) $outcome['failed'])
        );
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
