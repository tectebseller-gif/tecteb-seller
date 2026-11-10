<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Order\Application\OrderAdminUrlInterface;
use Tecteb\Marketplace\Modules\Order\Application\ReconcileOrders;
use Tecteb\Marketplace\Modules\Order\Domain\OrderInconsistency;
use Tecteb\Marketplace\Modules\Order\Presentation\OrderLedgerMessages;
use Tecteb\Marketplace\Modules\Order\Presentation\OrderReconciliationMessages;

/**
 * «تطبیق سفارش و سند» — the owner's §5, as a screen that only reads.
 *
 * ⛔ **THERE IS NO BUTTON ON THIS PAGE THAT CHANGES ANYTHING**, and that is
 * the design rather than an omission: «هیچ اصلاح مالی خودکاری روی داده واقعی
 * انجام نده». The two controls are «check the next window» and «narrow to one
 * shop», both of which are addresses. The service behind it has no writer to
 * give an answer to, so the claim is a property of the code and not a promise
 * this screen keeps.
 *
 * **A clean window is never reported as a clean marketplace.** The page says
 * how many lines it examined, where the cursor reached, whether it reached the
 * end, and — by name — which checks could not run at all. A reconciliation
 * that printed «هیچ مشکلی نیست» after failing to look is the one mistake this
 * control cannot make (`alpha.97`).
 */
final class OrderAuditPage
{
    public const SLUG = 'tmc-order-audit';
    public const CAPABILITY = Capabilities::VIEW_VENDOR_FINANCE;

    /** `null` until asked, `false` when there is no service to ask. */
    private OrderAdminUrlInterface|false|null $orderUrls = null;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('تطبیق سفارش و سند', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('اجازهٔ دیدن این صفحه را ندارید.', 'tecteb-marketplace-core'));
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $request = Request::capture();
        $after = max(0, $request->queryInt('after'));
        $vendorRaw = trim($request->queryText('vendor'));
        $vendor = ctype_digit($vendorRaw) && (int) $vendorRaw > 0 ? (int) $vendorRaw : null;

        echo Components::shellOpen(self::menuLabel(), self::SLUG);
        echo Components::reportNav(self::SLUG);
        echo '<p class="tmc-field__desc">'
            . esc_html__('این صفحه فقط می‌خواند. هیچ سند مالی، وضعیت قلم یا ردیفی را عوض نمی‌کند و هیچ دکمه‌ای برای اصلاح خودکار ندارد — تصمیم با شماست.', 'tecteb-marketplace-core')
            . '</p>';

        $answer = $this->service()->pass($after, $vendor);
        if (!$answer['allowed']) {
            echo Components::state(
                'empty',
                __('این بررسی برای شما باز نیست.', 'tecteb-marketplace-core'),
                __('خواندن ارقام مالی فروشندگان مجوز جداگانه‌ای دارد.', 'tecteb-marketplace-core')
            );
            echo Components::shellClose();
            return;
        }
        if (!$answer['readable']) {
            echo Components::notice(
                'error',
                esc_html__('جدول اقلام خوانده نشد، پس هیچ بررسی‌ای انجام نشد. این پیام یعنی «نتوانستم نگاه کنم»، نه «چیزی پیدا نشد».', 'tecteb-marketplace-core')
            );
            echo Components::shellClose();
            return;
        }

