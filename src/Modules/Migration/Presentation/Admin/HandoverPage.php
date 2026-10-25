<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Presentation\Admin;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Migration\Application\ApplyCategoryMapping;
use Tecteb\Marketplace\Modules\Product\Application\ApplyCategoryLinks;
use Tecteb\Marketplace\Modules\Product\Application\CategoryLinkPlan;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMap;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMapping;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\DokanRecordImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\GrantImportedStaff;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCompleteness;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordPreview;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordScope;
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
        $request = Request::capture();
        $notice = $this->handleAction($request);
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(
                str_starts_with($notice, 'ok:') ? 'success' : 'error',
                substr($notice, strpos($notice, ':') + 1)
            );
        }
        echo Components::notice('info', __('ورود سابقه هیچ دسترسی و هیچ بدهی نمی‌سازد. آنچه در این صفحه تصمیم گرفته می‌شود، همان چیزی است که سابقه به‌تنهایی نمی‌تواند بگوید.', 'tecteb-marketplace-core'));

        // ⛔ THREE lists, and which is which is the whole point.
        //
        // `$shops` is the shops: ids with a MARKETPLACE trace — a product row
        // or a vendor profile this marketplace's import created. They are the
        // subject of the gates, because each gate is a question about a shop
        // we have taken on. ⛔ It used to be `vendorUserIds()`, whose first
        // trace was `vendorsWithRecords()`, so importing an id's records made
        // it a shop and put it in the completeness table — the criterion was
        // a row the import itself wrote. That is the circularity the owner
        // found in the shipped `alpha.78`.
        //
        // `$recordOwners` is every id whose records we hold. Their records
        // are kept and shown — «سوابق را حفظ و نمایش بده» — so the staff and
        // finance sections, which are ABOUT records, are drawn for them too.
        //
        // `$scope` is every id the record import can touch: the two above
        // plus every owner Dokan's own record tables name. Measured on
        // staging: the preview showed 40 and 102, the run wrote fourteen rows
        // owned by 2, 4, 6, 8, 16 and 102, and those owners appeared here
        // only afterwards. The preview, the button AND the walk now read this
        // one list.
        $completeness = $this->container->get(MigrationCompleteness::class);
        $shops = $completeness->shopVendorUserIds();
        $recordOwners = $completeness->recordOwnerVendorUserIds();
        $recordSubjects = array_values(array_unique(array_merge($shops, $recordOwners)));
        sort($recordSubjects);

        $scope = $this->container->get(ShopRecordScope::class);
        $scopeIds = $scope->ids();
        if ($scopeIds === [] && $recordSubjects === []) {
            echo $this->emptyState();
            echo Components::shellClose();
            return;
        }

        // First, and read only: «اولین گام پس از نصب فقط دیدن پیش‌نمایش
        // سوابق باشد، نه اجرای خودکار ورود.» Nothing on this page starts an
        // import by being looked at.
        $this->renderRecordPreview($scopeIds, $scope, $fa);
        if ($shops === []) {
            echo Components::notice('info', __('هیچ فروشگاه واردشده‌ای پیدا نشد. ردیف‌های بالا صاحبان سابقه‌اند، نه فروشگاه‌های این بازارگاه؛ جدول وضعیت مهاجرت دربارهٔ فروشگاه‌های واردشده است و چیزی برای نشان‌دادن ندارد.', 'tecteb-marketplace-core'));
        } else {
            $this->renderCompleteness($shops, $fa);
        }
        // Right after the gates, because this card is the only door out of one
        // of them — and because the gate and the card have to agree about what
        // «نگاشت دسته‌ها» means. Drawn for every install, Dokan or no Dokan:
        // the number of imported products still waiting for a category is read
        // from our own table and is true either way.
        $this->renderCategories($fa, $request);
        $this->renderCategoryLinks($fa);
        if ($recordSubjects !== []) {
            $this->renderStaff($recordSubjects, $fa);
            $this->renderFinance($recordSubjects, $fa);
        }
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
     * @param list<int> $shops every id the import can touch, not only the
     *                          imported shops — see `render()`
     */
    private function renderRecordPreview(array $shops, ShopRecordScope $scope, callable $fa): void
    {
        $preview = $this->container->get(ShopRecordPreview::class);
        $rows = $preview->forVendors($shops);
        // Provenance per id, keyed, so the table says which rows are shops.
        $origin = [];
        foreach ($scope->rows() as $scopeRow) {
            $origin[(int) $scopeRow['vendor_user_id']] = $scopeRow;
        }

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('پیش‌نمایش سوابق فروشگاه', 'tecteb-marketplace-core') . '</h2>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('سه نوع سابقه: پرسنل، دفتر مانده و درخواست‌های برداشت. این جدول فقط می‌خواند — نه از دکان چیزی تغییر می‌کند و نه سابقه‌ای ثبت می‌شود. ورود سوابق فقط با دکمهٔ پایین همین بخش انجام می‌شود.', 'tecteb-marketplace-core')
            . '</p>';

        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول پیش‌نمایش سوابق', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('این شناسه چیست', 'tecteb-marketplace-core') . '</th>'
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
                    . '<td>' . ($first
                        ? self::originCell($origin[(int) $row['vendor_user_id']] ?? null)
                        : '') . '</td>'
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

        if (!$scope->sourceReadable()) {
            // A scope that may be short, said out loud. Two scopes went
            // unnoticed for a round because the narrower one looked
            // complete; a narrowed list that prints as the whole list is the
            // same mistake one layer down.
            echo Components::notice('warning', __('فهرست صاحبان سابقه در دکان کامل خوانده نشد، پس همین جدول هم می‌تواند ناقص باشد. تا وقتی این خواندن درست انجام نشود، «همهٔ صاحبان سابقه همین‌ها هستند» گفته نمی‌شود.', 'tecteb-marketplace-core'));
        }

        $names = static fn (array $ids): string => implode('، ', array_map(
            // Not escaped here: `Components::notice()` escapes the whole
            // sentence, and escaping twice would print the entities.
            static fn (int $id): string => '#' . PersianDigits::toPersian((string) $id),
            $ids
        ));

        $sourceOnly = $scope->sourceOnlyIds();
        if ($sourceOnly !== []) {
            echo Components::notice('info', sprintf(
                /* translators: %s: a list of user ids */
                __('این شناسه‌ها در دکان صاحب سابقه‌اند و از این بازارگاه وارد نشده‌اند: %s. ورود سوابق، تاریخچهٔ آن‌ها را هم ثبت می‌کند — و همین ثبت نه فروشگاهی می‌سازد، نه پذیرشی، نه دسترسی و نه بدهی. تا وقتی فروشگاهی برایشان وارد و پذیرفته نشده، فروشگاه معتبر این بازارگاه نیستند.', 'tecteb-marketplace-core'),
                $names($sourceOnly)
            ));
        }

        // ⛔ And the ids whose records we ALREADY hold and which are still not
        // shops. On `alpha.78` these read «فروشگاه واردشده» the moment their
        // records landed, and walked into the migration-completeness table on
        // the strength of a row this plugin wrote itself.
        $recordOnly = $scope->recordOnlyIds();
        if ($recordOnly !== []) {
            echo Components::notice('info', sprintf(
                /* translators: %s: a list of user ids */
                __('سابقهٔ این شناسه‌ها این‌طرف ثبت شده و هیچ‌کدام فروشگاه این بازارگاه نیستند: %s. سوابقشان می‌مانَد و همین‌جا نشان داده می‌شود، ولی در جدول وضعیت مهاجرت نمی‌آیند — داشتن سابقه فروشگاه‌بودن نیست.', 'tecteb-marketplace-core'),
                $names($recordOnly)
            ));
        }

        echo $this->importButton($preview->anythingToImport($shops), $scope->sourceReadable());
        echo '</section>';
    }

    /**
     * What an id in the scope actually is.
     *
     * ⛔ Four separate facts, because the staging surprise was one id looking
     * exactly like another: whether WE imported a shop for it, whether a
     * WordPress user exists, whether it has a vendor profile here, and
     * whether that profile may sell. «Owns records» is none of those, and a
     * row that said nothing but the id let six owners of records read as
     * shops.
     *
     * `has_user === null` prints «نامعلوم» rather than «نه»: the directory is
     * an optional dependency and «I could not ask» is not an answer about the
     * account.
     *
     * @param array{vendor_user_id:int, origin:string, imported:bool, has_records:bool, in_source:bool, has_user:bool|null, has_profile:bool, can_sell:bool}|null $row
     */
    private static function originCell(?array $row): string
    {
        if ($row === null) {
            return esc_html__('نامعلوم', 'tecteb-marketplace-core');
        }
        // Three badges, because «we imported a shop for it», «we hold its
        // records» and «Dokan merely names it» are three facts and the middle
        // one used to print as the first.
        $badge = match ((string) $row['origin']) {
            ShopRecordScope::IMPORTED => Components::badge('success', '✓', __('فروشگاه واردشده', 'tecteb-marketplace-core')),
            ShopRecordScope::RECORDS_ONLY => Components::badge('warning', '!', __('سابقه‌اش وارد شده، فروشگاه نیست', 'tecteb-marketplace-core')),
            default => Components::badge('warning', '!', __('فقط صاحب سابقه در دکان', 'tecteb-marketplace-core')),
        };

        $notes = [];
        $notes[] = $row['has_user'] === null
            ? __('کاربر وردپرس: نامعلوم', 'tecteb-marketplace-core')
            : ($row['has_user']
                ? __('کاربر وردپرس دارد', 'tecteb-marketplace-core')
                : __('کاربر وردپرسی با این شناسه نیست', 'tecteb-marketplace-core'));
        $notes[] = $row['has_profile']
            ? ($row['can_sell']
                ? __('پروندهٔ فروشنده دارد و اجازهٔ فروش دارد', 'tecteb-marketplace-core')
                : __('پروندهٔ فروشنده دارد و اجازهٔ فروش ندارد', 'tecteb-marketplace-core'))
            : __('پروندهٔ فروشنده ندارد', 'tecteb-marketplace-core');

        return $badge . '<span class="tmc-field__desc">' . esc_html(implode(' — ', $notes)) . '</span>';
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
    private function importButton(bool $anythingToImport, bool $scopeReadable): string
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
        if (!$scopeReadable) {
            // ⛔ No button at all while the owner list may be short. The walk
            // reads Dokan's tables, which hold every shop at once, so a
            // short list is exactly the case where the owners it failed to
            // mention are the ones nobody has seen — and importing then is
            // the one thing this page exists to prevent. The run would skip
            // them anyway (`ShopRecordScope::admits()` admits nobody while
            // the list is unreadable), so this is the first of two defences.
            return $note . Components::notice('warning', __('تا وقتی فهرست صاحبان سابقه در دکان کامل خوانده نشود، ورود انجام نمی‌شود: فهرست ناقص یعنی ممکن است صاحبانی باشند که این صفحه نشانشان نداده، و سابقهٔ نشان‌داده‌نشده وارد نمی‌شود. اول خواندن را درست کنید.', 'tecteb-marketplace-core'));
        }
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
            $left = $row['missing'] === []
                ? esc_html__('چیزی باقی نمانده است.', 'tecteb-marketplace-core')
                : esc_html(implode('، ', array_map([self::class, 'gateLabel'], $row['missing'])));
            // Why the record gate answered what it did. A zero that was read
            // and a zero nobody could check used to print the same word, and
            // the only way to clear the second one was to invent a record.
            if (in_array('records_imported', (array) $row['missing'], true)) {
                $left .= '<span class="tmc-field__desc">'
                    . esc_html(self::recordsStateReason((string) ($row['detail']['records_state'] ?? '')))
                    . '</span>';
            }
            // And which half of «نگاشت دسته‌ها» is open — the decision or
            // the products. Saving the mapping clears the first and moves
            // none of the second, so one word for both is how «۱۸۴ محصول
            // بی‌دسته» reads as done.
            if (in_array('categories_mapped', (array) $row['missing'], true)) {
                $waiting = (int) ($row['detail']['products_without_category'] ?? 0);
                $unmapped = count((array) ($row['detail']['unmapped_mappable'] ?? []));
                $says = [];
                if ($unmapped > 0) {
                    $says[] = sprintf(
                        /* translators: %s: number of source categories */
                        __('%s دستهٔ مبدأ نگاشت نشده', 'tecteb-marketplace-core'),
                        $fa((string) $unmapped)
                    );
                }
                if ($waiting < 0) {
                    $says[] = __('تعداد محصولات بی‌دسته خوانده نشد', 'tecteb-marketplace-core');
                } elseif ($waiting > 0) {
                    $says[] = sprintf(
                        /* translators: %s: number of products */
                        __('%s محصول واردشده دسته‌ای ندارد و منتظر اعمال است', 'tecteb-marketplace-core'),
                        $fa((string) $waiting)
                    );
                }
                if ($says !== []) {
                    $left .= '<span class="tmc-field__desc">' . esc_html(implode(' · ', $says)) . '</span>';
                }
            }
            echo '<tr><td>' . esc_html($this->shopName($vendorUserId)) . '</td>'
                . '<td>' . self::verdictBadge((string) $row['verdict']) . '</td>'
                . '<td>' . $left . '</td></tr>';
        }
        echo '</tbody></table></div>';
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

        if ($action === 'save_category' || $action === 'forget_category') {
            // ⛔ The source key arrives as its DIGEST and is resolved against
            // the list the page drew. It cannot arrive as itself: a Persian
            // `product_cat` slug is percent-encoded, and `sanitize_key()` and
            // `sanitize_text_field()` both delete `%xx` octets — so a form
            // carrying the slug would hand this method a different category,
            // or an empty one, with nothing anywhere looking wrong.
            $mapping = $this->container->get(CategoryMapping::class);
            if (!$mapping->sourceAvailable()) {
                return 'err:' . __('دکان در حال اجرا نیست، پس دستهٔ مبدأ خوانده نمی‌شود و نگاشتی ذخیره نشد.', 'tecteb-marketplace-core');
            }
            $ref = $request->postKey('cat_ref');
            $sourceKey = '';
            foreach ($mapping->sourceCategories() as $row) {
                if (self::sourceRef((string) $row['key']) === $ref) {
                    $sourceKey = (string) $row['key'];
                    break;
                }
            }
            if ($sourceKey === '') {
                return 'err:' . __('آن دستهٔ مبدأ پیدا نشد، پس چیزی ذخیره نشد.', 'tecteb-marketplace-core');
            }

            $map = $this->container->get(CategoryMap::class);
            // Merged, never replaced. The form decides about ONE source
            // category; posting it as the whole map would erase every other
            // decision already taken — the same reason `save_roles` merges.
            $merged = $map->all();
            $target = '';
            if ($action === 'forget_category') {
                unset($merged[$sourceKey]);
            } else {
                $target = $request->postKey('cat_target');
                if ($target === '') {
                    return 'err:' . __('دسته‌ای انتخاب نشد، پس چیزی ذخیره نشد.', 'tecteb-marketplace-core');
                }
                // ⛔ Only a category that resolves RIGHT NOW. A key nobody can
                // find would be stored as a decision and then held on every
                // product for ever, which is a worse state than undecided.
                if ($mapping->targetPath($target) === '') {
                    return 'err:' . __('آن شناسه به هیچ دستهٔ ووکامرسی نمی‌رسد، پس ذخیره نشد.', 'tecteb-marketplace-core');
                }
                $merged[$sourceKey] = $target;
            }
            if (!$map->save($merged)) {
                return 'err:' . __('ذخیرهٔ نگاشت دسته انجام نشد.', 'tecteb-marketplace-core');
            }
            $this->container->get(AuditLogger::class)->log(
                AuditEventCatalog::DOKAN_CATEGORY_MAP_SAVED,
                get_current_user_id(),
                'product_category',
                null,
                [
                    'source_key' => $sourceKey,
                    'target' => $target,
                    'entries' => count($merged),
                    'removed' => $action === 'forget_category',
                ]
            );
            return 'ok:' . ($action === 'forget_category'
                ? __('نگاشت این دسته حذف شد. هیچ محصولی عوض نشد.', 'tecteb-marketplace-core')
                : __('نگاشت این دسته ذخیره شد. هنوز هیچ محصولی عوض نشده است — برای اعمال روی محصولات واردشده، دکمهٔ پایین همان کارت را بزنید.', 'tecteb-marketplace-core'));
        }

        if ($action === 'apply_category_links') {
            // The ids AND the key each row was shown with. `ApplyCategoryLinks`
            // reads the relationship again and refuses a row whose single term
            // has moved since, so this is consent to a change that was
            // PRINTED — not to whatever the shop says at the moment of the
            // click (`alpha.49`).
            $ids = [];
            $seen = [];
            foreach ($request->postMap('link') as $productId => $key) {
                $productId = (int) $productId;
                if ($productId > 0) {
                    $ids[] = $productId;
                    $seen[$productId] = (string) $key;
                }
            }
            $result = $this->container->get(ApplyCategoryLinks::class)->apply(
                $ids,
                $seen,
                $this->container->get(CapabilityCheckerInterface::class)->currentUserId() ?? 0
            );
            if (!$result['ok']) {
                return 'err:' . match ($result['code']) {
                    'nothing_selected' => __('هیچ محصولی فرستاده نشد، پس چیزی نوشته نشد.', 'tecteb-marketplace-core'),
                    'batch_too_large' => __('تعداد محصولات این درخواست بیش از حد مجاز بود، پس چیزی نوشته نشد.', 'tecteb-marketplace-core'),
                    'categories_unreadable' => __('فهرست دسته‌های ووکامرس خوانده نشد، پس هیچ‌چیز نوشته نشد.', 'tecteb-marketplace-core'),
                    default => __('اعمال اتصال دسته انجام نشد.', 'tecteb-marketplace-core'),
                };
            }
            // Every outcome by name. «۱۲ محصول نوشته شد» on its own hides the
            // rows that did not, and those are the ones a person has to see.
            $parts = [];
            foreach ($result['counts'] as $code => $n) {
                $parts[] = self::linkOutcome((string) $code) . ': ' . PersianDigits::toPersian((string) $n);
            }
            $written = (int) ($result['counts']['linked'] ?? 0);
            return ($written > 0 ? 'ok:' : 'err:') . sprintf(
                /* translators: 1: written count, 2: per-outcome list */
                __('دستهٔ %1$s محصول از ارتباط خودِ ووکامرس نوشته شد. %2$s', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $written),
                implode(' · ', $parts)
            );
        }

        if ($action === 'apply_categories') {
            // The ids AND the target each one was shown with. The service
            // derives the target again and refuses a row whose target moved,
            // so this is consent to a change that was printed rather than to
            // whatever the option says at the moment of the click.
            $shown = [];
            foreach ($request->postMap('apply') as $productId => $target) {
                $shown[(int) $productId] = (string) $target;
            }
            $result = $this->container->get(ApplyCategoryMapping::class)->apply($shown);
            if ($result['refused'] !== '') {
                return 'err:' . match ($result['refused']) {
                    'not_allowed' => __('دسترسی لازم برای اعمال نگاشت را ندارید.', 'tecteb-marketplace-core'),
                    'nothing_selected' => __('هیچ محصولی فرستاده نشد، پس چیزی نوشته نشد.', 'tecteb-marketplace-core'),
                    'source_unreadable' => __('دکان در حال اجرا نیست و دستهٔ مبدأ خوانده نمی‌شود، پس هیچ‌چیز نوشته نشد.', 'tecteb-marketplace-core'),
                    default => __('اعمال نگاشت انجام نشد.', 'tecteb-marketplace-core'),
                };
            }
            // Every outcome counted by name. «۱۲ محصول نوشته شد» alone hides
            // the rows that did not, and those are the ones a person has to
            // look at.
            $byCode = [];
            foreach ($result['outcomes'] as $code) {
                $byCode[(string) $code] = ($byCode[(string) $code] ?? 0) + 1;
            }
            $parts = [];
            foreach ($byCode as $code => $n) {
                $parts[] = self::applyOutcome((string) $code) . ': ' . PersianDigits::toPersian((string) $n);
            }
            $written = (int) $result['written'];
            return ($written > 0 ? 'ok:' : 'err:') . sprintf(
                /* translators: 1: written count, 2: per-outcome list */
                __('دستهٔ %1$s محصول نوشته شد. %2$s', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $written),
                implode(' · ', $parts)
            );
        }

        if ($action === 'import_records') {
            $preview = $this->container->get(ShopRecordPreview::class);
            // ⛔ The SAME list the preview drew and the SAME list the walk
            // covers. Asking `vendorUserIds()` here was the other half of the
            // staging mismatch: the button could refuse «سابقهٔ واردنشده‌ای
            // پیدا نشد» while fourteen rows were waiting for ids that list
            // does not contain, and it could queue a run that then wrote
            // records nobody had been shown.
            $scope = $this->container->get(ShopRecordScope::class);
            if (!$scope->sourceReadable()) {
                // The same refusal the button already states, enforced where
                // the action is taken rather than only where it is drawn: a
                // POST can arrive from a page rendered before the read broke.
                return 'err:' . __('فهرست صاحبان سابقه در دکان کامل خوانده نشد، پس ورود شروع نشد. فهرست ناقص می‌تواند صاحبانی داشته باشد که نشان داده نشده‌اند، و سابقهٔ نشان‌داده‌نشده وارد نمی‌شود.', 'tecteb-marketplace-core');
            }
            $shops = $scope->ids();
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

    /**
     * The category mapping: the decision, and — separately — the products.
     *
     * ⛔ Two halves on one card because they are constantly mistaken for each
     * other, and the delivered `alpha.79` had only the first:
     *
     *   A. WHAT A SOURCE CATEGORY MEANS. Saved in `CategoryMap`, used by the
     *      import at creation time, and it moves no product that is already
     *      here. Until this round nothing in `src/` called `save()`, so this
     *      decision could not be taken at all and the handover page named
     *      `categories_mapped` as a blocker with no door out of it.
     *
     *   B. THE PRODUCTS. The rows an import created carry `category_key = ''`
     *      and keep carrying it. They change only when a manager applies the
     *      mapping on purpose, which is the second form below — and the
     *      numbers are shown before anything is written.
     *
     * The source side needs Dokan: the import reads a product's source
     * category and does not keep it, so with Dokan gone there is nothing on
     * our side to map FROM. That is said out loud rather than drawn as an
     * empty table, which would read as «nothing to map».
     */
    private function renderCategories(callable $fa, Request $request): void
    {
        $mapping = $this->container->get(CategoryMapping::class);

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('نگاشت دستهٔ محصولات', 'tecteb-marketplace-core') . '</h2>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('ذخیرهٔ نگاشت می‌گوید یک دستهٔ دکان در این بازارگاه چه معنایی دارد. هیچ محصولی را جابه‌جا نمی‌کند: ردیف‌هایی که ورود ساخته بدون دسته‌اند و بدون دسته می‌مانند، تا وقتی با اقدام صریح شما اعمال شود. پیش از اعمال، تعداد و تغییر پیشنهادی را همین‌جا می‌بینید.', 'tecteb-marketplace-core')
            . '</p>';

        $plan = $mapping->plan();
        $waiting = (int) $plan['total'];
        echo '<p class="tmc-field__desc">' . ($waiting < 0
            ? esc_html__('تعداد محصولات بی‌دسته خوانده نشد، پس عددی گزارش نمی‌شود.', 'tecteb-marketplace-core')
            : esc_html(sprintf(
                /* translators: %s: number of products */
                __('همین حالا %s محصول واردشده دسته‌ای ندارد.', 'tecteb-marketplace-core'),
                $fa((string) $waiting)
            ))) . '</p>';

        if (!$mapping->targetsAvailable()) {
            echo Components::notice('warning', __('هیچ دستهٔ محصولی در ووکامرس خوانده نشد، پس چیزی برای انتخاب وجود ندارد. این افزونه هرگز دسته نمی‌سازد؛ دسته‌ها را در «محصولات ← دسته‌بندی‌ها» بسازید.', 'tecteb-marketplace-core'));
        }
        if (!$mapping->sourceAvailable()) {
            echo Components::notice('warning', __('دکان در حال اجرا نیست، پس دستهٔ مبدأ خوانده نمی‌شود. ورود، دستهٔ مبدأ را می‌خوانَد و ذخیره نمی‌کند، پس نه جدول نگاشت کشیده می‌شود و نه اعمال برنامه‌ریزی می‌شود. این «چیزی برای نگاشت نیست» نیست.', 'tecteb-marketplace-core'));
            echo '</section>';
            return;
        }

        $sources = $mapping->sourceCategories();
        $noSource = $mapping->productsWithoutSourceCategory();

        if ($sources === []) {
            echo Components::state(
                'empty',
                __('هیچ دستهٔ مبدأیی پیدا نشد', 'tecteb-marketplace-core'),
                __('محصولات دکان دستهٔ ووکامرسی ندارند که نگاشت شود.', 'tecteb-marketplace-core')
            );
        } else {
            echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
                . esc_attr__('جدول نگاشت دسته‌ها', 'tecteb-marketplace-core') . '">';
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('دستهٔ مبدأ', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('شناسه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('تعداد محصول', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('دستهٔ بازارگاه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($sources as $row) {
                $ref = self::sourceRef((string) $row['key']);
                // ⛔ The target cell says WHICH of three it is. «تعیین‌نشده» and
                // «به دسته‌ای اشاره می‌کند که پیدا نشد» are opposite problems
                // and one word for both is how an invalid mapping gets applied.
                if (!$row['decided']) {
                    $target = Components::badge('warning', '!', __('تعیین‌نشده', 'tecteb-marketplace-core'));
                } elseif (!$row['target_valid']) {
                    $target = Components::badge('error', '✕', __('نامعتبر', 'tecteb-marketplace-core'))
                        . ' ' . Components::code((string) $row['target'])
                        . '<span class="tmc-field__desc">'
                        . esc_html__('این شناسه به هیچ دستهٔ ووکامرسی نمی‌رسد، پس روی هیچ محصولی نوشته نمی‌شود.', 'tecteb-marketplace-core')
                        . '</span>';
                } else {
                    $target = esc_html((string) $row['target_path'])
                        . '<span class="tmc-field__desc">' . esc_html($fa((string) $row['target'])) . '</span>';
                }
                echo '<tr><td>' . ((string) $row['label'] === ''
                        ? esc_html__('(بی‌نام)', 'tecteb-marketplace-core')
                        : esc_html((string) $row['label'])) . '</td>'
                    . '<td>' . Components::code((string) $row['key']) . '</td>'
                    . '<td>' . esc_html($fa((string) $row['products'])) . '</td>'
                    . '<td>' . $target . '</td>'
                    . '<td><a class="tmc-button tmc-button--secondary" href="'
                    . esc_url(add_query_arg(
                        ['page' => self::SLUG, 'cat' => $ref],
                        admin_url('admin.php')
                    )) . '#tmc-cat-pick">'
                    . esc_html__('انتخاب دسته', 'tecteb-marketplace-core') . '</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }

        if ($noSource > 0) {
            echo Components::notice('info', sprintf(
                /* translators: %s: number of products */
                __('%s محصول دکان هیچ دستهٔ مبدأیی ندارد. هیچ نگاشتی این‌ها را درست نمی‌کند، چون چیزی برای نگاشت وجود ندارد، و هیچ دسته‌ای هم خودکار برایشان تعیین نمی‌شود؛ دستهٔ این‌ها را در صفحهٔ «بررسی محصولات» یک انسان انتخاب می‌کند.', 'tecteb-marketplace-core'),
                $fa((string) $noSource)
            ));
        }

        $this->renderCategoryPicker($mapping, $sources, $request, $fa);
        $this->renderCategoryApply($plan, $fa);
        echo '</section>';
    }

    /**
     * One source category's picker — opened by a link, saved by a POST.
     *
     * A `<select>` of the owner's 1,070 `product_cat` terms is ~90KB, and
     * «لوازم جانبی» sits in six branches with nothing on screen to tell them
     * apart (`alpha.25`). So the same answer the vendor's form gives: a search
     * box, results carrying their whole ancestry, and a cap.
     *
     * ⛔ The source category travels as a HASH, never as itself. A
     * `product_cat` slug on a Persian site is percent-encoded
     * («%d8%aa%d8%ac…»), and both `sanitize_key()` and `sanitize_text_field()`
     * delete `%xx` octets — so a form that carried the slug would arrive
     * holding a different category, or none. The digest is hex, survives both,
     * and is resolved back against the list that was drawn.
     *
     * @param list<array<string,mixed>> $sources
     */
    private function renderCategoryPicker(
        CategoryMapping $mapping,
        array $sources,
        Request $request,
        callable $fa
    ): void {
        $ref = $request->queryKey('cat');
        if ($ref === '') {
            return;
        }
        $chosen = null;
        foreach ($sources as $row) {
            if (self::sourceRef((string) $row['key']) === $ref) {
                $chosen = $row;
                break;
            }
        }
        echo '<h3 class="tmc-card__title" id="tmc-cat-pick">'
            . esc_html__('انتخاب دستهٔ بازارگاه', 'tecteb-marketplace-core') . '</h3>';
        if ($chosen === null) {
            echo Components::notice('error', __('آن دستهٔ مبدأ در فهرست بالا نیست. ممکن است محصولاتش رفته باشند یا دسته‌شان عوض شده باشد؛ از جدول بالا یکی را انتخاب کنید.', 'tecteb-marketplace-core'));
            return;
        }

        $query = $request->queryText('cat_q');
        $found = $mapping->pick($query);
        echo '<p class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: 1: source category label, 2: product count */
            __('برای «%1$s» — %2$s محصول در دکان.', 'tecteb-marketplace-core'),
            (string) $chosen['label'] !== '' ? (string) $chosen['label'] : (string) $chosen['key'],
            $fa((string) $chosen['products'])
        )) . '</p>';

        // The search is a GET, so the page can be reloaded or bookmarked and
        // nothing is written by looking.
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">'
            . '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">'
            . '<input type="hidden" name="cat" value="' . esc_attr($ref) . '">'
            . '<label class="screen-reader-text" for="tmc-cat-q">'
            . esc_html__('جست‌وجوی دستهٔ بازارگاه', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="search" id="tmc-cat-q" name="cat_q" value="' . esc_attr($query) . '"'
            . ' placeholder="' . esc_attr__('نام دسته را بنویسید…', 'tecteb-marketplace-core') . '">'
            . ' <button type="submit" class="tmc-button tmc-button--secondary">'
            . esc_html__('جست‌وجوی دسته', 'tecteb-marketplace-core') . '</button>'
            . '</form>';

        echo '<p class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: 1: matches, 2: shown, 3: total */
            __('%1$s مورد مطابق از %3$s دستهٔ ووکامرس، %2$s مورد نمایش داده شد.', 'tecteb-marketplace-core'),
            $fa((string) $found['matched']),
            $fa((string) count($found['results'])),
            $fa((string) $found['total'])
        )) . '</p>';

        echo '<form method="post">';
        if ($found['results'] === []) {
            echo Components::notice('info', __('هیچ دسته‌ای با این عبارت پیدا نشد. عبارت دیگری بنویسید.', 'tecteb-marketplace-core'));
        } else {
            echo '<fieldset class="tmc-fieldset"><legend>'
                . esc_html__('دستهٔ بازارگاه', 'tecteb-marketplace-core') . '</legend>';
            foreach ($found['results'] as $category) {
                $id = 'tmc-cat-' . $category->id;
                echo '<p><input type="radio" id="' . esc_attr($id) . '" name="cat_target" value="'
                    . esc_attr($category->value()) . '"'
                    . ((string) $chosen['target'] === $category->value() ? ' checked' : '') . '>'
                    . ' <label for="' . esc_attr($id) . '">'
                    . esc_html($category->path !== '' ? $category->path : $category->name)
                    . ($category->fuzzy
                        ? ' ' . Components::badge('neutral', '~', __('شبیه', 'tecteb-marketplace-core'))
                        : '')
                    . '</label></p>';
            }
            echo '</fieldset>';
        }
        echo '<input type="hidden" name="cat_ref" value="' . esc_attr($ref) . '">'
            . wp_nonce_field(self::NONCE, 'tmc_handover_nonce', true, false)
            . '<p><button type="submit" class="tmc-button" name="handover_action" value="save_category">'
            . esc_html__('ذخیرهٔ نگاشت این دسته', 'tecteb-marketplace-core') . '</button>';
        if ((bool) $chosen['decided']) {
            // Undeciding is a decision too, and it has to be reachable —
            // otherwise a mapping saved by mistake is permanent.
            echo ' <button type="submit" class="tmc-button tmc-button--secondary" name="handover_action" value="forget_category">'
                . esc_html__('حذف این نگاشت', 'tecteb-marketplace-core') . '</button>';
        }
        echo '</p><p class="tmc-field__desc">'
            . esc_html__('ذخیره فقط همین یک دسته را تعیین می‌کند و روی هیچ محصولی نوشته نمی‌شود.', 'tecteb-marketplace-core')
            . '</p></form>';
    }

    /**
     * What applying would do, and the one button that does it.
     *
     * The counts come from `plan()`, which reads our own rows; the form
     * carries, per product, the target that was PRINTED. The service derives
     * the target again at the click and refuses a row whose target moved
     * between the two — a click on an old page is not consent to whatever the
     * option says now (`alpha.49`).
     *
     * @param array<string,mixed> $plan
     */
    private function renderCategoryApply(array $plan, callable $fa): void
    {
        echo '<h3 class="tmc-card__title">'
            . esc_html__('اعمال نگاشت روی محصولات واردشده', 'tecteb-marketplace-core') . '</h3>';

        $apply = (array) $plan['apply'];
        $held = (array) $plan['held'];

        if (!(bool) $plan['finished']) {
            echo Components::notice('warning', sprintf(
                /* translators: 1: scanned, 2: total */
                __('این شمارش به آخر نرسید: %1$s ردیف بررسی شد و %2$s ردیف منتظر است. آنچه پایین می‌بینید دربارهٔ همین ردیف‌های بررسی‌شده است، نه کل آن‌ها.', 'tecteb-marketplace-core'),
                $fa((string) $plan['scanned']),
                // `Components::notice()` escapes its text, so this must NOT
                // be escaped here as well.
                (int) $plan['total'] < 0
                    ? __('نامعلوم', 'tecteb-marketplace-core')
                    : $fa((string) $plan['total'])
            ));
        }

        if ($apply === []) {
            echo Components::state(
                'empty',
                __('هیچ محصولی آمادهٔ اعمال نیست', 'tecteb-marketplace-core'),
                __('یا هیچ محصول بی‌دسته‌ای نمانده، یا دستهٔ مبدأ هر کدام هنوز نگاشت نشده است. دلیل هر گروه پایین نام‌برده شده.', 'tecteb-marketplace-core')
            );
        } else {
            // Grouped by the change, because «۱۸۴ محصول عوض می‌شود» is not a
            // preview and 184 rows is not a page a person reads.
            $groups = [];
            foreach ($apply as $row) {
                $key = $row['source_key'] . "\0" . $row['target'];
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'source' => (string) ($row['source_label'] !== '' ? $row['source_label'] : $row['source_key']),
                        'target_path' => (string) $row['target_path'],
                        'products' => 0,
                    ];
                }
                $groups[$key]['products']++;
            }
            echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
                . esc_attr__('جدول تغییر پیشنهادی', 'tecteb-marketplace-core') . '">';
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('دستهٔ مبدأ', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('به دستهٔ بازارگاه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('تعداد محصول', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($groups as $group) {
                echo '<tr><td>' . esc_html($group['source']) . '</td>'
                    . '<td>' . esc_html($group['target_path']) . '</td>'
                    . '<td>' . esc_html($fa((string) $group['products'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';

            echo '<form method="post">';
            foreach ($apply as $row) {
                echo '<input type="hidden" name="apply[' . (int) $row['product_id'] . ']" value="'
                    . esc_attr((string) $row['target']) . '">';
            }
            echo wp_nonce_field(self::NONCE, 'tmc_handover_nonce', true, false)
                . '<p><button type="submit" class="tmc-button" name="handover_action" value="apply_categories">'
                . esc_html(sprintf(
                    /* translators: %s: number of products */
                    __('اعمال روی %s محصول', 'tecteb-marketplace-core'),
                    $fa((string) count($apply))
                ))
                . '</button></p>'
                . '<p class="tmc-field__desc">'
                . esc_html__('فقط ستون دسته روی همین ردیف‌ها نوشته می‌شود — و فقط روی ردیفی که همین حالا بی‌دسته است. هیچ محصول تازه‌ای ساخته نمی‌شود، هیچ ورودی تکرار نمی‌شود، و مالکیت، وضعیت انتشار، اجازهٔ فروش، دسته‌های ووکامرس و اطلاعات مالی دست نمی‌خورند.', 'tecteb-marketplace-core')
                . '</p></form>';
        }

        if ($held !== []) {
            echo '<h3 class="tmc-card__title">'
                . esc_html__('آنچه اعمال نمی‌شود، و دلیلش', 'tecteb-marketplace-core') . '</h3>';
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('دلیل', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('تعداد محصول', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($held as $reason => $rows) {
                echo '<tr><td>' . esc_html(self::heldReason((string) $reason))
                    . '</td><td>' . esc_html($fa((string) count($rows))) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    /**
     * A source category key, transportable.
     *
     * Hex, and that is the point: `sanitize_key()` and `sanitize_text_field()`
     * both strip `%xx` octets, and a Persian `product_cat` slug is nothing but
     * those. The key itself never travels through a form.
     */
    private static function sourceRef(string $sourceKey): string
    {
        return substr(sha1($sourceKey), 0, 32);
    }

    /**
     * What happened to one product, in words.
     *
     * Eight answers rather than «شد / نشد», because they ask for different
     * things: `already_set` means a human chose a category and this refused to
     * overwrite it, `target_moved` means the page was stale, `storage_failed`
     * means try again, and `not_imported` means no migration decision reaches
     * that row at all.
     */
    /**
     * The category each imported product's own WooCommerce relationship names.
     *
     * A second card beside the mapping, not a replacement for it, because the
     * two answer different questions. The mapping says what a DOKAN category
     * means here and needs a human decision per source category. This one
     * needs no decision at all for most rows: the product is already filed in
     * `product_cat`, so the category is a fact to be copied rather than a
     * choice to be made — «دسته‌های موجود ووکامرس و ارتباط فعلی محصولات با
     * آن‌ها مرجع هستند … انتخاب دستی مجدد همهٔ دسته‌ها قابل‌قبول نیست».
     *
     * Read only until the button is pressed: `CategoryLinkPlan` has no write
     * method of any kind, which an architecture test asserts from its bytes.
     */
    private function renderCategoryLinks(callable $fa): void
    {
        $plan = $this->container->get(CategoryLinkPlan::class)->plan();

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('دستهٔ محصولات از ارتباط فعلی ووکامرس', 'tecteb-marketplace-core') . '</h2>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('هر محصول واردشده همین حالا در ووکامرس دسته دارد یا ندارد. این کارت همان ارتباط را بر اساس شناسهٔ دسته می‌خواند — نه شباهت نام و نه حدس — و نشان می‌دهد نوشتنش چه چیزی را عوض می‌کند. تا زدن دکمه هیچ‌چیز نوشته نمی‌شود، و هیچ دسته یا رابطهٔ ووکامرس ساخته، حذف یا تغییر نمی‌شود.', 'tecteb-marketplace-core')
            . '</p>';

        if (!$plan['available']) {
            echo '<p class="tmc-field__desc">'
                . esc_html__('فهرست دسته‌های ووکامرس خوانده نشد، پس ارتباط هیچ محصولی تفسیر نمی‌شود و عددی گزارش نمی‌شود.', 'tecteb-marketplace-core')
                . '</p></section>';
            return;
        }

        $total = (int) $plan['total'];
        echo '<p class="tmc-field__desc">' . ($total < 0
            ? esc_html__('تعداد کل ردیف‌های واردشده خوانده نشد، پس عددی گزارش نمی‌شود.', 'tecteb-marketplace-core')
            : esc_html(sprintf(
                /* translators: 1: scanned 2: total */
                __('%1$s ردیف از %2$s ردیف واردشده در این صفحه بررسی شد.', 'tecteb-marketplace-core'),
                $fa((string) $plan['scanned']),
                $fa((string) $total)
            ))) . '</p>';
        if (!$plan['finished']) {
            // ⛔ Said, not hidden. A page that reached its cap cannot claim
            // «this is all of them» (`alpha.70`).
            echo '<p class="tmc-field__desc">'
                . esc_html__('این صفحه به سقف خودش رسید، پس ردیف‌های بعدی در این شمارش نیستند.', 'tecteb-marketplace-core')
                . '</p>';
        }

        // Every bucket by name, including the empty ones that matter.
        echo '<ul class="tmc-list">';
        foreach ($plan['counts'] as $code => $n) {
            echo '<li>' . esc_html(self::linkCount((string) $code) . ': ' . $fa((string) $n)) . '</li>';
        }
        echo '</ul>';

        $this->renderLinkGroups($fa, is_array($plan['groups']) ? $plan['groups'] : []);
        $this->renderLinkApply($fa, is_array($plan['apply']) ? $plan['apply'] : []);
        echo '</section>';
    }

    /**
     * The ambiguous products, grouped by the SET of categories they share.
     *
     * Grouped so one answer covers «these eleven are in both چسب branches»
     * instead of eleven identical questions — and every id is printed with its
     * full ancestry, because that short list IS what a human picks from.
     * Nothing here is a form: «انتخاب دستی فقط برای استثناهای واقعی باقی
     * بماند», and the choice is made on the product's own review page.
     *
     * @param list<array<string,mixed>> $groups
     */
    private function renderLinkGroups(callable $fa, array $groups): void
    {
        if ($groups === []) {
            return;
        }
        echo '<h3>' . esc_html__('محصولات چنددسته‌ای — نیازمند تصمیم انسان', 'tecteb-marketplace-core') . '</h3>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('این محصول‌ها بیش از یک دستهٔ ووکامرس دارند. مدل این بازارگاه برای هر محصول یک دسته نگه می‌دارد، پس هیچ‌کدام خودسرانه انتخاب نمی‌شود و دسته‌های دیگر هم کنار گذاشته نمی‌شوند: ارتباط هر سه در ووکامرس سر جایش می‌مانَد و انتخاب از همین فهرستِ کوتاه روی صفحهٔ بررسی همان محصول انجام می‌شود.', 'tecteb-marketplace-core')
            . '</p>';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th>' . esc_html__('دسته‌های فعلی (شناسه و مسیر کامل)', 'tecteb-marketplace-core') . '</th>'
            . '<th>' . esc_html__('تعداد محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th>' . esc_html__('نمونه شناسهٔ محصول', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($groups as $group) {
            $paths = [];
            foreach (is_array($group['paths'] ?? null) ? $group['paths'] : [] as $row) {
                $paths[] = '#' . $fa((string) ($row['id'] ?? '')) . ' — ' . (string) ($row['path'] ?? '');
            }
            foreach (is_array($group['invalid_ids'] ?? null) ? $group['invalid_ids'] : [] as $bad) {
                $paths[] = '#' . $fa((string) $bad) . ' — ' . __('نامعتبر', 'tecteb-marketplace-core');
            }
            if (!empty($group['default_attached'])) {
                $paths[] = __('به‌علاوهٔ دستهٔ پیش‌فرض ووکامرس', 'tecteb-marketplace-core');
            }
            $sample = [];
            foreach (is_array($group['sample'] ?? null) ? $group['sample'] : [] as $id) {
                $sample[] = '#' . $fa((string) $id);
            }
            echo '<tr><td>' . esc_html(implode(' · ', $paths)) . '</td>'
                . '<td>' . esc_html($fa((string) ($group['products'] ?? 0))) . '</td>'
                . '<td>' . esc_html(implode(' ، ', $sample)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * The definite links, and the one explicit action that writes them.
     *
     * Each row carries the key it was SHOWN with, so the service can refuse a
     * product whose single term moved between this render and the click rather
     * than writing whatever is there now (`alpha.49`).
     *
     * @param list<array<string,mixed>> $apply
     */
    private function renderLinkApply(callable $fa, array $apply): void
    {
        if ($apply === []) {
            echo '<p class="tmc-field__desc">'
                . esc_html__('هیچ ردیفی آمادهٔ نوشتن نیست: ردیف‌های واردشده یا از قبل دسته دارند، یا دسته‌شان قطعی نیست و بالاتر نام برده شده‌اند.', 'tecteb-marketplace-core')
                . '</p>';
            return;
        }
        echo '<h3>' . esc_html__('اتصال‌های قطعی — آمادهٔ اعمال', 'tecteb-marketplace-core') . '</h3>';
        echo '<form method="post">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th>' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th>' . esc_html__('دستهٔ پیشنهادی (شناسه و مسیر کامل)', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach (array_slice($apply, 0, ApplyCategoryLinks::BATCH_LIMIT) as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            echo '<tr><td>' . esc_html('#' . $fa((string) $productId) . ' — ' . (string) ($row['title'] ?? '')) . '</td>'
                . '<td>' . esc_html('#' . $fa((string) ($row['proposed_key'] ?? '')) . ' — ' . (string) ($row['proposed_path'] ?? ''))
                . '<input type="hidden" name="link[' . esc_attr((string) $productId) . ']" value="'
                . esc_attr((string) ($row['proposed_key'] ?? '')) . '"></td></tr>';
        }
        echo '</tbody></table>';
        echo wp_nonce_field(self::NONCE, 'tmc_handover_nonce', true, false)
            . '<p><button type="submit" class="tmc-button" name="handover_action" value="apply_category_links">'
            . esc_html__('اعمال اتصال‌های قطعی روی محصولات واردشده', 'tecteb-marketplace-core')
            . '</button></p></form>';
    }

    /** The label for one bucket of the link plan. */
    private static function linkCount(string $code): string
    {
        return match ($code) {
            CategoryLinkPlan::APPLY => __('آمادهٔ نوشتن (یک دستهٔ معتبر، بدون کلید ذخیره‌شده)', 'tecteb-marketplace-core'),
            CategoryLinkPlan::SETTLED => __('از قبل درست است (کلید ذخیره‌شده همان یک دستهٔ محصول است)', 'tecteb-marketplace-core'),
            CategoryLinkPlan::DECIDED => __('کلید ذخیره‌شده‌شان دستهٔ دیگری است و بازنویسی نمی‌شود', 'tecteb-marketplace-core'),
            default => self::linkOutcome($code),
        };
    }

    /** One sentence per outcome of applying a product's own relationship. */
    private static function linkOutcome(string $code): string
    {
        return match ($code) {
            'linked' => __('از ارتباط ووکامرس نوشته شد', 'tecteb-marketplace-core'),
            'already_set' => __('از قبل دسته داشت و دست نخورد', 'tecteb-marketplace-core'),
            'link_moved' => __('دستهٔ محصول بین نمایش و کلیک عوض شد و نوشته نشد', 'tecteb-marketplace-core'),
            CategoryLinkPlan::MULTIPLE => __('بیش از یک دسته دارد، پس هیچ‌کدام خودسرانه انتخاب نشد', 'tecteb-marketplace-core'),
            CategoryLinkPlan::NARROWED => __('بیش از یک دسته دارد و کلید ذخیره‌شده‌اش یکی از آن‌هاست — نیازمند تصمیم انسان', 'tecteb-marketplace-core'),
            CategoryLinkPlan::DEFAULT_TERM => __('تنها دستهٔ پیش‌فرض ووکامرس را دارد، که دسته نیست', 'tecteb-marketplace-core'),
            CategoryLinkPlan::NO_CATEGORY => __('در ووکامرس هیچ دسته‌ای ندارد', 'tecteb-marketplace-core'),
            CategoryLinkPlan::INVALID => __('دسته‌هایش در این سایت پیدا نشدند', 'tecteb-marketplace-core'),
            CategoryLinkPlan::NO_PRODUCT => __('محصول ووکامرسی پشتش نیست', 'tecteb-marketplace-core'),
            CategoryLinkPlan::UNREADABLE => __('ارتباط دسته‌اش خوانده نشد', 'tecteb-marketplace-core'),
            'not_found' => __('چنین ردیفی نیست', 'tecteb-marketplace-core'),
            'not_imported' => __('ورودی آن را نساخته، پس تصمیم مهاجرت به آن نمی‌رسد', 'tecteb-marketplace-core'),
            'bad_category' => __('دستهٔ خالی دسته نیست', 'tecteb-marketplace-core'),
            'storage_failed' => __('نوشتن انجام نشد', 'tecteb-marketplace-core'),
            default => $code,
        };
    }

    private static function applyOutcome(string $code): string
    {
        return match ($code) {
            'written' => __('نوشته شد', 'tecteb-marketplace-core'),
            'already_set' => __('از قبل دسته داشت و دست نخورد', 'tecteb-marketplace-core'),
            'target_moved' => __('دستهٔ پیشنهادی بین نمایش و کلیک عوض شد و نوشته نشد', 'tecteb-marketplace-core'),
            'not_found' => __('چنین ردیفی نیست', 'tecteb-marketplace-core'),
            'not_imported' => __('ورودی آن را نساخته، پس تصمیم مهاجرت به آن نمی‌رسد', 'tecteb-marketplace-core'),
            'bad_category' => __('دستهٔ خالی دسته نیست', 'tecteb-marketplace-core'),
            'storage_failed' => __('نوشتن انجام نشد', 'tecteb-marketplace-core'),
            'not_waiting' => __('دیگر منتظر دسته نبود', 'tecteb-marketplace-core'),
            default => self::heldReason($code),
        };
    }

    /** Why a waiting product is not being written — one sentence each. */
    private static function heldReason(string $reason): string
    {
        return match ($reason) {
            'unmapped' => __('دستهٔ مبدأشان هنوز نگاشت نشده است', 'tecteb-marketplace-core'),
            'invalid_target' => __('نگاشتشان به دسته‌ای اشاره می‌کند که پیدا نشد', 'tecteb-marketplace-core'),
            'no_source_category' => __('در دکان هیچ دسته‌ای ندارند، پس نگاشتی برایشان وجود ندارد', 'tecteb-marketplace-core'),
            'source_missing' => __('محصول ووکامرسِ متناظرشان در فهرست دکان نیست، پس دستهٔ مبدأ خوانده نشد', 'tecteb-marketplace-core'),
            'source_unreadable' => __('دکان در حال اجرا نیست و دستهٔ مبدأ خوانده نمی‌شود', 'tecteb-marketplace-core'),
            default => $reason,
        };
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

    /**
     * Why «ورود سابقه» is still open, in the words of the state that holds it.
     *
     * ⛔ The one sentence that must never be printed here is a count. For
     * vendor 40 on staging all three sources read successfully and all three
     * were empty, and the table said «ورود سابقه» — advice whose only exit
     * was a record nobody has. A verified zero now closes the gate, so this
     * method only ever explains a gate that is genuinely still open.
     *
     * ⛔ And the two «نمی‌دانم»s have separate sentences, because they ask for
     * different things: `dokan_absent` is «the question can no longer be
     * asked, and no recorded verification exists», `unverifiable` is «this
     * install could not read its own record table». `no_source` never
     * reaches here — Dokan answering that there is no such source closes the
     * gate, so it is never a blocker to explain.
     */
    private static function recordsStateReason(string $state): string
    {
        return match ($state) {
            MigrationCompleteness::RECORDS_OUTSTANDING =>
                __('دکان سابقه‌ای دارد که این‌طرف نیست. دکمهٔ «ورود سوابق به صف» در بخش بالا همین را وارد می‌کند.', 'tecteb-marketplace-core'),
            MigrationCompleteness::RECORDS_UNREADABLE =>
                __('دست‌کم یکی از سه منبع خوانده نشد، پس معلوم نیست سابقه‌ای مانده یا نه. این «صفر» نیست و به‌عنوان صفر شمرده نمی‌شود.', 'tecteb-marketplace-core'),
            MigrationCompleteness::RECORDS_DOKAN_ABSENT =>
                __('دکان روی این نصب اجرا نمی‌شود، پس «چیزی مانده یا نه» پرسیدنی نیست. این دروازه از شمارش باز نمی‌شود: وجود محصول یا بخشی از سوابق اثبات نمی‌کند بقیه رسیده‌اند. آنچه می‌توانست ببندد، خودِ تأیید است — ثبتی که بگوید هر سه نوع تا آخر پیموده شده‌اند در حالی که دکان خوانده می‌شد — و چنین ثبتی در این نسخه وجود ندارد.', 'tecteb-marketplace-core'),
            MigrationCompleteness::RECORDS_UNVERIFIABLE =>
                __('این نصب نمی‌تواند بگوید چه داریم — جدول سوابق این افزونه در دسترس نبود. تا آن حل نشود نه «صفر» گفته می‌شود و نه «کامل».', 'tecteb-marketplace-core'),
            default => __('وضعیت سابقهٔ این فروشگاه مشخص نشد.', 'tecteb-marketplace-core'),
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
