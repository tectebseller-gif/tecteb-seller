<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;

/**
 * Every Persian sentence the product screens say.
 *
 * `notice()` returns null for a code it does not own, so the caller falls back
 * to the vendor area's vocabulary: each module keeps its own sentences, and a
 * shared code (forbidden, storage_failed) is still said the same way
 * everywhere.
 */
final class ProductMessages
{
    public static function status(ProductStatus $status): string
    {
        return match ($status) {
            ProductStatus::Draft => __('پیش‌نویس', 'tecteb-marketplace-core'),
            ProductStatus::Submitted => __('در انتظار بررسی', 'tecteb-marketplace-core'),
            ProductStatus::ChangesRequested => __('نیازمند اصلاح', 'tecteb-marketplace-core'),
            ProductStatus::Published => __('منتشرشده', 'tecteb-marketplace-core'),
            ProductStatus::Suspended => __('تعلیق‌شده', 'tecteb-marketplace-core'),
            ProductStatus::Archived => __('بایگانی', 'tecteb-marketplace-core'),
        };
    }

    /** success | warning | error | neutral */
    public static function statusTone(ProductStatus $status): string
    {
        return match ($status) {
            ProductStatus::Published => 'success',
            ProductStatus::Submitted, ProductStatus::ChangesRequested => 'warning',
            ProductStatus::Suspended => 'error',
            ProductStatus::Draft, ProductStatus::Archived => 'neutral',
        };
    }

    public static function type(string $type): string
    {
        return match ($type) {
            ProductType::SIMPLE => __('ساده', 'tecteb-marketplace-core'),
            ProductType::VARIABLE => __('متغیر', 'tecteb-marketplace-core'),
            default => $type,
        };
    }

    /** @return array<string,string> value => label, for a <select> */
    public static function types(): array
    {
        $labels = [];
        foreach (ProductType::all() as $type) {
            $labels[$type] = self::type($type);
        }
        return $labels;
    }

    public static function fieldType(SpecFieldType $type): string
    {
        return match ($type) {
            SpecFieldType::Text => __('متن', 'tecteb-marketplace-core'),
            SpecFieldType::Number => __('عدد', 'tecteb-marketplace-core'),
            SpecFieldType::Boolean => __('بله / خیر', 'tecteb-marketplace-core'),
            SpecFieldType::Choice => __('انتخاب از فهرست', 'tecteb-marketplace-core'),
            SpecFieldType::Date => __('تاریخ', 'tecteb-marketplace-core'),
        };
    }

    /** @return array<string,string> */
    public static function fieldTypes(): array
    {
        $labels = [];
        foreach (SpecFieldType::cases() as $case) {
            $labels[$case->value] = self::fieldType($case);
        }
        return $labels;
    }

    /** The names the form and the error messages use for the same field. */
    public static function field(string $key): string
    {
        return match ($key) {
            'title' => __('عنوان', 'tecteb-marketplace-core'),
            'type' => __('نوع محصول', 'tecteb-marketplace-core'),
            'category', 'categoryKey' => __('دسته', 'tecteb-marketplace-core'),
            'price', 'priceMinor' => __('قیمت', 'tecteb-marketplace-core'),
            'image' => __('تصویر', 'tecteb-marketplace-core'),
            'brand' => __('برند', 'tecteb-marketplace-core'),
            'shortDescription' => __('توضیح کوتاه', 'tecteb-marketplace-core'),
            'salePriceMinor' => __('قیمت با تخفیف', 'tecteb-marketplace-core'),
            'saleFrom' => __('شروع تخفیف', 'tecteb-marketplace-core'),
            'saleTo' => __('پایان تخفیف', 'tecteb-marketplace-core'),
            'weightGrams' => __('وزن (گرم)', 'tecteb-marketplace-core'),
            'dimensions' => __('ابعاد', 'tecteb-marketplace-core'),
            'taxClass' => __('گروه مالیاتی', 'tecteb-marketplace-core'),
            'sku' => __('کد SKU', 'tecteb-marketplace-core'),
            'stock' => __('موجودی', 'tecteb-marketplace-core'),
            'minPurchase' => __('حداقل خرید', 'tecteb-marketplace-core'),
            'maxPurchase' => __('حداکثر خرید', 'tecteb-marketplace-core'),
            default => $key,
        };
    }

