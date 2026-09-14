<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Vendor\Application\TaskState;
use Tecteb\Marketplace\Modules\Vendor\Application\WorkspaceTask;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequest;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequestStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

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

    /**
     * Turns a use-case result code into one sentence for the vendor.
     *
     * Three of these sentences quote a value the server computed — a size
     * limit, an allowed format list, the documents still missing. Each has a
     * second shape for when that value did not survive the redirect: the
     * page still says what went wrong and where to look, because a sentence
     * that prints «حداکثر ۰ مگابایت» is worse than one that prints no number
     * at all.
     */
    public static function notice(string $code, array $context = []): string
    {
        $size = static fn (mixed $b): string => PersianDigits::toPersian((string) round(((int) $b) / 1048576, 1));
        return match ($code) {
            'draft_saved' => __('پیش‌نویس ذخیره شد.', 'tecteb-marketplace-core'),
            'submitted' => __('درخواست شما ارسال شد و در صف بررسی قرار گرفت.', 'tecteb-marketplace-core'),
            'document_uploaded' => __('مدرک بارگذاری شد.', 'tecteb-marketplace-core'),
            'requirements_undefined' => __('ارسال ممکن نیست: مدیر بازارگاه هنوز فهرست مدارک را تعریف نکرده است. پیش‌نویس شما محفوظ است.', 'tecteb-marketplace-core'),
            'missing_documents' => ($context['types'] ?? '') !== ''
                ? sprintf(__('این مدارک اجباری هنوز بارگذاری نشده‌اند: %s', 'tecteb-marketplace-core'), (string) $context['types'])
                : __('هنوز همه مدارک اجباری بارگذاری نشده‌اند. مدارکی که نشان «اجباری» دارند در همین صفحه مشخص‌اند.', 'tecteb-marketplace-core'),
            'incomplete_form' => __('چند فیلد اجباری خالی است. آن‌ها را پر کنید و دوباره ذخیره کنید.', 'tecteb-marketplace-core'),
            'not_editable' => __('این درخواست در وضعیتی است که دیگر ویرایش نمی‌شود.', 'tecteb-marketplace-core'),
            'no_application' => __('هنوز درخواستی ثبت نشده است.', 'tecteb-marketplace-core'),
            'not_submittable' => __('این درخواست در وضعیت فعلی ارسال‌شدنی نیست.', 'tecteb-marketplace-core'),
            'too_large' => isset($context['max_bytes']) && (int) $context['max_bytes'] > 0
                ? sprintf(__('فایل بزرگ‌تر از حد مجاز است (حداکثر %s مگابایت).', 'tecteb-marketplace-core'), $size($context['max_bytes']))
                : __('فایل بزرگ‌تر از حد مجاز است. سقف حجم روی کارت همین مدرک نوشته شده است.', 'tecteb-marketplace-core'),
            'mime_not_allowed' => ($context['allowed'] ?? '') !== ''
                ? sprintf(__('نوع فایل پذیرفته نیست. فرمت‌های مجاز: %s', 'tecteb-marketplace-core'), (string) $context['allowed'])
                : __('نوع فایل پذیرفته نیست. فرمت‌های مجاز روی کارت همین مدرک نوشته شده‌اند.', 'tecteb-marketplace-core'),
            'unknown_type' => __('این نوع مدرک دیگر خواسته نمی‌شود.', 'tecteb-marketplace-core'),
            'empty_file' => __('فایل خالی است.', 'tecteb-marketplace-core'),
            'no_file' => __('فایلی انتخاب نشده بود.', 'tecteb-marketplace-core'),
            'transfer_failed' => __('بارگذاری فایل ناتمام ماند. دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'storage_failed' => __('ذخیره‌سازی انجام نشد. کمی بعد دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'storage_unavailable' => __('بارگذاری مدرک فعلاً ممکن نیست: محل امنِ نگهداری مدارک روی این سایت آماده نیست. مدیر سایت باید آن را تنظیم کند؛ تا آن زمان هیچ مدرکی پذیرفته نمی‌شود.', 'tecteb-marketplace-core'),
            'forbidden' => __('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'),
            // ---- store settings ------------------------------------------
            'store_saved' => __('تنظیمات فروشگاه ذخیره شد.', 'tecteb-marketplace-core'),
            'store_saved_with_problems' => sprintf(
                __('تنظیمات ذخیره شد، اما این موارد پذیرفته نشدند: %s', 'tecteb-marketplace-core'),
                (string) ($context['problems'] ?? '')
            ),
            'change_requested' => __('درخواست شما ثبت شد و در صف بررسی مدیر است.', 'tecteb-marketplace-core'),
            'bank_change_requested' => ($context['otp_available'] ?? 'no') === 'yes'
                ? __('درخواست تغییر حساب ثبت شد و پس از تأیید پیامکی و تأیید مدیر اعمال می‌شود.', 'tecteb-marketplace-core')
                : __('درخواست تغییر حساب ثبت شد و در انتظار تأیید مدیر است. تأیید پیامکی در این نسخه انجام نمی‌شود و تا تعیین تکلیف، تسویه متوقف است.', 'tecteb-marketplace-core'),
            'change_already_pending' => __('یک درخواست برای همین مورد در انتظار بررسی است؛ تا تعیین تکلیف آن، درخواست تازه ثبت نمی‌شود.', 'tecteb-marketplace-core'),
            'no_change' => __('تغییری نسبت به مقدار فعلی وجود ندارد.', 'tecteb-marketplace-core'),
            'bad_iban' => __('شبا معتبر نیست. باید با IR شروع شود و ۲۴ رقم داشته باشد.', 'tecteb-marketplace-core'),
            // ---- staff ----------------------------------------------------
            'staff_invited' => __('دعوت ساخته شد. لینک یک‌بارمصرف را در همین صفحه ببینید و برای همکارتان بفرستید.', 'tecteb-marketplace-core'),
            'staff_role_changed' => __('نقش به‌روزرسانی شد.', 'tecteb-marketplace-core'),
            'staff_suspended' => __('دسترسی این همکار همین حالا قطع شد. سابقه فعالیت او پاک نشده است.', 'tecteb-marketplace-core'),
            'staff_reinstated' => __('دسترسی این همکار بازگردانده شد.', 'tecteb-marketplace-core'),
            'staff_activated' => __('حساب شما فعال شد. حالا می‌توانید با نام کاربری و رمز تازه وارد شوید.', 'tecteb-marketplace-core'),
            'staff_limit' => sprintf(
                __('به سقف %s نفر رسیده‌اید. افزایش سقف با مدیر بازارگاه است.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['limit'] ?? ''))
            ),
            'incomplete_staff' => __('همه فیلدهای پرسنل اجباری‌اند: نام، نام خانوادگی، نام کاربری، ایمیل و موبایل.', 'tecteb-marketplace-core'),
            'bad_username' => __('نام کاربری فقط حروف کوچک انگلیسی، عدد، نقطه و خط تیره می‌پذیرد و دست‌کم سه نویسه است.', 'tecteb-marketplace-core'),
            'bad_email' => __('ایمیل معتبر نیست.', 'tecteb-marketplace-core'),
            'username_taken' => __('این نام کاربری قبلاً استفاده شده است.', 'tecteb-marketplace-core'),
            'email_taken' => __('این ایمیل به حساب دیگری تعلق دارد.', 'tecteb-marketplace-core'),
            'already_staff' => __('این شخص در فروشگاه دیگری پرسنل است. هر نفر فقط به یک فروشگاه وصل می‌شود.', 'tecteb-marketplace-core'),
            'no_permissions' => __('نقشی بدون هیچ دسترسی ساخته نمی‌شود.', 'tecteb-marketplace-core'),
            'user_not_created' => __('ساخت حساب کاربری انجام نشد. کمی بعد دوباره تلاش کنید.', 'tecteb-marketplace-core'),
            'invite_invalid' => __('این لینک دعوت معتبر نیست یا منقضی شده است.', 'tecteb-marketplace-core'),
            'password_too_short' => sprintf(
                __('رمز عبور باید دست‌کم %s نویسه باشد.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['minimum'] ?? ''))
            ),
            'not_found' => __('این مورد پیدا نشد.', 'tecteb-marketplace-core'),
            default => __('انجام شد.', 'tecteb-marketplace-core'),
        };
    }

    public static function staffStatus(StaffStatus $status): string
    {
        return match ($status) {
            StaffStatus::Invited => __('دعوت‌شده، هنوز فعال نشده', 'tecteb-marketplace-core'),
            StaffStatus::Active => __('فعال', 'tecteb-marketplace-core'),
            StaffStatus::Suspended => __('تعلیق‌شده', 'tecteb-marketplace-core'),
        };
    }

    public static function staffPreset(StaffRolePreset $preset): string
    {
        return match ($preset) {
            StaffRolePreset::StoreManager => __('مدیر فروشگاه', 'tecteb-marketplace-core'),
            StaffRolePreset::ProductAndInventory => __('محصول و موجودی', 'tecteb-marketplace-core'),
            StaffRolePreset::OrderAndShipping => __('سفارش و ارسال', 'tecteb-marketplace-core'),
            StaffRolePreset::Accountant => __('حسابداری', 'tecteb-marketplace-core'),
            StaffRolePreset::CustomerSupport => __('پشتیبانی مشتری', 'tecteb-marketplace-core'),
            StaffRolePreset::Custom => __('سفارشی', 'tecteb-marketplace-core'),
        };
    }

    public static function staffArea(StaffArea $area): string
    {
        return match ($area) {
            StaffArea::Product => __('محصول', 'tecteb-marketplace-core'),
            StaffArea::Inventory => __('موجودی', 'tecteb-marketplace-core'),
            StaffArea::Order => __('سفارش', 'tecteb-marketplace-core'),
            StaffArea::Report => __('گزارش', 'tecteb-marketplace-core'),
            StaffArea::Finance => __('مالی', 'tecteb-marketplace-core'),
        };
    }

    public static function staffLevel(StaffLevel $level): string
    {
        return match ($level) {
            StaffLevel::None => __('بدون دسترسی', 'tecteb-marketplace-core'),
            StaffLevel::View => __('مشاهده', 'tecteb-marketplace-core'),
            StaffLevel::Respond => __('پاسخ و مشاهده', 'tecteb-marketplace-core'),
            StaffLevel::Edit => __('ویرایش', 'tecteb-marketplace-core'),
        };
    }

    public static function changeField(string $field): string
    {
        return match ($field) {
            ChangeRequest::FIELD_STORE_NAME => __('نام فروشگاه', 'tecteb-marketplace-core'),
            ChangeRequest::FIELD_BANK => __('حساب بانکی', 'tecteb-marketplace-core'),
            default => $field,
        };
    }

    public static function changeStatus(ChangeRequestStatus $status): string
    {
        return match ($status) {
            ChangeRequestStatus::Pending => __('در انتظار تأیید مدیر', 'tecteb-marketplace-core'),
            ChangeRequestStatus::Approved => __('تأیید شد', 'tecteb-marketplace-core'),
            ChangeRequestStatus::Rejected => __('رد شد', 'tecteb-marketplace-core'),
        };
    }

    public static function isErrorNotice(string $code): bool
    {
        return !in_array($code, [
            'draft_saved', 'submitted', 'document_uploaded',
            'store_saved', 'change_requested', 'bank_change_requested',
            'staff_invited', 'staff_role_changed', 'staff_suspended', 'staff_reinstated', 'staff_activated',
        ], true);
    }
}
