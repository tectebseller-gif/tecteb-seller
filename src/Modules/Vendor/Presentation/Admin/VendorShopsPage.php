<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierObligations;
use Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierReceivables;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * «انتخاب فروشگاه» — a manager choosing WHICH shop to read, explicitly.
 *
 * The owner's §4, and every clause of it is a line here rather than a
 * sentence in a document:
 *
 *   «دکمهٔ «ورود به داشبورد فروشنده» برای مدیر فاقد فروشگاه، انتخاب صریح
 *    فروشگاه را باز کند. هیچ فروشگاهی ساخته نشود و فروشنده‌ای تصادفی انتخاب
 *    نشود. دسترسی مشاهده با مجوز مشخص مدیریت و ثبت در ردِ ممیزی باشد و هویت
 *    حساب مدیر تغییر نکند. انتخاب فروشگاه به‌تنهایی نباید اجازهٔ ویرایش یا
 *    اقدام به‌جای فروشنده بدهد.»
 *
 *  - **explicit.** A list, and a link per row. Nothing is pre-selected, there
 *    is no «first shop» default, and with no `shop` in the address the page
 *    shows the list and nothing else. That is the answer to «فروشنده‌ای
 *    تصادفی انتخاب نشود» — the old button went to the product-review screen
 *    precisely because picking a shop for somebody was not acceptable.
 *  - **nothing is created.** This class has no writer of any kind: no
 *    repository `save()`, no `create()`, no capability grant, no role change.
 *    «هیچ فروشگاهی ساخته نشود» is a property of the code rather than a
 *    promise, and `VendorShopsPageIsReadOnlyTest` reads the file to say so.
 *  - **its own permission.** `Capabilities::VIEW_VENDOR_SHOPS`, which is not
 *    part of any other capability. A manager who can approve products does
 *    not get this by default.
 *  - **audited.** Opening one shop's view writes `VENDOR_SHOP_VIEWED` with
 *    the shop and the actor. Reading somebody's figures is an act.
 *  - **identity unchanged.** Nothing here calls `wp_set_current_user()`,
 *    switches a user, or writes a «viewing as» cookie. The manager stays
 *    themselves, on a wp-admin page, reading numbers.
 *  - **reading only.** There is not one form on the shop view. The vendor's
 *    own `/vendor/` area still builds every page from the SIGNED-IN user and
 *    takes no shop parameter, so there is no way for this page to act as the
 *    vendor even by accident.
 *
 * ### What it deliberately does NOT show
 *
 * Counts and totals, never people. No buyer name, no address, no telephone,
 * no IBAN, no document, no ticket text. «بدون نشت اطلاعات» is not satisfied
 * by a permission check alone: a page that shows a manager a shop's customers
 * has leaked them whatever the capability said. What a manager needs to
 * answer «is this shop working» is how many products are in which state, what
 * the money comes to, and what is outstanding — and that is what is here.
 */
