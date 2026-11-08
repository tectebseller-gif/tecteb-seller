<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Core\Support\SiteTime;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText;
use Tecteb\Marketplace\Modules\Order\Application\OrderLedgerReport;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\OrderLedgerFilter;
use Tecteb\Marketplace\Modules\Order\Presentation\OrderLedgerMessages;

/**
 * «سفارش‌ها — ردیف به ردیف» — every recorded line, filterable.
 *
 * ⛔ **The owner's §7.** The reports page gives totals, and «مدیر باید بتواند
 * هر محصول و هر سفارش را به‌صورت جداگانه بررسی کند… گزارش فعلی فقط جمع کل است
 * و در مقیاس بزرگ قابل استفاده نیست». The totals stay exactly where they are:
 * this is another reader over the same table.
 *
 * **Everything is server-side.** The filters are a `WHERE`, the page is a
 * `LIMIT`, the count is a `COUNT(*)`, and the sort is one of six fixed
 * expressions — so this screen costs the same on a shop with fifty lines and
 * on one with fifty thousand. Nothing here loads a WooCommerce order or a
 * product: the link to an order is BUILT rather than fetched, which is the
 * difference between a page and the one `alpha.96` had to take apart.
 *
 * **Three totals, never one.** «نمایش شفاف جمع تاریخی، جمع معتبر فعلی و مانده
 * در انتظار» — and they are the totals of the PAGE, said so in words, because
 * a figure that claimed to be the whole filtered set while being one page of
 * it is the kind of number somebody acts on.
 *
 * ⛔ **No customer data, because the table has none.** `tmc_order_items` holds
 * no name, no address, no telephone and no e-mail, and this page reads nothing
 * else — so «هیچ اطلاعات محرمانه مشتری … افشا نشود» is a property of the data
 * rather than a rule a screen keeps.
 *
 * **Nothing here changes anything.** There is no status button, no settlement
 * button and no refund: those live on the screens that own them, and a report
 * that could act would be a second path to a financial decision.
 */
final class OrderLedgerPage
{
    public const SLUG = 'tmc-order-ledger';
    public const CAPABILITY = Capabilities::VIEW_VENDOR_FINANCE;
    public const EXPORT_ACTION = 'tmc_order_ledger_csv';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('سفارش‌ها ردیف به ردیف', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('اجازهٔ دیدن این صفحه را ندارید.', 'tecteb-marketplace-core'));
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $request = Request::capture();
        $filter = OrderLedgerFilter::fromInput($this->input($request));
        $answer = $this->report()->forManager($filter);

        echo Components::shellOpen(
            __('سفارش‌ها ردیف به ردیف', 'tecteb-marketplace-core'),
            self::SLUG
        );

        if (!$answer['allowed']) {
            // Should be unreachable behind the capability check above, and said
            // plainly rather than rendered as an empty table: «no rows» and
            // «you may not read these rows» are different sentences and only
            // one of them is about the data.
            echo Components::state(
                'empty',
                __('این گزارش برای شما باز نیست.', 'tecteb-marketplace-core'),
                __('خواندن ارقام مالی فروشندگان مجوز جداگانه‌ای دارد.', 'tecteb-marketplace-core')
            );
            echo Components::shellClose();
            return;
        }

        echo '<p class="tmc-field__desc">'
            . esc_html__('هر ردیف این جدول یک قلم سفارش است. فیلترها و صفحه‌بندی روی سرور اجرا می‌شوند، پس این صفحه با بزرگ‌شدن کاتالوگ کند نمی‌شود.', 'tecteb-marketplace-core')
            . '</p>';

