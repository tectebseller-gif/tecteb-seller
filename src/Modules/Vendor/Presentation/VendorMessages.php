<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Vendor\Application\TaskState;
use Tecteb\Marketplace\Modules\Vendor\Application\WorkspaceTask;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

/**
 * Every Persian sentence the vendor area says, in one file.
 *
 * Rules this file keeps: speak to a shop owner, never to a developer; say
 * what happens next rather than what failed internally; and never promise
 * something the build cannot do (a number is «تأییدنشده», not «در انتظار
 * تأیید», because nothing is pending — the service is not connected).
 */
final class VendorMessages
{
    public static function status(ApplicationStatus $status): string
    {
        return match ($status) {
            ApplicationStatus::Draft => __('پیش‌نویس', 'tecteb-marketplace-core'),
            ApplicationStatus::Submitted => __('ارسال‌شده', 'tecteb-marketplace-core'),
            ApplicationStatus::InReview => __('در حال بررسی', 'tecteb-marketplace-core'),
            ApplicationStatus::ChangesRequested => __('نیازمند اصلاح', 'tecteb-marketplace-core'),
            ApplicationStatus::Approved => __('تأییدشده', 'tecteb-marketplace-core'),
            ApplicationStatus::Rejected => __('ردشده', 'tecteb-marketplace-core'),
            ApplicationStatus::Suspended => __('تعلیق‌شده', 'tecteb-marketplace-core'),
        };
    }

    /** success | warning | error | neutral — drives the chip colour. */
    public static function statusTone(ApplicationStatus $status): string
    {
        return match ($status) {
            ApplicationStatus::Approved => 'success',
            ApplicationStatus::ChangesRequested, ApplicationStatus::Submitted, ApplicationStatus::InReview => 'warning',
            ApplicationStatus::Rejected, ApplicationStatus::Suspended => 'error',
            ApplicationStatus::Draft => 'neutral',
        };
    }

    public static function statusHeadline(ApplicationStatus $status, bool $hasApplication): string
    {
        if (!$hasApplication) {
            return __('هنوز درخواستی ثبت نکرده‌اید.', 'tecteb-marketplace-core');
        }
        return match ($status) {
            ApplicationStatus::Draft => __('درخواست شما پیش‌نویس است و هنوز ارسال نشده.', 'tecteb-marketplace-core'),
            ApplicationStatus::Submitted => __('درخواست شما ارسال شد و در نوبت بررسی است.', 'tecteb-marketplace-core'),
            ApplicationStatus::InReview => __('درخواست شما در حال بررسی است.', 'tecteb-marketplace-core'),
            ApplicationStatus::ChangesRequested => __('مدیر بازارگاه اصلاحاتی خواسته است.', 'tecteb-marketplace-core'),
            ApplicationStatus::Approved => __('فروشگاه شما تأیید شده است.', 'tecteb-marketplace-core'),
            ApplicationStatus::Rejected => __('درخواست شما رد شد.', 'tecteb-marketplace-core'),
            ApplicationStatus::Suspended => __('فروشگاه شما موقتاً تعلیق شده است.', 'tecteb-marketplace-core'),
        };
    }

    /** @return array{title:string, detail:string, action:string} */
    public static function task(WorkspaceTask $task): array
    {
        $count = static fn (mixed $n): string => PersianDigits::toPersian((string) (int) $n);
        return match ($task->key) {
            'profile' => [
                'title' => __('اطلاعات فروشگاه و تماس', 'tecteb-marketplace-core'),
                'detail' => $task->state === TaskState::Done
                    ? __('کامل است. هر زمان خواستید می‌توانید ویرایشش کنید.', 'tecteb-marketplace-core')
                    : __('نام فروشگاه، نام حقوقی، ایمیل، موبایل و نشانی لازم است.', 'tecteb-marketplace-core'),
                'action' => $task->state === TaskState::Done
                    ? __('ویرایش اطلاعات', 'tecteb-marketplace-core')
                    : __('تکمیل اطلاعات', 'tecteb-marketplace-core'),
            ],
            'documents' => [
                'title' => __('مدارک', 'tecteb-marketplace-core'),
                'detail' => match ($task->state) {
                    TaskState::Waiting => __('مدیر بازارگاه هنوز تعیین نکرده چه مدارکی لازم است. تا آن زمان چیزی از شما خواسته نمی‌شود.', 'tecteb-marketplace-core'),
                    TaskState::Done => sprintf(__('%s مدرک بارگذاری شده است.', 'tecteb-marketplace-core'), $count($task->context['count'] ?? 0)),
                    TaskState::Todo => sprintf(__('%s مدرک اجباری باقی مانده است.', 'tecteb-marketplace-core'), $count($task->context['missing'] ?? 0)),
                },
                'action' => __('بارگذاری مدارک', 'tecteb-marketplace-core'),
            ],
            'documents_none' => [
                'title' => __('مدارک', 'tecteb-marketplace-core'),
                'detail' => __('برای این نوع فروشندگی مدرکی لازم نیست؛ مدیر بازارگاه این را ثبت کرده است.', 'tecteb-marketplace-core'),
                'action' => '',
            ],
            'submit' => [
                'title' => __('ارسال درخواست', 'tecteb-marketplace-core'),
                'detail' => __('همه‌چیز آماده است. با ارسال، درخواست در صف بررسی مدیر قرار می‌گیرد.', 'tecteb-marketplace-core'),
                'action' => __('ارسال برای بررسی', 'tecteb-marketplace-core'),
            ],
            'submit_blocked' => [
                'title' => __('ارسال درخواست', 'tecteb-marketplace-core'),
                'detail' => __('پس از تکمیل موارد بالا، دکمه ارسال همین‌جا فعال می‌شود.', 'tecteb-marketplace-core'),
                'action' => '',
            ],
            'fix_and_resubmit' => [
                'title' => __('اصلاح و ارسال دوباره', 'tecteb-marketplace-core'),
                'detail' => __('یادداشت مدیر را در بالای همین صفحه ببینید، اصلاح کنید و دوباره بفرستید.', 'tecteb-marketplace-core'),
                'action' => __('رفتن به فرم اصلاح', 'tecteb-marketplace-core'),
            ],
            'review' => [
                'title' => __('بررسی مدیر', 'tecteb-marketplace-core'),
                'detail' => __('درخواست شما در نوبت است. نتیجه در همین صفحه نمایش داده می‌شود.', 'tecteb-marketplace-core'),
                'action' => '',
            ],
            'approved' => [
                'title' => __('نتیجه بررسی', 'tecteb-marketplace-core'),
                'detail' => __('تأیید شد. اجازه فروش برای فروشگاه شما صادر شده است.', 'tecteb-marketplace-core'),
                'action' => '',
            ],
            'rejected' => [
                'title' => __('نتیجه بررسی', 'tecteb-marketplace-core'),
                'detail' => __('درخواست رد شد. توضیح مدیر در بالای صفحه آمده است.', 'tecteb-marketplace-core'),
                'action' => '',
            ],
            'suspended' => [
                'title' => __('وضعیت فروشگاه', 'tecteb-marketplace-core'),
                'detail' => __('فروشگاه موقتاً تعلیق شده است. برای پیگیری با مدیر بازارگاه تماس بگیرید.', 'tecteb-marketplace-core'),
                'action' => '',
            ],
            'mobile' => [
                'title' => __('شماره موبایل', 'tecteb-marketplace-core'),
                'detail' => __('شماره شما ثبت شده اما تأیید نشده است: سرویس پیامک هنوز به بازارگاه وصل نیست. هیچ دسترسی‌ای به تأیید این شماره گره نخورده است.', 'tecteb-marketplace-core'),
                'action' => '',
            ],
            default => ['title' => $task->key, 'detail' => '', 'action' => ''],
        };
    }