    /**
     * @param array<string,scalar|null> $context
     * @return string|null null when this code belongs to another module
     */
    public static function notice(string $code, array $context = []): ?string
    {
        $fa = static fn (mixed $v): string => PersianDigits::toPersian((string) $v);
        $fields = static function (array $context): string {
            $raw = (string) ($context['fields'] ?? '');
            if ($raw === '') {
                return '';
            }
            $names = array_map(
                static fn (string $key): string => ProductMessages::field(trim($key)),
                explode('، ', $raw)
            );
            return implode('، ', $names);
        };

        return match ($code) {
            'product_created' => __('محصول به‌عنوان پیش‌نویس ساخته شد. مرحله‌های بعد را کامل کنید.', 'tecteb-marketplace-core'),
            'product_saved' => __('تغییرات محصول ذخیره شد.', 'tecteb-marketplace-core'),
            'inventory_saved' => __('موجودی و مشخصات فروش همین حالا ذخیره شد.', 'tecteb-marketplace-core'),
            'product_submitted' => __('محصول برای بررسی مدیر فرستاده شد. تا تعیین تکلیف، نسخه فعلی تغییر نمی‌کند.', 'tecteb-marketplace-core'),
            'product_published' => __('محصول منتشر شد؛ فروشگاه شما مجوز انتشار مستقیم دارد.', 'tecteb-marketplace-core'),
            'revision_requested' => __('تغییرهای حساس به‌صورت «نسخه پیشنهادی» ثبت شد. نسخه منتشرشده تا تأیید مدیر دست‌نخورده می‌ماند و موجودی همین حالا اعمال شد.', 'tecteb-marketplace-core'),
            'product_archived_ok' => __('محصول بایگانی شد. سابقه و سفارش‌های قبلی آن پاک نشده است.', 'tecteb-marketplace-core'),
            'product_restored' => __('محصول از بایگانی به پیش‌نویس بازگشت.', 'tecteb-marketplace-core'),

            // A refused row is named, not counted. «۴ نرفت» sends somebody
            // hunting through forty rows; «۴ نرفت: ۱۲، ۱۹، ۲۳، ۴۱» does not.
            'bulk_done' => (int) ($context['failed'] ?? 0) === 0
                ? sprintf(
                    /* translators: %s: how many rows succeeded */
                    __('اقدام گروهی روی %s مورد انجام شد.', 'tecteb-marketplace-core'),
                    $fa((string) ($context['ok'] ?? 0))
                )
                : sprintf(
                    /* translators: 1: succeeded, 2: refused, 3: the refused ids with their reasons */
                    __('%1$s مورد انجام شد و %2$s مورد انجام نشد: %3$s', 'tecteb-marketplace-core'),
                    $fa((string) ($context['ok'] ?? 0)),
                    $fa((string) ($context['failed'] ?? 0)),
                    self::refusedList(is_array($context['refused'] ?? null) ? $context['refused'] : [])
                ),
            'nothing_selected' => __('هیچ موردی انتخاب نشده بود.', 'tecteb-marketplace-core'),
            'unknown_bulk_action' => __('اقدام گروهی انتخاب نشده بود.', 'tecteb-marketplace-core'),
            'bulk_too_large' => sprintf(
                /* translators: 1: how many were selected, 2: the limit */
                __('%1$s مورد انتخاب شده و این بیش از سقف %2$s موردِ یک درخواست است. کمتر انتخاب کنید؛ نیمه‌کاره‌ماندن وسط کار بدتر از رد کردنِ درخواست است.', 'tecteb-marketplace-core'),
                $fa((string) ($context['selected'] ?? 0)),
                $fa((string) ($context['limit'] ?? 0))
            ),
            // The one message whose job is to stop somebody losing work.
            'stale_revision' => __('این محصول از وقتی این صفحه باز شده تغییر کرده است — احتمالاً یکی دیگر از فروشگاه شما ذخیره‌اش کرده. برای اینکه کار او پاک نشود، این ذخیره انجام نشد. مقدارهای شما همین‌جا مانده‌اند: صفحه را در یک تب دیگر باز کنید، تفاوت را ببینید و بعد تصمیم بگیرید.', 'tecteb-marketplace-core'),
            'product_reviewed' => __('تصمیم شما ثبت شد.', 'tecteb-marketplace-core'),
            'revision_approved' => __('نسخه پیشنهادی تأیید و روی محصول اعمال شد.', 'tecteb-marketplace-core'),
            'revision_rejected' => __('نسخه پیشنهادی رد شد. نسخه منتشرشده همچنان روی سایت است.', 'tecteb-marketplace-core'),
            'direct_publish_granted' => __('مجوز انتشار مستقیم برای این فروشنده صادر شد.', 'tecteb-marketplace-core'),
            'direct_publish_revoked' => __('مجوز انتشار مستقیم این فروشنده برداشته شد؛ محصول‌های بعدی از مسیر بررسی می‌گذرند.', 'tecteb-marketplace-core'),

            'incomplete_product' => $fields($context) !== ''
                ? sprintf(__('این فیلدها هنوز کامل نیستند: %s', 'tecteb-marketplace-core'), $fields($context))
                : __('چند فیلد اجباری هنوز کامل نیست.', 'tecteb-marketplace-core'),
            'missing_image' => __('دست‌کم یک تصویر لازم است؛ تصویر اصلی روی کارت محصول نشان داده می‌شود.', 'tecteb-marketplace-core'),
            'missing_specs' => ($context['fields'] ?? '') !== ''
                ? sprintf(__('این مشخصه‌های دسته اجباری‌اند و خالی‌اند: %s', 'tecteb-marketplace-core'), (string) $context['fields'])
                : __('مشخصه‌های اجباری این دسته هنوز پر نشده‌اند.', 'tecteb-marketplace-core'),
            'invalid_specs' => ($context['fields'] ?? '') !== ''
                ? sprintf(__('مقدار این مشخصه‌ها با نوع فیلد نمی‌خواند: %s', 'tecteb-marketplace-core'), (string) $context['fields'])
                : __('مقدار یکی از مشخصه‌ها با نوع فیلد نمی‌خواند.', 'tecteb-marketplace-core'),
            'bad_type' => __('نوع محصول باید ساده یا متغیر باشد. محصول خارجی و گروهی در این نسخه پشتیبانی نمی‌شود.', 'tecteb-marketplace-core'),
            'bad_price' => __('قیمت نمی‌تواند منفی باشد.', 'tecteb-marketplace-core'),
            'bad_sale_price' => __('قیمت تخفیف‌خورده نباید از قیمت اصلی بیشتر یا منفی باشد.', 'tecteb-marketplace-core'),
            'bad_sale_window' => __('تاریخ پایان تخفیف نباید پیش از تاریخ شروع باشد.', 'tecteb-marketplace-core'),
            'bad_stock' => __('موجودی نمی‌تواند منفی باشد. موجودی صفر یعنی «ناموجود» و فروش را متوقف می‌کند.', 'tecteb-marketplace-core'),
            'bad_quantity' => __('حداقل خرید دست‌کم ۱ است و حداکثر خرید نباید از آن کمتر باشد.', 'tecteb-marketplace-core'),
            'sku_taken' => ($context['sku'] ?? '') !== ''
                ? sprintf(__('کد SKU «%s» روی محصول دیگری از همین فروشگاه ثبت شده است.', 'tecteb-marketplace-core'), (string) $context['sku'])
                : __('این کد SKU روی محصول دیگری از همین فروشگاه ثبت شده است.', 'tecteb-marketplace-core'),
            'in_review' => __('این محصول در صف بررسی مدیر است و تا تعیین تکلیف ویرایش نمی‌شود. موجودی همچنان قابل تغییر است.', 'tecteb-marketplace-core'),
            'product_archived' => __('این محصول بایگانی است. برای ویرایش، ابتدا آن را به پیش‌نویس برگردانید.', 'tecteb-marketplace-core'),
            'not_submittable' => __('این محصول در وضعیت فعلی ارسال‌شدنی نیست.', 'tecteb-marketplace-core'),
            'already_decided' => __('برای این نسخه پیشنهادی قبلاً تصمیم گرفته شده است.', 'tecteb-marketplace-core'),
            'invalid_transition' => __('این تغییر وضعیت مجاز نیست.', 'tecteb-marketplace-core'),
            'note_required' => __('برای اصلاح و رد، نوشتن دلیل اجباری است.', 'tecteb-marketplace-core'),

            'image_uploaded' => __('تصویر افزوده شد. برای ثبت روی محصول، فرم را ذخیره کنید.', 'tecteb-marketplace-core'),
            'image_too_large' => sprintf(
                __('تصویر بزرگ‌تر از حد مجاز است (حداکثر %s مگابایت).', 'tecteb-marketplace-core'),
                $fa(round(\Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy::MAX_BYTES / 1048576, 1))
            ),
            'image_mime_not_allowed' => __('فقط تصویر JPEG، PNG و WebP پذیرفته می‌شود.', 'tecteb-marketplace-core'),

            // ---- variable products ---------------------------------------
            'attribute_saved' => __('ویژگی ذخیره شد. حالا برای هر ترکیب، قیمت و موجودی تعیین کنید.', 'tecteb-marketplace-core'),
            'attribute_deleted' => __('ویژگی و ترکیب‌های وابسته‌اش حذف شدند.', 'tecteb-marketplace-core'),
            'variation_added' => __('ترکیب تازه اضافه شد.', 'tecteb-marketplace-core'),
            'variation_saved' => __('ترکیب ذخیره شد.', 'tecteb-marketplace-core'),
            'variation_deleted' => __('ترکیب حذف شد.', 'tecteb-marketplace-core'),
            'not_variable' => __('این کار فقط برای محصول «متغیر» معنا دارد.', 'tecteb-marketplace-core'),
            'incomplete_attribute' => __('کلید و عنوان ویژگی هر دو لازم‌اند.', 'tecteb-marketplace-core'),
            'attribute_needs_options' => __('ویژگی بدون گزینه ساخته نمی‌شود؛ دست‌کم یک گزینه بنویسید.', 'tecteb-marketplace-core'),
            'attributes_first' => __('اول ویژگی‌ها (مثل اندازه یا رنگ) را تعریف کنید، بعد ترکیب‌ها را.', 'tecteb-marketplace-core'),
            'incomplete_combination' => ($context['fields'] ?? '') !== ''
                ? sprintf(__('برای این ویژگی‌ها گزینه‌ای انتخاب نشده است: %s', 'tecteb-marketplace-core'), (string) $context['fields'])
                : __('برای همه ویژگی‌ها باید یک گزینه انتخاب شود.', 'tecteb-marketplace-core'),
            'unknown_option' => sprintf(
                __('گزینه‌ای که انتخاب شده در فهرست ویژگی «%s» نیست.', 'tecteb-marketplace-core'),
                (string) ($context['attribute'] ?? '')
            ),
            'unknown_attribute' => __('این ویژگی برای این محصول تعریف نشده است.', 'tecteb-marketplace-core'),
            'too_many_attributes' => sprintf(__('سقف ویژگی‌های یک محصول %s است.', 'tecteb-marketplace-core'), $fa($context['limit'] ?? '')),
            'too_many_variations' => sprintf(__('سقف ترکیب‌های یک محصول %s است.', 'tecteb-marketplace-core'), $fa($context['limit'] ?? '')),
            'variable_needs_attributes' => __('محصول متغیر بدون ویژگی آمادهٔ فروش نیست: دست‌کم یک ویژگی با گزینه‌هایش تعریف کنید.', 'tecteb-marketplace-core'),
            'variable_needs_variations' => __('محصول متغیر بدون ترکیب آمادهٔ فروش نیست: دست‌کم یک ترکیب با قیمت بسازید.', 'tecteb-marketplace-core'),
            'variable_needs_priced_variation' => __('هیچ ترکیب فعالی با قیمت معتبر وجود ندارد.', 'tecteb-marketplace-core'),
            'variation_without_price' => sprintf(
                __('%s ترکیب فعال هنوز قیمت ندارد. تا وقتی قیمت هر ترکیب مشخص نشود، این محصول فروختنی نیست.', 'tecteb-marketplace-core'),
                $fa($context['count'] ?? '')
            ),

            // ---- category templates --------------------------------------
            'template_created' => __('الگوی مشخصات ساخته شد. حالا فیلدهای این دسته را اضافه کنید.', 'tecteb-marketplace-core'),
            'template_saved' => __('الگو ذخیره شد.', 'tecteb-marketplace-core'),
            'template_exists' => __('برای این دسته قبلاً الگویی ساخته شده است.', 'tecteb-marketplace-core'),
            'incomplete_template' => __('کلید دسته و عنوان الگو هر دو لازم‌اند.', 'tecteb-marketplace-core'),
            'field_added' => sprintf(
                __('فیلد اضافه شد. نسخه الگو به %s رسید؛ محصول‌های قبلی با نسخه خودشان معتبر می‌مانند.', 'tecteb-marketplace-core'),
                $fa($context['schema_version'] ?? '')
            ),
            'field_saved' => ($context['required'] ?? false)
                ? sprintf(
                    __('فیلد ذخیره شد و اکنون اجباری است. نسخه الگو %s شد؛ محصول‌های قبلی باطل نمی‌شوند و این قاعده از ویرایش بعدی آن‌ها خواسته می‌شود.', 'tecteb-marketplace-core'),
                    $fa($context['schema_version'] ?? '')
                )
                : sprintf(__('فیلد ذخیره شد. نسخه الگو %s شد.', 'tecteb-marketplace-core'), $fa($context['schema_version'] ?? '')),
            'field_deprecated' => __('این فیلد دیگر از فروشنده پرسیده نمی‌شود. پاسخ‌های ثبت‌شده حذف نشدند.', 'tecteb-marketplace-core'),
            'field_restored' => __('این فیلد دوباره پرسیده می‌شود.', 'tecteb-marketplace-core'),
            'incomplete_field' => __('کلید و برچسب فیلد هر دو لازم‌اند.', 'tecteb-marketplace-core'),
            'bad_field_type' => __('نوع فیلد معتبر نیست.', 'tecteb-marketplace-core'),
            'field_key_taken' => __('این کلید در همین الگو استفاده شده است. کلید هرگز تغییر نمی‌کند تا پاسخ‌های قبلی گم نشوند.', 'tecteb-marketplace-core'),
            'choice_needs_options' => __('فیلد «انتخاب از فهرست» بدون گزینه ساخته نمی‌شود.', 'tecteb-marketplace-core'),
            'too_many_fields' => sprintf(__('سقف فیلدهای یک الگو %s است.', 'tecteb-marketplace-core'), $fa($context['limit'] ?? '')),

            'seo_saved' => __('سئوی محصول ذخیره شد و روی صفحهٔ عمومی اعمال شد.', 'tecteb-marketplace-core'),
            'projected' => __('محصول در فروشگاه به‌روزرسانی شد.', 'tecteb-marketplace-core'),
            'withdrawn' => __('محصول از ویترین فروشگاه برداشته شد؛ حذف نشد.', 'tecteb-marketplace-core'),
            'woocommerce_missing' => __('WooCommerce فعال نیست، پس این محصول صفحهٔ عمومی ندارد. تصمیم بازارگاه ثبت شده و با فعال‌شدن WooCommerce نگاشت انجام می‌شود.', 'tecteb-marketplace-core'),

            // ---- CSV ------------------------------------------------------
            'csv_exported' => sprintf(__('%s ردیف در فایل CSV نوشته شد.', 'tecteb-marketplace-core'), $fa($context['rows'] ?? 0)),
            'csv_previewed' => __('این پیش‌نمایش است و هنوز چیزی ذخیره نشده. ردیف‌ها را ببینید و سپس «اعمال» را بزنید.', 'tecteb-marketplace-core'),
            'csv_imported' => sprintf(
                __('ورود CSV انجام شد: %1$s ساخته، %2$s به‌روزرسانی، %3$s رد شد.', 'tecteb-marketplace-core'),
                $fa($context['created'] ?? 0),
                $fa($context['updated'] ?? 0),
                $fa($context['skipped'] ?? 0)
            ),
            'csv_empty' => __('فایلی خوانده نشد یا خالی بود.', 'tecteb-marketplace-core'),
            'csv_missing_columns' => __('سطر عنوان فایل باید دست‌کم ستون title داشته باشد.', 'tecteb-marketplace-core'),
            'csv_too_many_rows' => __('تعداد ردیف‌ها از سقف یک بار ورود بیشتر است. فایل را تکه‌تکه کنید.', 'tecteb-marketplace-core'),
            'csv_row_ok' => __('آماده اعمال', 'tecteb-marketplace-core'),
            'csv_row_needs_title' => __('این ردیف نه SKU شناخته‌شده دارد و نه عنوان.', 'tecteb-marketplace-core'),
            default => null,
        };
    }

