<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * What a CSV import WOULD do, before it does it.
 *
 * Each row says which product it matched, what would happen to it, and — when
 * the answer is "nothing" — why, in the same sentence the form would have
 * used. Nothing is written until the vendor presses the second button.
 */
final class ProductCsvView
{
    /**
     * @param array{rows:list<array{line:int,sku:string,title:string,action:string,code:string,context:array<string,scalar|null>}>,created:int,updated:int,skipped:int} $report
     */
    public static function render(array $report, bool $applied, VendorUrls $urls, string $nonceField): string
    {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $rows = $report['rows'] ?? [];

        $html = '<section class="tv-card"><h2 class="tv-card__title">' . esc_html(
            $applied ? __('نتیجه ورود CSV', 'tecteb-marketplace-core') : __('پیش‌نمایش ورود CSV', 'tecteb-marketplace-core')
        ) . '</h2>';

        $html .= VendorUi::notice(
            $applied ? 'success' : 'info',
            (string) ProductMessages::notice($applied ? 'csv_imported' : 'csv_previewed', [
                'created' => (int) ($report['created'] ?? 0),
                'updated' => (int) ($report['updated'] ?? 0),
                'skipped' => (int) ($report['skipped'] ?? 0),
            ])
        );

        if ($rows === []) {
            $html .= VendorUi::notice('warning', __('هیچ ردیف قابل خواندنی در فایل نبود.', 'tecteb-marketplace-core'));
            return $html . '</section>';
        }

        // tabindex and a label: a region only a mouse can scroll is
        // unreachable by keyboard (axe scrollable-region-focusable). The same
        // defect was measured on the finance history table; this one shares
        // the pattern and therefore the fix.
        $html .= '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('پیش‌نمایش ردیف‌های فایل', 'tecteb-marketplace-core') . '">'
            . '<table class="tv-table"><caption class="tv-visually-hidden">'
            . esc_html__('ردیف‌های فایل CSV و نتیجه هرکدام', 'tecteb-marketplace-core') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('سطر', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('SKU', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('عنوان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('نتیجه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('توضیح', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            $context = is_array($row['context'] ?? null) ? $row['context'] : [];
            $html .= '<tr>'
                . '<td>' . esc_html($fa((int) $row['line'])) . '</td>'
                . '<td><bdi>' . esc_html((string) $row['sku'] !== '' ? (string) $row['sku'] : '—') . '</bdi></td>'
                . '<td>' . esc_html((string) $row['title'] !== '' ? (string) $row['title'] : '—') . '</td>'
                . '<td>' . VendorUi::chip(
                    $row['action'] === 'skip' ? 'warning' : 'success',
                    ProductMessages::csvAction((string) $row['action'])
                ) . '</td>'
                . '<td>' . esc_html(ProductMessages::notice($code, $context) ?? VendorMessages::notice($code, $context)) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table></div>';

        if (!$applied) {
            $html .= '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-form">'
                . $nonceField
                . '<input type="hidden" name="tmc_vendor_action" value="apply_products_csv">'
                . '<p class="tv-hint">' . esc_html__('با اعمال، فقط ردیف‌های بالا نوشته می‌شوند. محصول منتشرشده مستقیم تغییر نمی‌کند و تغییرش نسخه پیشنهادی می‌سازد.', 'tecteb-marketplace-core') . '</p>'
                . '<p class="tv-form__actions">' . VendorUi::submit(__('اعمال ورود', 'tecteb-marketplace-core')) . '</p>'
                . '</form>';
        }
        return $html . '<p class="tv-form__actions">'
            . VendorUi::button($urls->products(), __('بازگشت به فهرست محصولات', 'tecteb-marketplace-core'), 'secondary')
            . '</p></section>';
    }
}
