<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;

/**
 * The products this shop already has: look, then decide, product by product.
 *
 * Two acts on one page and they are never the same button:
 *
 *  1. **the inventory** — read-only, paginated, and it writes nothing at all.
 *     What is here, by type and status; which bucket each product is in; who
 *     the evidence says sells it; and what an intake could not carry.
 *  2. **the intake** — an explicit list of ids, imported as OBSERVED rows
 *     that change nothing about the products themselves.
 *
 * And a third act is deliberately NOT here: accepting a product into
 * marketplace management. That lives on the migration page with its own
 * warning, because it is the moment this plugin starts deciding whether
 * somebody else's product may be sold. «پذیرش مدیریت بازارگاه اقدامی صریح و
 * جدا باشد.»
 */
final class ProductIntakePage
{
    public const SLUG = 'tmc-product-intake';
    public const CAPABILITY = Capabilities::REVIEW_VENDOR;
    private const NONCE = 'tmc_product_intake';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('ورود محصولات موجود', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $inventory = $this->container->get(CatalogueInventory::class);
        $intake = $this->container->get(ImportExistingProducts::class);
        $request = Request::capture();
        $notice = $this->handleAction($request, $intake);
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('محصولات فعلی ووکامرس', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(
                str_starts_with($notice, 'ok:') ? 'success' : 'error',
                substr($notice, strpos($notice, ':') + 1)
            );
        }
        echo Components::notice('info', __('این صفحه هیچ محصول ووکامرسی نمی‌سازد و هیچ محصولی را عوض نمی‌کند. «ورود» یک ردیف بازارگاه می‌سازد که به همان محصول اشاره می‌کند، با مالکیت «مشاهده‌شده» — یعنی قیمت، موجودی، وضعیت انتشار، نویسنده، نشانی و سفارش‌های قبلی دست‌نخورده می‌مانند و هیچ گاردی از این بازارگاه دربارهٔ آن محصول نظر نمی‌دهد. پذیرش مدیریت، اقدام جداگانه‌ای در صفحهٔ «انتقال از دکان» است.', 'tecteb-marketplace-core'));

        $after = $request->queryInt('after');
        $page = $inventory->page($after);
        if (($page['available'] ?? false) !== true) {
            echo Components::notice('warning', __('ووکامرس روی این سایت پیدا نشد، پس کاتالوگی برای خواندن نیست.', 'tecteb-marketplace-core'));
            echo Components::shellClose();
            return;
        }

        $this->renderCounts($page, $fa);
        $this->renderRows($page, $fa);
        $this->renderRuns($intake, $fa);
        echo Components::shellClose();
    }

