<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierObligations;
use Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierReceivables;
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
        $this->agreedAmounts();
        $this->awaiting($fa);
        $this->receivables($fa);
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
            . '<th scope="col">' . esc_html__('چرا ثبت نشده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('قلم‌ها', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('شمارهٔ پیگیری', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($fa($row->supplierUserId)) . '</td>'
                . '<td>' . esc_html($fa($row->paidOn)) . '</td>'
                . '<td>' . esc_html($fa((string) DecimalAmount::toDecimal($row->amount->minor, $row->amount->exponent)))
                . ' ' . esc_html($row->amount->currency) . '</td>'
                // «نامعلوم» rather than the arithmetic of the known items: a
                // sum that leaves an item out is not this payment's share, and
                // printing it under that heading is how `alpha.60` came to
                // declare a match (§3).
                . '<td>' . ($row->shareIsKnown()
                    ? esc_html($fa((string) DecimalAmount::toDecimal($row->share->minor, $row->share->exponent))
                        . ' ' . $row->share->currency)
                    : esc_html__('نامعلوم', 'tecteb-marketplace-core')) . '</td>'
                . '<td>' . esc_html($row->shareIsKnown()
                    ? __('مبلغ پرداختی با سهم ثبت‌شده فرق دارد.', 'tecteb-marketplace-core')
                    : sprintf(
                        /* translators: %s: the order items whose share is not recorded */
                        __('سهم قلم %s ثبت نشده، پس مقایسه‌ای انجام نشد.', 'tecteb-marketplace-core'),
                        $fa(implode('، ', array_map('strval', $row->unknownShareItemIds)))
                    )) . '</td>'
                . '<td>' . esc_html($fa(implode('، ', array_map('strval', $row->orderItemIds)))) . '</td>'
                . '<td>' . Components::code($row->reference) . '</td>'
                // TWO ACTIONS, AND THEY ARE DIFFERENT ACTS.
                //
                // «بررسی و تطبیق» re-reads this payment's own items and, if
                // the figures are now knowable and agree, posts the money
                // line under the SAME payment — no second payment, and the
                // first figure stays in its own column.
                //
                // «ابطال ثبت» says the record was wrong. The reason is
                // required by the service, not by this markup, and the text
                // beside the button says what a void is and is not: it does
                // not mean the money came back.
                . '<td><form method="post">'
                . wp_nonce_field(self::NONCE, 'tmc_supplier_nonce', true, false)
                . '<input type="hidden" name="payment_id" value="' . esc_attr((string) $row->id) . '">'
                . '<button type="submit" class="tmc-button" name="supplier_action" value="reconcile">'
                . esc_html__('بررسی و تطبیق همین پرداخت', 'tecteb-marketplace-core') . '</button>'
                . '<label class="tmc-field__label" for="void-' . esc_attr((string) $row->id) . '">'
                . esc_html__('دلیل ابطال', 'tecteb-marketplace-core') . '</label>'
                . '<textarea class="tmc-input" id="void-' . esc_attr((string) $row->id) . '"'
                . ' name="void_reason" rows="2"></textarea>'
                . '<button type="submit" class="tmc-button" name="supplier_action" value="void">'
                . esc_html__('ابطال ثبت', 'tecteb-marketplace-core') . '</button>'
                . '<span class="tmc-hint">'
                . esc_html__('ابطال، سابقه را حذف نمی‌کند و به‌معنای بازگشت واقعی پول نیست؛ اگر این پرداخت در دفترکل ثبت شده باشد، سند معکوس در همان لحظه نوشته می‌شود.', 'tecteb-marketplace-core')
                . '</span>'
                . '</form></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    /** @return list<int> the positive integers in a comma-separated field */
    private static function idList(string $typed): array
    {
        $ids = [];
        foreach (preg_split('/[^0-9]+/', $typed) ?: [] as $piece) {
            if ($piece !== '') {
                $ids[] = (int) $piece;
            }
        }
        return $ids;
    }

    /**
     * «مبلغ توافق‌شده» — the number people agreed, and its history.
     *
     * Its own section and above the unposted list on purpose: it is the thing
     * that has to exist before a partial payment means anything, and a
     * manager who records payments without ever coming here gets exactly the
     * `alpha.61` behaviour, which is documented rather than broken.
     */
    private function agreedAmounts(): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('مبلغ توافق‌شدهٔ خرید', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('این مبلغ همان سهم محاسبه‌شدهٔ کمیسیون نیست و از آن ساخته نمی‌شود؛ عددی است که با تأمین‌کننده توافق شده. تا وقتی ثبت نشود، مانده‌ای برای محاسبهٔ پرداخت جزئی وجود ندارد. هر تغییر با دلیل در سابقهٔ همان قلم می‌مانَد و هیچ پرداخت یا سفارش ثبت‌شده‌ای بازنویسی نمی‌شود.', 'tecteb-marketplace-core') . '</p>'
            . '<form method="post">'
            . wp_nonce_field(self::NONCE, 'tmc_supplier_nonce', true, false)
            . '<input type="hidden" name="supplier_action" value="agree">'
            . $this->field('obligation_item', __('شناسهٔ قلم سفارش', 'tecteb-marketplace-core'), '', 'numeric')
            . $this->field('agreed_amount', __('مبلغ توافق‌شده', 'tecteb-marketplace-core'), __('خالی بگذارید تا «توافقی ثبت نشده» شود. صفر یک توافق واقعی است و با خالی یکی نیست.', 'tecteb-marketplace-core'), 'decimal')
            . '<div class="tmc-field"><label class="tmc-field__label" for="supplier-agreed_reason">'
            . esc_html__('دلیل (برای سابقه)', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" id="supplier-agreed_reason" name="agreed_reason" rows="2"></textarea></div>'
            . '<p><button type="submit" class="tmc-button">'
            . esc_html__('ثبت مبلغ توافق‌شده', 'tecteb-marketplace-core') . '</button></p>'
            . '</form></section>';
    }

    /**
     * «طلب از تأمین‌کننده» — the three events of §2, each with its own form.
     *
     * One form records the debt; two record what happened to it. There is no
     * button that moves money and no button that subtracts anything from a
     * future payment: «انتقال بانکی و کسر خودکار انجام نشود». An offset is a
     * RECORD that the manager intends to set an amount against what Tecteb
     * owes next, and the payment id on it is a reference.
     *
     * @param callable(string|int):string $fa
     */
    private function receivables(callable $fa): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('طلب از تأمین‌کننده پس از مرجوعی', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('اگر کالایی پس از پرداخت به تأمین‌کننده مرجوع شود، مبلغ مربوط به همان قلم و همان تعداد اینجا به‌عنوان «طلب» ثبت می‌شود. ثبت طلب، وصول واقعی و تهاتر سه رویداد جدا هستند و یک مبلغ دو بار وصول یا کسر نمی‌شود. هیچ انتقال بانکی و هیچ کسر خودکاری از اینجا انجام نمی‌شود.', 'tecteb-marketplace-core') . '</p>'
            . '<form method="post">'
            . wp_nonce_field(self::NONCE, 'tmc_supplier_nonce', true, false)
            . '<input type="hidden" name="supplier_action" value="receivable">'
            . $this->field('receivable_item', __('شناسهٔ قلم سفارش', 'tecteb-marketplace-core'), __('قلمی که پرداختش ثبت شده است.', 'tecteb-marketplace-core'), 'numeric')
            . $this->field('receivable_amount', __('مبلغ طلب', 'tecteb-marketplace-core'), __('بیشتر از مبلغ پرداخت‌شدهٔ همان قلم پذیرفته نمی‌شود.', 'tecteb-marketplace-core'), 'decimal')
            . $this->field('receivable_quantity', __('تعداد مرجوعی', 'tecteb-marketplace-core'), '', 'numeric')
            . $this->field('receivable_return', __('شناسهٔ مرجوعی (اختیاری)', 'tecteb-marketplace-core'), '', 'numeric')
            . '<div class="tmc-field"><label class="tmc-field__label" for="supplier-receivable_reason">'
            . esc_html__('دلیل', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" id="supplier-receivable_reason" name="receivable_reason" rows="2"></textarea></div>'
            . '<p><button type="submit" class="tmc-button">'
            . esc_html__('ثبت طلب', 'tecteb-marketplace-core') . '</button></p>'
            . '</form>';

        $open = $this->container->get(ManageSupplierReceivables::class)->allOpen(100);
        if ($open === []) {
            echo '<p>' . esc_html__('طلب بازی ثبت نشده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<h3>' . esc_html__('طلب‌های باز', 'tecteb-marketplace-core') . '</h3>';
        foreach ($open as $receivable) {
            echo '<div class="tmc-field"><p>' . esc_html(sprintf(
                /* translators: 1: receivable id, 2: order item, 3: owed, 4: remaining */
                __('طلب #%1$s — قلم %2$s — مبلغ %3$s — ماندهٔ %4$s', 'tecteb-marketplace-core'),
                $fa($receivable->id),
                $fa($receivable->orderItemId),
                $fa((string) DecimalAmount::toDecimal($receivable->amount->minor, $receivable->amount->exponent)),
                $fa((string) DecimalAmount::toDecimal($receivable->balanceMinor(), $receivable->amount->exponent))
            )) . '</p>'
                . '<form method="post">'
                . wp_nonce_field(self::NONCE, 'tmc_supplier_nonce', true, false)
                . '<input type="hidden" name="receivable_id" value="' . esc_attr((string) $receivable->id) . '">'
                . '<input type="hidden" name="form_token" value="' . esc_attr(wp_generate_uuid4()) . '">'
                . '<label for="settle-' . esc_attr((string) $receivable->id) . '">'
                . esc_html__('مبلغ', 'tecteb-marketplace-core') . '</label> '
                . '<input class="tmc-input" type="text" dir="ltr" inputmode="decimal"'
                . ' id="settle-' . esc_attr((string) $receivable->id) . '" name="settle_amount"> '
                . '<label for="settle-ref-' . esc_attr((string) $receivable->id) . '">'
                . esc_html__('شمارهٔ پیگیری وصول', 'tecteb-marketplace-core') . '</label> '
                . '<input class="tmc-input" type="text" dir="ltr"'
                . ' id="settle-ref-' . esc_attr((string) $receivable->id) . '" name="settle_reference"> '
                . '<button type="submit" class="tmc-button" name="supplier_action" value="collect">'
                . esc_html__('ثبت وصول واقعی', 'tecteb-marketplace-core') . '</button> '
                . '<button type="submit" class="tmc-button" name="supplier_action" value="offset">'
                . esc_html__('ثبت تهاتر با پرداخت بعدی', 'tecteb-marketplace-core') . '</button>'
                . '</form></div>';
        }
        echo '</section>';
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
        // SIX ACTIONS, ONE NONCE, AND A `match` RATHER THAN A CHAIN OF `if`s.
        //
        // The `default` is a refusal: a verb this page does not know is not
        // silently ignored, which is the trap `alpha.23` paid for when a word
        // that was in `handle()` and not in `ACTIONS` sent a user to an
        // unrelated page. Every branch returns the service's own code through
        // `SupplierPaymentMessages`, so the sentence and the decision cannot
        // drift apart.
        $payments = $this->container->get(RecordSupplierPayment::class);
        $obligations = $this->container->get(ManageSupplierObligations::class);
        $receivables = $this->container->get(ManageSupplierReceivables::class);
        $action = $request->postText('supplier_action');

        $result = match ($action) {
            'record' => $payments->record(
                (int) $request->postText('supplier_id'),
                self::idList($request->postText('order_items')),
                $request->postText('amount'),
                $request->postText('paid_on'),
                $request->postText('reference'),
                $request->postTextarea('note'),
                '',
                $request->postText('form_token')
            ),
            // «ابطال ثبت» — the reason travels with it and the service
            // refuses without one.
            'void' => $payments->void(
                $request->postInt('payment_id'),
                $request->postTextarea('void_reason')
            ),
            // «بررسی و تطبیق همان پرداخت» — no payment id is created here and
            // none can be: the service takes an existing one.
            'reconcile' => $payments->reconcile($request->postInt('payment_id')),
            'agree' => $obligations->agree(
                $request->postInt('obligation_item'),
                $request->postText('agreed_amount'),
                $request->postTextarea('agreed_reason')
            ),
            'receivable' => $receivables->record(
                $request->postInt('receivable_item'),
                $request->postText('receivable_amount'),
                $request->postInt('receivable_return'),
                $request->postInt('receivable_quantity'),
                $request->postTextarea('receivable_reason')
            ),
            'collect' => $receivables->collect(
                $request->postInt('receivable_id'),
                $request->postText('settle_amount'),
                $request->postText('settle_reference'),
                $request->postTextarea('settle_note'),
                $request->postText('form_token')
            ),
            'offset' => $receivables->offset(
                $request->postInt('receivable_id'),
                $request->postText('settle_amount'),
                $request->postInt('settle_payment'),
                $request->postTextarea('settle_note'),
                $request->postText('form_token')
            ),
            default => null,
        };
        if ($result === null) {
            return 'err:' . __('این درخواست شناخته نشد.', 'tecteb-marketplace-core');
        }
        return ($result->ok ? 'ok:' : 'err:')
            . (SupplierPaymentMessages::notice($result->code, $result->context) ?? $result->code);
    }
}
