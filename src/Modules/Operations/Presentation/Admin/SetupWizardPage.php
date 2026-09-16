<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Infrastructure\WordPress\WpJobScheduler;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface;
use Tecteb\Marketplace\Modules\Operations\Application\SetupChecklist;
use Tecteb\Marketplace\Modules\Operations\Presentation\SetupMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentTypeRepositoryInterface;

/**
 * «راه‌اندازی» — the first-run path, made out of the pages that already exist.
 *
 * It owns no settings. Every step links to the screen that writes that value
 * and reports the value back by reading it, so an installation configured
 * entirely without this page shows a complete checklist, and a value later
 * cleared makes its step incomplete again. A wizard with its own storage would
 * be a second home for the same setting, and the two would disagree the first
 * time somebody used the ordinary page.
 *
 * **«منتظر تصمیم» is a first-class answer.** The commission rate waits on
 * DEC-01, and FIN-02 forbids inventing one. A wizard that kept asking for a
 * number nobody has approved would be training the manager to type something
 * arbitrary into the field that decides what every vendor is paid.
 *
 * **Skipping is remembered, not obeyed.** A skipped step stops nagging and
 * keeps its place in the list, labelled as skipped. «تمام شد» therefore never
 * means «somebody pressed «بعدی» seven times».
 */
final class SetupWizardPage
{
    public const SLUG = 'tmc-setup';
    public const CAPABILITY = Capabilities::MANAGE_SETTINGS;
    private const NONCE = 'tmc_setup_action';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('راه‌اندازی', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('اجازهٔ دیدن این صفحه را ندارید.', 'tecteb-marketplace-core'));
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $request = Request::capture();
        $message = $this->handleAction($request);

        $steps = SetupChecklist::steps($this->state(), $this->skipped());
        $summary = SetupChecklist::summary($steps);

        echo Components::shellOpen(self::menuLabel(), self::SLUG);
        if ($message !== '') {
            [$type, $text] = explode(':', $message, 2);
            echo Components::notice($type === 'ok' ? 'success' : 'warning', $text);
        }

        echo '<p class="tmc-field__desc">'
            . esc_html__('این صفحه هیچ تنظیمی از خودش ندارد: هر گام به همان صفحه‌ای می‌برد که آن مقدار را می‌نویسد و وضعیتش را از روی همان مقدار می‌خواند. پس اگر همه‌چیز را از صفحه‌های عادی تنظیم کنید، همین فهرست هم کامل می‌شود.', 'tecteb-marketplace-core')
            . '</p>';

        echo Components::notice(
            $summary['todo'] === 0 && $summary['blocked'] === 0 ? 'success' : 'info',
            sprintf(
                /* translators: 1: done, 2: total, 3: waiting on a decision, 4: skipped */
                __('%1$s گام از %2$s انجام شده است؛ %3$s گام منتظر تصمیم مالک است و %4$s گام رد شده.', 'tecteb-marketplace-core'),
                $fa((string) $summary['done']),
                $fa((string) $summary['total']),
                $fa((string) $summary['blocked']),
                $fa((string) $summary['skipped'])
            )
        );

        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('گام‌ها', 'tecteb-marketplace-core') . '</h2><ol class="tmc-steps">';
        foreach ($steps as $index => $step) {
            echo $this->step($step, $index + 1, $fa);      // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</ol></section>' . Components::shellClose();
    }

