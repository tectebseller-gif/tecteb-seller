<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorCapabilities;

/**
 * Where the manager sets commission — and where the difference between «۰٪»
 * and «تعیین‌نشده» is made impossible to miss.
 *
 * The page never shows an empty rate as a number. An unset rule is printed as
 * «هنوز تعیین نشده» with an explicit warning that sales depending on it will
 * stop rather than proceed at zero, because a screen that rendered a blank as
 * «۰٪» would be the fastest way to give the marketplace's whole margin away.
 */
final class CommissionRulesPage
{
    public const SLUG = 'tmc-commission-rules';
    public const CAPABILITY = 'tmc_manage_settings';
    private const NONCE = 'tmc_commission_rules';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('قواعد کمیسیون', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $notice = $this->handleAction(Request::capture());
        $rules = $this->container->get(CommissionRuleRepositoryInterface::class);
        $fa = static fn (string|int|float $v): string => PersianDigits::toPersian((string) $v);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== '') {
            echo Components::notice(str_starts_with($notice, 'ok:') ? 'success' : 'error', substr($notice, strpos($notice, ':') + 1));
        }

        $general = $rules->rate(RateScope::General, 'general');
        echo Components::notice(
            $general->isSet() ? 'info' : 'warning',
            $general->isSet()
                ? sprintf(__('نرخ عمومی بازارگاه: %s درصد. هر محصول یا فروشنده‌ای که نرخ خودش را نداشته باشد از همین ارث می‌برد.', 'tecteb-marketplace-core'), $fa((string) $general->percent()))
                : __('نرخ عمومی هنوز تعیین نشده است. این «صفر» نیست: تا وقتی نرخی تعیین نشود، هیچ فروشی در ماژول مالی ثبت نمی‌شود و سفارش‌ها عملیاتی نمی‌شوند. نرخ صفر اگر واقعاً منظورتان است، باید صریح ثبت شود.', 'tecteb-marketplace-core')
        );

        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('قواعد ثبت‌شده', 'tecteb-marketplace-core') . '</h2>';
        $all = $rules->all();
        if ($all === []) {
            echo '<p>' . esc_html__('هیچ قاعده‌ای ثبت نشده است.', 'tecteb-marketplace-core') . '</p>';
        } else {
            echo '<table class="tmc-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('دامنه', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('مرجع', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('نرخ', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('اقدام', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($all as $rule) {
                echo '<tr><td>' . esc_html(self::scopeLabel($rule['scope'])) . '</td>'
                    . '<td>' . Components::code($rule['reference']) . '</td>'
                    . '<td>' . ($rule['rate']->isSet()
                        ? esc_html(sprintf(__('%s درصد', 'tecteb-marketplace-core'), $fa((string) $rule['rate']->percent())))
                        . ($rule['rate']->isZero() ? ' <strong>' . esc_html__('(بدون کمیسیون، تصمیم ثبت‌شده)', 'tecteb-marketplace-core') . '</strong>' : '')
                        : '<strong>' . esc_html__('تعیین‌نشده', 'tecteb-marketplace-core') . '</strong>') . '</td>'
                    . '<td><form method="post">' . wp_nonce_field(self::NONCE, 'tmc_rules_nonce', true, false)
                    . '<input type="hidden" name="rule_action" value="clear">'
                    . '<input type="hidden" name="scope" value="' . esc_attr($rule['scope']->value) . '">'
                    . '<input type="hidden" name="reference" value="' . esc_attr($rule['reference']) . '">'
                    . '<button type="submit" class="tmc-button">' . esc_html__('حذف قاعده', 'tecteb-marketplace-core') . '</button>'
                    . '</form></td></tr>';
            }
            echo '</tbody></table>'
                . '<p class="tmc-hint">' . esc_html__('حذف یک قاعده یعنی بازگشت به ارث‌بری از دامنه بالاتر — نه صفر شدن نرخ.', 'tecteb-marketplace-core') . '</p>';
        }
        echo '</section>';

        $scopes = [];
        foreach (RateScope::precedence() as $scope) {
            $scopes[$scope->value] = self::scopeLabel($scope);
        }
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('تعیین نرخ', 'tecteb-marketplace-core') . '</h2>'
            . '<form method="post">' . wp_nonce_field(self::NONCE, 'tmc_rules_nonce', true, false)
            . '<input type="hidden" name="rule_action" value="set">'
            . '<div class="tmc-field"><label class="tmc-field__label" for="rule-scope">' . esc_html__('دامنه', 'tecteb-marketplace-core') . '</label>'
            . '<select class="tmc-input" id="rule-scope" name="scope">';
        foreach ($scopes as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="rule-reference">' . esc_html__('مرجع (شناسه محصول، فروشنده یا دسته؛ برای نرخ عمومی خالی)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="rule-reference" name="reference" dir="ltr"></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="rule-percent">' . esc_html__('نرخ به درصد (مثلاً ۱۲٫۳۴)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input tmc-input--short" type="text" id="rule-percent" name="percent" dir="ltr" inputmode="decimal">'
            . '<p class="tmc-field__desc">' . esc_html__('صفر یعنی «بدون کمیسیون» و یک تصمیم است. خالی گذاشتن یعنی قاعده‌ای ثبت نمی‌شود؛ برای حذف قاعده از دکمه حذف استفاده کنید.', 'tecteb-marketplace-core') . '</p></div>'
            . '<p><button type="submit" class="tmc-button tmc-button--primary">' . esc_html__('ثبت نرخ', 'tecteb-marketplace-core') . '</button></p>'
            . '</form></section>';

        echo Components::notice('info', __('این صفحه فقط قاعده‌ها را نگه می‌دارد. محاسبه سهم هر سفارش وقتی فعال می‌شود که تصمیم‌های باز DEC-02 (تعریف «تکمیل» در سفارش چندفروشنده‌ای) و DEC-04 (تخصیص مالیات و کوپن سراسری) ثبت شوند.', 'tecteb-marketplace-core'));
        echo Components::shellClose();
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('rule_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_rules_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $rules = $this->container->get(CommissionRuleRepositoryInterface::class);
        $scope = RateScope::tryFrom($request->postKey('scope'));
        if ($scope === null) {
            return 'err:' . __('دامنه نامعتبر است.', 'tecteb-marketplace-core');
        }
        $reference = $scope === RateScope::General ? 'general' : trim($request->postText('reference'));
        if ($reference === '') {
            return 'err:' . __('برای این دامنه باید یک مرجع بنویسید.', 'tecteb-marketplace-core');
        }

        if ($request->postKey('rule_action') === 'clear') {
            $rules->clearRate($scope, $reference);
            $this->audit($scope, $reference, null);
            return 'ok:' . __('قاعده حذف شد؛ از این پس از دامنه بالاتر ارث می‌برد.', 'tecteb-marketplace-core');
        }

        $percent = str_replace(['٪', '%', '،', '٫'], ['', '', '', '.'], trim($request->postText('percent')));
        $percent = PersianDigits::toLatin($percent);
        if ($percent === '' || !is_numeric($percent)) {
            return 'err:' . __('نرخ را به عدد بنویسید. برای «بدون کمیسیون» عدد صفر را ثبت کنید.', 'tecteb-marketplace-core');
        }
        $bp = (int) round(((float) $percent) * 100);
        if ($bp < 0 || $bp > CommissionRate::MAX_BP) {
            return 'err:' . __('نرخ باید بین ۰ و ۱۰۰ درصد باشد.', 'tecteb-marketplace-core');
        }
        $rules->setRate($scope, $reference, CommissionRate::ofBasisPoints($bp));
        $this->audit($scope, $reference, $bp);
        return 'ok:' . __('نرخ ثبت شد.', 'tecteb-marketplace-core');
    }

    private function audit(RateScope $scope, string $reference, ?int $bp): void
    {
        $this->container->get(AuditLogger::class)->log(
            AuditEventCatalog::FINANCE_RATE_CHANGED,
            get_current_user_id(),
            'commission_rule',
            $scope->value . ':' . $reference,
            ['scope' => $scope->value, 'reference' => $reference, 'rate_bp' => $bp ?? -1, 'cleared' => $bp === null ? 1 : 0]
        );
    }

    private static function scopeLabel(RateScope $scope): string
    {
        return match ($scope) {
            RateScope::Product => __('محصول', 'tecteb-marketplace-core'),
            RateScope::Vendor => __('فروشنده', 'tecteb-marketplace-core'),
            RateScope::Category => __('دسته', 'tecteb-marketplace-core'),
            RateScope::General => __('عمومی بازارگاه', 'tecteb-marketplace-core'),
        };
    }
}