        $this->renderFilters($filter);
        $this->renderTotals($answer['rows'], $fa);
        $this->renderTable($answer['rows'], $fa);
        $this->renderPager($filter, $answer['total'], $fa);
        $this->renderExport($filter);
        echo Components::moreDetails([
            __('«جمع تاریخی فروش» شامل اقلام لغوشده هم هست، چون آن اقلام یک بار فروخته شده‌اند و سابقه‌شان پاک نمی‌شود. «جمع معتبر فعلی» همان جمع بدون اقلام لغوشده است، یعنی تعهدی که هنوز برجاست. این دو عدد عمداً جدا هستند و جمع‌کردنشان معنایی ندارد.', 'tecteb-marketplace-core'),
            __('سه عدد بالای جدول جمع همین صفحه‌اند، نه جمع کل فیلتر. برای جمع کل یک فروشگاه، صفحهٔ گزارش‌ها و صفحهٔ تسویه همان ارقام را روی کل ردیف‌ها حساب می‌کنند.', 'tecteb-marketplace-core')
            ,
            __('این جدول هیچ اطلاعات مشتری ندارد — نه نام، نه نشانی، نه تلفن و نه ایمیل. جدولی که این صفحه از آن می‌خواند چنین ستونی ندارد.', 'tecteb-marketplace-core'),
            __('تخفیف و هزینهٔ ارسال در این جدول ستون جداگانه ندارند: «مبلغ فروش» هر ردیف همان مبلغ پس از تخفیف است و هزینهٔ ارسال در ووکامرس روی کل سفارش ثبت می‌شود، نه روی قلم. برای دیدن آن‌ها سفارش را در ووکامرس باز کنید.', 'tecteb-marketplace-core'),
        ]);
        echo Components::shellClose();
    }

    /**
     * The CSV, sent and done.
     *
     * Not a redirect: a download has no next page. It reads the SAME filter
     * the table did — «خروجی CSV مطابق فیلترهای فعال» — by building it from
     * the same request through the same value object, so the file and the
     * screen cannot disagree about which rows they are about.
     */
    public function export(): void
    {
        $request = Request::capture();
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('اجازهٔ دریافت این خروجی را ندارید.', 'tecteb-marketplace-core'));
        }
        check_admin_referer(self::EXPORT_ACTION);
        $filter = OrderLedgerFilter::fromInput($this->input($request));
        $answer = $this->report()->forManager($filter);
        if (!$answer['allowed']) {
            wp_die(esc_html__('اجازهٔ دریافت این خروجی را ندارید.', 'tecteb-marketplace-core'));
        }
        $csv = $this->csv($answer['rows']);
        // Recorded, because an export of every line's commission and share is
        // worth a trail even though it changes nothing: «خروجی CSV قابل
        // سوءاستفاده نباشد» is partly a question of who took one.
        $this->container->get(AuditLogger::class)->log(
            AuditEventCatalog::ORDER_LEDGER_EXPORTED,
            get_current_user_id(),
            'vendor',
            (string) ($filter->vendorUserId ?? 0),
            ['vendor_id' => $filter->vendorUserId ?? 0, 'rows' => count($answer['rows'])]
        );
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tecteb-order-lines-' . gmdate('Ymd-His') . '.csv"');
        header('Content-Length: ' . strlen($csv));
        header('X-Content-Type-Options: nosniff');
        echo $csv;
        exit;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function csv(array $rows): string
    {
        $out = [];
        $out[] = [
            'order_id', 'order_item_id', 'product_id', 'variation_id', 'vendor_user_id',
            'title', 'sku', 'quantity', 'recorded_at_local', 'base_minor', 'tax_minor',
            'commission_kind', 'rate_bp', 'commission_minor', 'vendor_share_minor',
            'currency', 'exponent', 'payment_state', 'shipping_state',
            'financial_state', 'reversal_event', 'settlement_state',
        ];
        foreach ($rows as $row) {
            $out[] = [
                (string) $row['wc_order_id'],
                (string) $row['wc_order_item_id'],
                (string) $row['wc_product_id'],
                $row['variation_id'] === null ? '' : (string) $row['variation_id'],
                (string) $row['vendor_user_id'],
                (string) $row['title'],
                (string) $row['sku'],
                (string) $row['quantity'],
                $this->localTime((string) $row['created_at']),
                (string) $row['base_minor'],
                (string) $row['tax_minor'],
                (string) $row['commission_kind'],
                $row['rate_bp'] === null ? '' : (string) $row['rate_bp'],
                // ⛔ EMPTY, not nought. An unresolvable rate records nothing
                // rather than zero (FIN-02), and a spreadsheet that read `0`
                // would sum it as «no commission was due».
                $row['commission_minor'] === null ? '' : (string) $row['commission_minor'],
                $row['vendor_share_minor'] === null ? '' : (string) $row['vendor_share_minor'],
                (string) ($row['currency'] ?? ''),
                $row['exponent'] === null ? '' : (string) $row['exponent'],
                (string) $row['wc_disposition'],
                (string) $row['item_status'],
                (string) $row['financial_state'],
                (string) $row['reversal_event'],
                (string) $row['settlement_state'],
            ];
        }
        $csv = '';
        foreach ($out as $line) {
            $csv .= implode(',', array_map([self::class, 'cell'], $line)) . "\n";
        }
        // The BOM, so Excel opens Persian text as UTF-8 rather than as
        // mojibake — the same thing the product export does.
        return "\u{FEFF}" . $csv;
    }

    /**
     * One CSV cell, quoted — and never a formula.
     *
     * A value beginning `=`, `+`, `-` or `@` is a formula to every spreadsheet
     * that opens the file, so it is prefixed with a tab the way the product
     * export does. Nothing in these columns should be able to begin that way;
     * the title is a vendor's own text and that is exactly why it is not
     * trusted to.
     */
    private static function cell(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if ($value !== '' && str_contains('=+-@', $value[0])) {
            $value = "\t" . $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /** @param array<string,mixed> $filterInput */
    private function renderFilters(OrderLedgerFilter $filter): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('محدود کردن فهرست', 'tecteb-marketplace-core') . '</h2>';
        echo '<form method="get" class="tmc-filter-form">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        foreach ([
            ['vendor', __('شناسهٔ فروشگاه', 'tecteb-marketplace-core'), 'number', $filter->vendorUserId],
            ['order', __('شناسهٔ سفارش', 'tecteb-marketplace-core'), 'number', $filter->wcOrderId],
            ['product', __('شناسهٔ محصول (والد)', 'tecteb-marketplace-core'), 'number', $filter->wcProductId],
            ['variation', __('شناسهٔ تنوع', 'tecteb-marketplace-core'), 'number', $filter->variationId],
            ['title', __('نام محصول', 'tecteb-marketplace-core'), 'search', $filter->title],
            ['sku', __('کد کالا (SKU)', 'tecteb-marketplace-core'), 'search', $filter->sku],
            ['from', __('از تاریخ', 'tecteb-marketplace-core'), 'date', $filter->fromLocalDate],
            ['to', __('تا تاریخ', 'tecteb-marketplace-core'), 'date', $filter->toLocalDate],
            ['min_amount', __('مبلغ فروش از', 'tecteb-marketplace-core'), 'text', $filter->minAmountMinor],
            ['max_amount', __('مبلغ فروش تا', 'tecteb-marketplace-core'), 'text', $filter->maxAmountMinor],
        ] as [$name, $label, $type, $value]) {
            echo '<p class="tmc-field"><label class="tmc-field__label" for="tmc-ol-' . esc_attr($name) . '">'
                . esc_html($label) . '</label>'
                . '<input class="tmc-input" id="tmc-ol-' . esc_attr($name) . '" type="' . esc_attr($type) . '"'
                . ' name="' . esc_attr($name) . '" value="' . esc_attr((string) ($value ?? '')) . '"></p>';
        }
        $this->select('payment', __('وضعیت پرداخت', 'tecteb-marketplace-core'), $filter->payment, [
            'paid' => __('پرداخت‌شده', 'tecteb-marketplace-core'),
            'unpaid' => __('پرداخت‌نشده یا ناموفق', 'tecteb-marketplace-core'),
            'void' => __('لغوشده', 'tecteb-marketplace-core'),
            'refunded' => __('بازپرداخت‌شده در ووکامرس', 'tecteb-marketplace-core'),
            'unknown' => __('نامعلوم', 'tecteb-marketplace-core'),
        ]);
        $shippingOptions = [];
        foreach (OrderItemStatus::all() as $status) {
            $shippingOptions[$status->value] = OrderLedgerMessages::shipping($status->value);
        }
        $this->select(
            'shipping',
            __('وضعیت قلم فروشنده', 'tecteb-marketplace-core'),
            $filter->shipping?->value ?? '',
            $shippingOptions
        );
        $this->select('commission', __('نوع کمیسیون', 'tecteb-marketplace-core'), $filter->commissionKind, [
            'percent' => __('درصدی', 'tecteb-marketplace-core'),
            'fixed' => __('مبلغ ثابت', 'tecteb-marketplace-core'),
            'none' => __('بدون کمیسیون', 'tecteb-marketplace-core'),
            'unrecorded' => __('ثبت‌نشده', 'tecteb-marketplace-core'),
        ]);
        $this->select('sort', __('ترتیب', 'tecteb-marketplace-core'), $filter->sort === 'recent' ? '' : $filter->sort, [
            'oldest' => __('قدیمی‌ترین', 'tecteb-marketplace-core'),
            'amount_high' => __('بیشترین مبلغ', 'tecteb-marketplace-core'),
            'amount_low' => __('کمترین مبلغ', 'tecteb-marketplace-core'),
            'order' => __('شمارهٔ سفارش', 'tecteb-marketplace-core'),
            'share_high' => __('بیشترین سهم فروشنده', 'tecteb-marketplace-core'),
        ], __('تازه‌ترین', 'tecteb-marketplace-core'));
        $this->select('per_page', __('تعداد ردیف', 'tecteb-marketplace-core'), (string) $filter->perPage, [
            '25' => PersianDigits::toPersian('25'),
            '50' => PersianDigits::toPersian('50'),
            '100' => PersianDigits::toPersian('100'),
        ], '');
        echo '<button type="submit" class="tmc-button">' . esc_html__('اعمال', 'tecteb-marketplace-core') . '</button>';
        echo ' <a class="tmc-button tmc-button--quiet" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
            . esc_html__('پاک‌کردن فیلترها', 'tecteb-marketplace-core') . '</a>';
        echo '</form></section>';
    }

    /** @param array<string,string> $options */
    private function select(string $name, string $label, string $current, array $options, string $empty = ''): void
    {
        echo '<p class="tmc-field"><label class="tmc-field__label" for="tmc-ol-' . esc_attr($name) . '">'
            . esc_html($label) . '</label>'
            . '<select class="tmc-select" id="tmc-ol-' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        if ($empty !== '' || $current === '') {
            echo '<option value="">' . esc_html($empty === '' ? __('همه', 'tecteb-marketplace-core') : $empty) . '</option>';
        } else {
            echo '<option value="">' . esc_html__('همه', 'tecteb-marketplace-core') . '</option>';
        }
        foreach ($options as $value => $text) {
            echo '<option value="' . esc_attr((string) $value) . '"' . selected($current, (string) $value, false) . '>'
                . esc_html($text) . '</option>';
        }
        echo '</select></p>';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(string|int):string $fa
     */
    private function renderTotals(array $rows, callable $fa): void
    {
        $totals = OrderLedgerReport::totalsOf($rows);
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('جمع همین صفحه', 'tecteb-marketplace-core') . '</h2>';
        if ($totals['refused'] !== '') {
            echo Components::notice(
                'warning',
                esc_html__('ردیف‌های این صفحه در دو ارز مختلف ثبت شده‌اند، پس جمعی برایشان وجود ندارد. با فیلتر فروشگاه، هر دفتر را جدا ببینید.', 'tecteb-marketplace-core')
            );
            echo '</section>';
            return;
        }
        $rowsOut = [];
        foreach (['historic', 'valid', 'pending'] as $which) {
            $minor = $totals[$which];
            $exponent = $totals['exponent'];
            $text = $minor === null || $exponent === null
                ? __('نامعلوم', 'tecteb-marketplace-core')
                : ((string) (MoneyText::format($minor, $exponent) ?? __('نامعلوم', 'tecteb-marketplace-core')))
                  . ' ' . $totals['currency'];
            $rowsOut[] = ['label' => OrderLedgerMessages::totalLabel($which), 'value' => $text];
        }
        $rowsOut[] = [
            'label' => __('تعداد ردیف در این صفحه', 'tecteb-marketplace-core'),
            'value' => $fa((string) $totals['lines']),
        ];
        echo Components::dataList($rowsOut);
        echo '</section>';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(string|int):string $fa
     */
    private function renderTable(array $rows, callable $fa): void
    {
        if ($rows === []) {
            echo Components::state(
                'empty',
                __('با این فیلترها ردیفی پیدا نشد.', 'tecteb-marketplace-core'),
                __('فیلترها را بردارید یا بازهٔ تاریخ را گسترده‌تر کنید.', 'tecteb-marketplace-core')
            );
            return;
        }
        echo '<div class="tmc-scroll" role="region" tabindex="0" aria-label="'
            . esc_attr__('جدول اقلام سفارش', 'tecteb-marketplace-core') . '">';
        echo '<table class="widefat tmc-table"><thead><tr>';
        foreach ([
            __('سفارش', 'tecteb-marketplace-core'),
            __('محصول و مدل', 'tecteb-marketplace-core'),
            __('فروشگاه', 'tecteb-marketplace-core'),
            __('تاریخ ثبت', 'tecteb-marketplace-core'),
            __('تعداد', 'tecteb-marketplace-core'),
            __('مبلغ فروش', 'tecteb-marketplace-core'),
            __('مالیات', 'tecteb-marketplace-core'),
            __('کمیسیون', 'tecteb-marketplace-core'),
            __('سهم فروشنده', 'tecteb-marketplace-core'),
            __('پرداخت', 'tecteb-marketplace-core'),
            __('قلم فروشنده', 'tecteb-marketplace-core'),
            __('سند مالی', 'tecteb-marketplace-core'),
            __('تسویه', 'tecteb-marketplace-core'),
        ] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>' . $this->cells($row, $fa) . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(string|int):string $fa
     */
    private function cells(array $row, callable $fa): string
    {
        $exponent = $row['exponent'] === null ? 0 : (int) $row['exponent'];
        $money = static function (?int $minor) use ($exponent, $fa): string {
            // ⛔ «ثبت‌نشده» rather than «۰». FIN-02's rule, on a screen.
            if ($minor === null) {
                return esc_html__('ثبت‌نشده', 'tecteb-marketplace-core');
            }
            $text = MoneyText::format($minor, $exponent);
            return $text === null ? esc_html__('نامعلوم', 'tecteb-marketplace-core') : esc_html($text);
        };
        $orderId = (int) $row['wc_order_id'];
        // BUILT, not fetched. A direct link to the order in WooCommerce costs
        // nothing; loading the order to find out whether it still exists would
        // cost one query per row, which is the whole reason this page is usable.
        $orderLink = '<a href="' . esc_url(admin_url('post.php?post=' . $orderId . '&action=edit')) . '">#'
            . esc_html($fa((string) $orderId)) . '</a>';

        $product = esc_html($row['title'] === '' ? __('بدون عنوان', 'tecteb-marketplace-core') : (string) $row['title']);
        $model = $row['variation_id'] === null
            ? ''
            : '<br><span class="tmc-field__desc">' . esc_html(sprintf(
                /* translators: %s: the WooCommerce variation id */
                __('تنوع #%s', 'tecteb-marketplace-core'),
                $fa((string) $row['variation_id'])
            )) . '</span>';
        $sku = $row['sku'] === ''
            ? ''
            : '<br>' . Components::code((string) $row['sku']);

        return '<td>' . $orderLink . '</td>'
            . '<td>' . $product . $model . $sku . '</td>'
            . '<td>#' . esc_html($fa((string) $row['vendor_user_id'])) . '</td>'
            . '<td>' . esc_html($fa($this->localTime((string) $row['created_at']))) . '</td>'
            . '<td>' . esc_html($fa((string) $row['quantity'])) . '</td>'
            . '<td>' . $money((int) $row['base_minor']) . '</td>'
            . '<td>' . $money((int) $row['tax_minor']) . '</td>'
            . '<td>' . $money($row['commission_minor'] === null ? null : (int) $row['commission_minor'])
            . '<br><span class="tmc-field__desc">'
            . esc_html(OrderLedgerMessages::commissionKind(
                (string) $row['commission_kind'],
                $row['rate_bp'] === null ? null : (int) $row['rate_bp']
            ))
            . '</span></td>'
            . '<td>' . $money($row['vendor_share_minor'] === null ? null : (int) $row['vendor_share_minor']) . '</td>'
            . '<td>' . esc_html(OrderLedgerMessages::payment(
                $row['wc_order_status'] === null ? null : (string) $row['wc_order_status'],
                (bool) $row['wc_status_readable']
            )) . '</td>'
            . '<td>' . esc_html(OrderLedgerMessages::shipping((string) $row['item_status'])) . '</td>'
            . '<td>' . esc_html(OrderLedgerMessages::financial((string) $row['financial_state'])) . '</td>'
            . '<td>' . esc_html(OrderLedgerMessages::settlement((string) $row['settlement_state'])) . '</td>';
    }

    /** @param callable(string|int):string $fa */
    private function renderPager(OrderLedgerFilter $filter, ?int $total, callable $fa): void
    {
        if ($total === null) {
            // ⛔ «I could not count» is not «nought». A pager built from a
            // failed count would say «صفحهٔ ۱ از ۱» about a table nobody could
            // read.
            echo Components::notice(
                'warning',
                esc_html__('تعداد کل ردیف‌ها خوانده نشد، پس صفحه‌بندی نمایش داده نمی‌شود. همین صفحه معتبر است.', 'tecteb-marketplace-core')
            );
            return;
        }
        $pages = max(1, (int) ceil($total / $filter->perPage));
        $base = admin_url('admin.php?' . http_build_query(array_merge(['page' => self::SLUG], $filter->toQuery())));
        echo '<nav class="tmc-pager" aria-label="' . esc_attr__('صفحه‌بندی اقلام سفارش', 'tecteb-marketplace-core') . '">';
        if ($filter->page > 1) {
            echo '<a class="tmc-button tmc-button--quiet" href="'
                . esc_url(admin_url('admin.php?' . http_build_query(array_merge(
                    ['page' => self::SLUG],
                    $filter->onPage($filter->page - 1)->toQuery()
                )))) . '">' . esc_html__('صفحهٔ قبل', 'tecteb-marketplace-core') . '</a>';
        }
        echo '<span class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: 1: current page, 2: total pages, 3: total rows */
            __('صفحهٔ %1$s از %2$s — %3$s ردیف با این فیلترها', 'tecteb-marketplace-core'),
            $fa((string) $filter->page),
            $fa((string) $pages),
            $fa((string) $total)
        )) . '</span>';
        if ($filter->page < $pages) {
            echo '<a class="tmc-button tmc-button--quiet" href="'
                . esc_url(admin_url('admin.php?' . http_build_query(array_merge(
                    ['page' => self::SLUG],
                    $filter->onPage($filter->page + 1)->toQuery()
                )))) . '">' . esc_html__('صفحهٔ بعد', 'tecteb-marketplace-core') . '</a>';
        }
        echo '</nav>';
        unset($base);
    }

    private function renderExport(OrderLedgerFilter $filter): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EXPORT_ACTION) . '">';
        foreach ($filter->toQuery() as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        wp_nonce_field(self::EXPORT_ACTION);
        echo '<button type="submit" class="tmc-button tmc-button--quiet">'
            . esc_html__('دریافت CSV همین فیلترها', 'tecteb-marketplace-core') . '</button>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('خروجی دقیقاً همان فیلترهای بالا را دارد و تا سقف تعداد ردیف همین صفحه است.', 'tecteb-marketplace-core')
            . '</p>';
        echo '</form>';
    }

    /**
     * A stored UTC timestamp, in the site's own zone.
     *
     * The same object the audit page and the report's date filters use, so the
     * day a row is printed on and the day it is found on are one decision.
     */
    private function localTime(string $utc): string
    {
        if (trim($utc) === '') {
            return '—';
        }
        try {
            return $this->time()->render(new \DateTimeImmutable($utc, new \DateTimeZone('UTC')), 'Y-m-d H:i');
        } catch (\Exception) {
            return '—';
        }
    }

    private function time(): SiteTime
    {
        try {
            return $this->container->has(SiteTime::class)
                ? $this->container->get(SiteTime::class)
                : SiteTime::utc();
        } catch (\Throwable) {
            return SiteTime::utc();
        }
    }

    /** @return array<string,string|int> */
    private function input(Request $request): array
    {
        $out = [];
        foreach ([
            'vendor', 'order', 'product', 'variation', 'title', 'sku',
            'payment', 'shipping', 'commission', 'from', 'to',
            'min_amount', 'max_amount', 'sort', 'per_page', 'paged',
        ] as $key) {
            // `queryText()` for every one of them, including the numeric ones:
            // the value object decides what is a number and what is absent,
            // and a `(int)` cast here would make «0» and «not typed» the same
            // value one layer too early.
            $out[$key] = $request->isPost() ? $request->postText($key) : $request->queryText($key);
        }
        return $out;
    }

    private function report(): OrderLedgerReport
    {
        return $this->container->get(OrderLedgerReport::class);
    }
}
