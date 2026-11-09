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
use Tecteb\Marketplace\Modules\Order\Application\OrderAdminUrlInterface;
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

    /** `null` until asked, `false` when there is no service to ask. */
    private OrderAdminUrlInterface|false|null $orderUrls = null;

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
        echo Components::reportNav(self::SLUG);

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
        // ⛔ THE WHOLE FILTERED SET FIRST, THEN THE PAGE. The three figures
        // above the table are the PAGE's, said so in words — and a manager
        // who filtered to one product wants the product's total, not the
        // total of the twenty-five rows that happened to fit. Both are
        // printed, each labelled with what it is about.
        $this->renderSummary($filter, $fa);
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
        // ⛔ TWO SCOPES, BOTH NAMED. «کاربر باید بتواند انتخاب کند همین صفحه
        // یا تمام نتایج فیلترشده را خروجی بگیرد» — until this release there
        // was only one, and it was the page, while the button said «همین
        // فیلترها». The default here is the whole set, because that is what
        // the words on the button have always promised.
        $wholeSet = $request->postText('scope') !== 'page';

        $rowsOut = 0;
        $finished = true;
        $readable = true;
        $lines = [self::header()];

        if ($wholeSet) {
            $answer = $this->report()->sweepForManager(
                $filter,
                function (array $batch) use (&$lines, &$rowsOut): void {
                    foreach ($batch as $row) {
                        $lines[] = $this->row($row);
                        $rowsOut++;
                    }
                },
                OrderLedgerReport::SWEEP_CAP
            );
            if (!$answer['allowed']) {
                wp_die(esc_html__('اجازهٔ دریافت این خروجی را ندارید.', 'tecteb-marketplace-core'));
            }
            $finished = $answer['finished'];
            $readable = $answer['readable'];
        } else {
            $answer = $this->report()->forManager($filter);
            if (!$answer['allowed']) {
                wp_die(esc_html__('اجازهٔ دریافت این خروجی را ندارید.', 'tecteb-marketplace-core'));
            }
            foreach ($answer['rows'] as $row) {
                $lines[] = $this->row($row);
                $rowsOut++;
            }
            // One page is never «the whole filtered set», and the file says so.
            $finished = false;
            $readable = $answer['total'] !== null;
        }

        // ⛔ THE CAP AND THE SCOPE TRAVEL INSIDE THE FILE. A spreadsheet that
        // was cut at five thousand rows and does not say so is a spreadsheet
        // somebody sums (`alpha.85`: a limit stated on only one side hides the
        // other). The marker is a comment row, so it cannot be mistaken for
        // data by a reader that looks at the first column.
        $lines[] = [self::marker($wholeSet, $finished, $readable, $rowsOut), '', '', '', '', '', '', '',
            '', '', '', '', '', '', '', '', '', '', '', '', '', ''];

        $csv = '';
        foreach ($lines as $line) {
            $csv .= implode(',', array_map([self::class, 'cell'], $line)) . "\n";
        }
        // The BOM, so Excel opens Persian text as UTF-8 rather than as
        // mojibake — the same thing the product export does.
        $csv = "\u{FEFF}" . $csv;

        // Recorded, because an export of every line's commission and share is
        // worth a trail even though it changes nothing: «خروجی CSV قابل
        // سوءاستفاده نباشد» is partly a question of who took one.
        $this->container->get(AuditLogger::class)->log(
            AuditEventCatalog::ORDER_LEDGER_EXPORTED,
            get_current_user_id(),
            'vendor',
            (string) ($filter->vendorUserId ?? 0),
            [
                'vendor_id' => $filter->vendorUserId ?? 0,
                'rows' => $rowsOut,
                'scope' => $wholeSet ? 'filter' : 'page',
                'finished' => $finished ? 1 : 0,
            ]
        );
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tecteb-order-lines-' . gmdate('Ymd-His') . '.csv"');
        header('Content-Length: ' . strlen($csv));
        header('X-Content-Type-Options: nosniff');
        echo $csv;
        exit;
    }

    /** @return list<string> */
    private static function header(): array
    {
        return [
            'order_id', 'order_item_id', 'product_id', 'variation_id', 'vendor_user_id',
            'title', 'sku', 'quantity', 'recorded_at_local', 'base_minor', 'tax_minor',
            'commission_kind', 'rate_bp', 'commission_minor', 'vendor_share_minor',
            'currency', 'exponent', 'payment_state', 'shipping_state',
            'financial_state', 'reversal_event', 'settlement_state',
        ];
    }

    /**
     * The last row of every export: what it covers, in one sentence.
     *
     * Deliberately a sentence and not a flag: the person who opens the file is
     * not the person who pressed the button, and «5000 of an unknown number»
     * has to be readable without the screen it came from.
     */
    private static function marker(bool $wholeSet, bool $finished, bool $readable, int $rows): string
    {
        $what = $wholeSet
            ? __('دامنه: تمام ردیف‌های فیلترشده', 'tecteb-marketplace-core')
            : __('دامنه: فقط همین صفحهٔ جدول', 'tecteb-marketplace-core');
        $end = $wholeSet && !$finished
            ? sprintf(
                /* translators: %d: the export cap */
                __('به سقف %d ردیف رسید و کامل نیست', 'tecteb-marketplace-core'),
                OrderLedgerReport::SWEEP_CAP
            )
            : ($wholeSet
                ? __('تا آخر رفت', 'tecteb-marketplace-core')
                : __('یک صفحه، نه کل فیلتر', 'tecteb-marketplace-core'));
        $read = $readable ? '' : ' — ' . __('شمارش کل خوانده نشد', 'tecteb-marketplace-core');
        return '# ' . $what . ' · ' . sprintf(
            /* translators: %d: how many data rows this file carries */
            __('%d ردیف', 'tecteb-marketplace-core'),
            $rows
        ) . ' · ' . $end . $read;
    }

    /**
     * One line of the export, in the order `header()` names.
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private function row(array $row): array
    {
        return [
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

    /**
     * The filters — two rows of the ones people use, the rest folded.
     *
     * ⛔ **WHY THIS LOOKED LIKE A COLUMN OF THIRTEEN.** Every filter was a
     * `<p class="tmc-field">`, and `.tmc-field` is a FORM FIELD: prose width
     * and a six-step bottom margin, which is right for a settings page and
     * wrong for a filter bar. `.tmc-filter-form` itself had no rule at all in
     * either stylesheet — the `alpha.32` root cause, one screen later — so
     * the browser laid thirteen blocks out one per line. The grid is in the
     * stylesheet now, and `StyledClassesHaveRulesTest` covers this view.
     *
     * The split is by what a manager reaches for: shop, order, product name
     * and the two dates stay out; the eight narrow ones fold, and the fold is
     * OPEN when any of them is set, so a filtered list never hides the filter
     * that narrowed it.
     */
    private function renderFilters(OrderLedgerFilter $filter): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('محدود کردن فهرست', 'tecteb-marketplace-core') . '</h2>';
        echo '<form method="get" class="tmc-filter-form">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';

        foreach ([
            ['vendor', __('شناسهٔ فروشگاه', 'tecteb-marketplace-core'), 'number', $filter->vendorUserId],
            ['order', __('شناسهٔ سفارش', 'tecteb-marketplace-core'), 'number', $filter->wcOrderId],
            ['title', __('نام محصول', 'tecteb-marketplace-core'), 'search', $filter->title],
            ['sku', __('کد کالا (SKU)', 'tecteb-marketplace-core'), 'search', $filter->sku],
            ['from', __('از تاریخ', 'tecteb-marketplace-core'), 'date', $filter->fromLocalDate],
            ['to', __('تا تاریخ', 'tecteb-marketplace-core'), 'date', $filter->toLocalDate],
        ] as [$name, $label, $type, $value]) {
            $this->field($name, $label, $type, $value);
        }

        $advanced = $filter->wcProductId !== null || $filter->variationId !== null
            || $filter->minAmountMinor !== null || $filter->maxAmountMinor !== null
            || $filter->payment !== '' || $filter->shipping !== null
            || $filter->commissionKind !== '';
        echo '<details class="tmc-filter-form__more"' . ($advanced ? ' open' : '') . '>';
        echo '<summary>' . esc_html__('فیلترهای پیشرفته', 'tecteb-marketplace-core') . '</summary>';
        echo '<div class="tmc-filter-form__grid">';
        foreach ([
            ['product', __('شناسهٔ محصول (والد)', 'tecteb-marketplace-core'), 'number', $filter->wcProductId],
            ['variation', __('شناسهٔ تنوع', 'tecteb-marketplace-core'), 'number', $filter->variationId],
            ['min_amount', __('مبلغ فروش از', 'tecteb-marketplace-core'), 'text', $filter->minAmountMinor],
            ['max_amount', __('مبلغ فروش تا', 'tecteb-marketplace-core'), 'text', $filter->maxAmountMinor],
        ] as [$name, $label, $type, $value]) {
            $this->field($name, $label, $type, $value);
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
        echo '</div></details>';

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

        echo '<div class="tmc-filter-form__actions">';
        echo '<button type="submit" class="tmc-button">' . esc_html__('اعمال', 'tecteb-marketplace-core') . '</button>';
        echo '<a class="tmc-button tmc-button--quiet" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
            . esc_html__('پاک‌کردن فیلترها', 'tecteb-marketplace-core') . '</a>';
        echo '</div>';
        echo '</form></section>';
    }

    private function field(string $name, string $label, string $type, string|int|null $value): void
    {
        echo '<p class="tmc-field"><label class="tmc-field__label" for="tmc-ol-' . esc_attr($name) . '">'
            . esc_html($label) . '</label>'
            . '<input class="tmc-input" id="tmc-ol-' . esc_attr($name) . '" type="' . esc_attr($type) . '"'
            . ' name="' . esc_attr($name) . '" value="' . esc_attr((string) ($value ?? '')) . '"></p>';
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
     * The whole filtered set in figures — and, when a product is named, THAT
     * product's own report.
     *
     * ⛔ **«هر محصول باید گزارش مستقل … داشته باشد» IS THIS CARD.** The ask is
     * for per-product orders, valid sales, historic sales, commission,
     * cancellations and returns; filtering this report to one product and
     * summing the whole set answers all of it, from the one query that
     * already knows how. A second screen with its own product query would be
     * a second definition of «valid sales» — right until somebody edited one
     * of them (`alpha.87`).
     *
     * ⛔ **IT REFUSES RATHER THAN COMBINING.** Two currencies have no sum, and
     * two exponents of one currency need a rescale this query deliberately
     * does not do. Both are reported as «no single total», with each group's
     * own figures beside it, because a figure in no unit is worse than no
     * figure (`alpha.68`).
     *
     * @param callable(string|int):string $fa
     */
    private function renderSummary(OrderLedgerFilter $filter, callable $fa): void
    {
        $answer = $this->report()->summaryForManager($filter);
        if (!$answer['allowed']) {
            return;
        }
        $heading = $filter->wcProductId !== null
            ? sprintf(
                /* translators: %s: the WooCommerce product id */
                __('گزارش محصول #%s — کل ردیف‌های این فیلتر', 'tecteb-marketplace-core'),
                $fa((string) $filter->wcProductId)
            )
            : ($filter->isNarrowed() || $filter->vendorUserId !== null
                ? __('جمع کل ردیف‌های این فیلتر', 'tecteb-marketplace-core')
                : __('جمع کل همهٔ ردیف‌ها', 'tecteb-marketplace-core'));
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html($heading) . '</h2>';

        if (!$answer['readable']) {
            // ⛔ «نتوانستم بخوانم» and never a nought.
            echo Components::notice(
                'warning',
                esc_html__('جمع کل این فیلتر خوانده نشد، پس عددی برایش چاپ نمی‌شود. «خوانده نشد» با «صفر» یکی نیست.', 'tecteb-marketplace-core')
            );
            echo '</section>';
            return;
        }
        if ($answer['groups'] === []) {
            echo '<p class="tmc-field__desc">'
                . esc_html__('با این فیلترها هیچ ردیفی ثبت نشده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        if ($answer['refused'] !== '') {
            echo Components::notice('warning', esc_html(
                $answer['refused'] === 'mixed_currencies'
                    ? __('ردیف‌های این فیلتر در چند ارز ثبت شده‌اند، پس جمعی برایشان وجود ندارد. هر گروه جدا پایین آمده است.', 'tecteb-marketplace-core')
                    : __('ردیف‌های این فیلتر در چند مقیاس از یک ارز ثبت شده‌اند، پس این صفحه آن‌ها را با هم جمع نمی‌زند. هر گروه جدا پایین آمده است.', 'tecteb-marketplace-core')
            ));
        }
        foreach ($answer['groups'] as $group) {
            $exponent = $group['exponent'] === null ? 0 : (int) $group['exponent'];
            $currency = $group['currency'] === null ? '' : (string) $group['currency'];
            $money = static function (?int $minor) use ($exponent, $currency): string {
                if ($minor === null) {
                    // A sum past the integer range, reported rather than
                    // saturated into a number that looks like a total.
                    return __('نامعلوم', 'tecteb-marketplace-core');
                }
                $text = MoneyText::format($minor, $exponent);
                return $text === null
                    ? __('نامعلوم', 'tecteb-marketplace-core')
                    : $text . ($currency === '' ? '' : ' ' . $currency);
            };
            $rows = [
                ['label' => __('تعداد قلم', 'tecteb-marketplace-core'), 'value' => $fa((string) $group['lines'])],
                ['label' => __('جمع تاریخی سهم فروشنده', 'tecteb-marketplace-core'), 'value' => $money($group['historic'])],
                ['label' => __('جمع معتبر فعلی سهم فروشنده', 'tecteb-marketplace-core'), 'value' => $money($group['valid'])],
                ['label' => __('در انتظار تکمیل برای تسویه', 'tecteb-marketplace-core'), 'value' => $money($group['pending'])],
                ['label' => __('جمع مبلغ فروش', 'tecteb-marketplace-core'), 'value' => $money($group['base'])],
                ['label' => __('جمع مالیات', 'tecteb-marketplace-core'), 'value' => $money($group['tax'])],
                ['label' => __('جمع کمیسیون', 'tecteb-marketplace-core'), 'value' => $money($group['commission'])],
                ['label' => __('اقلام لغوشده', 'tecteb-marketplace-core'), 'value' => $fa((string) $group['cancelled'])],
            ];
            if ((int) $group['unrecorded'] > 0) {
                // ⛔ NAMED BESIDE THE TOTAL, not folded into it. `SUM()` skips
                // `NULL`, so a line whose rate was never resolved contributes
                // nothing — and a total printed without this count would read
                // as «that is all of it» (FIN-02).
                $rows[] = [
                    'label' => __('اقلام بدون سهم ثبت‌شده (در جمع‌ها نیستند)', 'tecteb-marketplace-core'),
                    'value' => $fa((string) $group['unrecorded']),
                ];
            }
            if ((int) $group['unrecorded_event'] > 0) {
                $rows[] = [
                    'label' => __('اقلام بدون سند مالی', 'tecteb-marketplace-core'),
                    'value' => $fa((string) $group['unrecorded_event']),
                ];
            }
            if (count($answer['groups']) > 1) {
                $rows[] = [
                    'label' => __('واحد این گروه', 'tecteb-marketplace-core'),
                    'value' => ($currency === '' ? __('نامعلوم', 'tecteb-marketplace-core') : $currency)
                        . ' / ' . $fa((string) $exponent),
                ];
            }
            echo Components::dataList($rows);
        }
        echo '<p class="tmc-field__desc">'
            . esc_html__('«جمع تاریخی» اقلام لغوشده را هم دارد و «جمع معتبر فعلی» ندارد. این دو عدد عمداً جدا هستند و جمع‌کردنشان معنایی ندارد. مرجوعی‌ها در صفحهٔ مرجوعی‌ها دیده می‌شوند و سند معکوسشان در ستون «سند مالی» همین جدول.', 'tecteb-marketplace-core')
            . '</p>';
        echo '</section>';
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
        // ⛔ INSIDE A CARD, and that is the whole fix for the owner's §3-ب.
        // `.tmc-table` is `display:block` until `@container tmccard` says
        // there is room for a table, and the container is `.tmc-card`. This
        // wrapper was a bare `<div>`, so NO ancestor carried the container
        // name, the query could never match, and a thirteen-column financial
        // table rendered as thirteen stacked lines per row — on every width,
        // forever. Nothing in PHP can see that; only a browser can.
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('اقلام سفارش', 'tecteb-marketplace-core') . '</h2>';
        echo '<div class="tmc-scroll" role="region" tabindex="0" aria-label="'
            . esc_attr__('جدول اقلام سفارش', 'tecteb-marketplace-core') . '">';
        echo '<table class="widefat tmc-table tmc-ledger"><thead><tr>';
        foreach (self::columns() as $column) {
            echo '<th scope="col">' . esc_html($column['label']) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>' . $this->cells($row, $fa) . '</tr>';
        }
        echo '</tbody></table></div></section>';
    }

    /**
     * The table's columns — the ONE list the heading and the cell label share.
     *
     * ⛔ **WHY ONE LIST.** In the stacked layout a narrow screen gets, the
     * `<thead>` is hidden and each cell is labelled by its own
     * `data-label` — `.tmc-table td::before { content: attr(data-label) }`.
     * The cells carried no `data-label` at all, so every row printed thirteen
     * values each preceded by a bare «: » with no word in front of it. Two
     * lists would have fixed it until somebody added a column to one of them,
     * so there is one, and the only way to print a cell is through it
     * (`alpha.78`).
     *
     * @return list<array{key:string, label:string, numeric:bool}>
     */
    private static function columns(): array
    {
        $n = static fn (string $key, string $label): array
            => ['key' => $key, 'label' => $label, 'numeric' => true, 'wrap' => false];
        $t = static fn (string $key, string $label, bool $wrap = false): array
            => ['key' => $key, 'label' => $label, 'numeric' => false, 'wrap' => $wrap];
        return [
            $t('order', __('سفارش', 'tecteb-marketplace-core')),
            $t('product', __('محصول و مدل', 'tecteb-marketplace-core'), true),
            $t('vendor', __('فروشگاه', 'tecteb-marketplace-core')),
            $n('date', __('تاریخ ثبت', 'tecteb-marketplace-core')),
            $n('quantity', __('تعداد', 'tecteb-marketplace-core')),
            $n('base', __('مبلغ فروش', 'tecteb-marketplace-core')),
            $n('commission', __('کمیسیون', 'tecteb-marketplace-core')),
            $n('share', __('سهم فروشنده', 'tecteb-marketplace-core')),
            $t('payment', __('پرداخت', 'tecteb-marketplace-core')),
            $t('shipping', __('قلم فروشنده', 'tecteb-marketplace-core')),
            $t('financial', __('سند مالی', 'tecteb-marketplace-core')),
            // ⛔ Not a twelfth FACT: the row's heavy accounting detail, folded.
            // «جزئیات سنگین حسابداری در ردیف قابل بازشدن … نمایش داده شود» —
            // and the facts behind it (the document key, the reversing
            // document, the unit, the settlement moment) were in the CSV and
            // on NO screen, which is the worst place for the one number this
            // release turns on.
            $t('details', __('جزئیات حسابداری', 'tecteb-marketplace-core')),
        ];
    }

    /**
     * One row's cells, every one of them labelled.
     *
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
        $number = '#' . esc_html($fa((string) $orderId));
        // ⛔ ASKED OF WooCommerce, not assembled from a remembered shape. The
        // old address was `post.php?post=…`, which opens nothing on an HPOS
        // installation — and the owner's staging IS HPOS, so the single most
        // useful link on this report was dead on the only site it had to work
        // on. `null` means no address could be built, and then the number is
        // printed WITHOUT a link rather than with one that goes nowhere.
        $url = $this->orderUrl($orderId);
        $orderLink = $url === null
            ? $number . '<br><span class="tmc-field__desc">'
                . esc_html__('پیوند ویرایش در دسترس نیست.', 'tecteb-marketplace-core') . '</span>'
            : '<a href="' . esc_url($url) . '">' . $number . '</a>';

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

        $values = [
            'order' => $orderLink,
            'product' => $product . $model . $sku,
            'vendor' => '#' . esc_html($fa((string) $row['vendor_user_id'])),
            'date' => esc_html($fa($this->localTime((string) $row['created_at']))),
            'quantity' => esc_html($fa((string) $row['quantity'])),
            'base' => $money((int) $row['base_minor']),
            'commission' => $money($row['commission_minor'] === null ? null : (int) $row['commission_minor'])
                . '<br><span class="tmc-field__desc">'
                . esc_html(OrderLedgerMessages::commissionKind(
                    (string) $row['commission_kind'],
                    $row['rate_bp'] === null ? null : (int) $row['rate_bp']
                ))
                . '</span>',
            'share' => $money($row['vendor_share_minor'] === null ? null : (int) $row['vendor_share_minor']),
            'payment' => esc_html(OrderLedgerMessages::payment(
                $row['wc_order_status'] === null ? null : (string) $row['wc_order_status'],
                (bool) $row['wc_status_readable']
            )),
            'shipping' => esc_html(OrderLedgerMessages::shipping((string) $row['item_status'])),
            'financial' => esc_html(OrderLedgerMessages::financial((string) $row['financial_state'])),
            'details' => $this->detail($row, $fa),
        ];

        $out = '';
        foreach (self::columns() as $column) {
            // ⛔ The wrap class existed in the stylesheet and NOTHING wore it:
            // `StyledClassesHaveRulesTest` asks whether a written class has a
            // rule, never whether a rule has a wearer. Seen in the rendered
            // table, where a product title broke to one word per line.
            $class = match (true) {
                $column['numeric'] => ' class="tmc-ledger__num"',
                ($column['wrap'] ?? false) === true => ' class="tmc-ledger__cell--wrap"',
                default => '',
            };
            $out .= '<td' . $class . ' data-label="' . esc_attr($column['label']) . '">'
                . ($values[$column['key']] ?? '') . '</td>';
        }
        return $out;
    }

    /**
     * One row's heavy accounting detail, folded.
     *
     * ⛔ **WHY A DISCLOSURE AND NOT THREE MORE COLUMNS.** The owner's column
     * list is eleven wide and a thirteen-column financial table already
     * stacks to nearly twenty thousand pixels on a telephone (measured:
     * `docs/evidence/alpha100-reports/measurements.json`). These facts are
     * the ones a manager wants ONCE — when a figure is being questioned — so
     * they belong behind a `<summary>`, which is `<details>`: native, no
     * JavaScript, and open-able with the keyboard.
     *
     * ⛔ **AND EVERY ABSENCE IS NAMED.** «سند مالی ثبت نشده» is a different
     * fact from «سند مالی: order:12300:…», and FIN-02 makes the first one
     * REAL: an unresolvable rate records nothing at all. A disclosure that
     * printed an empty line for it would hide the only state this report
     * exists to surface.
     *
     * @param array<string,mixed>         $row
     * @param callable(string|int):string $fa
     */
    private function detail(array $row, callable $fa): string
    {
        $unknown = __('نامعلوم', 'tecteb-marketplace-core');
        $lines = [];

        $lines[] = sprintf(
            /* translators: %s: the tax on this line, already formatted */
            __('مالیات: %s', 'tecteb-marketplace-core'),
            // The closure escapes, and `moreDetails()` escapes again; it is
            // the raw figure that is wanted here, so the formatting is redone
            // plainly rather than un-escaped.
            MoneyText::format((int) $row['tax_minor'], $row['exponent'] === null ? 0 : (int) $row['exponent'])
                ?? $unknown
        );
        $lines[] = sprintf(
            /* translators: %s: the settlement state in words */
            __('تسویه: %s', 'tecteb-marketplace-core'),
            OrderLedgerMessages::settlement((string) $row['settlement_state'])
        );
        // The column says «نامعلوم» in one word; this says which of the two
        // reasons it is, and prints nothing when the state speaks for itself.
        $note = OrderLedgerMessages::paymentNote(
            $row['wc_order_status'] === null ? null : (string) $row['wc_order_status'],
            (bool) $row['wc_status_readable']
        );
        if ($note !== '') {
            $lines[] = $note;
        }

        $unit = $row['unit_price_minor'] === null
            ? __('قیمت واحدِ گِردی وجود ندارد', 'tecteb-marketplace-core')
            : (MoneyText::format((int) $row['unit_price_minor'], $row['exponent'] === null ? 0 : (int) $row['exponent']) ?? $unknown);
        $lines[] = sprintf(
            /* translators: %s: the unit price, or why there is none */
            __('قیمت واحد: %s', 'tecteb-marketplace-core'),
            $unit
        );

        // ⛔ The unit the figures above are IN. Two exponents of one currency
        // are legitimate on one shop's books (`alpha.67`), so a row that does
        // not carry its own unit says so rather than borrowing a neighbour's.
        $lines[] = $row['currency'] === null
            ? __('واحد پول: روی این ردیف ثبت نشده', 'tecteb-marketplace-core')
            : sprintf(
                /* translators: 1: the currency code, 2: the decimal exponent */
                __('واحد پول: %1$s · ضریب اعشار: %2$s', 'tecteb-marketplace-core'),
                (string) $row['currency'],
                $fa((string) ($row['exponent'] ?? 0))
            );

        $lines[] = $row['rate_bp'] === null
            ? __('نرخ کمیسیون: ثبت نشده', 'tecteb-marketplace-core')
            : sprintf(
                /* translators: 1: basis points, 2: where the rate came from */
                __('نرخ کمیسیون: %1$s در ده‌هزار · منبع: %2$s', 'tecteb-marketplace-core'),
                $fa((string) $row['rate_bp']),
                (string) $row['rate_source'] === '' ? $unknown : (string) $row['rate_source']
            );

        $lines[] = (string) $row['ledger_event'] === ''
            ? __('سند مالی ثبت نشده است.', 'tecteb-marketplace-core')
            : sprintf(
                /* translators: %s: the ledger event key */
                __('کلید سند مالی: %s', 'tecteb-marketplace-core'),
                (string) $row['ledger_event']
            );
        // ⛔ The reversing document, by name. Cancelling order 12300 leaves the
        // accrual in place and writes a `…:void` document BESIDE it, so «سند
        // معکوس ندارد» on a cancelled line is a finding and this is the only
        // screen that would show it.
        $lines[] = (string) $row['reversal_event'] === ''
            ? __('سند معکوسی ثبت نشده است.', 'tecteb-marketplace-core')
            : sprintf(
                /* translators: %s: the reversing ledger event key */
                __('کلید سند معکوس: %s', 'tecteb-marketplace-core'),
                (string) $row['reversal_event']
            );

        $lines[] = $row['withdrawal_id'] === null
            ? __('در هیچ درخواست برداشتی نیست.', 'tecteb-marketplace-core')
            : sprintf(
                /* translators: %s: the withdrawal request id */
                __('درخواست برداشت: #%s', 'tecteb-marketplace-core'),
                $fa((string) $row['withdrawal_id'])
            );
        if ($row['settlement_completed_at'] !== null) {
            $lines[] = sprintf(
                /* translators: %s: a local date and time */
                __('تکمیل تسویه: %s', 'tecteb-marketplace-core'),
                $fa($this->localTime((string) $row['settlement_completed_at']))
            );
        }
        if ($row['shipped_at'] !== null) {
            $lines[] = sprintf(
                /* translators: %s: a local date and time */
                __('زمان ارسال: %s', 'tecteb-marketplace-core'),
                $fa($this->localTime((string) $row['shipped_at']))
            );
        }
        if ((string) $row['carrier'] !== '' || (string) $row['tracking_code'] !== '') {
            $lines[] = sprintf(
                /* translators: 1: the carrier, 2: the tracking code */
                __('حمل: %1$s · کد رهگیری: %2$s', 'tecteb-marketplace-core'),
                (string) $row['carrier'] === '' ? $unknown : (string) $row['carrier'],
                (string) $row['tracking_code'] === '' ? $unknown : (string) $row['tracking_code']
            );
        }

        // The two ids support needs to quote, last because they are the least
        // interesting until something has gone wrong.
        $lines[] = sprintf(
            /* translators: 1: our row id, 2: the WooCommerce order item id */
            __('شناسهٔ ردیف بازارگاه: #%1$s · شناسهٔ قلم ووکامرس: #%2$s', 'tecteb-marketplace-core'),
            $fa((string) $row['id']),
            $fa((string) $row['wc_order_item_id'])
        );

        return Components::moreDetails($lines, __('جزئیات حسابداری', 'tecteb-marketplace-core'));
    }

    /**
     * The address that edits one order, asked once per page.
     *
     * Memoised for the request because every row asks the same question of
     * the same service; the service itself costs no query, and this keeps the
     * container lookup to one.
     */
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

    /**
     * The two exports, each saying what it covers.
     *
     * ⛔ **THE OLD BUTTON SAID «همین فیلترها» AND SENT ONE PAGE.** It called
     * the same paged query the table did, so a manager who filtered to a year
     * and pressed it got twenty-five rows in a file whose name promised the
     * year. Two radio buttons now, the whole set first, and the cap is on the
     * screen as well as inside the file.
     */
    private function renderExport(OrderLedgerFilter $filter): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('خروجی CSV', 'tecteb-marketplace-core') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EXPORT_ACTION) . '">';
        foreach ($filter->toQuery() as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        wp_nonce_field(self::EXPORT_ACTION);
        echo '<fieldset class="tmc-field"><legend class="tmc-field__label">'
            . esc_html__('دامنهٔ خروجی', 'tecteb-marketplace-core') . '</legend>';
        echo '<p class="tmc-field__control"><label><input type="radio" name="scope" value="filter" checked> '
            . esc_html(sprintf(
                /* translators: %s: the export cap, in Persian digits */
                __('تمام ردیف‌های این فیلتر (تا سقف %s ردیف)', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) OrderLedgerReport::SWEEP_CAP)
            ))
            . '</label></p>';
        echo '<p class="tmc-field__control"><label><input type="radio" name="scope" value="page"> '
            . esc_html__('فقط همین صفحهٔ جدول', 'tecteb-marketplace-core') . '</label></p>';
        echo '</fieldset>';
        echo '<button type="submit" class="tmc-button tmc-button--quiet">'
            . esc_html__('دریافت CSV', 'tecteb-marketplace-core') . '</button>';
        echo '<p class="tmc-field__desc">'
            . esc_html(sprintf(
                /* translators: 1: the batch size, 2: the cap */
                __('ردیف‌ها دسته‌دسته (%1$s ردیف در هر دسته) خوانده می‌شوند، پس حافظه با بزرگ‌شدن فیلتر پر نمی‌شود. اگر فیلتر بیش از %2$s ردیف داشته باشد، فایل سر همان سقف می‌ایستد و در آخرین سطر خودش می‌نویسد که کامل نیست.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) OrderLedgerReport::SWEEP_BATCH),
                PersianDigits::toPersian((string) OrderLedgerReport::SWEEP_CAP)
            ))
            . '</p>';
        echo '</form></section>';
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