    /**
     * @param array{key:string, status:string, blocker:string, target:string} $step
     * @param callable(string|int):string $fa
     */
    private function step(array $step, int $number, callable $fa): string
    {
        $out = '<li class="tmc-steps__item tmc-steps__item--' . esc_attr($step['status']) . '">';
        $out .= '<h3 class="tmc-steps__title">' . esc_html(sprintf(
            /* translators: 1: step number, 2: step title */
            __('گام %1$s — %2$s', 'tecteb-marketplace-core'),
            $fa((string) $number),
            SetupMessages::title($step['key'])
        )) . '</h3>';
        $out .= '<p class="tmc-steps__status">' . esc_html(SetupMessages::status($step['status'])) . '</p>';
        $out .= '<p class="tmc-field__desc">' . esc_html(SetupMessages::describe($step['key'])) . '</p>';

        if ($step['blocker'] !== '') {
            $out .= Components::notice('info', SetupMessages::blocker($step['blocker']));
        }

        if ($step['status'] !== SetupChecklist::DONE) {
            $out .= '<a class="tmc-button" href="' . esc_url($this->targetUrl($step['target'])) . '">'
                . esc_html(SetupMessages::cta($step['key'])) . '</a>';
        }
        if ($step['status'] === SetupChecklist::TODO) {
            $out .= ' <form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_setup_nonce', true, false)
                . '<input type="hidden" name="step" value="' . esc_attr($step['key']) . '">'
                . '<button type="submit" class="tmc-button tmc-button--quiet" name="setup_action" value="skip">'
                . esc_html__('فعلاً رد کن', 'tecteb-marketplace-core') . '</button></form>';
        }
        if ($step['status'] === SetupChecklist::SKIPPED) {
            $out .= ' <form method="post" class="tmc-inline-form">'
                . wp_nonce_field(self::NONCE, 'tmc_setup_nonce', true, false)
                . '<input type="hidden" name="step" value="' . esc_attr($step['key']) . '">'
                . '<button type="submit" class="tmc-button tmc-button--quiet" name="setup_action" value="unskip">'
                . esc_html__('برگرداندن به فهرست', 'tecteb-marketplace-core') . '</button></form>';
        }
        return $out . '</li>';
    }

    /** A step's target is either one of our pages or a core screen. */
    private function targetUrl(string $target): string
    {
        return str_contains($target, '.php')
            ? admin_url($target)
            : admin_url('admin.php?page=' . $target);
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $settings = $this->container->get(SettingsService::class)->load();
        $documents = 0;
        try {
            $documents = count($this->container->get(DocumentTypeRepositoryInterface::class)->requirementSet()->types());
        } catch (\Throwable) {
            // The vendor module may not be loaded (WooCommerce missing). An
            // unknown count is reported as zero — «not configured» — rather
            // than taking the page down.
        }
        $rules = 0;
        try {
            $rules = count($this->container->get(CommissionRuleRepositoryInterface::class)->all());
        } catch (\Throwable) {
            // Same rule as above.
        }
        return [
            'woocommerce_active' => $this->container->get(DependencyProbeInterface::class)->woocommerceAvailable(),
            'commission_rate_bp' => $settings->commissionRateBp,
            'commission_rules' => $rules,
            'document_types' => $documents,
            'settlement_delay_days' => $settings->settlementDelayDays,
            'max_staff' => $settings->maxStaff,
            // «Reachable» here means pretty permalinks are on. With plain
            // permalinks the /vendor/ rules never match and the area is only
            // reachable by query string — which works, but is not the address
            // anybody would hand to a vendor.
            'vendor_page_reachable' => get_option('permalink_structure', '') !== '',
            'queue_binding' => WpJobScheduler::binding(),
            'decision_open' => true,
        ];
    }

    /** @return list<string> */
    private function skipped(): array
    {
        $raw = $this->container->get(OptionStoreInterface::class)->get(SetupChecklist::SKIPPED_OPTION, []);
        return is_array($raw) ? array_values(array_map('strval', $raw)) : [];
    }

    private function handleAction(Request $request): string
    {
        if (!$request->isPost() || !$request->hasPost('setup_action')) {
            return '';
        }
        if (!$request->nonceOk('tmc_setup_nonce', self::NONCE)) {
            return 'err:' . __('درخواست معتبر نبود. صفحه را تازه کنید و دوباره تلاش کنید.', 'tecteb-marketplace-core');
        }
        $step = $request->postKey('step');
        if (!in_array($step, SetupChecklist::ORDER, true)) {
            return 'err:' . __('این گام شناخته نشد.', 'tecteb-marketplace-core');
        }
        $options = $this->container->get(OptionStoreInterface::class);
        $skipped = $this->skipped();
        $action = $request->postKey('setup_action');
        if ($action === 'skip') {
            $skipped[] = $step;
        } elseif ($action === 'unskip') {
            $skipped = array_values(array_diff($skipped, [$step]));
        } else {
            return 'err:' . __('این اقدام از این صفحه انجام نمی‌شود.', 'tecteb-marketplace-core');
        }
        $options->set(SetupChecklist::SKIPPED_OPTION, array_values(array_unique($skipped)));
        return 'ok:' . ($action === 'skip'
            ? __('این گام فعلاً رد شد. در فهرست می‌ماند و هر وقت خواستید برمی‌گردد.', 'tecteb-marketplace-core')
            : __('این گام به فهرست کارهای باقی‌مانده برگشت.', 'tecteb-marketplace-core'));
    }
}
