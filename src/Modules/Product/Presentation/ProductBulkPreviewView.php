<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * What a bulk action WOULD do to each selected product, before it does it.
 *
 * «۴۰ مورد انتخاب شد» is not the fact the vendor needs. The fact they need is
 * which of the forty are going to move, and «ارسال برای بررسی» has no undo:
 * once thirty-six items are in the manager's queue, taking them back is the
 * manager's work, not a button.
 *
 * The confirm button posts the SAME action the list's own button posts, with
 * the same ids — so what runs afterwards is not a special «apply the preview»
 * path that could drift from the ordinary one. And because it re-plans every
 * row, a product somebody else changed in between is reported with its new
 * answer rather than the one shown here. That is why the page says this is a
 * forecast.
 */
final class ProductBulkPreviewView
{
    /**
     * @param array{action:string,rows:list<array{product_id:int,ok:bool,code:string,title:string,from:string,to:string}>,ok:int,failed:int} $preview
     */
    public static function render(array $preview, VendorUrls $urls, string $nonceField): string
    {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $rows = $preview['rows'] ?? [];
        $action = (string) ($preview['action'] ?? '');
        $willGo = (int) ($preview['ok'] ?? 0);
        $willNot = (int) ($preview['failed'] ?? 0);

        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html(sprintf(
                /* translators: %s: the bulk action's name, e.g. «ارسال برای بررسی» */
                __('پیش‌نمایش «%s»', 'tecteb-marketplace-core'),
                ProductMessages::bulkAction($action)
            )) . '</h2>';

        $html .= VendorUi::notice(
            $willNot > 0 ? 'warning' : 'info',
            (string) ProductMessages::notice('bulk_previewed', [
                'action' => $action,
                'ok' => $willGo,
                'failed' => $willNot,
            ])
        );

        if ($rows === []) {
            $html .= VendorUi::notice('warning', (string) ProductMessages::notice('nothing_selected'));
            return $html . self::backLink($urls) . '</section>';
        }

        $html .= '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('نتیجهٔ پیش‌بینی‌شده برای هر مورد انتخاب‌شده', 'tecteb-marketplace-core') . '">'
            . '<table class="tv-table"><caption class="tv-visually-hidden">'
            . esc_html__('موارد انتخاب‌شده و نتیجه‌ای که این اقدام روی هرکدام خواهد داشت', 'tecteb-marketplace-core')
            . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت فعلی', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('نتیجه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('توضیح', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $ok = (bool) ($row['ok'] ?? false);
            $code = (string) ($row['code'] ?? '');
            $title = (string) ($row['title'] ?? '');
            $html .= '<tr>'
                . '<td>' . esc_html($title !== '' ? $title : sprintf(
                    /* translators: %s: the product's id, shown when it has no title yet */
                    __('محصول %s', 'tecteb-marketplace-core'),
                    $fa((int) ($row['product_id'] ?? 0))
                )) . '</td>'
                . '<td>' . self::statusCell((string) ($row['from'] ?? '')) . '</td>'
                . '<td>' . VendorUi::chip(
                    $ok ? 'success' : 'warning',
                    $ok ? __('انجام می‌شود', 'tecteb-marketplace-core') : __('انجام نمی‌شود', 'tecteb-marketplace-core')
                ) . '</td>'
                // The refused row's own sentence — the same one the single-row
                // action would have shown, because it is the same code.
                . '<td>' . esc_html(self::explain($ok, $code, (string) ($row['to'] ?? ''))) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table></div>';

        $html .= '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-form">'
            . $nonceField
            // The ordinary bulk action, not an «apply preview» of its own.
            . '<input type="hidden" name="tmc_vendor_action" value="bulk_products">'
            . '<input type="hidden" name="bulk_action" value="' . esc_attr($action) . '">';
        foreach ($rows as $row) {
            $html .= '<input type="hidden" name="selected[]" value="' . esc_attr((string) ($row['product_id'] ?? 0)) . '">';
        }
        $html .= '<p class="tv-hint">'
            . esc_html__('این پیش‌بینی است، نه تضمین: اگر همکارتان در همین فاصله یکی از این محصول‌ها را ذخیره کند، نتیجهٔ آن یکی عوض می‌شود و در گزارش پس از اجرا گفته خواهد شد. موردهای «انجام نمی‌شود» هم فرستاده می‌شوند و همان‌جا رد می‌شوند؛ چیزی از قلم نمی‌افتد.', 'tecteb-marketplace-core')
            . '</p>'
            . '<p class="tv-form__actions">'
            . VendorUi::submit(
                $willGo > 0
                    ? sprintf(
                        /* translators: %s: how many of the selected products would go through */
                        __('تأیید و اجرا روی %s مورد', 'tecteb-marketplace-core'),
                        $fa($willGo)
                    )
                    : __('تأیید و اجرا', 'tecteb-marketplace-core')
            )
            . '</p></form>';

        return $html . self::backLink($urls) . '</section>';
    }

    /**
     * The same table, AFTER the run — what happened to each row and why.
     *
     * The owner pressed a bulk action and was told «۰ مورد انجام شد و ۴ مورد
     * انجام نشد» with nothing after the colon. The reasons existed the whole
     * time; they were dropped on the way to the page (`VendorNotice` kept
     * only scalars, and the refusal list is an array). Rather than repair the
     * sentence, the rows are shown — the same shape, the same wording and the
     * same `explain()` the forecast uses, so «پیش‌بینی» and «آنچه شد» can
     * never describe the same row differently.
     *
     * @param array{action:string,rows:list<array{product_id:int,ok:bool,code:string,title:string,from:string,to:string}>,ok:int,failed:int} $report
     */
    public static function renderResult(array $report, VendorUrls $urls): string
    {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $rows = $report['rows'] ?? [];
        $action = (string) ($report['action'] ?? '');
        $done = (int) ($report['ok'] ?? 0);
        $not = (int) ($report['failed'] ?? 0);

        // Three shapes, deliberately not one with a number in it: «همه رفت»,
        // «بعضی رفت» and «هیچ‌کدام نرفت» are three different things to do
        // next, and a single grey box makes them look like one.
        $code = $not === 0 ? 'bulk_done' : ($done === 0 ? 'bulk_none' : 'bulk_partial');
        $tone = $not === 0 ? 'success' : ($done === 0 ? 'error' : 'warning');

        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html(sprintf(
                /* translators: %s: the bulk action's name */
                __('نتیجهٔ «%s»', 'tecteb-marketplace-core'),
                ProductMessages::bulkAction($action)
            )) . '</h2>';
        $html .= VendorUi::notice($tone, (string) ProductMessages::notice($code, [
            'action' => $action,
            'ok' => $done,
            'failed' => $not,
        ]));

        if ($rows === []) {
            return $html . self::backLink($urls) . '</section>';
        }

        $html .= '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('نتیجهٔ اجرا برای هر مورد انتخاب‌شده', 'tecteb-marketplace-core') . '">'
            . '<table class="tv-table"><caption class="tv-visually-hidden">'
            . esc_html__('موارد انتخاب‌شده و آنچه این اقدام با هرکدام کرد', 'tecteb-marketplace-core')
            . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت کنونی', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('نتیجه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('توضیح', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $ok = (bool) ($row['ok'] ?? false);
            $title = (string) ($row['title'] ?? '');
            $html .= '<tr>'
                . '<td>' . esc_html($title !== '' ? $title : sprintf(
                    /* translators: %s: the product's id, shown when it has no title yet */
                    __('محصول %s', 'tecteb-marketplace-core'),
                    $fa((int) ($row['product_id'] ?? 0))
                )) . '</td>'
                . '<td>' . self::statusCell((string) ($row['to'] ?? '')) . '</td>'
                . '<td>' . VendorUi::chip(
                    $ok ? 'success' : 'warning',
                    $ok ? __('انجام شد', 'tecteb-marketplace-core') : __('انجام نشد', 'tecteb-marketplace-core')
                ) . '</td>'
                . '<td>' . esc_html(self::explainResult($ok, (string) ($row['code'] ?? ''), (string) ($row['to'] ?? '')))
                . '</td></tr>';
        }

        return $html . '</tbody></table></div>' . self::backLink($urls) . '</section>';
    }

    /** Past tense for a row that ran; the refusal's own sentence for one that did not. */
    private static function explainResult(bool $ok, string $code, string $to): string
    {
        if (!$ok) {
            return ProductMessages::notice($code) ?? VendorMessages::notice($code) ?? $code;
        }
        $case = ProductStatus::tryFrom($to);
        return $case === null
            ? (string) (ProductMessages::notice($code) ?? '')
            : sprintf(
                /* translators: %s: the status the product moved to */
                __('به «%s» رفت.', 'tecteb-marketplace-core'),
                ProductMessages::status($case)
            );
    }

    private static function backLink(VendorUrls $urls): string
    {
        // A way out that is not the confirm button. A preview whose only
        // control commits it is not a preview.
        return '<p class="tv-form__actions">'
            . VendorUi::button($urls->products(), __('بازگشت بدون اجرا', 'tecteb-marketplace-core'), 'secondary')
            . '</p>';
    }

    private static function statusCell(string $status): string
    {
        $case = ProductStatus::tryFrom($status);
        if ($case === null) {
            return '—';
        }
        return VendorUi::chip(ProductMessages::statusTone($case), ProductMessages::status($case));
    }

    private static function explain(bool $ok, string $code, string $to): string
    {
        if (!$ok) {
            return ProductMessages::notice($code) ?? VendorMessages::notice($code) ?? $code;
        }
        $case = ProductStatus::tryFrom($to);
        return $case === null
            ? (string) (ProductMessages::notice($code) ?? '')
            : sprintf(
                /* translators: %s: the status the product would move to */
                __('به «%s» می‌رود.', 'tecteb-marketplace-core'),
                ProductMessages::status($case)
            );
    }
}
