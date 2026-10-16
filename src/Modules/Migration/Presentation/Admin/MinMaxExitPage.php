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
use Tecteb\Marketplace\Modules\Migration\Application\ShopQuantityPortInterface;
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
        $this->renderLedger($ledger, $service, $fa);
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
            // FORWARD-LOOKING, because this table is a prediction. The
            // earlier wording («نوشته شد ولی اعمال نمی‌شود») described a
            // write that has not happened: the preview writes nothing at all,
            // and a manager reading a past tense here would believe the
            // migration had already run — «پیش‌نمایش را عملیات انجام‌شده نشان
            // نده». The ledger's own status keeps the past tense, because
            // there it IS the past.
            'unenforced' => __('محصولِ غیرِبازارگاه — قاعده نوشته می‌شود، ولی تا صدور «مجوز اعمال» چیزی آن را اعمال نمی‌کند', 'tecteb-marketplace-core'),
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
            echo Components::notice('warning', __('مهم‌ترین عدد این صفحه همین است: روی محصولی که بازارگاه مالکش نیست، گاردِ تعداد خریدِ بازارگاه به‌خودیِ‌خود اجرا نمی‌شود. پس انتقالِ قاعده برای این محصول‌ها ردیفی می‌نویسد که تا صدور مجوز، کسی نمی‌خواندش — و غیرفعال‌کردن Min Max بی آن مجوز، قاعده‌شان را از بین می‌برد. از این نسخه راهی برای همین حالت هست: پس از انتقال، در بخش «مجوز اعمال قاعده» می‌توانید محصول‌به‌محصول اجازه دهید بازارگاه فقط همان قاعدهٔ تعداد خرید را اعمال کند. این مجوز نه فروشنده را عوض می‌کند، نه مالکیت محصول را، نه چرخهٔ تأییدش را.', 'tecteb-marketplace-core'));
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

    private function renderLedger(MinMaxHandoverRepositoryInterface $ledger, MigrateMinMax $service, callable $fa): void
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
            // A granted product is rollable back too: the rollback takes the
            // grant with it, because the grant was about the rule this
            // migration wrote.
            MinMaxHandoverRepositoryInterface::ENFORCED,
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
                . '<th scope="col">' . esc_html__('سابقه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('بازگشت', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($rows as $row) {
                // Earlier decisions about the SAME product — a rolled-back or
                // released row stays on record, so «this one has been through
                // this before» is a thing the page can say. One is this row
                // itself, so only a second one is history.
                $earlier = max(0, count($service->historyFor((int) $row['post_id'], 20)) - 1);
                echo '<tr><td>' . Components::code((string) $row['post_id']) . '</td>'
                    . '<td>' . esc_html(sprintf('%s / %s / %s', (string) $row['source_min'], (string) $row['source_max'], (string) $row['source_step'])) . '</td>'
                    . '<td>' . esc_html(sprintf('%s / %s / %s', (string) $row['written_min'], (string) $row['written_max'], (string) $row['written_step'])) . '</td>'
                    . '<td>' . esc_html($this->beforeLabel($row)) . '</td>'
                    . '<td>' . esc_html($earlier === 0
                        ? __('اولین تصمیم', 'tecteb-marketplace-core')
                        : sprintf(
                            /* translators: 1: how many earlier decisions */
                            __('%1$s تصمیم قبلی', 'tecteb-marketplace-core'),
                            PersianDigits::toPersian((string) $earlier)
                        )) . '</td>'
                    . '<td><form method="post" class="tmc-inline-form">'
                    . wp_nonce_field(self::NONCE, 'tmc_minmax_nonce', true, false)
                    . '<input type="hidden" name="post_id" value="' . esc_attr((string) $row['post_id']) . '">'
                    . '<button type="submit" class="tmc-button tmc-button--quiet" name="minmax_action" value="rollback">'
                    . esc_html__('بازگشت', 'tecteb-marketplace-core') . '</button></form></td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // The products whose rule is written and NOT APPLIED, with the one
        // button that changes that — and the granted ones, with the button
        // that takes it back.
        //
        // This is the `written_unenforced` state given somewhere to go. Until
        // `alpha.55` it was a number on a page with no action beside it: the
        // rule was on the product, correct, and read by nothing, because
        // `QuantityGuard::ruleFor()` returns before reading it when the
        // product is not the marketplace's. The owner's own site has twelve
        // products in exactly that state.
        $unenforced = $this->liveRows($ledger, MinMaxHandoverRepositoryInterface::UNENFORCED);
        $enforced = $this->liveRows($ledger, MinMaxHandoverRepositoryInterface::ENFORCED);
        /** @var ShopQuantityPortInterface $port */
        $port = $this->container->get(ShopQuantityPortInterface::class);
        if ($unenforced !== [] || $enforced !== []) {
            echo '<h3 class="tmc-card__title">' . esc_html__('مجوز اعمال قاعده', 'tecteb-marketplace-core') . '</h3>';
            // ABOVE the table, not only inside it. A grant is the fingerprint
            // of the three values it was given for, so a rule edited
            // afterwards leaves the grant alive and dead: from that moment
            // the product allows any quantity. The per-row cell says so, but
            // a manager who does not scan every row would not see it, and
            // «هیچ حد خریدی بی هشدار از بین نرود» is about this exact state.
            $stale = $service->staleGrants();
            if ($stale !== []) {
                echo Components::notice('warning', sprintf(
                    /* translators: 1: how many products, 2: their ids */
                    __('%1$s محصول مجوز دارند ولی قاعده‌شان پس از صدور مجوز عوض شده، پس همین حالا هیچ حد خریدی روی آن‌ها اعمال نمی‌شود: %2$s. قاعده را به همان مقداری برگردانید که منتقل شده بود، یا بازگشت بزنید و دوباره منتقل و مجوز کنید. صدور دوبارهٔ مجوز روی قاعدهٔ عوض‌شده رد می‌شود.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) count($stale)),
                    PersianDigits::toPersian(implode('، ', array_map('strval', $stale)))
                ));
            }
            echo '<p>' . esc_html__('این محصول‌ها قاعدهٔ تعداد خرید را دارند ولی بازارگاه مالکشان نیست، پس به‌خودیِ‌خود قاعده اعمال نمی‌شود. «اعمال شود» فقط و فقط همین یک قاعده را روشن می‌کند: فروشنده، مالکیت محصول، وضعیت انتشار و چرخهٔ تأیید دست‌نخورده می‌مانند و هیچ صفحهٔ دیگری دربارهٔ این محصول نظر نمی‌دهد. اگر پس از انتقال کسی قاعده را عوض کرده باشد، مجوز رد می‌شود و می‌گوید کدام فیلد عوض شده.', 'tecteb-marketplace-core') . '</p>';
            echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('مجوز اعمال قاعده', 'tecteb-marketplace-core') . '">';
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('شناسه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('قاعدهٔ نوشته‌شده', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('الان اعمال می‌شود؟', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ([[$unenforced, 'enforce', __('اعمال شود', 'tecteb-marketplace-core')],
                      [$enforced, 'unenforce', __('مجوز برداشته شود', 'tecteb-marketplace-core')]] as [$set, $verb, $label]) {
                foreach ($set as $row) {
                    echo '<tr><td>' . Components::code((string) $row['post_id']) . '</td>'
                        . '<td>' . esc_html(sprintf(
                            '%s / %s / %s',
                            (string) $row['written_min'],
                            (string) $row['written_max'] === '' ? '∞' : (string) $row['written_max'],
                            (string) $row['written_step']
                        )) . '</td>'
                        . '<td>' . esc_html($this->statusLabel((string) $row['status'], [])) . '</td>'
                        . '<td>' . esc_html($this->liveAnswer($port, $row, $verb)) . '</td>'
                        . '<td><form method="post" class="tmc-inline-form">'
                        . wp_nonce_field(self::NONCE, 'tmc_minmax_nonce', true, false)
                        . '<input type="hidden" name="post_id" value="' . esc_attr((string) $row['post_id']) . '">'
                        . '<button type="submit" class="tmc-button tmc-button--quiet" name="minmax_action" value="' . esc_attr($verb) . '">'
                        . esc_html($label) . '</button></form></td></tr>';
                }
            }
            echo '</tbody></table></div>';
        }

        // The products that got an answer but no write, with the one button
        // that sends them back for another decision. Without it, a refused or
        // held product is decided about for ever: `find()` returns its live
        // row and the next batch answers `already_decided` — measured on
        // `alpha.53`, `retry_migrated=0 retry_already_decided=1` after the
        // cause had been removed.
        $open = $ledger->withStatus([
            MinMaxHandoverRepositoryInterface::REFUSED,
            MinMaxHandoverRepositoryInterface::HELD,
        ], 50);
        $open = array_values(array_filter($open, static fn (array $r): bool => ($r['live_key'] ?? null) !== null));
        if ($open !== []) {
            echo '<h3 class="tmc-card__title">' . esc_html__('تلاش دوباره، محصول به محصول', 'tecteb-marketplace-core') . '</h3>';
            echo '<p>' . esc_html__('این محصول‌ها تصمیم گرفته شده‌اند و چیزی برایشان نوشته نشده. علتشان با دست رفع می‌شود — قاعدهٔ گام در Min Max، یا مقداری که مدیر در ووکامرس نگه داشته — و این افزونه نمی‌تواند بفهمد که رفع شده. «آزاد کردن» محصول را به صف تصمیم برمی‌گرداند و سابقهٔ تصمیم قبلی را نگه می‌دارد.', 'tecteb-marketplace-core') . '</p>';
            echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('تلاش دوباره', 'tecteb-marketplace-core') . '">';
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('شناسه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('منبع', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('تلاش دوباره', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($open as $row) {
                $problems = (string) ($row['problems'] ?? '') === ''
                    ? []
                    : explode(',', (string) $row['problems']);
                echo '<tr><td>' . Components::code((string) $row['post_id']) . '</td>'
                    . '<td>' . esc_html(sprintf('%s / %s / %s', (string) $row['source_min'], (string) $row['source_max'], (string) $row['source_step'])) . '</td>'
                    . '<td>' . esc_html($this->statusLabel((string) $row['status'], $problems)) . '</td>'
                    . '<td><form method="post" class="tmc-inline-form">'
                    . wp_nonce_field(self::NONCE, 'tmc_minmax_nonce', true, false)
                    . '<input type="hidden" name="post_id" value="' . esc_attr((string) $row['post_id']) . '">'
                    . '<button type="submit" class="tmc-button tmc-button--quiet" name="minmax_action" value="release">'
                    . esc_html__('آزاد کردن برای تصمیم دوباره', 'tecteb-marketplace-core') . '</button></form></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }

    /**
     * Whether the grant is enforcing ANYTHING RIGHT NOW.
     *
     * The ledger status says what was decided; this says what the cart does,
     * and after `alpha.55` the two can disagree in one direction: the grant is
     * stored as a fingerprint of the rule it was given for, so a rule somebody
     * changed afterwards is not enforced. A page that printed only
     * `rule_enforced` would be a row shown and not applied — the failure this
     * repository has found in four rounds — so the live answer is asked HERE,
     * once, and the table prints it beside the status.
     *
     * It costs two statements per GRANTED row and none for the rest, because
     * only a granted row can be in that disagreement. This page lists at most
     * fifty of each, is opened by hand during a migration, and is the one
     * place where «what does the cart do» has to be right rather than cheap.
     *
     * @param array<string,mixed> $row
     */
    private function liveAnswer(ShopQuantityPortInterface $port, array $row, string $verb): string
    {
        if ($verb !== 'unenforce') {
            return __('نه — مجوز صادر نشده', 'tecteb-marketplace-core');
        }
        if ($port->enforcementGranted((int) $row['post_id'])) {
            return __('بله', 'tecteb-marketplace-core');
        }
        return __('نه — قاعده پس از صدور مجوز عوض شده؛ مجوز را دوباره صادر کنید', 'tecteb-marketplace-core');
    }

    /**
     * The LIVE rows in one status — the ones a button may act on.
     *
     * A released or rolled-back row keeps its status and loses its
     * `live_key`, which is what makes it history; offering an action on it
     * would be offering to decide a product twice.
     *
     * @return list<array<string,mixed>>
     */
    private function liveRows(MinMaxHandoverRepositoryInterface $ledger, string $status): array
    {
        return array_values(array_filter(
            $ledger->withStatus([$status], 50),
            static fn (array $r): bool => ($r['live_key'] ?? null) !== null
        ));
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
            MinMaxHandoverRepositoryInterface::UNENFORCED => __('نوشته شد — مجوز اعمال صادر نشده، پس اعمال نمی‌شود', 'tecteb-marketplace-core'),
            MinMaxHandoverRepositoryInterface::ENFORCED => __('نوشته شد و با مجوز مدیر اعمال می‌شود', 'tecteb-marketplace-core'),
            MinMaxHandoverRepositoryInterface::ROLLED_BACK => __('بازگردانده شد', 'tecteb-marketplace-core'),
            // A released row is history, counted apart from the live ones: a
            // refusal the manager has already sent back for a retry must not
            // make the exit report say a rule is still blocking.
            MinMaxHandoverRepositoryInterface::REFUSED . '_released' => __('رد شد — برای تصمیم دوباره آزاد شده', 'tecteb-marketplace-core'),
            MinMaxHandoverRepositoryInterface::HELD . '_released' => __('قاعدهٔ مدیر — برای تصمیم دوباره آزاد شده', 'tecteb-marketplace-core'),
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
            if (($batch['code'] ?? '') === 'minmax_absent') {
                return 'err:' . __('Min Max روی این سایت خوانده نشد، پس چیزی منتقل نشد.', 'tecteb-marketplace-core');
            }
            // The cursor is stored whether the batch finished or stopped,
            // because in BOTH cases it is the last product that got an answer
            // — and when it stopped, it stops BEFORE the failed product, so
            // the next continuation looks at that product again. `alpha.53`
            // stored a cursor past the failure and the product was never seen
            // again by any later pass.
            update_option('tmc_minmax_cursor', (int) $batch['cursor'], false);
            update_option('tmc_minmax_run', (int) get_option('tmc_minmax_run', 0) + 1, false);
            $counts = $batch['counts'];
            $done = sprintf(
                /* translators: 1: migrated, 2: refused, 3: held, 4: unenforced, 5: cursor */
                __('%1$s منتقل، %2$s رد، %3$s قاعدهٔ مدیر، %4$s نوشته‌شدهٔ اعمال‌نشدنی. ادامه از شناسهٔ %5$s.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($counts[MinMaxHandoverRepositoryInterface::MIGRATED] ?? 0)),
                PersianDigits::toPersian((string) ($counts[MinMaxHandoverRepositoryInterface::REFUSED] ?? 0)),
                PersianDigits::toPersian((string) ($counts[MinMaxHandoverRepositoryInterface::HELD] ?? 0)),
                PersianDigits::toPersian((string) ($counts[MinMaxHandoverRepositoryInterface::UNENFORCED] ?? 0)),
                PersianDigits::toPersian((string) ($batch['cursor'] ?? 0))
            );
            $stoppedAt = (int) ($batch['stopped_at'] ?? 0);
            if ($stoppedAt > 0) {
                // The product is NAMED. «خطا با شناسهٔ محصول نمایش داده شود» —
                // and a count on its own would leave the manager scanning a
                // catalogue for the one product that did not move.
                $failed = $batch['failed'][0]['outcome'] ?? '';
                return 'err:' . sprintf(
                    /* translators: 1: product id, 2: reason, 3: the batch counts so far */
                    __('دسته روی محصول #%1$s ایستاد و جلوتر نرفت: %2$s. تا پیش از آن: %3$s همان محصول در ادامهٔ بعدی دوباره بررسی می‌شود و محصول‌های پیش از آن دوباره نوشته نمی‌شوند.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $stoppedAt),
                    $this->outcomeMessage((string) $failed),
                    $done
                );
            }
            return 'ok:' . __('دسته انجام شد: ', 'tecteb-marketplace-core') . $done
                . (($batch['done'] ?? false) === true ? ' ' . __('به آخر رسید.', 'tecteb-marketplace-core') : '');
        }
        if ($action === 'rollback') {
            $result = $service->rollback((int) $request->postKey('post_id'));
            if ($result->ok) {
                return 'ok:' . __('سه ردیف، دفتر مالکیت و وضعیت سابقه با هم به حالت پیش از انتقال برگشتند.', 'tecteb-marketplace-core');
            }
            return 'err:' . $this->rollbackMessage($result->code, $result->context);
        }
        if ($action === 'enforce' || $action === 'unenforce') {
            $postId = (int) $request->postKey('post_id');
            $result = $action === 'enforce'
                ? $service->enforce($postId)
                : $service->stopEnforcing($postId);
            if ($result->ok) {
                return 'ok:' . ($action === 'enforce'
                    ? __('از این لحظه بازارگاه فقط قاعدهٔ تعداد خریدِ همین محصول را اعمال می‌کند — در سبد کلاسیک و از Store API. فروشنده، مالکیت محصول و چرخهٔ تأییدش عوض نشدند.', 'tecteb-marketplace-core')
                    : __('مجوز برداشته شد. سه ردیف قاعده سر جایشان هستند و دیگر اعمال نمی‌شوند؛ این بازگشت نیست و مقصد دست‌نخورده است.', 'tecteb-marketplace-core'));
            }
            return 'err:' . $this->rollbackMessage($result->code, $result->context);
        }
        if ($action === 'release') {
            $result = $service->release((int) $request->postKey('post_id'));
            if ($result->ok) {
                return 'ok:' . __('این محصول برای تصمیم دوباره آزاد شد. سابقهٔ تصمیم قبلی سر جایش است و دستهٔ بعدی دوباره دربارهٔ آن می‌پرسد.', 'tecteb-marketplace-core');
            }
            return 'err:' . $this->rollbackMessage($result->code, $result->context);
        }
        return 'err:' . __('اقدام ناشناخته بود.', 'tecteb-marketplace-core');
    }

    /**
     * Why one product got no answer, in a sentence.
     *
     * Each of these is a REFUSAL BEFORE ANY WRITE or a transaction that went
     * back, so in every case the product's destination is exactly as it was —
     * which is the thing the manager needs to know before deciding whether to
     * press continue again.
     */
    private function outcomeMessage(string $outcome): string
    {
        return match ($outcome) {
            'not_written' => __('نوشتن سه ردیف مقصد تأیید نشد، پس هیچ‌چیز ثبت نشد.', 'tecteb-marketplace-core'),
            'history_not_written' => __('ردیف سابقه ثبت نشد، پس کل انتقال این محصول برگشت — مقصد دست‌نخورده است.', 'tecteb-marketplace-core'),
            'not_committed' => __('تراکنش نهایی نشد، پس هیچ‌چیز ذخیره نشد.', 'tecteb-marketplace-core'),
            'no_transaction', 'not_atomic' => __('تراکنش باز نشد. انتقال بی تراکنش انجام نمی‌شود، چون نیمهٔ انجام‌شده از هیچ بدتر است.', 'tecteb-marketplace-core'),
            'caller_transaction' => __('تراکنش از قبل باز بود و این انتقال تراکنش دیگری را commit نمی‌کند.', 'tecteb-marketplace-core'),
            'claim_refused' => __('ثبت اولیهٔ تصمیم انجام نشد؛ احتمالاً اجرای دیگری همین لحظه روی همین محصول کار می‌کند.', 'tecteb-marketplace-core'),
            'no_status' => __('وضعیت این محصول تعیین نشد، پس هیچ‌چیز ثبت نشد. این را گزارش کنید.', 'tecteb-marketplace-core'),
            'not_locked' => __('قفل ردیف‌های مقصد گرفته نشد، پس مقصد پیش از خواندن محافظت‌شده نبود و چیزی نوشته نشد.', 'tecteb-marketplace-core'),
            'destination_unreadable' => __('ردیف‌های مقصد خوانده نشدند. «خوانده نشد» با «ردیفی نیست» یکی گرفته نمی‌شود، پس هیچ‌چیز نوشته نشد.', 'tecteb-marketplace-core'),
            default => $outcome,
        };
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
            'minmax_rollback_not_stored' => sprintf(
                /* translators: 1: the meta key that would not store */
                __('بازگشت ذخیره نشد («%1$s»)، پس کل بازگشت برگشت و مقصد همان مقدارِ پس از انتقال است — نیمه‌برگشته نیست. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
                (string) ($context['part'] ?? '')
            ),
            'minmax_rollback_unrecorded' => __('دفتر سابقه ثبت نشد، پس کل بازگشت برگشت و مقصد همان مقدارِ پس از انتقال است. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'minmax_rollback_not_committed' => __('تراکنش بازگشت نهایی نشد، پس هیچ‌چیز عوض نشد.', 'tecteb-marketplace-core'),
            'minmax_rollback_no_transaction', 'minmax_rollback_not_atomic' => __('تراکنش باز نشد. بازگشت بی تراکنش انجام نمی‌شود، چون مقصدِ نیمه‌برگشته از نبودِ بازگشت بدتر است.', 'tecteb-marketplace-core'),
            'minmax_rollback_caller_transaction' => __('تراکنش از قبل باز بود و این بازگشت تراکنش دیگری را commit نمی‌کند.', 'tecteb-marketplace-core'),
            'minmax_rollback_not_locked' => __('قفل ردیف‌های مقصد گرفته نشد، پس مقایسه و نوشتن محافظت‌شده نبود و بازگشت انجام نشد.', 'tecteb-marketplace-core'),
            'minmax_rollback_unreadable', 'minmax_enforce_unreadable', 'minmax_unenforce_unreadable' => __('خواندن ردیف‌های مقصد انجام نشد. «خوانده نشد» با «ردیفی نیست» یکی گرفته نمی‌شود، پس هیچ‌چیز نوشته نشد. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'minmax_enforce_changed', 'minmax_unenforce_changed' => sprintf(
                /* translators: 1: field, 2: what we wrote, 3: what is there now */
                __('مجوز صادر نشد: «%1$s» پس از انتقال عوض شده. ما %2$s نوشته بودیم و الان %3$s است. مجوز دربارهٔ قاعده‌ای است که مدیر خوانده، و این دیگر همان قاعده نیست.', 'tecteb-marketplace-core'),
                (string) ($context['field'] ?? ''),
                PersianDigits::toPersian((string) ($context['wrote'] ?? '')),
                PersianDigits::toPersian((string) ($context['now'] ?? ''))
            ),
            'minmax_enforce_nothing', 'minmax_unenforce_nothing' => __('برای این محصول تصمیم زنده‌ای ثبت نشده، پس مجوزی هم ندارد.', 'tecteb-marketplace-core'),
            'minmax_enforce_not_allowed' => __('مجوز فقط برای محصولی معنا دارد که قاعده‌اش نوشته شده و بازارگاه مالکش نیست. محصولی که بازارگاه مالکش است از قبل قاعده‌اش اعمال می‌شود.', 'tecteb-marketplace-core'),
            'minmax_unenforce_not_allowed' => __('این محصول مجوز اعمال ندارد، پس چیزی برای برداشتن نیست.', 'tecteb-marketplace-core'),
            'minmax_enforce_not_locked', 'minmax_unenforce_not_locked' => __('قفل ردیف‌های مقصد گرفته نشد، پس مقایسه محافظت‌شده نبود و مجوز صادر نشد.', 'tecteb-marketplace-core'),
            'minmax_enforce_not_stored', 'minmax_unenforce_not_stored' => __('مجوز ذخیره نشد، پس هیچ‌چیز عوض نشد. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'minmax_enforce_unrecorded', 'minmax_unenforce_unrecorded' => __('دفتر سابقه ثبت نشد، پس کل اقدام برگشت. احتمالاً همین لحظه جای دیگری تغییر کرده؛ صفحه را تازه کنید.', 'tecteb-marketplace-core'),
            'minmax_enforce_not_committed', 'minmax_unenforce_not_committed' => __('تراکنش نهایی نشد، پس هیچ‌چیز ذخیره نشد.', 'tecteb-marketplace-core'),
            'minmax_enforce_no_transaction', 'minmax_enforce_not_atomic',
            'minmax_unenforce_no_transaction', 'minmax_unenforce_not_atomic' => __('تراکنش باز نشد. مجوز و دفتر سابقه با هم نوشته می‌شوند یا هیچ‌کدام.', 'tecteb-marketplace-core'),
            'minmax_enforce_caller_transaction', 'minmax_unenforce_caller_transaction' => __('تراکنش از قبل باز بود و این اقدام تراکنش دیگری را commit نمی‌کند.', 'tecteb-marketplace-core'),
            'minmax_release_nothing' => __('برای این محصول تصمیم زنده‌ای ثبت نشده، پس چیزی برای آزاد کردن نیست.', 'tecteb-marketplace-core'),
            'minmax_release_not_allowed' => __('فقط محصولی که «رد» یا «قاعدهٔ مدیر» شده آزاد می‌شود. محصولی که منتقل شده را باید برگرداند، نه آزاد کرد.', 'tecteb-marketplace-core'),
            'minmax_release_unrecorded' => __('آزادسازی ثبت نشد؛ احتمالاً همین لحظه جای دیگری تغییر کرده. صفحه را تازه کنید.', 'tecteb-marketplace-core'),
            default => $code,
        };
    }

    /** The levels, for the tests to name without reaching into the domain. */
    public static function levels(): array
    {
        return [MinMaxRule::AT_PRODUCT, MinMaxRule::AT_VARIATION, MinMaxRule::AT_TERM, MinMaxRule::AT_GLOBAL];
    }
}