        $this->renderFilter($vendor);
        $this->renderSummary($answer, $fa);
        $this->renderFindings($answer, $fa);
        $this->renderCursor($answer, $vendor, $fa);
        echo Components::moreDetails([
            __('هفت دستهٔ بررسی: سفارش ووکامرس با محصول بازارگاه و بدون قلم · قلم بدون سند مالی · یک سند روی چند قلم · قلم لغوشده بدون سند معکوس · سند معکوس روی قلم لغونشده · سند نامتوازن یا دو‌واحدی · فروشنده یا وضعیت ناسازگار.', 'tecteb-marketplace-core'),
            __('قلمی که هیچ نرخی برایش حل نشده و هیچ سهمی هم ثبت نشده، نقص نیست: قاعدهٔ FIN-02 می‌گوید نرخ حل‌نشده هیچ ثبت نمی‌کند، و گزارش ردیفی همان را «ثبت‌نشده» چاپ می‌کند. این بررسی تنها وقتی ایراد می‌گیرد که عددی ثبت شده باشد و سندی پشتش نباشد.', 'tecteb-marketplace-core'),
            __('بررسی سمت سفارش‌های ووکامرس به خودِ ووکامرس نیاز دارد و پنجره‌اش محدود است. اگر اجرا نشود، با نام گفته می‌شود و هیچ‌وقت به‌عنوان «سالم» شمرده نمی‌شود.', 'tecteb-marketplace-core'),
        ]);
        echo Components::shellClose();
    }

    private function renderFilter(?int $vendor): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('محدود کردن به یک فروشگاه', 'tecteb-marketplace-core') . '</h2>';
        echo '<form method="get" class="tmc-filter-form">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        echo '<p class="tmc-field"><label class="tmc-field__label" for="tmc-oa-vendor">'
            . esc_html__('شناسهٔ فروشگاه', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" id="tmc-oa-vendor" type="number" name="vendor" value="'
            . esc_attr($vendor === null ? '' : (string) $vendor) . '"></p>';
        echo '<div class="tmc-filter-form__actions">';
        echo '<button type="submit" class="tmc-button">' . esc_html__('بررسی', 'tecteb-marketplace-core') . '</button>';
        echo '<a class="tmc-button tmc-button--quiet" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
            . esc_html__('همهٔ فروشگاه‌ها', 'tecteb-marketplace-core') . '</a>';
        echo '</div></form></section>';
    }

    /**
     * @param array<string,mixed> $answer
     * @param callable(string|int):string $fa
     */
    private function renderSummary(array $answer, callable $fa): void
    {
        /** @var array<string,int> $counts */
        $counts = $answer['counts'];
        $total = array_sum($counts);
        /** @var array{checked:bool, reason:string, orders:int} $scan */
        $scan = $answer['order_scan'];

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('نتیجهٔ این پنجره', 'tecteb-marketplace-core') . '</h2>';

        if ($total === 0) {
            echo Components::notice(
                $scan['checked'] ? 'success' : 'warning',
                esc_html(sprintf(
                    /* translators: %s: how many lines were examined */
                    __('در %s قلم بررسی‌شدهٔ این پنجره هیچ ناسازگاری پیدا نشد.', 'tecteb-marketplace-core'),
                    $fa((string) $answer['examined'])
                ))
            );
        } else {
            echo Components::notice('warning', esc_html(sprintf(
                /* translators: 1: findings, 2: lines examined */
                __('%1$s ناسازگاری در %2$s قلم بررسی‌شدهٔ این پنجره.', 'tecteb-marketplace-core'),
                $fa((string) $total),
                $fa((string) $answer['examined'])
            )));
        }

        // ⛔ THE SKIPPED CHECKS, BY NAME AND BEFORE THE FIGURES. A window with
        // no findings and a check that did not run is not a clean window.
        if (!$scan['checked']) {
            echo Components::notice('warning', esc_html(sprintf(
                /* translators: 1: the check's name, 2: why it did not run */
                __('این بررسی اجرا نشد: «%1$s» — %2$s این یعنی «نتوانستم نگاه کنم»، نه «چیزی نیست».', 'tecteb-marketplace-core'),
                OrderReconciliationMessages::title(OrderInconsistency::OrderWithoutLine->value),
                OrderReconciliationMessages::scanReason((string) $scan['reason'])
            )));
        }

        $rows = [];
        foreach (OrderInconsistency::findings() as $class) {
            $rows[] = [
                'label' => OrderReconciliationMessages::title($class->value),
                'value' => $class === OrderInconsistency::OrderWithoutLine && !$scan['checked']
                    ? __('بررسی نشد', 'tecteb-marketplace-core')
                    : $fa((string) ($counts[$class->value] ?? 0)),
            ];
        }
        $rows[] = [
            'label' => __('اقلام بررسی‌شده در این پنجره', 'tecteb-marketplace-core'),
            'value' => $fa((string) $answer['examined']),
        ];
        if ($scan['checked']) {
            // ⛔ THE CEILING, SAID OUT LOUD. Until `alpha.103` this row read
            // «۱۰۰ سفارش ووکامرس خوانده شد» and stopped there — which on a
            // shop with five hundred orders is read as «I looked at all of
            // them». `alpha.100`'s rule: a silent ceiling is worse than a
            // stated one, because the reader cannot tell it is there.
            $rows[] = [
                'label' => sprintf(
                    /* translators: %s: the announced ceiling, in Persian digits */
                    __('سفارش‌های ووکامرس خوانده‌شده (سقف اعلام‌شده: %s سفارش آخر)', 'tecteb-marketplace-core'),
                    $fa((string) ReconcileOrders::ORDER_WINDOW)
                ),
                'value' => $fa((string) $scan['orders']),
            ];
        }
        $rows[] = [
            'label' => __('به پایان اقلام رسید؟', 'tecteb-marketplace-core'),
            'value' => $answer['finished']
                ? __('بله', 'tecteb-marketplace-core')
                : __('نه — پنجرهٔ بعد را بررسی کنید', 'tecteb-marketplace-core'),
        ];
        echo Components::dataList($rows);
        echo '</section>';
    }

    /**
     * @param array<string,mixed> $answer
     * @param callable(string|int):string $fa
     */
    private function renderFindings(array $answer, callable $fa): void
    {
        /** @var list<array<string,mixed>> $findings */
        $findings = $answer['findings'];
        if ($findings === []) {
            return;
        }
        // Grouped by class, because the advice is per class and repeating a
        // paragraph of it on forty rows is the wall of prose `alpha.85`
        // folded away everywhere else.
        $byClass = [];
        foreach ($findings as $finding) {
            $byClass[(string) $finding['class']][] = $finding;
        }
        foreach ($byClass as $class => $rows) {
            echo '<section class="tmc-card"><h2 class="tmc-card__title">'
                . esc_html(OrderReconciliationMessages::title($class))
                . ' — ' . esc_html($fa((string) count($rows))) . '</h2>';
            echo '<p class="tmc-field__desc">'
                . esc_html(OrderReconciliationMessages::advice($class)) . '</p>';
            echo '<div class="tmc-scroll" role="region" tabindex="0" aria-label="'
                . esc_attr(OrderReconciliationMessages::title($class)) . '">';
            echo '<table class="widefat tmc-table tmc-ledger"><thead><tr>';
            foreach ([
                __('سفارش', 'tecteb-marketplace-core'),
                __('قلم', 'tecteb-marketplace-core'),
                __('محصول', 'tecteb-marketplace-core'),
                __('فروشگاه', 'tecteb-marketplace-core'),
                __('وضعیت قلم', 'tecteb-marketplace-core'),
                __('کلید سند', 'tecteb-marketplace-core'),
            ] as $heading) {
                echo '<th scope="col">' . esc_html($heading) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $orderId = (int) $row['wc_order_id'];
                $url = $this->orderUrl($orderId);
                $number = '#' . esc_html($fa((string) $orderId));
                echo '<tr>'
                    . '<td data-label="' . esc_attr__('سفارش', 'tecteb-marketplace-core') . '">'
                    . ($url === null ? $number : '<a href="' . esc_url($url) . '">' . $number . '</a>')
                    . '</td>'
                    . '<td class="tmc-ledger__num" data-label="' . esc_attr__('قلم', 'tecteb-marketplace-core') . '">'
                    . esc_html((int) $row['line_id'] === 0 ? '—' : $fa((string) $row['line_id'])) . '</td>'
                    . '<td data-label="' . esc_attr__('محصول', 'tecteb-marketplace-core') . '">'
                    . esc_html((string) $row['title'] === '' ? '—' : (string) $row['title']) . '</td>'
                    . '<td data-label="' . esc_attr__('فروشگاه', 'tecteb-marketplace-core') . '">'
                    . esc_html((int) $row['vendor_user_id'] === 0 ? '—' : '#' . $fa((string) $row['vendor_user_id']))
                    . '</td>'
                    . '<td data-label="' . esc_attr__('وضعیت قلم', 'tecteb-marketplace-core') . '">'
                    . esc_html((string) $row['status'] === ''
                        ? '—'
                        : OrderLedgerMessages::shipping((string) $row['status'])) . '</td>'
                    . '<td data-label="' . esc_attr__('کلید سند', 'tecteb-marketplace-core') . '">'
                    . ((string) $row['ledger_event'] === ''
                        ? '—'
                        : Components::code((string) $row['ledger_event'])) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table></div></section>';
        }
    }

    /**
     * @param array<string,mixed> $answer
     * @param callable(string|int):string $fa
     */
    private function renderCursor(array $answer, ?int $vendor, callable $fa): void
    {
        if ($answer['finished']) {
            return;
        }
        // ⛔ PRINTED ONLY WHEN IT GOES SOMEWHERE. A «next window» link built
        // from a cursor that did not move points at the window you are on,
        // which is how `alpha.85` turned a finished scan into an endless one.
        $cursor = (int) $answer['cursor'];
        if ($cursor <= 0) {
            return;
        }
        $args = ['page' => self::SLUG, 'after' => (string) $cursor];
        if ($vendor !== null) {
            $args['vendor'] = (string) $vendor;
        }
        echo '<p><a class="tmc-button" href="' . esc_url(admin_url('admin.php?' . http_build_query($args))) . '">'
            . esc_html__('بررسی پنجرهٔ بعد', 'tecteb-marketplace-core') . '</a></p>';
        echo '<p class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: %s: the last line id examined */
            __('این پنجره تا قلم شمارهٔ %s بررسی شد. تا وقتی این دکمه هست، اقلام بررسی‌نشده باقی مانده‌اند.', 'tecteb-marketplace-core'),
            $fa((string) $cursor)
        )) . '</p>';
    }

    private function orderUrl(int $wcOrderId): ?string
    {
        if ($this->orderUrls === null) {
            try {
                $this->orderUrls = $this->container->has(OrderAdminUrlInterface::class)
                    ? $this->container->get(OrderAdminUrlInterface::class)
                    : false;
            } catch (\Throwable) {
                $this->orderUrls = false;
            }
        }
        return $this->orderUrls === false ? null : $this->orderUrls->editUrl($wcOrderId);
    }

    private function service(): ReconcileOrders
    {
        return $this->container->get(ReconcileOrders::class);
    }
}
