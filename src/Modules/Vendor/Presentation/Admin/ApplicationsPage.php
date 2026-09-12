<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorCapabilities;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * The manager's review queue, inside wp-admin where managers already work.
 *
 * Two decisions per application and nothing else: approve, or say what must
 * change. Both are recorded with who and when (master spec §4.1); the note a
 * manager writes is the text the applicant will read, so it is required.
 */
final class ApplicationsPage
{
    public const SLUG = 'tmc-vendor-applications';
    public const CAPABILITY = VendorCapabilities::REVIEW;
    private const NONCE = 'tmc_vendor_review';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('درخواست‌های فروشندگان', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $request = Request::capture();
        $notice = $this->handleAction($request);
        $repository = $this->container->get(VendorRepositoryInterface::class);
        $openId = $request->queryInt('application');

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(str_starts_with($notice, 'ok:') ? 'success' : 'error', substr($notice, strpos($notice, ':') + 1));
        }

        if ($openId > 0) {
            $this->renderDetail($openId);
        } else {
            $this->renderQueue($repository);
        }
        echo Components::shellClose();
    }

    private function renderQueue(VendorRepositoryInterface $repository): void
    {
        $counts = $repository->countByStatus();
        $applications = $repository->listApplications();
        $fa = static fn (int $n): string => PersianDigits::toPersian((string) $n);

        echo '<p class="tmc-lead">' . esc_html__('هر درخواست را باز کنید تا اطلاعات و مدارکش را ببینید و تصمیم بگیرید.', 'tecteb-marketplace-core') . '</p>';

        $summary = [];
        foreach ([ApplicationStatus::Submitted, ApplicationStatus::ChangesRequested, ApplicationStatus::Approved, ApplicationStatus::Rejected] as $status) {
            $summary[] = ['label' => VendorMessages::status($status), 'value' => $fa($counts[$status->value] ?? 0)];
        }
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('خلاصه صف', 'tecteb-marketplace-core') . '</h2>';
        echo Components::dataList($summary);
        echo '</section>';

        if ($applications === []) {
            echo '<section class="tmc-card"><p>' . esc_html__('هنوز هیچ درخواستی ثبت نشده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }

        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('درخواست‌ها', 'tecteb-marketplace-core') . '</h2>';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آخرین تغییر', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($applications as $application) {
            $url = admin_url('admin.php?page=' . self::SLUG . '&application=' . $application->id);
            echo '<tr>'
                . '<th scope="row" data-label="' . esc_attr__('فروشگاه', 'tecteb-marketplace-core') . '">' . esc_html($application->details->storeName !== '' ? $application->details->storeName : __('بدون نام', 'tecteb-marketplace-core')) . '</th>'
                . '<td data-label="' . esc_attr__('وضعیت', 'tecteb-marketplace-core') . '">' . Components::badge(VendorMessages::statusTone($application->status), '•', VendorMessages::status($application->status)) . '</td>'
                . '<td data-label="' . esc_attr__('آخرین تغییر', 'tecteb-marketplace-core') . '">' . Components::code($application->updatedAt) . '</td>'
                . '<td data-label="' . esc_attr__('اقدام', 'tecteb-marketplace-core') . '"><a class="tmc-button tmc-button--secondary" href="' . esc_url($url) . '">' . esc_html__('بررسی', 'tecteb-marketplace-core') . '</a></td>'
                . '</tr>';
        }
        echo '</tbody></table></section>';
    }

    private function renderDetail(int $applicationId): void
    {
        $repository = $this->container->get(VendorRepositoryInterface::class);
        $application = $repository->findApplication($applicationId);
        if ($application === null) {
            echo Components::notice('error', __('این درخواست پیدا نشد.', 'tecteb-marketplace-core'));
            return;
        }
        $documents = $this->container->get(DocumentRepositoryInterface::class)->forApplication($applicationId);
        $back = admin_url('admin.php?page=' . self::SLUG);

        echo '<p><a href="' . esc_url($back) . '">' . esc_html__('بازگشت به فهرست درخواست‌ها', 'tecteb-marketplace-core') . '</a></p>';
        echo '<section class="tmc-card"><div class="tmc-module__head">'
            . '<h2 class="tmc-card__title">' . esc_html($application->details->storeName) . '</h2>'
            . Components::badge(VendorMessages::statusTone($application->status), '•', VendorMessages::status($application->status))
            . '</div>';
        echo Components::dataList([
            ['label' => __('نام حقوقی', 'tecteb-marketplace-core'), 'value' => $application->details->legalName],
            ['label' => __('ایمیل', 'tecteb-marketplace-core'), 'value' => Components::code($application->details->contactEmail), 'raw' => true],
            ['label' => __('موبایل', 'tecteb-marketplace-core'), 'value' => Components::code($application->details->contactMobile) . ' — ' . esc_html__('ثبت‌شده، تأییدنشده', 'tecteb-marketplace-core'), 'raw' => true],
            ['label' => __('نشانی', 'tecteb-marketplace-core'), 'value' => $application->details->address],
            ['label' => __('پذیرش قوانین', 'tecteb-marketplace-core'), 'value' => $application->details->termsAccepted ? __('بله', 'tecteb-marketplace-core') : __('خیر', 'tecteb-marketplace-core')],
            ['label' => __('زمان ارسال', 'tecteb-marketplace-core'), 'value' => $application->submittedAt ?? __('ارسال نشده', 'tecteb-marketplace-core')],
        ]);
        echo '</section>';

        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('مدارک', 'tecteb-marketplace-core') . '</h2>';
        if ($documents === []) {
            echo '<p>' . esc_html__('مدرکی بارگذاری نشده است.', 'tecteb-marketplace-core') . '</p>';
        } else {
            $urls = new VendorUrls(home_url('/vendor/'), home_url('/vendor/application/'));
            echo '<ul class="tmc-list">';
            foreach ($documents as $document) {
                echo '<li class="tmc-list__item">'
                    . '<span class="tmc-list__name">' . esc_html($document->typeSlug) . '</span>'
                    . Components::code($document->originalName)
                    . '<a class="tmc-button tmc-button--secondary" href="' . esc_url($urls->document($document->id)) . '">' . esc_html__('دریافت فایل', 'tecteb-marketplace-core') . '</a>'
                    . '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';

        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('تصمیم شما', 'tecteb-marketplace-core') . '</h2>'
            . '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '&application=' . $applicationId)) . '">'
            . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
            . '<input type="hidden" name="application_id" value="' . esc_attr((string) $applicationId) . '">'
            . '<div class="tmc-field"><label class="tmc-field__label" for="tmc-review-note">' . esc_html__('یادداشت برای فروشنده', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" id="tmc-review-note" name="note" rows="3" style="inline-size:100%"></textarea>'
            . '<p class="tmc-field__desc">' . esc_html__('برای «درخواست اصلاح» و «رد» نوشتن یادداشت اجباری است؛ همین متن را فروشنده می‌بیند.', 'tecteb-marketplace-core') . '</p></div>'
            . '<div class="tmc-field tmc-field--check"><label><input type="checkbox" name="can_publish" value="1"> '
            . esc_html__('اجازه انتشار مستقیم محصول هم داده شود (اختیاری و جدا از اجازه فروش)', 'tecteb-marketplace-core') . '</label></div>'
            . '<div class="tmc-actions">'
            . '<button type="submit" name="decision" value="approve" class="tmc-button tmc-button--primary">' . esc_html__('تأیید فروشنده', 'tecteb-marketplace-core') . '</button>'
            . '<button type="submit" name="decision" value="changes" class="tmc-button tmc-button--secondary">' . esc_html__('درخواست اصلاح', 'tecteb-marketplace-core') . '</button>'
            . '<button type="submit" name="decision" value="reject" class="tmc-button tmc-button--secondary">' . esc_html__('رد درخواست', 'tecteb-marketplace-core') . '</button>'
            . '</div></form></section>';
    }

    /** @return string '' | 'ok:<msg>' | 'err:<msg>' */
    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('decision')) {
            return '';
        }
        if (!$request->nonceOk('tmc_review_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $id = $request->postInt('application_id');
        $note = $request->postTextarea('note');
        $service = $this->container->get(ReviewApplication::class);

        $result = match ($request->postKey('decision')) {
            'approve' => $service->approve($id, $request->postChecked('can_publish')),
            'changes' => $service->requestChanges($id, $note),
            'reject' => $service->reject($id, $note),
            default => null,
        };
        if ($result === null) {
            return '';
        }
        if ($result->ok) {
            return 'ok:' . __('تصمیم ثبت شد و برای فروشنده نمایش داده می‌شود.', 'tecteb-marketplace-core');
        }
        return 'err:' . match ($result->code) {
            'note_required' => __('برای این تصمیم نوشتن یادداشت اجباری است.', 'tecteb-marketplace-core'),
            'invalid_transition' => __('این تصمیم در وضعیت فعلی درخواست ممکن نیست.', 'tecteb-marketplace-core'),
            'forbidden' => __('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'),
            'not_found' => __('این درخواست پیدا نشد.', 'tecteb-marketplace-core'),
            default => __('ثبت تصمیم انجام نشد.', 'tecteb-marketplace-core'),
        };
    }
}