    /** @param array<string,mixed> $page */
    private function renderCounts(array $page, callable $fa): void
    {
        $counts = $page['counts'];
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('آنچه در فروشگاه هست', 'tecteb-marketplace-core') . '</h2>';
        echo '<dl class="tmc-data">'
            . '<dt>' . esc_html__('کل محصولات', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html($fa((string) ($counts['total'] ?? 0))) . '</dd>'
            . '<dt>' . esc_html__('تنوع‌ها', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html($fa((string) ($counts['variations'] ?? 0))) . '</dd>'
            . '</dl>';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('وضعیت انتشار', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تعداد', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach (($counts['by_status'] ?? []) as $status => $total) {
            echo '<tr><td>' . Components::code((string) $status) . '</td><td>'
                . esc_html($fa((string) $total)) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('نوع محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تعداد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('در فرم این بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach (($counts['by_type'] ?? []) as $type => $total) {
            $supported = !array_key_exists($type, $counts['unsupported'] ?? []);
            echo '<tr><td>' . Components::code((string) $type) . '</td><td>'
                . esc_html($fa((string) $total)) . '</td><td>'
                . esc_html($supported
                    ? __('هست', 'tecteb-marketplace-core')
                    : __('نیست — وارد نمی‌شود و تبدیل هم نمی‌شود', 'tecteb-marketplace-core'))
                . '</td></tr>';
        }
        echo '</tbody></table>';

        $duplicates = $page['duplicate_links'] ?? [];
        if ($duplicates !== []) {
            echo Components::notice('warning', __('برای بعضی محصول‌ها بیش از یک ردیف بازارگاه ثبت شده. اینکه کدام ردیف واقعی است، تصمیمی دربارهٔ کاتالوگ یک نفر است و این صفحه آن را حل نمی‌کند — فقط نشان می‌دهد.', 'tecteb-marketplace-core'));
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('شناسهٔ ووکامرس', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('تعداد ردیف', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($duplicates as $row) {
                echo '<tr><td>' . Components::code((string) $row['wc_product_id']) . '</td><td>'
                    . esc_html($fa((string) $row['rows'])) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $page */
    private function renderRows(array $page, callable $fa): void
    {
        $sentences = ImportExistingProducts::reasonSentences();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('یک صفحه از محصولات', 'tecteb-marketplace-core') . '</h2>';
        echo '<p>' . esc_html__('شواهد فروشنده‌بودن کنار هر ردیف است. نویسندهٔ وردپرس به‌تنهایی اثبات فروشنده‌بودن نیست: محصولی که نویسنده‌اش مدیر است محصول خودِ فروشگاه اصلی شمرده می‌شود و این افزونه برایش فروشنده‌ای حدس نمی‌زند.', 'tecteb-marketplace-core') . '</p>';
        echo '<form method="post">'
            . wp_nonce_field(self::NONCE, 'tmc_intake_nonce', true, false);
        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('محصولات', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('انتخاب', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('شناسه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('عنوان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('نوع / وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('دسته‌بندی مالکیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('شواهد فروشنده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('افزونه‌های دیگر', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('قابل ورود؟', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach (($page['rows'] ?? []) as $row) {
            $reasons = array_map(
                static fn (string $r): string => $sentences[$r] ?? $r,
                (array) $row['reasons']
            );
            echo '<tr>'
                . '<td>' . ($row['intake_ready'] === true || $row['bucket'] === CatalogueInventory::BUCKET_SHOP
                    ? '<input type="checkbox" name="wc_ids[]" value="' . esc_attr((string) $row['wc_product_id']) . '">'
                    : '—') . '</td>'
                . '<td>' . Components::code((string) $row['wc_product_id']) . '</td>'
                . '<td>' . esc_html((string) $row['title']) . '</td>'
                . '<td>' . esc_html((string) $row['type'] . ' / ' . (string) $row['status'])
                . ((int) $row['variations'] > 0
                    ? '<br>' . esc_html(sprintf(
                        /* translators: 1: how many variations */
                        __('%1$s تنوع', 'tecteb-marketplace-core'),
                        PersianDigits::toPersian((string) $row['variations'])
                    ))
                    : '') . '</td>'
                . '<td>' . esc_html($this->bucketLabel((string) $row['bucket'])) . '</td>'
                . '<td>' . esc_html($this->evidenceLabel($row)) . '</td>'
                . '<td>' . esc_html(implode('، ', array_keys((array) $row['plugins']))) . '</td>'
                . '<td>' . ($row['intake_ready'] === true
                    ? esc_html__('بله', 'tecteb-marketplace-core')
                    : esc_html(implode(' ', $reasons))) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p><label for="tmc-intake-assign">'
            . esc_html__('فروشندهٔ این محصول‌ها (شناسهٔ کاربر) — فقط وقتی لازم است که محصول فروشنده‌ای ندارد:', 'tecteb-marketplace-core')
            . '</label> <input type="number" id="tmc-intake-assign" name="assign_to" min="0" value="0"></p>';
        echo '<p>' . esc_html__('اگر این کادر صفر بماند، محصولی که فروشنده‌اش از شواهد معلوم نیست وارد نمی‌شود و دلیلش گفته می‌شود. این افزونه هیچ‌وقت محصول فروشگاه اصلی را به مدیر واردشده یا یک فروشندهٔ تصادفی نسبت نمی‌دهد.', 'tecteb-marketplace-core') . '</p>';
        echo '<button type="submit" class="tmc-button" name="intake_action" value="preview">'
            . esc_html__('پیش‌نمایش (هیچ نوشتنی)', 'tecteb-marketplace-core') . '</button> ';
        echo '<button type="submit" class="tmc-button" name="intake_action" value="import">'
            . esc_html__('ورود انتخاب‌شده‌ها', 'tecteb-marketplace-core') . '</button>';
        echo '</form>';

        if (($page['done'] ?? true) !== true) {
            echo '<p><a class="tmc-button tmc-button--quiet" href="'
                . esc_url(add_query_arg(['page' => self::SLUG, 'after' => (int) $page['cursor']], admin_url('admin.php')))
                . '">' . esc_html__('صفحهٔ بعد', 'tecteb-marketplace-core') . '</a></p>';
        }
        echo '</section>';
    }

    private function renderRuns(ImportExistingProducts $intake, callable $fa): void
    {
        $runs = $intake->runs();
        if ($runs === []) {
            return;
        }
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('ورودهای انجام‌شده', 'tecteb-marketplace-core') . '</h2>';
        echo '<p>' . esc_html__('بازگشت فقط ردیف‌های همین اجرا را برمی‌دارد. محصول ووکامرس، رسانه و سفارش‌ها دست نمی‌خورند — این افزونه اصلاً نمی‌تواند آن‌ها را حذف کند. ردیفی که از آن موقع پذیرفته شده یا پیشنهاد بی‌پاسخ دارد برداشته نمی‌شود و نامش گفته می‌شود.', 'tecteb-marketplace-core') . '</p>';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('شناسهٔ اجرا', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('ردیف ساخته‌شده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('زمان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('بازگشت', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($runs as $runId => $run) {
            echo '<tr><td>' . Components::code((string) $runId) . '</td>'
                . '<td>' . esc_html($fa((string) count((array) ($run['products'] ?? [])))) . '</td>'
                . '<td>' . esc_html((string) ($run['at'] ?? '')) . '</td>'
                . '<td><form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_intake_nonce', true, false)
                . '<input type="hidden" name="run_id" value="' . esc_attr((string) $runId) . '">'
                . '<button type="submit" class="tmc-button tmc-button--quiet" name="intake_action" value="rollback">'
                . esc_html__('بازگشت این اجرا', 'tecteb-marketplace-core') . '</button></form></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            CatalogueInventory::BUCKET_MARKETPLACE => __('مال بازارگاه', 'tecteb-marketplace-core'),
            CatalogueInventory::BUCKET_OBSERVED => __('فقط مشاهده‌شده', 'tecteb-marketplace-core'),
            CatalogueInventory::BUCKET_DOKAN => __('دکان', 'tecteb-marketplace-core'),
            CatalogueInventory::BUCKET_SHOP => __('خودِ فروشگاه اصلی', 'tecteb-marketplace-core'),
            CatalogueInventory::BUCKET_VENDOR => __('فروشندهٔ بازارگاه، بی ردیف', 'tecteb-marketplace-core'),
            default => __('مبهم', 'tecteb-marketplace-core'),
        };
    }

    /** @param array<string,mixed> $row */
    private function evidenceLabel(array $row): string
    {
        $parts = [];
        $parts[] = sprintf(
            /* translators: 1: author user id */
            __('نویسنده #%1$s', 'tecteb-marketplace-core'),
            PersianDigits::toPersian((string) $row['author_id'])
        );
        if ((array) $row['author_roles'] !== []) {
            $parts[] = implode('/', (array) $row['author_roles']);
        }
        if ((string) $row['vendor_profile'] !== '') {
            $parts[] = __('پروندهٔ فروشندگی: ', 'tecteb-marketplace-core') . (string) $row['vendor_profile'];
        }
        if ($row['dokan_seller'] === true) {
            $parts[] = __('فروشندهٔ دکان', 'tecteb-marketplace-core');
        }
        return implode(' · ', $parts);
    }

    private function handleAction(Request $request, ImportExistingProducts $intake): string
    {
        if (!$request->isPost() || !$request->hasPost('intake_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_intake_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $action = $request->postKey('intake_action');
        if ($action === 'rollback') {
            $result = $intake->rollback($request->postText('run_id'));
            if ($result->ok) {
                return 'ok:' . sprintf(
                    /* translators: 1: how many rows were removed */
                    __('ردیف‌های این اجرا برداشته شدند (%1$s ردیف). هیچ محصول، رسانه یا سفارشی حذف نشد.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) ($result->context['removed'] ?? 0))
                );
            }
            if ($result->code === 'intake_rollback_kept') {
                return 'err:' . sprintf(
                    /* translators: 1: how many were removed, 2: the ids that were kept with their reason */
                    __('%1$s ردیف برداشته شد و این‌ها نگه داشته شدند: %2$s — ردیفی که پذیرفته شده یا پیشنهاد بی‌پاسخ دارد با بازگشت برداشته نمی‌شود.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) ($result->context['removed'] ?? 0)),
                    (string) ($result->context['kept'] ?? '')
                );
            }
            return 'err:' . __('بازگشت انجام نشد: ', 'tecteb-marketplace-core') . $result->code;
        }

        $ids = $this->idsFrom($request);
        if ($ids === []) {
            return 'err:' . __('هیچ محصولی انتخاب نشده بود.', 'tecteb-marketplace-core');
        }
        $assignTo = $request->postInt('assign_to');
        if ($action === 'preview') {
            return 'ok:' . $this->previewSentence($intake->preview($ids, $assignTo));
        }
        if ($action === 'import') {
            $batch = $intake->importBatch($intake->newRunId(), $ids, $assignTo);
            if (($batch['code'] ?? '') === 'forbidden') {
                return 'err:' . __('دسترسی لازم را ندارید.', 'tecteb-marketplace-core');
            }
            $counts = $batch['counts'] ?? ['imported' => 0, 'skipped' => 0, 'failed' => 0];
            return ($batch['ok'] ?? false ? 'ok:' : 'err:') . sprintf(
                /* translators: 1: imported, 2: skipped, 3: failed, 4: run id */
                __('%1$s وارد شد، %2$s رد شد، %3$s انجام نشد. شناسهٔ اجرا: %4$s. جزئیات هر ردیف در جدول پایین صفحه.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $counts['imported']),
                PersianDigits::toPersian((string) $counts['skipped']),
                PersianDigits::toPersian((string) $counts['failed']),
                (string) ($batch['run_id'] ?? '')
            ) . ' ' . $this->previewSentence($batch['results'] ?? []);
        }
        return 'err:' . __('اقدام ناشناخته بود.', 'tecteb-marketplace-core');
    }

    /**
     * Each product's verdict, named. A count on its own would leave a manager
     * hunting for the ones that did not go.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function previewSentence(array $rows): string
    {
        $sentences = ImportExistingProducts::reasonSentences();
        $parts = [];
        foreach ($rows as $row) {
            $reason = (string) ($row['reason'] ?? '');
            $parts[] = sprintf(
                '#%s: %s',
                PersianDigits::toPersian((string) ($row['wc_product_id'] ?? 0)),
                $reason === ''
                    ? (string) ($row['verdict'] ?? '')
                    : ($sentences[$reason] ?? $reason)
            );
        }
        return implode(' | ', array_slice($parts, 0, 25));
    }

    /** @return list<int> */
    private function idsFrom(Request $request): array
    {
        $raw = $request->postTextList('wc_ids');
        $out = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
