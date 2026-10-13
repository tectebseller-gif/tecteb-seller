<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax;
use Tecteb\Marketplace\Modules\Migration\Application\MinMaxHandoverRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Domain\MinMaxRule;

/**
 * Leaving «Min Max Control»: look first, move second, and be able to go back.
 *
 * Three things on one page, in the order a manager needs them:
 *
 *  1. **what Min Max is holding** — the mode, the counts per level, and the
 *     global and term rules spelled out. Read-only, with no side effect at
 *     all: a preview that wrote something would be a decision taken early.
 *  2. **what would happen** — how many rules convert, how many are refused
 *     and WHY BY NAME, how many the manager already owns here, and how many
 *     would be written onto products this marketplace does not own, where
 *     nothing would enforce them.
 *  3. **the decision** — a batch at a time, resumable, with a rollback per
 *     product.
 *
 * The page says plainly what the migration cannot do. A rule whose step counts
 * from the minimum has no equivalent here, and the honest consequence is that
 * MIN MAX CANNOT BE SWITCHED OFF while such a rule is in force — printed next
 * to the number, because a count of refusals with no sentence beside it reads
 * as a rounding error.
 */
final class MinMaxExitPage
{
    public const SLUG = 'tmc-minmax-exit';
    public const CAPABILITY = Capabilities::REVIEW_VENDOR;
    private const NONCE = 'tmc_minmax_exit';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('خروج از Min Max', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $service = $this->container->get(MigrateMinMax::class);
        $ledger = $this->container->get(MinMaxHandoverRepositoryInterface::class);
        $notice = $this->handleAction(Request::capture(), $service);
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('انتقال قواعد خرید', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(
                str_starts_with($notice, 'ok:') ? 'success' : 'error',
                substr($notice, strpos($notice, ':') + 1)
            );
        }
        echo Components::notice('info', __('این صفحه هیچ‌چیزِ Min Max را تغییر نمی‌دهد: نه تنظیماتش، نه ردیف‌های متایش. آنچه نوشته می‌شود سه ردیف خودِ بازارگاه است (حداقل، حداکثر و گام خرید) روی همان محصول، و برای هر محصول مقدار قبلیِ همان سه ردیف ثبت می‌شود تا بازگشت ممکن باشد.', 'tecteb-marketplace-core'));

        $preview = $service->preview();
        if (($preview['available'] ?? false) !== true) {
            echo Components::notice('warning', __('Min Max Control روی این سایت پیدا نشد. تا نصب و فعال‌بودنش، چیزی برای خواندن نیست — و «نبودِ افزونه روی نصب تمیز» آزمون خروج نیست.', 'tecteb-marketplace-core'));
            echo Components::shellClose();
            return;
        }

        $this->renderMode($preview, $fa);
        $this->renderPreview($preview, $fa);
        $this->renderBlockers($preview, $fa);
        $this->renderLedger($ledger, $fa);
        echo Components::shellClose();
    }

