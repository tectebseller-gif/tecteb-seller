<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;

/**
 * Every decision taken about one product, newest first.
 *
 * It used to live inside a cell of the manager's list, folded into a
 * `<details>` on every row. That is one query per row and a column that gets
 * wider the longer a product has existed, for something nobody reads while
 * scanning a list — «جزئیات … و سابقهٔ تصمیم‌ها داخل ردیف فهرست باز نشوند». It
 * belongs to one product at a time, which is where it is now.
 *
 * The labels are here and nowhere else: a decision whose name differs between
 * two screens is a decision the manager cannot search for.
 */
final class ProductDecisionHistoryView
{
    /** @param list<ProductDecision> $rows newest first */
    public static function render(array $rows): string
    {
        $html = '<h3 class="tmc-card__subtitle">' . esc_html__('سابقهٔ تصمیم‌ها', 'tecteb-marketplace-core') . '</h3>';
        if ($rows === []) {
            return $html . '<p class="tmc-card__note">'
                . esc_html__('تصمیمی برای این محصول ثبت نشده است.', 'tecteb-marketplace-core') . '</p>';
        }
        $html .= '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('سابقهٔ تصمیم‌ها', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('تاریخ', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تصمیم', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('یادداشت', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<th scope="row" data-label="' . esc_attr__('تاریخ', 'tecteb-marketplace-core') . '">'
                . esc_html(PersianDigits::toPersian(substr($row->createdAt, 0, 16))) . '</th>'
                . '<td data-label="' . esc_attr__('تصمیم', 'tecteb-marketplace-core') . '">'
                . esc_html(self::label($row)) . '</td>'
                . '<td data-label="' . esc_attr__('یادداشت', 'tecteb-marketplace-core') . '">'
                . (trim($row->note) !== '' ? esc_html($row->note) : '—') . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** The latest decision as one line, for the top of a product's page. */
    public static function latest(?ProductDecision $decision): string
    {
        if ($decision === null) {
            return esc_html__('تصمیمی ثبت نشده', 'tecteb-marketplace-core');
        }
        return esc_html(self::label($decision) . ' — ' . PersianDigits::toPersian(substr($decision->createdAt, 0, 16)));
    }

    public static function label(ProductDecision $decision): string
    {
        return match ($decision->decision) {
            ProductDecision::SUBMITTED => __('ارسال برای بررسی', 'tecteb-marketplace-core'),
            ProductDecision::APPROVED => __('تأیید و انتشار', 'tecteb-marketplace-core'),
            ProductDecision::CHANGES_REQUESTED => __('نیازمند اصلاح', 'tecteb-marketplace-core'),
            ProductDecision::REJECTED => __('رد و بایگانی', 'tecteb-marketplace-core'),
            ProductDecision::SUSPENDED => __('تعلیق', 'tecteb-marketplace-core'),
            ProductDecision::RESTORED => __('بازگشت به پیش‌نویس', 'tecteb-marketplace-core'),
            ProductDecision::CORRECTED => __('اصلاح توسط مدیر', 'tecteb-marketplace-core'),
            ProductDecision::FIELD_KEPT => __('نسخهٔ ووکامرس ماند', 'tecteb-marketplace-core'),
            ProductDecision::FIELD_ACCEPTED => __('مقدار بازارگاه اعمال شد', 'tecteb-marketplace-core'),
            // Derived by migration 19 from the single old column. Labelled as
            // such because its DATE is inferred, not recorded.
            ProductDecision::IMPORTED => __('یادداشت قبلی (تاریخش دقیق نیست)', 'tecteb-marketplace-core'),
            default => $decision->decision,
        };
    }
}