final class VendorShopsPage
{
    public const SLUG = 'tmc-vendor-shops';
    public const CAPABILITY = Capabilities::VIEW_VENDOR_SHOPS;
    private const PER_PAGE = 50;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('فروشگاه‌ها (مشاهده)', 'tecteb-marketplace-core');
    }

    /** The address of the picker, built from `admin_url()` and never a literal. */
    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::SLUG);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $request = Request::capture();
        $shop = $request->queryInt('shop');
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        echo Components::notice('info', __('این صفحه فقط برای مشاهده است. هیچ تغییری از اینجا روی فروشگاه انجام نمی‌شود، هویت حساب شما عوض نمی‌شود و شما به‌جای فروشنده اقدامی نمی‌کنید. اطلاعات شخصی خریداران، مدارک و شمارهٔ حساب در این صفحه نشان داده نمی‌شود.', 'tecteb-marketplace-core'));

        if ($shop > 0) {
            $this->shop($shop, $fa);
        } else {
            $this->picker($request, $fa);
        }
        echo Components::shellClose();
    }

    /** The list. Nothing is selected and nothing is chosen for anybody. */
    private function picker(Request $request, callable $fa): void
    {
        $vendors = $this->container->get(VendorRepositoryInterface::class);
        $stores = $this->container->get(StoreRepositoryInterface::class);
        $term = $request->queryText('s');
        $page = max(1, $request->queryInt('paged'));

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('انتخاب فروشگاه', 'tecteb-marketplace-core') . '</h2>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">'
            . '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">'
            . '<label for="tmc-shop-search">' . esc_html__('جست‌وجوی نام فروشگاه', 'tecteb-marketplace-core') . '</label> '
            . '<input type="search" id="tmc-shop-search" name="s" value="' . esc_attr($term) . '"> '
            . '<button type="submit" class="button">' . esc_html__('جست‌وجو', 'tecteb-marketplace-core')
            . '</button></form>';

        if ($term !== '') {
            // The shop's own settings row is what carries the name a person
            // typed, so the search goes there. It returns ids, which are then
            // read as profiles below — one place decides what a row says.
            $ids = array_map(
                static fn (array $row): int => (int) $row['vendor_user_id'],
                $stores->searchByShopName($term, self::PER_PAGE)
            );
            $total = count($ids);
        } else {
            $ids = $vendors->approvedVendorUserIds(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $total = $vendors->countApprovedVendors();
        }

        if ($ids === []) {
            echo '<p>' . esc_html__('فروشگاهی برای نمایش نیست.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: number of shops */
            __('%s فروشگاه تأییدشده.', 'tecteb-marketplace-core'),
            $fa($total)
        )) . '</p>';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اجازهٔ فروش', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('مشاهده', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($ids as $vendorUserId) {
            $profile = $vendors->findProfileByUser((int) $vendorUserId);
            $name = $profile === null || trim($profile->storeName) === ''
                ? sprintf(
                    /* translators: %s: vendor user id */
                    __('بدون نام — فروشگاه #%s', 'tecteb-marketplace-core'),
                    $fa((int) $vendorUserId)
                )
                : $profile->storeName;
            echo '<tr><td>' . esc_html($name) . '</td><td>'
                . esc_html($profile !== null && $profile->canSell
                    ? __('بله', 'tecteb-marketplace-core')
                    : __('خیر', 'tecteb-marketplace-core'))
                . '</td><td><a href="' . esc_url(add_query_arg('shop', (int) $vendorUserId, self::url())) . '">'
                . esc_html__('مشاهدهٔ این فروشگاه', 'tecteb-marketplace-core') . '</a></td></tr>';
        }
        echo '</tbody></table>';
        if ($term === '' && $total > self::PER_PAGE) {
            $pages = (int) ceil($total / self::PER_PAGE);
            echo '<p class="tmc-pager">';
            for ($n = 1; $n <= $pages; $n++) {
                echo $n === $page
                    ? '<strong>' . esc_html($fa($n)) . '</strong> '
                    : '<a href="' . esc_url(add_query_arg('paged', $n, self::url())) . '">'
                        . esc_html($fa($n)) . '</a> ';
            }
            echo '</p>';
        }
        echo '</section>';
    }

    /**
     * One shop, read-only — and the audit row that says who read it.
     *
     * The audit entry is written BEFORE the figures are printed, so a read
     * that fails halfway is still a read that happened. It names the shop and
     * the actor and nothing else: an audit trail of what a manager LOOKED AT
     * is not a place to copy a shop's numbers into.
     */
    private function shop(int $vendorUserId, callable $fa): void
    {
        $vendors = $this->container->get(VendorRepositoryInterface::class);
        $profile = $vendors->findProfileByUser($vendorUserId);
        if ($profile === null) {
            echo Components::notice('error', __('فروشگاهی با این شناسه پیدا نشد.', 'tecteb-marketplace-core'));
            echo '<p><a href="' . esc_url(self::url()) . '">'
                . esc_html__('بازگشت به فهرست فروشگاه‌ها', 'tecteb-marketplace-core') . '</a></p>';
            return;
        }
        $actorId = get_current_user_id();
        $this->container->get(AuditLogger::class)->log(
            AuditEventCatalog::VENDOR_SHOP_VIEWED,
            $actorId,
            'vendor',
            (string) $vendorUserId,
            ['vendor_id' => $vendorUserId, 'actor_id' => $actorId]
        );

        echo '<p><a href="' . esc_url(self::url()) . '">'
            . esc_html__('بازگشت به فهرست فروشگاه‌ها', 'tecteb-marketplace-core') . '</a></p>';
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html($profile->storeName !== '' ? $profile->storeName : sprintf(
                /* translators: %s: vendor user id */
                __('بدون نام — فروشگاه #%s', 'tecteb-marketplace-core'),
                $fa($vendorUserId)
            ))
            . '</h2>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: yes or no */
            __('اجازهٔ فروش: %s', 'tecteb-marketplace-core'),
            $profile->canSell ? __('بله', 'tecteb-marketplace-core') : __('خیر', 'tecteb-marketplace-core')
        )) . '</p>';

        $counts = $this->container->get(ProductRepositoryInterface::class)->countsByStatus($vendorUserId);
        if ($counts !== []) {
            echo '<h3>' . esc_html__('محصول‌ها', 'tecteb-marketplace-core') . '</h3><ul>';
            foreach ($counts as $status => $count) {
                echo '<li>' . esc_html((string) $status) . ': ' . esc_html($fa((int) $count)) . '</li>';
            }
            echo '</ul>';
        }

        $balance = $this->container->get(VendorBalance::class)->of($vendorUserId);
        // MONEY THROUGH THE ONE FORMATTER, at the unit the balance came back
        // in; the last row is a COUNT of lines and stays a plain number.
        $balanceExponent = (int) ($balance['exponent'] ?? 0);
        $money = static fn (int $minor): string =>
            MoneyText::format($minor, $balanceExponent) ?? PersianDigits::toPersian((string) $minor);
        echo '<h3>' . esc_html__('مانده', 'tecteb-marketplace-core') . '</h3><ul>'
            . '<li>' . esc_html__('کل درآمد ثبت‌شده', 'tecteb-marketplace-core') . ': '
            . esc_html($money((int) $balance['earned'])) . '</li>'
            . '<li>' . esc_html__('قابل برداشت', 'tecteb-marketplace-core') . ': '
            . esc_html($money((int) $balance['eligible'])) . '</li>'
            . '<li>' . esc_html__('پرداخت‌شده', 'tecteb-marketplace-core') . ': '
            . esc_html($money((int) $balance['paid'])) . '</li>'
            . '<li>' . esc_html__('سهم ثبت‌نشده (تعداد قلم)', 'tecteb-marketplace-core') . ': '
            . esc_html($fa((int) $balance['unrecorded'])) . '</li>'
            . '</ul>';

        $open = $this->container->get(WithdrawalRepositoryInterface::class)->openFor($vendorUserId);
        echo '<p>' . esc_html($open === null
            ? __('درخواست برداشت باز: ندارد.', 'tecteb-marketplace-core')
            : sprintf(
                /* translators: 1: amount, 2: status */
                __('درخواست برداشت باز: %1$s — وضعیت %2$s', 'tecteb-marketplace-core'),
                $fa($open->amountMinor),
                $open->status->value
            )) . '</p>';

        $payments = $this->container->get(SupplierPaymentRepositoryInterface::class)
            ->forSupplier($vendorUserId, 20);
        echo '<h3>' . esc_html__('پرداخت‌های ثبت‌شده به این تأمین‌کننده', 'tecteb-marketplace-core') . '</h3>';
        if ($payments === []) {
            echo '<p>' . esc_html__('پرداختی ثبت نشده است.', 'tecteb-marketplace-core') . '</p>';
        } else {
            echo '<ul>';
            foreach ($payments as $payment) {
                echo '<li>' . esc_html(sprintf(
                    /* translators: 1: date, 2: amount, 3: state */
                    __('%1$s — %2$s — %3$s', 'tecteb-marketplace-core'),
                    $payment->paidOn,
                    // The payment's own unit, which its row carries.
                    MoneyText::format($payment->amount->minor, $payment->amount->exponent)
                        ?? $fa($payment->amount->minor),
                    $payment->isVoid()
                        ? __('ابطال‌شده', 'tecteb-marketplace-core')
                        : $payment->posting
                )) . '</li>';
            }
            echo '</ul>';
        }

        $receivables = $this->container->get(ManageSupplierReceivables::class)->openFor($vendorUserId, 20);
        if ($receivables !== []) {
            echo '<h3>' . esc_html__('طلب باز از این تأمین‌کننده', 'tecteb-marketplace-core') . '</h3><ul>';
            foreach ($receivables as $receivable) {
                echo '<li>' . esc_html(sprintf(
                    /* translators: 1: amount owed, 2: remaining balance */
                    __('مبلغ %1$s — ماندهٔ %2$s', 'tecteb-marketplace-core'),
                    MoneyText::format($receivable->amount->minor, $receivable->amount->exponent)
                        ?? $fa($receivable->amount->minor),
                    MoneyText::format($receivable->balanceMinor(), $receivable->amount->exponent)
                        ?? $fa($receivable->balanceMinor())
                )) . '</li>';
            }
            echo '</ul>';
        }

        $obligations = $this->container->get(ManageSupplierObligations::class)->forSupplier($vendorUserId, 20);
        if ($obligations !== []) {
            echo '<h3>' . esc_html__('مبالغ توافق‌شده', 'tecteb-marketplace-core') . '</h3><ul>';
            foreach ($obligations as $obligation) {
                echo '<li>' . esc_html(sprintf(
                    /* translators: 1: order item id, 2: agreed amount, 3: paid so far */
                    __('قلم %1$s — توافق %2$s — پرداخت‌شده %3$s', 'tecteb-marketplace-core'),
                    $fa($obligation->orderItemId),
                    $obligation->isAgreed()
                        ? (MoneyText::format((int) $obligation->agreed?->minor, $obligation->exponent)
                            ?? $fa((int) $obligation->agreed?->minor))
                        : __('نامعلوم', 'tecteb-marketplace-core'),
                    MoneyText::format($obligation->paidMinor, $obligation->exponent)
                        ?? $fa($obligation->paidMinor)
                )) . '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
    }
}