    /** @param array<string,mixed> $preview */
    private function renderMode(array $preview, callable $fa): void
    {
        /** @var array{mode:string, prefix:string, option:string, premium:bool} $mode */
        $mode = $preview['mode'];
        $counts = $preview['counts'];
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('آنچه Min Max نگه داشته', 'tecteb-marketplace-core') . '</h2>';
        echo '<dl class="tmc-data">'
            . '<dt>' . esc_html__('حالت مؤثر', 'tecteb-marketplace-core') . '</dt><dd>'
            . Components::code($mode['mode']) . '</dd>'
            . '<dt>' . esc_html__('پیشوند کلیدهای متا', 'tecteb-marketplace-core') . '</dt><dd>'
            . Components::code($mode['prefix'] === '' ? '(بدون پیشوند)' : $mode['prefix']) . '</dd>'
            . '<dt>' . esc_html__('کلید تنظیمات', 'tecteb-marketplace-core') . '</dt><dd>'
            . Components::code($mode['option']) . '</dd>'
            . '<dt>' . esc_html__('نسخهٔ premium', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html($mode['premium'] ? __('فعال است — قاعدهٔ هر تنوع اجرا می‌شود', 'tecteb-marketplace-core') : __('فعال نیست — قاعدهٔ هر تنوع اجرا نمی‌شود و قاعدهٔ محصول والد اعمال می‌شود', 'tecteb-marketplace-core'))
            . '</dd></dl>';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('سطح', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تعداد قاعده', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ([
            'product' => __('محصول', 'tecteb-marketplace-core'),
            'variation' => __('تنوع', 'tecteb-marketplace-core'),
            'term' => __('دسته/برچسب', 'tecteb-marketplace-core'),
            'global' => __('پیش‌فرض سراسری', 'tecteb-marketplace-core'),
            'default_quantity' => __('تعداد پیش‌فرض جعبه', 'tecteb-marketplace-core'),
        ] as $key => $label) {
            echo '<tr><td>' . esc_html($label) . '</td><td>'
                . esc_html($fa((string) ($counts[$key] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table>';
        if (($preview['global'] ?? null) !== null) {
            $global = $preview['global'];
            echo '<p>' . esc_html(sprintf(
                /* translators: 1: min, 2: max, 3: step */
                __('قاعدهٔ سراسری: حداقل %1$s، حداکثر %2$s، گام %3$s.', 'tecteb-marketplace-core'),
                $fa((string) $global['effective']['min']),
                $global['effective']['max'] === null
                    ? __('بدون سقف', 'tecteb-marketplace-core')
                    : $fa((string) $global['effective']['max']),
                $fa((string) $global['effective']['step'])
            )) . '</p>';
            if ($global['unrestricted'] === true) {
                echo '<p>' . esc_html__('این همان پیش‌فرض نصبِ افزونه است و هیچ محدودیتی اعمال نمی‌کند، پس در این سطح چیزی برای انتقال نیست.', 'tecteb-marketplace-core') . '</p>';
            }
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $preview */
    private function renderPreview(array $preview, callable $fa): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('پیش‌نمایش انتقال (هیچ نوشتنی)', 'tecteb-marketplace-core') . '</h2>';
        echo '<table class="tmc-table"><tbody>';
        foreach ([
            'convertible' => __('قابل انتقال، با همان مجموعهٔ تعدادهای مجاز', 'tecteb-marketplace-core'),
            'refused' => __('غیرقابل انتقال (دلیل‌ها پایین‌تر)', 'tecteb-marketplace-core'),
            'held_by_manager' => __('در بازارگاه قاعدهٔ مدیر دارد — بی‌اجازه بازنویسی نمی‌شود', 'tecteb-marketplace-core'),
            'unenforced' => __('محصولِ غیرِبازارگاه — نوشتن ممکن است ولی چیزی آن را اعمال نمی‌کند', 'tecteb-marketplace-core'),
            'already_decided' => __('قبلاً تصمیم گرفته شده', 'tecteb-marketplace-core'),
            'scanned' => __('بررسی‌شده', 'tecteb-marketplace-core'),
        ] as $key => $label) {
            echo '<tr><td>' . esc_html($label) . '</td><td>'
                . esc_html($fa((string) ($preview[$key] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table>';
        if (($preview['truncated'] ?? false) === true) {
            echo Components::notice('warning', __('پیش‌نمایش به سقف بررسی رسید، پس این اعداد کف‌اند نه کل. انتقال دسته‌ای خودش تا آخر می‌رود.', 'tecteb-marketplace-core'));
        }
        if ((int) ($preview['unenforced'] ?? 0) > 0) {
            echo Components::notice('warning', __('مهم‌ترین عدد این صفحه همین است: روی محصولی که بازارگاه مالکش نیست، گاردِ تعداد خریدِ بازارگاه اصلاً اجرا نمی‌شود. پس انتقال قاعده برای این محصول‌ها ردیف می‌نویسد که هیچ‌کس نمی‌خواندش، و غیرفعال‌کردن Min Max قاعده‌شان را از بین می‌برد. این یک تصمیم مالک است، نه یک اصلاح کد.', 'tecteb-marketplace-core'));
        }

        $examples = $preview['examples'] ?? [];
        if ($examples !== []) {
            echo '<h3 class="tmc-card__title">' . esc_html__('نمونه‌ها، با مجموعهٔ تعدادهای مجاز در هر دو طرف', 'tecteb-marketplace-core') . '</h3>';
            echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('نمونه‌ها', 'tecteb-marketplace-core') . '">';
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('شناسه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('قاعدهٔ Min Max', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('مجاز در Min Max', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('مجاز پس از انتقال', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('نتیجه', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($examples as $row) {
                $rule = $row['rule'];
                echo '<tr><td>' . Components::code((string) $row['post_id']) . '</td>'
                    . '<td>' . esc_html(sprintf(
                        '%s / %s / %s',
                        $fa((string) $rule['effective']['min']),
                        $rule['effective']['max'] === null ? '∞' : $fa((string) $rule['effective']['max']),
                        $fa((string) $rule['effective']['step'])
                    )) . '</td>'
                    . '<td>' . esc_html($fa(implode('، ', $row['allowed_by_minmax']))) . '</td>'
                    . '<td>' . esc_html($fa(implode('، ', $row['allowed_after_conversion']))) . '</td>'
                    . '<td>' . esc_html($this->statusLabel((string) $row['status'], $row['problems'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<h3 class="tmc-card__title">' . esc_html__('انتقال صریح', 'tecteb-marketplace-core') . '</h3>';
        echo '<p>' . esc_html__('هر بار یک دسته. اجرای دوباره روی همان دسته هیچ‌چیز تازه‌ای نمی‌نویسد، چون برای هر محصول فقط یک تصمیم ثبت می‌شود.', 'tecteb-marketplace-core') . '</p>';
        echo '<form method="post" class="tmc-inline-form">'
            . wp_nonce_field(self::NONCE, 'tmc_minmax_nonce', true, false)
            . '<label for="tmc-minmax-cursor">' . esc_html__('ادامه از شناسهٔ', 'tecteb-marketplace-core') . '</label> '
            . '<input type="number" id="tmc-minmax-cursor" name="cursor" min="0" value="'
            . esc_attr((string) $this->storedCursor()) . '"> '
            . '<label><input type="checkbox" name="allow_overwrite" value="1"> '
            . esc_html__('قاعده‌هایی که مدیر در بازارگاه تعیین کرده هم بازنویسی شوند', 'tecteb-marketplace-core')
            . '</label> '
            . '<button type="submit" class="tmc-button" name="minmax_action" value="migrate">'
            . esc_html__('انتقال یک دسته', 'tecteb-marketplace-core') . '</button></form>';
        echo '</section>';
    }

    /** @param array<string,mixed> $preview */
    private function renderBlockers(array $preview, callable $fa): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('چیزهایی که معادل ندارند — و تا وقتی هستند، Min Max خاموش نمی‌شود', 'tecteb-marketplace-core')
            . '</h2>';
        $problems = $preview['problems'] ?? [];
        if ($problems === []) {
            echo '<p>' . esc_html__('هیچ قاعده‌ای که معادل نداشته باشد پیدا نشد.', 'tecteb-marketplace-core') . '</p>';
        } else {
            $sentences = MigrateMinMax::problemSentences();
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('دلیل', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('تعداد', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($problems as $code => $count) {
                echo '<tr><td>' . esc_html($sentences[$code] ?? (string) $code)
                    . ' ' . Components::code((string) $code) . '</td>'
                    . '<td>' . esc_html($fa((string) $count)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h3 class="tmc-card__title">' . esc_html__('و این‌ها هیچ‌وقت منتقل نمی‌شوند', 'tecteb-marketplace-core') . '</h3><ul>';
        foreach (MigrateMinMax::withoutReplacement() as $sentence) {
            echo '<li>' . esc_html($sentence) . '</li>';
        }
        echo '</ul></section>';
    }

    private function renderLedger(MinMaxHandoverRepositoryInterface $ledger, callable $fa): void
    {
        $tally = $ledger->tally();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('دفتر انتقال', 'tecteb-marketplace-core') . '</h2>';
        if ($tally === []) {
            echo '<p>' . esc_html__('هنوز هیچ محصولی منتقل نشده.', 'tecteb-marketplace-core') . '</p>';
            echo '</section>';
            return;
        }
        echo '<table class="tmc-table"><tbody>';
        foreach ($tally as $status => $count) {
            echo '<tr><td>' . esc_html($this->statusLabel((string) $status, [])) . '</td><td>'
                . esc_html($fa((string) $count)) . '</td></tr>';
        }
        echo '</tbody></table>';
        $rows = $ledger->withStatus([
            MinMaxHandoverRepositoryInterface::MIGRATED,
            MinMaxHandoverRepositoryInterface::UNENFORCED,
        ], 50);
        if ($rows !== []) {
            echo '<h3 class="tmc-card__title">' . esc_html__('بازگشت، محصول به محصول', 'tecteb-marketplace-core') . '</h3>';
            echo '<p>' . esc_html__('بازگشت مقدار قبلیِ همان سه ردیف را برمی‌گرداند. اگر پس از انتقال کسی قاعده را عوض کرده باشد، بازگشت رد می‌شود و می‌گوید کدام فیلد عوض شده — تغییر جدیدتر کورکورانه پاک نمی‌شود.', 'tecteb-marketplace-core') . '</p>';
            echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('بازگشت', 'tecteb-marketplace-core') . '">';
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('شناسه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('منبع', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('نوشته شد', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('قبلاً', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('بازگشت', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><td>' . Components::code((string) $row['post_id']) . '</td>'
                    . '<td>' . esc_html(sprintf('%s / %s / %s', (string) $row['source_min'], (string) $row['source_max'], (string) $row['source_step'])) . '</td>'
                    . '<td>' . esc_html(sprintf('%s / %s / %s', (string) $row['written_min'], (string) $row['written_max'], (string) $row['written_step'])) . '</td>'
                    . '<td>' . esc_html($this->beforeLabel($row)) . '</td>'
                    . '<td><form method="post" class="tmc-inline-form">'
                    . wp_nonce_field(self::NONCE, 'tmc_minmax_nonce', true, false)
                    . '<input type="hidden" name="post_id" value="' . esc_attr((string) $row['post_id']) . '">'
                    . '<button type="submit" class="tmc-button tmc-button--quiet" name="minmax_action" value="rollback">'
                    . esc_html__('بازگشت', 'tecteb-marketplace-core') . '</button></form></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $row */
    private function beforeLabel(array $row): string
    {
        $parts = [];
        foreach (['dest_min_before', 'dest_max_before', 'dest_step_before'] as $key) {
            $value = $row[$key] ?? null;
            // «There was no row» is printed as such. An empty cell beside an
            // empty string would make the two look alike, and the whole
            // rollback depends on them being different.
            $parts[] = $value === null
                ? __('ردیفی نبود', 'tecteb-marketplace-core')
                : ($value === '' ? __('خالی', 'tecteb-marketplace-core') : (string) $value);
        }
        return implode(' / ', $parts);
    }

    /** @param list<string> $problems */
    private function statusLabel(string $status, array $problems): string
    {
        $base = match ($status) {
            MinMaxHandoverRepositoryInterface::MIGRATED => __('منتقل شد', 'tecteb-marketplace-core'),
            MinMaxHandoverRepositoryInterface::REFUSED => __('رد شد', 'tecteb-marketplace-core'),
            MinMaxHandoverRepositoryInterface::HELD => __('قاعدهٔ مدیر — دست نخورد', 'tecteb-marketplace-core'),
            MinMaxHandoverRepositoryInterface::UNENFORCED => __('نوشته شد ولی اعمال نمی‌شود', 'tecteb-marketplace-core'),
            MinMaxHandoverRepositoryInterface::ROLLED_BACK => __('بازگردانده شد', 'tecteb-marketplace-core'),
            default => $status,
        };
        if ($problems === []) {
            return $base;
        }
        $sentences = MigrateMinMax::problemSentences();
        $named = array_map(static fn (string $p): string => $sentences[$p] ?? $p, $problems);
        return $base . ' — ' . implode('، ', $named);
    }

    private function storedCursor(): int
    {
        return (int) get_option('tmc_minmax_cursor', 0);
    }

    private function handleAction(Request $request, MigrateMinMax $service): string
    {
        if (!$request->isPost() || !$request->hasPost('minmax_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_minmax_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $action = $request->postKey('minmax_action');
        if ($action === 'migrate') {
            $cursor = (int) $request->postKey('cursor');
            // The checkbox carries the permission, and an unticked checkbox is
            // not sent at all (`alpha.46`) — so absence is «no», which is the
            // answer that changes nothing.
            $allow = $request->postKey('allow_overwrite') === '1';
            $batch = $service->migrateBatch(
                (int) get_option('tmc_minmax_run', 0) + 1,
                $cursor,
                $allow
            );
            if (($batch['ok'] ?? false) !== true) {
                return 'err:' . __('Min Max روی این سایت خوانده نشد، پس چیزی منتقل نشد.', 'tecteb-marketplace-core');
            }
            update_option('tmc_minmax_cursor', (int) $batch['cursor'], false);
            update_option('tmc_minmax_run', (int) get_option('tmc_minmax_run', 0) + 1, false);
            $counts = $batch['counts'];
            return 'ok:' . sprintf(
                /* translators: 1: migrated, 2: refused, 3: held, 4: unenforced, 5: cursor */
                __('دسته انجام شد: %1$s منتقل، %2$s رد، %3$s قاعدهٔ مدیر، %4$s نوشته‌شدهٔ اعمال‌نشدنی. ادامه از شناسهٔ %5$s.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($counts[MinMaxHandoverRepositoryInterface::MIGRATED] ?? 0)),
                PersianDigits::toPersian((string) ($counts[MinMaxHandoverRepositoryInterface::REFUSED] ?? 0)),
                PersianDigits::toPersian((string) ($counts[MinMaxHandoverRepositoryInterface::HELD] ?? 0)),
                PersianDigits::toPersian((string) ($counts[MinMaxHandoverRepositoryInterface::UNENFORCED] ?? 0)),
                PersianDigits::toPersian((string) ($batch['cursor'] ?? 0))
            ) . ($batch['done'] === true ? ' ' . __('به آخر رسید.', 'tecteb-marketplace-core') : '');
        }
        if ($action === 'rollback') {
            $result = $service->rollback((int) $request->postKey('post_id'));
            if ($result->ok) {
                return 'ok:' . __('سه ردیف به مقدار قبلِ انتقال برگشت.', 'tecteb-marketplace-core');
            }
            return 'err:' . $this->rollbackMessage($result->code, $result->context);
        }
        return 'err:' . __('اقدام ناشناخته بود.', 'tecteb-marketplace-core');
    }

    /** @param array<string,mixed> $context */
    private function rollbackMessage(string $code, array $context): string
    {
        return match ($code) {
            'minmax_rollback_changed' => sprintf(
                /* translators: 1: field, 2: what we wrote, 3: what is there now */
                __('بازگشت انجام نشد: «%1$s» پس از انتقال عوض شده. ما %2$s نوشته بودیم و الان %3$s است. تصمیم جدیدتر کورکورانه پاک نمی‌شود؛ اگر می‌خواهید برگردد، اول آن را در ووکامرس به مقدار نوشته‌شده برگردانید.', 'tecteb-marketplace-core'),
                (string) ($context['field'] ?? ''),
                PersianDigits::toPersian((string) ($context['wrote'] ?? '')),
                PersianDigits::toPersian((string) ($context['now'] ?? ''))
            ),
            'minmax_rollback_nothing' => __('برای این محصول تصمیمی ثبت نشده، پس چیزی برای بازگشت نیست.', 'tecteb-marketplace-core'),
            'minmax_rollback_already' => __('این محصول قبلاً برگردانده شده.', 'tecteb-marketplace-core'),
            'minmax_rollback_not_written' => __('برای این محصول چیزی نوشته نشده بود (رد یا قاعدهٔ مدیر)، پس بازگشتی هم ندارد.', 'tecteb-marketplace-core'),
            'minmax_rollback_not_stored' => __('بازگشت ذخیره نشد. سه ردیف ممکن است نیمه برگشته باشند؛ همین محصول را دوباره بررسی کنید.', 'tecteb-marketplace-core'),
            'minmax_rollback_unrecorded' => __('ردیف‌ها برگشتند ولی دفتر ثبت نشد. دوباره تلاش نکنید تا دفتر بررسی شود.', 'tecteb-marketplace-core'),
            default => $code,
        };
    }

    /** The levels, for the tests to name without reaching into the domain. */
    public static function levels(): array
    {
        return [MinMaxRule::AT_PRODUCT, MinMaxRule::AT_VARIATION, MinMaxRule::AT_TERM, MinMaxRule::AT_GLOBAL];
    }
}
