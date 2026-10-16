<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Finance\Presentation\SupplierPaymentMessages;

/**
 * «پرداخت به تأمین‌کننده» — writing down a payment that has already happened.
 *
 * **This screen is not the settlement queue and must not be read as one.** The
 * settlement queue is a vendor asking for money the marketplace owes them and
 * a manager deciding; this is Tecteb recording a purchase it has already paid
 * for. The owner's §4 asked for the two to be kept apart by name as well as by
 * table — «نام‌های مشابه نباید باعث شود چرخهٔ برداشت فروشنده برای خرید نقدی
 * از تأمین‌کننده استفاده شود» — so the two screens say which they are, in the
 * first sentence, and neither offers the other's buttons.
 *
 * **There is no «pay» button here, and there will not be one.** Every control
 * on this page writes a record. The money left the bank before anybody opened
 * it.
 */
final class SupplierPaymentsPage
{
    public const SLUG = 'tmc-supplier-payments';
    public const CAPABILITY = Capabilities::REVIEW_WITHDRAWALS;
    private const NONCE = 'tmc_supplier_payments';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('پرداخت به تأمین‌کننده', 'tecteb-marketplace-core');
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
            echo Components::notice(str_starts_with($notice, 'ok:') ? 'success' : 'error', substr($notice, strpos($notice, ':') + 1));
        }

        echo Components::notice('info', __('این صفحه پرداختی را ثبت می‌کند که قبلاً انجام شده است. هیچ انتقال وجهی از اینجا انجام نمی‌شود و این صفحه چرخهٔ «تسویه و برداشت» فروشنده نیست؛ آن یکی درخواست فروشنده است و این یکی خرید تک‌طب.', 'tecteb-marketplace-core'));

        $this->form();
        $this->awaiting($fa);
        echo Components::shellClose();
    }

    /** The form: supplier, items, amount, date, tracking number, note. */
    private function form(): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('ثبت پرداخت انجام‌شده', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('مبلغ را خودتان می‌نویسید؛ بازارگاه آن را از سهم محاسبه‌شده نمی‌سازد. اگر مبلغ پرداختی با سهم ثبت‌شدهٔ همان اقلام فرق داشته باشد، پرداخت ثبت می‌شود ولی در دفترکل ثبت نمی‌شود و در فهرست پایین همین صفحه می‌آید.', 'tecteb-marketplace-core') . '</p>'
            . '<form method="post">'
            . wp_nonce_field(self::NONCE, 'tmc_supplier_nonce', true, false)
            . '<input type="hidden" name="supplier_action" value="record">'
            // The form's own token: the SAME submission can never write twice,
            // and two deliberate identical payments on one day still can
            // («به‌روزرسانی صفحه … نباید پرداخت دوم بسازد»).
            . '<input type="hidden" name="form_token" value="' . esc_attr(wp_generate_uuid4()) . '">'
            . $this->field('supplier_id', __('شناسهٔ کاربری تأمین‌کننده', 'tecteb-marketplace-core'), '', 'numeric')
            . $this->field('order_items', __('شناسهٔ قلم‌های سفارش (با کاما)', 'tecteb-marketplace-core'), __('مثلاً: ۱۲،۱۳،۱۴ — هر قلم فقط یک بار پرداخت می‌شود.', 'tecteb-marketplace-core'))
            . $this->field('amount', __('مبلغ پرداخت‌شده', 'tecteb-marketplace-core'), __('به واحد پول دفتر همین تأمین‌کننده.', 'tecteb-marketplace-core'), 'decimal')
            . $this->field('paid_on', __('تاریخ پرداخت (۱۴۰۴-۰۱-۰۱ میلادی: YYYY-MM-DD)', 'tecteb-marketplace-core'), '', 'numeric')
            . $this->field('reference', __('شمارهٔ پیگیری', 'tecteb-marketplace-core'), __('بدون شمارهٔ پیگیری ثبت نمی‌شود.', 'tecteb-marketplace-core'))
            . '<div class="tmc-field"><label class="tmc-field__label" for="supplier-note">'
            . esc_html__('توضیح (اختیاری)', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" id="supplier-note" name="note" rows="2"></textarea></div>'
            // SAID OUT LOUD, because a manager would otherwise look for the
            // control. The record and its table carry a receipt path and the
            // service takes one; what does not exist yet is the upload, and a
            // half-built attachment — a file written where nothing can read it
            // back — is worse than none (`alpha.49`). Named in the delivery as
            // a gap with its reason rather than hidden behind an empty field.
            . '<p class="tmc-hint">' . esc_html__('پیوست رسید در این نسخه ساخته نشده است؛ شمارهٔ پیگیری و توضیح، ردیف را قابل تطبیق می‌کنند.', 'tecteb-marketplace-core') . '</p>'
            . '<p><button type="submit" class="tmc-button tmc-button--primary">'
            . esc_html__('ثبت پرداخت انجام‌شده', 'tecteb-marketplace-core') . '</button></p>'
            . '</form></section>';
    }

    /**
     * The payments that are recorded and NOT in the ledger — the conflict,
     * listed rather than resolved.
     *
     * @param callable(string|int):string $fa
     */
    private function awaiting(callable $fa): void
    {
        $service = $this->container->get(RecordSupplierPayment::class);
        $rows = $service->awaitingDefinition(100);

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('پرداخت‌هایی که در دفترکل ثبت نشده‌اند', 'tecteb-marketplace-core') . '</h2>';
        echo '<p class="tmc-hint">' . esc_html(SupplierPaymentMessages::conflictExplanation()) . '</p>';
        if ($rows === []) {
            echo '<p>' . esc_html__('هیچ پرداختی در این وضعیت نیست.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('تأمین‌کننده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تاریخ', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('مبلغ پرداخت‌شده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('سهم ثبت‌شدهٔ اقلام', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('قلم‌ها', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('شمارهٔ پیگیری', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($fa($row->supplierUserId)) . '</td>'
                . '<td>' . esc_html($fa($row->paidOn)) . '</td>'
                . '<td>' . esc_html($fa((string) DecimalAmount::toDecimal($row->amount->minor, $row->amount->exponent)))
                . ' ' . esc_html($row->amount->currency) . '</td>'
                . '<td>' . esc_html($fa((string) DecimalAmount::toDecimal($row->share->minor, $row->share->exponent)))
                . ' ' . esc_html($row->share->currency) . '</td>'
                . '<td>' . esc_html($fa(implode('، ', array_map('strval', $row->orderItemIds)))) . '</td>'
                . '<td>' . Components::code($row->reference) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private function field(string $name, string $label, string $hint = '', string $mode = ''): string
    {
        return '<div class="tmc-field"><label class="tmc-field__label" for="supplier-' . esc_attr($name) . '">'
            . esc_html($label) . '</label>'
            . '<input class="tmc-input" type="text" id="supplier-' . esc_attr($name) . '"'
            . ' name="' . esc_attr($name) . '" dir="ltr"'
            . ($mode !== '' ? ' inputmode="' . esc_attr($mode) . '"' : '') . '>'
            . ($hint !== '' ? '<span class="tmc-hint">' . esc_html($hint) . '</span>' : '')
            . '</div>';
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('supplier_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_supplier_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $ids = [];
        foreach (preg_split('/[^0-9]+/', $request->postText('order_items')) ?: [] as $piece) {
            if ($piece !== '') {
                $ids[] = (int) $piece;
            }
        }
        $result = $this->container->get(RecordSupplierPayment::class)->record(
            (int) $request->postText('supplier_id'),
            $ids,
            $request->postText('amount'),
            $request->postText('paid_on'),
            $request->postText('reference'),
            $request->postTextarea('note'),
            '',
            $request->postText('form_token')
        );
        return ($result->ok ? 'ok:' : 'err:')
            . (SupplierPaymentMessages::notice($result->code, $result->context) ?? $result->code);
    }
}
