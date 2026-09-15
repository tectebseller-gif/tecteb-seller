<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageCoupons;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageWholesale;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleStatus;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\MarketplaceMessages;

/**
 * Who may buy wholesale — the manager's decision, and the two things this
 * screen refuses to decide for them.
 *
 * It offers no marketplace-wide tariff and no minimum order, because DEC-05
 * has not set either, and it offers no «کد تخفیف سراسری» button, because
 * DEC-04 has not said who funds one. Both refusals are printed rather than
 * hidden: a manager looking for a feature that is not here should find out
 * why in the same place they looked for it.
 */
final class WholesalePage
{
    public const SLUG = 'tmc-wholesale';
    public const CAPABILITY = Capabilities::REVIEW_VENDOR;
    private const NONCE = 'tmc_wholesale';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('خریداران عمده', 'tecteb-marketplace-core');
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
        echo Components::notice('warning', MarketplaceMessages::openTermsWarning());
        echo '<p class="tmc-field__desc">'
            . esc_html(sprintf(
                /* translators: 1: wholesale open terms, 2: the global coupon refusal code */
                __('موارد تعیین‌نشده: %1$s و %2$s. هر پلهٔ قیمت را خود فروشنده برای محصول خودش می‌نویسد؛ بازارگاه تعرفه یا حداقل خریدی تحمیل نمی‌کند.', 'tecteb-marketplace-core'),
                implode('، ', ManageWholesale::OPEN_TERMS),
                ManageCoupons::GLOBAL_UNDECIDED
            ))
            . '</p>';

        $this->renderQueue($fa);
        echo Components::shellClose();
    }

    private function renderQueue(callable $fa): void
    {
        $accounts = $this->container->get(ManageWholesale::class)->queue();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('درخواست‌های خرید عمده', 'tecteb-marketplace-core') . '</h2>';
        if ($accounts === []) {
            echo '<p>' . esc_html__('هیچ درخواستی ثبت نشده است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('جدول خریداران عمده', 'tecteb-marketplace-core') . '">';
        echo '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('کاربر', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('شرکت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('شناسه ثبت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($accounts as $account) {
            echo '<tr><td>' . Components::code((string) $account->userId) . '</td>'
                . '<td>' . esc_html($account->company) . '</td>'
                . '<td>' . ($account->registrationId === '' ? '—' : Components::code($account->registrationId)) . '</td>'
                . '<td>' . esc_html(MarketplaceMessages::wholesaleStatus($account->status)) . '</td>'
                . '<td><form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_wholesale_nonce', true, false)
                . '<input type="hidden" name="user_id" value="' . esc_attr((string) $account->userId) . '">'
                . '<label class="tmc-field__label" for="note-' . esc_attr((string) $account->userId) . '">'
                . esc_html__('یادداشت', 'tecteb-marketplace-core') . '</label>'
                . '<input class="tmc-input" type="text" name="note" id="note-' . esc_attr((string) $account->userId) . '">'
                . '<button type="submit" class="tmc-button" name="wholesale_action" value="approved">'
                . esc_html__('تأیید', 'tecteb-marketplace-core') . '</button> '
                . '<button type="submit" class="tmc-button" name="wholesale_action" value="rejected">'
                . esc_html__('رد', 'tecteb-marketplace-core') . '</button> '
                . '<button type="submit" class="tmc-button" name="wholesale_action" value="suspended">'
                . esc_html__('تعلیق', 'tecteb-marketplace-core') . '</button>'
                . '</form></td></tr>';
        }
        unset($fa);
        echo '</tbody></table></div></section>';
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('wholesale_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_wholesale_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $status = WholesaleStatus::tryFrom($request->postKey('wholesale_action'));
        $userId = $request->postInt('user_id');
        if ($status === null || $userId <= 0) {
            return 'err:' . __('اقدام نامعتبر است.', 'tecteb-marketplace-core');
        }
        $result = $this->container->get(ManageWholesale::class)->decide($userId, $status, $request->postText('note'));
        return ($result->ok ? 'ok:' : 'err:')
            . (MarketplaceMessages::notice($result->code, $result->context) ?? $result->code);
    }
}
