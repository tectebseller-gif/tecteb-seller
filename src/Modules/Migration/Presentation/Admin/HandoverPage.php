<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\DokanRecordImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\GrantImportedStaff;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCompleteness;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordPreview;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\StaffRoleMap;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

/**
 * Where an imported past becomes — or deliberately does not become — something
 * operational.
 *
 * The import page is about reading Dokan. This page is about the decisions
 * nobody can take on the owner's behalf: which Dokan role means what here, and
 * who is responsible for a balance another system recorded.
 *
 * It is a separate page rather than another card on the import screen because
 * the two are separate in time and in risk. Importing is reversible
 * bookkeeping that can run unattended; this is a person deciding who may touch
 * orders and who owes money. Putting them on one screen invites the second to
 * be clicked through on the way to the first.
 *
 * The page's own rule: it never shows a total that adds Dokan's closing
 * balance to Dokan's withdrawals. They overlap by design, and a screen that
 * puts a plus sign between them is how somebody pays a bill twice.
 */
final class HandoverPage
{
    public const SLUG = 'tmc-handover';
    public const CAPABILITY = Capabilities::REVIEW_VENDOR;
    private const NONCE = 'tmc_dokan_handover';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('تحویل مهاجرت', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $notice = $this->handleAction(Request::capture());
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(
                str_starts_with($notice, 'ok:') ? 'success' : 'error',
                substr($notice, strpos($notice, ':') + 1)
            );
        }
        echo Components::notice('info', __('ورود سابقه هیچ دسترسی و هیچ بدهی نمی‌سازد. آنچه در این صفحه تصمیم گرفته می‌شود، همان چیزی است که سابقه به‌تنهایی نمی‌تواند بگوید.', 'tecteb-marketplace-core'));

        // ⛔ The vendor universe is every shop with ANY import trace, not the
        // shops that already have records. It used to be
        // `vendorsWithRecords()` — a `UNION` of the three history tables —
        // which is empty until records are imported, and nothing in
        // production imported them. So a shop whose products and profile both
        // arrived was invisible here, and the empty state advised running the
        // product import again, which could not have helped.
        $shops = $this->container->get(MigrationCompleteness::class)->vendorUserIds();
        if ($shops === []) {
            echo $this->emptyState();
            echo Components::shellClose();
            return;
        }