    public static function taskStateLabel(TaskState $state): string
    {
        return match ($state) {
            TaskState::Done => __('انجام شد', 'tecteb-marketplace-core'),
            TaskState::Todo => __('نیازمند اقدام', 'tecteb-marketplace-core'),
            TaskState::Waiting => __('در انتظار', 'tecteb-marketplace-core'),
        };
    }

    public static function taskStateTone(TaskState $state): string
    {
        return match ($state) {
            TaskState::Done => 'success',
            TaskState::Todo => 'warning',
            TaskState::Waiting => 'neutral',
        };
    }

    /** Turns a use-case result code into one sentence for the vendor. */
    public static function notice(string $code, array $context = []): string
    {
        $size = static fn (mixed $b): string => PersianDigits::toPersian((string) round(((int) $b) / 1048576, 1));
        return match ($code) {
            'draft_saved' => __('پیش‌نویس ذخیره شد.', 'tecteb-marketplace-core'),
            'submitted' => __('درخواست شما ارسال شد و در صف بررسی قرار گرفت.', 'tecteb-marketplace-core'),
            'document_uploaded' => __('مدرک بارگذاری شد.', 'tecteb-marketplace-core'),
            'requirements_undefined' => __('ارسال ممکن نیست: مدیر بازارگاه هنوز فهرست مدارک را تعریف نکرده است. پیش‌نویس شما محفوظ است.', 'tecteb-marketplace-core'),
            'missing_documents' => sprintf(__('این مدارک اجباری هنوز بارگذاری نشده‌اند: %s', 'tecteb-marketplace-core'), (string) ($context['types'] ?? '')),
            'incomplete_form' => __('چند فیلد اجباری خالی است. آن‌ها را پر کنید و دوباره ذخیره کنید.', 'tecteb-marketplace-core'),
            'not_editable' => __('این درخواست در وضعیتی است که دیگر ویرایش نمی‌شود.', 'tecteb-marketplace-core'),
            'no_application' => __('هنوز درخواستی ثبت نشده است.', 'tecteb-marketplace-core'),
            'not_submittable' => __('این درخواست در وضعیت فعلی ارسال‌شدنی نیست.', 'tecteb-marketplace-core'),
            'too_large' => sprintf(__('فایل بزرگ‌تر از حد مجاز است (حداکثر %s مگابایت).', 'tecteb-marketplace-core'), $size($context['max_bytes'] ?? 0)),
            'mime_not_allowed' => sprintf(__('نوع فایل پذیرفته نیست. فرمت‌های مجاز: %s', 'tecteb-marketplace-core'), (string) ($context['allowed'] ?? '')),
            'unknown_type' => __('این نوع مدرک دیگر خواسته نمی‌شود.', 'tecteb-marketplace-core'),
            'empty_file' => __('فایل خالی است.', 'tecteb-marketplace-core'),
            'no_file' => __('فایلی انتخاب نشده بود.', 'tecteb-marketplace-core'),
            'transfer_failed' => __('بارگذاری فایل ناتمام ماند. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'storage_failed' => __('ذخیره‌سازی انجام نشد. کمی بعد دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'forbidden' => __('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'),
            default => __('انجام شد.', 'tecteb-marketplace-core'),
        };
    }

    public static function isErrorNotice(string $code): bool
    {
        return !in_array($code, ['draft_saved', 'submitted', 'document_uploaded'], true);
    }
}