    public static function csvAction(string $action): string
    {
        return match ($action) {
            'create' => __('ساخت محصول تازه', 'tecteb-marketplace-core'),
            'update' => __('به‌روزرسانی', 'tecteb-marketplace-core'),
            'revision' => __('نسخه پیشنهادی برای محصول منتشرشده', 'tecteb-marketplace-core'),
            default => __('رد شد', 'tecteb-marketplace-core'),
        };
    }

    /** @return list<string> codes this module considers a success */
    public static function successCodes(): array
    {
        return [
            'product_created', 'product_saved', 'inventory_saved', 'product_submitted',
            'product_published', 'revision_requested', 'product_archived_ok', 'product_restored',
            'product_reviewed', 'revision_approved', 'revision_rejected',
            'direct_publish_granted', 'direct_publish_revoked',
            'template_created', 'template_saved', 'field_added', 'field_saved',
            'field_deprecated', 'field_restored',
            'csv_exported', 'csv_previewed', 'csv_imported', 'csv_row_ok', 'image_uploaded',
            'attribute_saved', 'attribute_deleted', 'variation_added', 'variation_saved', 'variation_deleted',
            'seo_saved', 'projected', 'withdrawn',
            // A batch that RAN is a success, even when some rows were refused:
            // the refusals are in the message, and painting the whole thing red
            // would hide the thirty-six that went.
            'bulk_done',
        ];
    }

    /**
     * «۱۲ (قیمت ندارد)، ۱۹ (در حال بررسی)» — id and reason, in the vendor's
     * words, capped so one bad import cannot produce a paragraph.
     *
     * @param array<string,string> $refused product id => refusal code
     */
    private static function refusedList(array $refused): string
    {
        $parts = [];
        foreach (array_slice($refused, 0, 10, true) as $productId => $code) {
            $parts[] = sprintf(
                '%s (%s)',
                PersianDigits::toPersian((string) $productId),
                self::notice((string) $code) ?? (string) $code
            );
        }
        if (count($refused) > 10) {
            $parts[] = sprintf(
                /* translators: %s: how many more rows were refused */
                __('و %s مورد دیگر', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) (count($refused) - 10))
            );
        }
        return implode('، ', $parts);
    }

    public static function isErrorNotice(string $code): bool
    {
        return !in_array($code, self::successCodes(), true);
    }
}