        // First, and read only: «اولین گام پس از نصب فقط دیدن پیش‌نمایش
        // سوابق باشد، نه اجرای خودکار ورود.» Nothing on this page starts an
        // import by being looked at.
        $this->renderRecordPreview($shops, $fa);
        $this->renderCompleteness($shops, $fa);
        $this->renderStaff($shops, $fa);
        $this->renderFinance($shops, $fa);
        echo Components::shellClose();
    }

    /**
     * The empty state, and it says something rather than nothing.
     *
     * «هیچ داده‌ای نیست» is true and useless. What a person needs here is which
     * screen puts data on this one.
     */
    private function emptyState(): string
    {
        return '<section class="tmc-card">'
            . Components::state(
                'empty',
                __('هنوز هیچ فروشگاهی از دکان وارد نشده است', 'tecteb-marketplace-core'),
                __('این صفحه دربارهٔ فروشگاه‌هایی است که از دکان وارد شده‌اند — با محصول، با پروندهٔ فروشنده، یا با سابقه. هیچ‌کدام از این سه نشانه پیدا نشد، پس اول از صفحهٔ «مهاجرت از دکان» یک اجرای آزمایشی بگیرید و سپس ورود را انجام دهید.', 'tecteb-marketplace-core'),
                [[
                    'href' => admin_url('admin.php?page=' . MigrationPage::SLUG),
                    'label' => __('رفتن به مهاجرت از دکان', 'tecteb-marketplace-core'),
                    'primary' => true,
                ]]
            )
            . '</section>';
    }

    /**
     * What the records LOOK like, before anybody imports them.
     *
     * Read only: `ShopRecordPreview` has no write method of any kind, so
     * «اولین گام پس از نصب فقط دیدن پیش‌نمایش سوابق باشد» is a property of
     * the service and not a rule this page has to keep. The one button below
     * is the manager's explicit action, and it queues a job — it does not
     * import anything in the request that drew the page.
     *
     * Four answers per kind, kept apart on purpose: «صفر سابقه» is a count,
     * «هنوز بررسی نشده» is «I could not ask», «خواندن ناموفق» is «I asked and
     * the statement failed», and «واردشده» is a count on our side. ⛔ A failed
     * read is NEVER shown as nought — that is the whole reason
     * `RecordSource` is a type and not an `int`.
     *
     * @param list<int> $shops
     */
    private function renderRecordPreview(array $shops, callable $fa): void
    {
        $preview = $this->container->get(ShopRecordPreview::class);
        $rows = $preview->forVendors($shops);

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('پیش‌نمایش سوابق فروشگاه', 'tecteb-marketplace-core') . '</h2>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('سه نوع سابقه: پرسنل، دفتر مانده و درخواست‌های برداشت. این جدول فقط می‌خواند — نه از دکان چیزی تغییر می‌کند و نه سابقه‌ای ثبت می‌شود. ورود سوابق فقط با دکمهٔ پایین همین بخش انجام می‌شود.', 'tecteb-marketplace-core')
            . '</p>';

        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول پیش‌نمایش سوابق', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('نوع سابقه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('در دکان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('واردشده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('توضیح', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        $unreadable = false;
        foreach ($rows as $row) {
            $first = true;
            foreach (ShopRecordPreview::KINDS as $kind) {
                $cell = $row['kinds'][$kind];
                if (($row['anything_unreadable'] ?? false) === true) {
                    $unreadable = true;
                }
                echo '<tr><td>'
                    . ($first ? esc_html($this->shopName((int) $row['vendor_user_id'])) : '')
                    . '</td>'
                    . '<td>' . esc_html(self::kindLabel($kind)) . '</td>'
                    . '<td>' . self::recordBadge((string) $cell['verdict']) . '</td>'
                    // ⛔ A count only where there IS one. `null` prints «—»
                    // and the sentence in the last column says which of the
                    // three «no number» cases it is; printing nought here
                    // would be the collapse this whole section exists to
                    // stop.
                    . '<td>' . ($cell['source']['count'] === null ? '—' : $fa((string) $cell['source']['count'])) . '</td>'
                    . '<td>' . ($cell['imported'] === null ? '—' : $fa((string) $cell['imported'])) . '</td>'
                    . '<td>' . esc_html(self::recordReason((string) $cell['reason'])) . '</td>'
                    . '</tr>';
                $first = false;
            }
        }
        echo '</tbody></table></div>';

        if ($unreadable) {
            echo Components::notice('warning', __('دست‌کم یک سابقه خوانده نشد. «خوانده نشد» با «صفر سابقه» یکی نیست و به‌عنوان صفر گزارش نمی‌شود؛ تا وقتی خواندن درست انجام نشود، نبودِ عدد یک پرسش بی‌جواب است نه یک جواب.', 'tecteb-marketplace-core'));
        }

        echo $this->importButton($preview->anythingToImport($shops));
        echo '</section>';
    }

    /**
     * The one explicit action, and what it is not.
     *
     * ⛔ It queues a job; it does not import inside the request that drew this
     * page. One LIVE record import at a time whatever the run id, decided by
     * the unique index on the live key rather than by a check — two managers
     * pressing this together must not start two walks over the same Dokan
     * rows.
     */
    private function importButton(bool $anythingToImport): string
    {
        $live = $this->liveRecordJob();
        if ($live !== null) {
            return Components::notice('info', sprintf(
                /* translators: 1: job id, 2: done, 3: total */
                __('ورود سوابق در صف است (کار شمارهٔ %1$s): %2$s از %3$s. تا پایانش ورود تازه‌ای شروع نمی‌شود؛ وضعیتش در صفحهٔ «صف و سلامت اجرا» دیده می‌شود.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $live->id),
                PersianDigits::toPersian((string) ($live->done + $live->failed)),
                PersianDigits::toPersian((string) $live->total)
            ));
        }
        $note = '<p class="tmc-field__desc">'
            . esc_html__('ورود سوابق فقط تاریخچه ثبت می‌کند: هیچ بدهی قابل‌پرداخت، هیچ تسویه، هیچ دسترسی پرسنل، هیچ تغییر مالکیت محصول و هیچ فعال‌شدن فروشی. کار قابل‌ادامه است و اگر نیمه‌کاره بماند از همان‌جا ادامه می‌دهد؛ درخواست تکراری سابقهٔ تکراری نمی‌سازد.', 'tecteb-marketplace-core')
            . '</p>';
        if (!$anythingToImport) {
            // Nothing to do is not the same as a broken button, so the button
            // is still here and says why pressing it would change nothing.
            return $note . Components::notice('info', __('بر اساس پیش‌نمایش بالا، سابقهٔ واردنشده‌ای پیدا نشد. اگر وضعیتی «خوانده نشد» است، اول آن را حل کنید — چون تا خوانده نشود معلوم نیست چیزی مانده یا نه.', 'tecteb-marketplace-core'));
        }
        return $note
            . '<form method="post">'
            . wp_nonce_field(self::NONCE, 'tmc_handover_nonce', true, false)
            . '<p><button type="submit" class="tmc-button" name="handover_action" value="import_records">'
            . esc_html__('ورود سوابق به صف', 'tecteb-marketplace-core')
            . '</button></p></form>';
    }

    /** The live record-import job, or null. */
    private function liveRecordJob(): ?Job
    {
        if (!$this->container->has(JobRepositoryInterface::class)) {
            return null;
        }
        foreach ($this->container->get(JobRepositoryInterface::class)->live(50) as $job) {
            if ($job instanceof Job && $job->type === DokanRecordImportJob::TYPE) {
                return $job;
            }
        }
        return null;
    }

    private static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'staff' => __('پرسنل', 'tecteb-marketplace-core'),
            'balance' => __('دفتر مانده', 'tecteb-marketplace-core'),
            'withdrawals' => __('درخواست برداشت', 'tecteb-marketplace-core'),
            default => $kind,
        };
    }

    private static function recordBadge(string $verdict): string
    {
        return match ($verdict) {
            ShopRecordPreview::IMPORTED => Components::badge('success', '✓', __('واردشده', 'tecteb-marketplace-core')),
            ShopRecordPreview::PARTIAL => Components::badge('warning', '!', __('نیمه‌واردشده', 'tecteb-marketplace-core')),
            ShopRecordPreview::PENDING => Components::badge('warning', '!', __('واردنشده', 'tecteb-marketplace-core')),
            ShopRecordPreview::EMPTY => Components::badge('neutral', '•', __('صفر سابقه', 'tecteb-marketplace-core')),
            ShopRecordPreview::UNREADABLE => Components::badge('error', '×', __('خواندن ناموفق', 'tecteb-marketplace-core')),
            default => Components::badge('neutral', '?', __('هنوز بررسی نشده', 'tecteb-marketplace-core')),
        };
    }

    private static function recordReason(string $reason): string
    {
        return match ($reason) {
            'source_has_none' => __('دکان برای این فروشگاه چنین سابقه‌ای ندارد — پرسیده شد و جوابش صفر بود.', 'tecteb-marketplace-core'),
            'not_imported_yet' => __('در دکان هست و این‌طرف هیچ ردیفی نیست. دکمهٔ «ورود سوابق به صف» همین را وارد می‌کند.', 'tecteb-marketplace-core'),
            'some_rows_held' => __('بخشی وارد شده. ورود قابل‌ادامه است و از همان‌جا که مانده ادامه می‌دهد.', 'tecteb-marketplace-core'),
            'all_rows_held' => __('همهٔ ردیف‌هایی که دکان دارد این‌طرف هم هستند.', 'tecteb-marketplace-core'),
            'held_but_source_now_empty' => __('این‌طرف سابقه هست و دکان امروز چیزی نشان نمی‌دهد. چیزی حذف نمی‌شود؛ آنچه وارد شده می‌مانَد.', 'tecteb-marketplace-core'),
            'source_unreadable' => __('خواندن از دکان شکست خورد. این «صفر» نیست و به‌عنوان صفر شمرده نمی‌شود.', 'tecteb-marketplace-core'),
            'our_records_unreadable' => __('خواندن جدول سوابق خودِ ما شکست خورد، پس معلوم نیست چه داریم.', 'tecteb-marketplace-core'),
            'source_not_on_this_install' => __('این منبع روی این نصب نیست — جدولش وجود ندارد، یا بخش مربوطهٔ دکان نصب نیست.', 'tecteb-marketplace-core'),
            'dokan_not_running' => __('دکان روی این نصب اجرا نمی‌شود، پس هیچ پرسشی از آن ممکن نبود.', 'tecteb-marketplace-core'),
            'record_store_not_installed' => __('جدول سوابق این افزونه روی این نصب نیست؛ اول ارتقای ساختار داده لازم است.', 'tecteb-marketplace-core'),
            default => $reason,
        };
    }

    /** @param list<int> $shops */
    private function renderCompleteness(array $shops, callable $fa): void
    {
        $service = $this->container->get(MigrationCompleteness::class);
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('وضعیت مهاجرت هر فروشگاه', 'tecteb-marketplace-core') . '</h2>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('«ثبت سابقه» دروازهٔ صفر است: لازم، و به‌تنهایی هیچ. یک فروشگاه وقتی «کامل» است که هر شش دروازه بسته شده باشد.', 'tecteb-marketplace-core')
            . '</p>';

        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول وضعیت مهاجرت', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آنچه باقی مانده', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($shops as $vendorUserId) {
            $row = $service->forVendor($vendorUserId);
            echo '<tr><td>' . esc_html($this->shopName($vendorUserId)) . '</td>'
                . '<td>' . self::verdictBadge((string) $row['verdict']) . '</td>'
                . '<td>' . ($row['missing'] === []
                    ? esc_html__('چیزی باقی نمانده است.', 'tecteb-marketplace-core')
                    : esc_html(implode('، ', array_map([self::class, 'gateLabel'], $row['missing']))))
                . '</td></tr>';
        }
        echo '</tbody></table></div>';
        unset($fa);
        echo '</section>';
    }

    /** @param list<int> $shops */
    private function renderStaff(array $shops, callable $fa): void
    {
        $records = $this->container->get(ShopRecordRepositoryInterface::class);
        $roles = $this->container->get(StaffRoleMap::class);
        $grant = $this->container->get(GrantImportedStaff::class);
        $staffRepo = $this->container->get(StaffRepositoryInterface::class);

        $allStaff = [];
        foreach ($shops as $vendorUserId) {
            foreach ($records->staffForVendor($vendorUserId) as $member) {
                $allStaff[] = $member;
            }
        }

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('پرسنل واردشده از دکان', 'tecteb-marketplace-core') . '</h2>';
        echo Components::notice('warning', __('هیچ نقشی به‌طور خودکار نگاشت نمی‌شود. نقشی که اینجا تعیین نشده باشد، هیچ دسترسی‌ای نمی‌دهد — نه فقط‌خواندنی، نه معلق، هیچ.', 'tecteb-marketplace-core'));

        if ($allStaff === []) {
            echo Components::state(
                'empty',
                __('هیچ پرسنلی در سابقهٔ واردشده نیست', 'tecteb-marketplace-core'),
                __('پرسنل فروشگاه قابلیت نسخهٔ Pro دکان است؛ روی نصب Lite این فهرست خالی می‌ماند. این یعنی «اینجا چیزی پیدا نشد»، نه «این فروشگاه پرسنل ندارد».', 'tecteb-marketplace-core')
            );
            echo '</section>';
            return;
        }

        $undecided = $roles->undecided($allStaff);
        if ($undecided !== []) {
            echo '<h3 class="tmc-card__title">' . esc_html__('نقش‌هایی که منتظر تصمیم شما هستند', 'tecteb-marketplace-core') . '</h3>';
            echo '<form method="post"><table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('نقش در دکان', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('تعداد افراد', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('نقش متناظر در بازارگاه', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($undecided as $entry) {
                $role = (string) $entry['role'];
                $id = 'tmc-role-' . md5($role);
                echo '<tr><td>' . ($role === ''
                        ? esc_html__('(بدون نقش)', 'tecteb-marketplace-core')
                        : Components::code($role))
                    . '</td>'
                    . '<td>' . esc_html($fa((string) $entry['people'])) . '</td>'
                    . '<td><label class="screen-reader-text" for="' . esc_attr($id) . '">'
                    . esc_html__('نقش متناظر در بازارگاه', 'tecteb-marketplace-core') . '</label>'
                    . '<select id="' . esc_attr($id) . '" name="role_map[' . esc_attr($role) . ']" class="tmc-select">'
                    . '<option value="">' . esc_html__('— هنوز تصمیمی نگرفته‌ام —', 'tecteb-marketplace-core') . '</option>';
                foreach (StaffRoleMap::targets() as $target) {
                    echo '<option value="' . esc_attr($target) . '">' . esc_html(self::targetLabel($target)) . '</option>';
                }
                echo '</select></td></tr>';
            }
            echo '</tbody></table>'
                . wp_nonce_field(self::NONCE, 'tmc_handover_nonce', true, false)
                . '<p><button type="submit" class="tmc-button" name="handover_action" value="save_roles">'
                . esc_html__('ذخیرهٔ نگاشت نقش‌ها', 'tecteb-marketplace-core') . '</button></p></form>';
        }

        echo '<h3 class="tmc-card__title">' . esc_html__('افراد', 'tecteb-marketplace-core') . '</h3>';
        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول پرسنل واردشده', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('نام', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('نقش در دکان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت اینجا', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($shops as $vendorUserId) {
            foreach ($grant->plan($vendorUserId) as $row) {
                $membership = $staffRepo->findByUser((int) $row['staff_user_id']);
                echo '<tr><td>' . esc_html((string) $row['display_name']) . '</td>'
                    . '<td>' . esc_html($this->shopName($vendorUserId)) . '</td>'
                    . '<td>' . ((string) $row['dokan_role'] === ''
                        ? esc_html__('(بدون نقش)', 'tecteb-marketplace-core')
                        : Components::code((string) $row['dokan_role'])) . '</td>'
                    . '<td>' . $this->membershipCell($row, $membership) . '</td>'
                    . '<td>' . $this->staffActionCell($vendorUserId, $row, $membership) . '</td>'
                    . '</tr>';
            }
        }
        echo '</tbody></table></div></section>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function membershipCell(array $row, ?object $membership): string
    {
        if ($membership !== null) {
            return Components::badge(
                $membership->status === StaffStatus::Active ? 'success' : 'neutral',
                $membership->status === StaffStatus::Active ? '✓' : '·',
                $membership->status === StaffStatus::Active
                    ? __('فعال — دسترسی دارد', 'tecteb-marketplace-core')
                    : __('دعوت‌شده — هنوز هیچ دسترسی ندارد', 'tecteb-marketplace-core')
            );
        }
        return match ((string) $row['verdict']) {
            GrantImportedStaff::AWAITING_DECISION => Components::badge('warning', '!', __('منتظر تصمیم — هیچ دسترسی ندارد', 'tecteb-marketplace-core')),
            GrantImportedStaff::DECLINED => Components::badge('neutral', '—', __('تصمیم گرفته شد: بدون دسترسی', 'tecteb-marketplace-core')),
            GrantImportedStaff::NO_ACCOUNT => Components::badge('error', '✕', __('حساب کاربری وردپرس پیدا نشد', 'tecteb-marketplace-core')),
            GrantImportedStaff::ALREADY_STAFF => Components::badge('warning', '!', __('پرسنل فروشگاه دیگری است', 'tecteb-marketplace-core')),
            default => Components::badge('neutral', '·', __('آمادهٔ ایجاد عضویت', 'tecteb-marketplace-core')),
        };
    }

    /** @param array<string,mixed> $row */
    private function staffActionCell(int $vendorUserId, array $row, ?object $membership): string
    {
        if ($membership !== null && $membership->status === StaffStatus::Invited) {
            return '<form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_handover_nonce', true, false)
                . '<input type="hidden" name="staff_id" value="' . esc_attr((string) $membership->id) . '">'
                . '<button type="submit" class="tmc-button" name="handover_action" value="confirm_staff">'
                . esc_html__('تأیید و فعال‌سازی', 'tecteb-marketplace-core') . '</button></form>';
        }
        if ($membership !== null) {
            return '<span class="tmc-field__desc">' . esc_html__('عضویت برقرار است؛ تغییر نقش از پنل فروشنده انجام می‌شود.', 'tecteb-marketplace-core') . '</span>';
        }
        if ((string) $row['verdict'] === GrantImportedStaff::GRANTED) {
            return '<form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_handover_nonce', true, false)
                . '<input type="hidden" name="vendor_user_id" value="' . esc_attr((string) $vendorUserId) . '">'
                . '<button type="submit" class="tmc-button" name="handover_action" value="grant_staff">'
                . esc_html__('ایجاد عضویت (دعوت‌شده)', 'tecteb-marketplace-core') . '</button></form>';
        }
        return '<span class="tmc-field__desc">' . esc_html__('اقدامی در دسترس نیست.', 'tecteb-marketplace-core') . '</span>';
    }

    /** @param list<int> $shops */
    /**
     * The sentence for one reconciliation difference.
     *
     * The codes come from `ReconcileDokanFinance::differencesFor()` and the
     * sentences live here, because Application does not get to call `__()`
     * (`alpha.9`). A code with no sentence is printed as itself rather than
     * swallowed: an unexplained difference must still be visible.
     */
    private function differenceSentence(string $code): string
    {
        return match ($code) {
            'marketplace_ledger_unavailable' =>
                __('ماژول مالی این بازارگاه بارگذاری نشده، پس ستون دوم خوانده نشد. این «صفر» نیست.', 'tecteb-marketplace-core'),
            'nothing_traded_here_yet' =>
                __('این فروشگاه هنوز در این بازارگاه نفروخته، پس ستون دوم واقعاً خالی است — و این تنها دلیل درستِ صفر بودنش است.', 'tecteb-marketplace-core'),
            'handover_decision_open' =>
                __('هنوز کسی تصمیم نگرفته با این سابقه چه شود، پس قرار نیست ستون دوم شاملش باشد.', 'tecteb-marketplace-core'),
            'dokan_records_paid_withdrawals_twice' =>
                __('دکان برداشتِ پرداخت‌شده را دوبار ثبت می‌کند: یک ردیف برداشت و یک بدهکار در جدول مانده. ماندهٔ پایانی از قبل کسرش کرده، پس این دو را جمع نکنید.', 'tecteb-marketplace-core'),
            'dokan_money_columns_carry_no_unit' =>
                __('ستون‌های پول دکان عدد خالی‌اند، بی ارز و بی مقیاس؛ هر رقم این طرف ارز و مقیاس خودش را دارد. مقایسه فقط پس از آن ممکن است که کسی بگوید عددهای دکان به کدام واحد بوده‌اند.', 'tecteb-marketplace-core'),
            'order_totals_read_from_dokan_orders' =>
                __('جمع سفارش‌ها از جدول قدیمی خوانده شده. مرجع ۵٫x جدول دیگری است که والد و زیرسفارش را جدا می‌کند، و عوض‌کردن مرجع تصمیم مالک است.', 'tecteb-marketplace-core'),
            default => $code,
        };
    }

    private function renderFinance(array $shops, callable $fa): void
    {
        $finance = $this->container->get(ReconcileDokanFinance::class);
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('تطبیق مالی و انتقال مسئولیت', 'tecteb-marketplace-core') . '</h2>';
        echo Components::notice('warning', __('ارقام زیر دقیقاً همان چیزی است که دکان ثبت کرده و با هیچ نرخی دوباره محاسبه نشده است. برداشتِ پرداخت‌شده در خودِ «ماندهٔ پایانی» کسر شده؛ این دو عدد هرگز با هم جمع نمی‌شوند.', 'tecteb-marketplace-core'));

        foreach ($shops as $vendorUserId) {
            // ONE snapshot per shop: the figures printed below and the token
            // the form carries are the same read. Asking for the report and
            // then asking separately for a token — which is what alpha.19 did
            // — issues a receipt for a document the manager was never handed.
            $snapshot = $finance->snapshotFor($vendorUserId);
            $report = $finance->reportFrom($snapshot);
            $handover = $report['handover'];
            echo '<article class="tmc-card tmc-card--nested"><h3 class="tmc-card__title">'
                . esc_html($this->shopName($vendorUserId)) . '</h3>';

            echo Components::dataList([
                ['label' => __('ماندهٔ پایانی به حساب دکان', 'tecteb-marketplace-core'), 'value' => $fa((string) $report['dokan']['closing'])],
                ['label' => __('جمع بستانکار (ثبت دکان)', 'tecteb-marketplace-core'), 'value' => $fa((string) $report['dokan']['credit'])],
                ['label' => __('جمع بدهکار (ثبت دکان)', 'tecteb-marketplace-core'), 'value' => $fa((string) $report['dokan']['debit'])],
                ['label' => __('از این بدهکار، برداشتِ قبلاً پرداخت‌شده', 'tecteb-marketplace-core'), 'value' => $fa((string) $report['already_counted_once']['debit'])],
                ['label' => __('خط دفترکل این بازارگاه برای این سابقه', 'tecteb-marketplace-core'), 'value' => $fa('0')],
            ]);

            // WHY the two sides differ, each reason named. Two columns of
            // different numbers with no explanation is the shape that makes
            // somebody add them up — and the first one of these, «this shop
            // has not traded here yet», is the honest reason for the zero
            // above.
            $differences = (array) ($report['differences'] ?? []);
            if ($differences !== []) {
                echo '<p class="tmc-field__desc">'
                    . esc_html__('این دو ستون جمع نمی‌شوند، و دلیل هر اختلافشان این‌هاست:', 'tecteb-marketplace-core')
                    . '</p><ul class="tmc-list">';
                foreach ($differences as $difference) {
                    echo '<li>' . esc_html($this->differenceSentence((string) ($difference['code'] ?? '')))
                        . ' <span class="tmc-card__note">' . Components::code((string) ($difference['detail'] ?? ''))
                        . '</span></li>';
                }
                echo '</ul>';
            }

            $statuses = $report['dokan']['withdrawals_by_status'];
            if ($statuses !== []) {
                echo '<table class="tmc-table"><caption>' . esc_html__('درخواست‌های برداشت، با وضعیت خود دکان', 'tecteb-marketplace-core') . '</caption><thead><tr>'
                    . '<th scope="col">' . esc_html__('وضعیت دکان', 'tecteb-marketplace-core') . '</th>'
                    . '<th scope="col">' . esc_html__('تعداد', 'tecteb-marketplace-core') . '</th>'
                    . '<th scope="col">' . esc_html__('جمع', 'tecteb-marketplace-core') . '</th>'
                    . '</tr></thead><tbody>';
                foreach ($statuses as $status => $tally) {
                    echo '<tr><td>' . Components::code((string) $status) . '</td>'
                        . '<td>' . esc_html($fa((string) $tally['count'])) . '</td>'
                        . '<td>' . esc_html($fa((string) $tally['total'])) . '</td></tr>';
                }
                echo '</tbody></table>';
            }

            if ((string) $handover['decision'] === ReconcileDokanFinance::OPEN) {
                echo '<p class="tmc-field__desc">'
                    . esc_html__('تا وقتی تصمیمی ثبت نشود، این بازارگاه بابت این رقم هیچ تعهدی ندارد و هیچ پرداختی انجام نمی‌شود.', 'tecteb-marketplace-core')
                    . '</p>';
                // The version match for the figures printed above, taken from
                // the SAME snapshot they were printed from. It travels with
                // the decision so the service can refuse one taken against
                // numbers that have since moved. It is not a «seen» flag and
                // not evidence anybody read anything — it says which version
                // of the imported past this form was built from.
                echo '<form method="post" class="tmc-inline-form">'
                    . wp_nonce_field(self::NONCE, 'tmc_handover_nonce', true, false)
                    . '<input type="hidden" name="vendor_user_id" value="' . esc_attr((string) $vendorUserId) . '">'
                    . '<input type="hidden" name="figures_token" value="'
                    . esc_attr((string) $report['figures_token']) . '">'
                    . '<label class="screen-reader-text" for="tmc-note-' . esc_attr((string) $vendorUserId) . '">'
                    . esc_html__('یادداشت تصمیم', 'tecteb-marketplace-core') . '</label>'
                    . '<input type="text" id="tmc-note-' . esc_attr((string) $vendorUserId) . '" name="handover_note" class="tmc-input"'
                    . ' placeholder="' . esc_attr__('یادداشت (اختیاری)', 'tecteb-marketplace-core') . '">'
                    . '<button type="submit" class="tmc-button" name="handover_action" value="accept_finance">'
                    . esc_html__('مسئولیت با بازارگاه است', 'tecteb-marketplace-core') . '</button>'
                    . '<button type="submit" class="tmc-button tmc-button--ghost" name="handover_action" value="outside_finance">'
                    . esc_html__('خارج از بازارگاه تسویه می‌شود', 'tecteb-marketplace-core') . '</button>'
                    . '</form>';
            } else {
                echo Components::notice('success', sprintf(
                    /* translators: 1: decision, 2: frozen amount, 3: date */
                    __('تصمیم ثبت‌شده: %1$s — رقم توافق‌شده %2$s در تاریخ %3$s. این ثبت هیچ خط دفترکلی ننوشت و هیچ پرداختی ایجاد نکرد.', 'tecteb-marketplace-core'),
                    self::decisionLabel((string) $handover['decision']),
                    PersianDigits::toPersian((string) $handover['closing_at_decision']),
                    PersianDigits::toPersian((string) $handover['decided_at'])
                ));
                if ((int) $handover['pending_requests_untouched'] > 0) {
                    echo Components::notice('warning', sprintf(
                        /* translators: %s: number of unpaid Dokan withdrawal requests */
                        __('%s درخواست برداشتِ تسویه‌نشده در دکان باقی است. این تصمیم آن‌ها را پرداخت نکرد و پرداختشان تصمیم جداگانه‌ای است.', 'tecteb-marketplace-core'),
                        PersianDigits::toPersian((string) $handover['pending_requests_untouched'])
                    ));
                }
            }
            echo '</article>';
        }
        echo '</section>';
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('handover_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_handover_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $action = $request->postKey('handover_action');

        if ($action === 'save_roles') {
            $roles = $this->container->get(StaffRoleMap::class);
            $submitted = $request->postMap('role_map');
            // Merged, not replaced: the form only ever shows the UNDECIDED
            // roles, so posting it as the whole map would erase every decision
            // already taken.
            $merged = $roles->all();
            foreach ($submitted as $role => $target) {
                if (trim((string) $target) !== '') {
                    $merged[(string) $role] = (string) $target;
                }
            }
            return $roles->save($merged)
                ? 'ok:' . __('نگاشت نقش‌ها ذخیره شد. هنوز هیچ عضویتی ساخته نشده است.', 'tecteb-marketplace-core')
                : 'err:' . __('ذخیرهٔ نگاشت انجام نشد.', 'tecteb-marketplace-core');
        }

        if ($action === 'import_records') {
            $preview = $this->container->get(ShopRecordPreview::class);
            $shops = $this->container->get(MigrationCompleteness::class)->vendorUserIds();
            if (!$preview->anythingToImport($shops)) {
                // Refused rather than queued: a job that would do nothing is
                // a row somebody then has to interpret.
                return 'err:' . __('سابقهٔ واردنشده‌ای پیدا نشد، پس کاری به صف اضافه نشد. اگر وضعیتی «خوانده نشد» است، اول آن را حل کنید.', 'tecteb-marketplace-core');
            }
            $counts = $this->container->get(DokanReaderInterface::class)->counts();
            /** @var JobRepositoryInterface $jobs */
            $jobs = $this->container->get(JobRepositoryInterface::class);
            $queued = $jobs->enqueue(
                DokanRecordImportJob::TYPE,
                [
                    'run_id' => 'dokan-records-'
                        . $this->container->get(ClockInterface::class)->now()->format('Ymd-His'),
                    // ⛔ All three kinds, because all three are what this job
                    // walks. The product import declared a total of vendors
                    // plus products while counting three phases, and the
                    // figure on the queue page went past its own denominator.
                    'total' => (int) ($counts['staff'] ?? 0)
                        + (int) ($counts['balance'] ?? 0)
                        + (int) ($counts['withdrawals'] ?? 0),
                ],
                // One live record import at a time, whatever the run id —
                // decided by the unique index on the live key, not by a check.
                DokanRecordImportJob::TYPE,
                get_current_user_id()
            );
            if ($queued['id'] === 0) {
                return 'err:' . __('ورود سوابق به صف اضافه نشد. صفحهٔ سلامت صف را ببینید.', 'tecteb-marketplace-core');
            }
            if (!$queued['created']) {
                return 'err:' . sprintf(
                    /* translators: %s: job id */
                    __('یک ورود سوابق در صف هست (کار شمارهٔ %s) و تا پایانش ورود تازه‌ای شروع نمی‌شود.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $queued['id'])
                );
            }
            return 'ok:' . sprintf(
                /* translators: %s: job id */
                __('ورود سوابق به صف اضافه شد (کار شمارهٔ %s). فقط تاریخچه ثبت می‌شود: هیچ بدهی، هیچ تسویه، هیچ دسترسی و هیچ تغییری در دکان.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $queued['id'])
            );
        }

        if ($action === 'grant_staff') {
            $result = $this->container->get(GrantImportedStaff::class)->grant($request->postInt('vendor_user_id'));
            if (($result['forbidden'] ?? false) === true) {
                return 'err:' . __('دسترسی لازم را ندارید.', 'tecteb-marketplace-core');
            }
            return 'ok:' . sprintf(
                /* translators: 1: granted, 2: awaiting a decision */
                __('%1$s عضویت «دعوت‌شده» ساخته شد و %2$s نفر هنوز منتظر تصمیم‌اند. عضویت دعوت‌شده هیچ دسترسی‌ای نمی‌دهد تا وقتی تأیید شود.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $result[GrantImportedStaff::GRANTED]),
                PersianDigits::toPersian((string) $result[GrantImportedStaff::AWAITING_DECISION])
            );
        }

        if ($action === 'confirm_staff') {
            $outcome = $this->container->get(GrantImportedStaff::class)->confirm($request->postInt('staff_id'));
            return $outcome === GrantImportedStaff::GRANTED
                ? 'ok:' . __('عضویت فعال شد. دسترسی دقیقاً همان نقش نگاشت‌شده است و نه بیشتر.', 'tecteb-marketplace-core')
                : 'err:' . __('فعال‌سازی انجام نشد.', 'tecteb-marketplace-core');
        }

        if ($action === 'accept_finance' || $action === 'outside_finance') {
            $decision = $action === 'accept_finance'
                ? ReconcileDokanFinance::ACCEPTED
                : ReconcileDokanFinance::STAYS_OUTSIDE;
            $result = $this->container->get(ReconcileDokanFinance::class)->decide(
                $request->postInt('vendor_user_id'),
                $decision,
                $request->postText('handover_note'),
                $request->postText('figures_token')
            );
            if ($result['ok']) {
                return 'ok:' . __('مسئولیت مالی ثبت شد. هیچ خط دفترکلی نوشته نشد، هیچ برداشتی ساخته نشد و هیچ رقمی با نرخ تازه محاسبه نشد.', 'tecteb-marketplace-core');
            }
            // Each refusal says what to DO, because «ثبت نشد» sends a manager
            // looking for a broken button instead of a changed number.
            return 'err:' . match ((string) $result['reason']) {
                'figures_changed' => __('ارقام این فروشگاه نسبت به نسخه‌ای که این فرم از روی آن ساخته شده تغییر کرده‌اند. صفحه را تازه کنید؛ تصمیم روی نسخه‌ای که دیگر برقرار نیست ثبت نمی‌شود.', 'tecteb-marketplace-core'),
                'report_not_seen' => __('این درخواست نسخهٔ ارقام را همراه نداشت. تصمیم باید از فرم همین صفحه ثبت شود تا معلوم باشد روی کدام نسخه از ارقام گرفته شده است.', 'tecteb-marketplace-core'),
                'forbidden' => __('شما اجازهٔ ثبت این تصمیم را ندارید.', 'tecteb-marketplace-core'),
                default => __('ثبت تصمیم انجام نشد.', 'tecteb-marketplace-core'),
            };
        }

        return 'err:' . __('اقدام ناشناخته.', 'tecteb-marketplace-core');
    }

    private function shopName(int $vendorUserId): string
    {
        $user = get_userdata($vendorUserId);
        $name = $user ? (string) $user->display_name : '';
        return $name !== ''
            ? $name . ' (#' . PersianDigits::toPersian((string) $vendorUserId) . ')'
            : '#' . PersianDigits::toPersian((string) $vendorUserId);
    }

    private static function verdictBadge(string $verdict): string
    {
        return match ($verdict) {
            MigrationCompleteness::COMPLETE => Components::badge('success', '✓', __('کامل', 'tecteb-marketplace-core')),
            MigrationCompleteness::RECORDED_ONLY => Components::badge('warning', '!', __('فقط سابقه ثبت شده', 'tecteb-marketplace-core')),
            default => Components::badge('warning', '!', __('ناقص', 'tecteb-marketplace-core')),
        };
    }

    private static function gateLabel(string $gate): string
    {
        return match ($gate) {
            'records_imported' => __('ورود سابقه', 'tecteb-marketplace-core'),
            'categories_mapped' => __('نگاشت دسته‌ها', 'tecteb-marketplace-core'),
            'ownership_taken' => __('انتقال مالکیت محصول‌ها', 'tecteb-marketplace-core'),
            'vendor_can_sell' => __('پذیرش فروشگاه', 'tecteb-marketplace-core'),
            'staff_decided' => __('تصمیم دربارهٔ پرسنل', 'tecteb-marketplace-core'),
            'finance_decided' => __('تصمیم دربارهٔ مانده', 'tecteb-marketplace-core'),
            default => $gate,
        };
    }

    private static function targetLabel(string $target): string
    {
        if ($target === StaffRoleMap::DECLINED) {
            return __('هیچ دسترسی‌ای ندارد', 'tecteb-marketplace-core');
        }
        return match (StaffRolePreset::tryFrom($target)) {
            StaffRolePreset::StoreManager => __('مدیر فروشگاه', 'tecteb-marketplace-core'),
            StaffRolePreset::ProductAndInventory => __('محصول و موجودی', 'tecteb-marketplace-core'),
            StaffRolePreset::OrderAndShipping => __('سفارش و ارسال', 'tecteb-marketplace-core'),
            StaffRolePreset::Accountant => __('حسابدار', 'tecteb-marketplace-core'),
            StaffRolePreset::CustomerSupport => __('پشتیبانی مشتری', 'tecteb-marketplace-core'),
            default => $target,
        };
    }

    private static function decisionLabel(string $decision): string
    {
        return match ($decision) {
            ReconcileDokanFinance::ACCEPTED => __('مسئولیت با بازارگاه است', 'tecteb-marketplace-core'),
            ReconcileDokanFinance::STAYS_OUTSIDE => __('خارج از بازارگاه تسویه می‌شود', 'tecteb-marketplace-core'),
            default => __('تصمیمی گرفته نشده', 'tecteb-marketplace-core'),
        };
    }
}
