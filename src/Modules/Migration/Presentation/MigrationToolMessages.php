<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Presentation;

use Tecteb\Marketplace\Modules\Migration\Application\ConcludeMigration;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationPlanChecklist;

/**
 * The Persian sentences for concluding the migration, and for the refusals.
 *
 * Presentation, because `ConcludeMigration` is Application and must not call
 * `__()` — the rule this repository paid for in `alpha.9` and restated for the
 * action queue in `alpha.9`'s own round.
 *
 * Every refusal names what to do next. «مرحله‌هایی مانده» without saying WHICH
 * would be the gate with no way out that `alpha.97` describes, so the
 * unfinished steps travel in the context and are translated here by the same
 * `MigrationWizardText` the wizard card uses — one list of step names, two
 * readers.
 */
final class MigrationToolMessages
{
    /**
     * @param array<string,mixed> $context
     */
    public static function of(string $code, array $context = []): string
    {
        return match ($code) {
            'migration_concluded' => __(
                'مهاجرت تمام‌شده ثبت شد. ابزارهای موقت از منوی معمولی برداشته شدند؛ هیچ کد، داده، سند یا مسیر بازگشتی حذف نشده و از صفحهٔ «سلامت» می‌توانید دوباره نمایششان دهید.',
                'tecteb-marketplace-core'
            ),
            'migration_reopened' => __(
                'ابزارهای موقت مهاجرت دوباره در منو نمایش داده می‌شوند.',
                'tecteb-marketplace-core'
            ),
            ConcludeMigration::FORBIDDEN => __(
                'برای این کار مجوز بررسی فروشندگان لازم است.',
                'tecteb-marketplace-core'
            ),
            ConcludeMigration::NOTHING_IMPORTED => __(
                'هیچ فروشگاهی وارد نشده، پس مهاجرتی نیست که تمام‌شده اعلام شود.',
                'tecteb-marketplace-core'
            ),
            ConcludeMigration::ALREADY => __(
                'مهاجرت از قبل تمام‌شده ثبت شده بود؛ چیزی نوشته نشد.',
                'tecteb-marketplace-core'
            ),
            ConcludeMigration::NOT_CONCLUDED => __(
                'مهاجرت تمام‌شده ثبت نشده بود، پس چیزی برای برگرداندن نیست.',
                'tecteb-marketplace-core'
            ),
            ConcludeMigration::NOT_STORED => __(
                'ذخیرهٔ این تصمیم انجام نشد و منو تغییر نکرد. دوباره تلاش کنید.',
                'tecteb-marketplace-core'
            ),
            ConcludeMigration::STEPS_REMAIN => self::stepsRemain($context),
            default => __('این درخواست انجام نشد.', 'tecteb-marketplace-core'),
        };
    }

    /**
     * «These shops still have these steps» — named, not counted.
     *
     * A refusal that said «۳ مرحله مانده» would send a manager back to a
     * twelve-row table to find which three. The step names come from
     * `MigrationWizardText`, so this sentence and the wizard card cannot
     * disagree about what a step is called.
     *
     * @param array<string,mixed> $context
     */
    private static function stepsRemain(array $context): string
    {
        $parts = [];
        foreach ((array) ($context['unfinished'] ?? []) as $vendorUserId => $steps) {
            $names = [];
            foreach ((array) $steps as $step) {
                $name = MigrationWizardText::step((string) $step);
                $names[] = $name === '' ? (string) $step : $name;
            }
            $parts[] = sprintf(
                /* translators: 1: shop id, 2: the unfinished step names */
                __('فروشگاه #%1$s: %2$s', 'tecteb-marketplace-core'),
                \Tecteb\Marketplace\Core\Support\PersianDigits::toPersian((string) (int) $vendorUserId),
                implode('، ', $names)
            );
        }
        if ($parts === []) {
            return __('هنوز مرحله‌هایی از مهاجرت تمام نشده‌اند.', 'tecteb-marketplace-core');
        }
        return sprintf(
            /* translators: %s: shops and their unfinished steps */
            __('تا وقتی هر دوازده مرحلهٔ چک‌لیست برای همهٔ فروشگاه‌های واردشده «انجام شد» نباشد، ابزارها پنهان نمی‌شوند. مانده: %s', 'tecteb-marketplace-core'),
            implode(' · ', $parts)
        );
    }

    /** Every step key this release knows, so a caller can be bound to them. */
    public static function stepKeys(): array
    {
        return MigrationPlanChecklist::ORDER;
    }
}
