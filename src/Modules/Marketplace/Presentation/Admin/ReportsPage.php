<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Marketplace\Application\Notify;
use Tecteb\Marketplace\Modules\Marketplace\Application\Reports;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\Charts;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\NoticeMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * «گزارش‌ها و اطلاعیه‌ها» — the manager's two approved reports (UX §12.3,
 * «مالی و عملیاتی») and their own notice inbox.
 *
 * Both on one screen because the manager's version of «what should I know» is
 * the same question the vendor's page answers, asked from the marketplace's
 * side. A second menu entry for four numbers would be a menu entry nobody
 * clicks twice.
 *
 * Every figure is computed when the page is opened. There is no stored total
 * anywhere in this plugin, which is why these numbers can never disagree with
 * the settlement screen — they are the same sum, asked again.
 *
 * The inbox is the MANAGER'S OWN. There is no «everybody's notifications»
 * view, here or anywhere: an inbox belongs to a person, and a screen that
 * could show somebody else's would be the one place that rule leaked.
 */
final class ReportsPage
{
    public const SLUG = 'tmc-reports';
    public const CAPABILITY = Capabilities::REVIEW_VENDOR;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('گزارش‌ها', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('اجازهٔ دیدن این صفحه را ندارید.', 'tecteb-marketplace-core'));
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo '<div class="wrap tmc-admin"><h1>'
            . esc_html__('گزارش‌ها و اطلاعیه‌ها', 'tecteb-marketplace-core') . '</h1>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('هر عدد این صفحه همین لحظه از روی دفترکل و رکوردهای سفارش حساب می‌شود. هیچ عددی ذخیره و بازخوانی نمی‌شود، پس این ارقام هیچ‌وقت با صفحهٔ تسویه اختلاف پیدا نمی‌کنند.', 'tecteb-marketplace-core')
            . '</p>';

        $this->renderReports($fa);
        $this->renderInbox($fa);
        echo '</div>';
    }

    /** @param callable(string|int):string $fa */
    private function renderReports(callable $fa): void
    {
        $vendorIds = $this->vendorIds();
        $reports = $this->container->get(Reports::class)->forManager($vendorIds);
        $hidden = NoticeMessages::hiddenFigures();

        foreach ($reports as $key => $figures) {
            echo '<section class="tmc-card"><h2>' . esc_html(NoticeMessages::reportTitle((string) $key)) . '</h2>';
            if ((int) ($figures['available'] ?? 0) !== 1) {
                echo '<p class="tmc-field__desc">'
                    . esc_html__('این بخش در حال حاضر اجرا نمی‌شود، پس عددی برایش نیست. صفر نوشتن دروغ می‌شد.', 'tecteb-marketplace-core')
                    . '</p></section>';
                continue;
            }
            echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
                . esc_attr(NoticeMessages::reportTitle((string) $key)) . '">'
                . '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('عنوان', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('مقدار', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($figures as $figure => $value) {
                if (in_array((string) $figure, $hidden, true)) {
                    continue;
                }
                $shown = NoticeMessages::isMoney((string) $figure)
                    ? $fa(number_format((int) $value / 100)) . ' ' . __('تومان', 'tecteb-marketplace-core')
                    : $fa((string) $value);
                // Same `data-label` rule as the moderation queue: the header
                // row is hidden when this table stacks, so the cells carry
                // their own labels.
                echo '<tr><th scope="row" data-label="' . esc_attr__('عنوان', 'tecteb-marketplace-core') . '">'
                    . esc_html(NoticeMessages::figure((string) $figure)) . '</th>'
                    . '<td data-label="' . esc_attr__('مقدار', 'tecteb-marketplace-core') . '">'
                    . esc_html($shown) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            // Same rule as the vendor's page: the chart follows the table it
            // illustrates, and the money card has none.
            $rows = NoticeMessages::chartRows((string) $key, $figures);
            if ($rows !== []) {
                echo Charts::card(     // phpcs:ignore WordPress.Security.EscapeOutput
                    NoticeMessages::reportTitle((string) $key),
                    $rows,
                    NoticeMessages::chartCaption((string) $key),
                    'tmc-chart'
                );
            }
            if ($key === Reports::FINANCE && (int) ($figures['unrecorded_lines'] ?? 0) > 0) {
                echo Components::notice('info', NoticeMessages::unrecordedNote());
            }
            echo '</section>';
        }
    }

    /** @param callable(string|int):string $fa */
    private function renderInbox(callable $fa): void
    {
        $notify = $this->container->get(Notify::class);
        $userId = get_current_user_id();
        $notices = $notify->inbox($userId, false, 25);

        echo '<section class="tmc-card"><h2>'
            . esc_html__('اطلاعیه‌های شما', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-field__desc">'
            . esc_html__('این اعلان‌ها فقط در همین پنل نشان داده می‌شوند؛ در این نسخه هیچ ایمیل و پیامکی فرستاده نمی‌شود.', 'tecteb-marketplace-core')
            . '</p>';
        if ($notices === []) {
            echo '<p>' . esc_html__('اعلان تازه‌ای ندارید.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<ul class="tmc-notices">';
        foreach ($notices as $notice) {
            echo '<li class="tmc-notice' . ($notice->isUnread() ? ' tmc-notice--unread' : '') . '">'
                . '<p>' . esc_html(NoticeMessages::sentence($notice->event, $notice->context)) . '</p>'
                . '<p class="tmc-field__desc"><time datetime="' . esc_attr($notice->createdAt) . '">'
                . esc_html($fa($notice->createdAt)) . '</time>'
                . ($notice->isUnread()
                    ? ' — ' . esc_html__('خوانده‌نشده', 'tecteb-marketplace-core')
                    : '')
                . '</p></li>';
        }
        echo '</ul></section>';
    }

    /**
     * Every vendor the marketplace has, as user ids.
     *
     * Asked of the Vendor module rather than worked out here: who counts as a
     * vendor is its question, and a second answer would drift from the first.
     *
     * From the PROFILES, not from approved applications. The first version
     * walked the applications and reported «۰ فروشگاه» on a site that had
     * three — a profile is what makes somebody a vendor, and however the row
     * came to exist is not this page's business.
     *
     * @return list<int>
     */
    private function vendorIds(): array
    {
        return $this->container->get(VendorRepositoryInterface::class)->vendorUserIds();
    }
}
