<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\DashboardPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\HealthPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\ModulesPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\SettingsPage;

/**
 * Top-level «بازارگاه تک‌طب» with exactly four capability-gated pages
 * (CORE-03/05) — and, from `alpha.33`, the red count of what is waiting.
 *
 * The count is WordPress's own bubble MARKUP, because core places it in the
 * expanded menu, in the collapsed one and in the responsive drawer, in the
 * submenu and on the top-level item. A hand-rolled span would have to chase
 * all four and would be wrong in the one state nobody opens while building it.
 *
 * **The colour is ours, and it has to be.** Measured on WordPress 6.8:
 * `.update-plugins` is `rgb(56, 88, 233)` — blue, not the red core used to
 * paint it — and `#adminmenu .current .update-plugins` is `rgb(12, 12, 12)`,
 * so the bubble turns near-black on exactly the screens this plugin owns. The
 * owner asked for «نشان اعلان قرمز», so `tmc-count` carries the red, in both
 * states, printed inline in `admin_head` because the admin MENU is on every
 * screen and the plugin stylesheet is not.
 *
 * Two rules it keeps:
 *
 *  - **It is derived, never stored.** The number is a query taken while the
 *    menu is built, so opening the page cannot clear it and a decision cannot
 *    leave it behind. There is nothing to mark as read, because there is no
 *    mark.
 *  - **It is never asked for a user who cannot see the page.** The capability
 *    is checked before the callable runs, so a subscriber landing in wp-admin
 *    costs no query at all.
 */
final class MenuRegistrar
{
    /** The one class of ours on the bubble, and the only thing the rule hooks. */
    public const BUBBLE_CLASS = 'tmc-count';

    /**
     * The red, written once.
     *
     * `#D63638` on white text measures 4.73:1 — above the 4.5:1 a 12px bold
     * number needs (`tools/contrast.php` checks it with the brand colours).
     */
    public const BUBBLE_RED = '#D63638';

    /** @var list<string> hook suffixes returned by WordPress; assets load only on these */
    private array $hookSuffixes = [];

    /**
     * Was a bubble actually drawn on this request?
     *
     * The style is printed only then: an admin who cannot review products, or
     * a queue that is empty, costs nothing at all — not even 200 bytes in the
     * head of every screen.
     */
    private bool $drewBubble = false;

    public function __construct(private ContainerInterface $container)
    {
    }

    public function register(): void
    {
        $dashboard = new DashboardPage($this->container);
        $health = new HealthPage($this->container);
        $settings = new SettingsPage($this->container);
        $modules = new ModulesPage($this->container);

        // Counted once and used twice: the top-level item and the page it
        // belongs to must not be able to disagree.
        $waiting = $this->waiting();

        $hook = add_menu_page(
            __('بازارگاه تک‌طب', 'tecteb-marketplace-core'),
            __('بازارگاه تک‌طب', 'tecteb-marketplace-core') . $this->bubble(array_sum($waiting)),
            DashboardPage::CAPABILITY,
            DashboardPage::SLUG,
            [$dashboard, 'render'],
            'dashicons-store',
            56
        );
        $this->remember($hook);

        foreach ([$dashboard, $health, $settings, $modules] as $page) {
            $hook = add_submenu_page(
                DashboardPage::SLUG,
                $page::pageTitle(),
                $page::menuLabel(),
                $page::CAPABILITY,
                $page::SLUG,
                [$page, 'render']
            );
            $this->remember($hook);
        }

        // Pages other modules contribute (vendor review, document types).
        // They register HERE so their hook suffix lands in hookSuffixes() and
        // the stylesheet still loads on plugin screens only.
        foreach (AdminExtensions::pages() as $extra) {
            $hook = add_submenu_page(
                DashboardPage::SLUG,
                $extra['page_title'],
                $extra['menu_label'] . $this->bubble($waiting[$extra['slug']] ?? 0),
                $extra['capability'],
                $extra['slug'],
                $extra['render']
            );
            $this->remember($hook);
        }

        if ($this->drewBubble) {
            add_action('admin_head', [self::class, 'printBubbleStyle']);
        }
    }

    /**
     * The red, and nothing else.
     *
     * Inline and tiny rather than a stylesheet: this rule belongs to the admin
     * MENU, which renders on every wp-admin screen, and enqueuing the plugin's
     * stylesheet everywhere to paint one number would put the whole file on
     * pages it has no business on. Printed only on requests that drew a bubble.
     *
     * `!important` on the current-menu rule: core's own
     * `#adminmenu li.current a .update-plugins` is more specific than anything
     * that can be written without mirroring its whole selector, and mirroring
     * it is a rule that breaks the day core edits its own.
     */
    public static function printBubbleStyle(): void
    {
        printf(
            '<style id="tmc-menu-count">#adminmenu .%1$s,#adminmenu .current .%1$s,'
                . '#adminmenu a:focus .%1$s,#adminmenu .wp-has-current-submenu .%1$s'
                . '{background:%2$s !important;color:#fff !important;}</style>' . "\n",
            esc_attr(self::BUBBLE_CLASS),
            esc_attr(self::BUBBLE_RED)
        );
    }

    /**
     * What each page says is waiting for somebody.
     *
     * @return array<string,int> slug => count, only for pages this user may open
     */
    private function waiting(): array
    {
        $counts = [];
        foreach (AdminExtensions::pages() as $extra) {
            if ($extra['bubble'] === null || !current_user_can($extra['capability'])) {
                continue;
            }
            try {
                $count = (int) ($extra['bubble'])();
            } catch (\Throwable) {
                // A decoration on the menu is never worth the admin. The menu
                // is built on EVERY wp-admin request, including the ones where
                // this plugin's tables are mid-migration or the database is
                // answering nothing — and on those the whole back office would
                // go down for a red number. No bubble is drawn, which is not a
                // claim that nothing is waiting: the page itself still counts,
                // and says so in words.
                continue;
            }
            if ($count > 0) {
                $counts[$extra['slug']] = $count;
            }
        }
        return $counts;
    }

    /**
     * WordPress's own red bubble, or nothing at all.
     *
     * Zero prints nothing — «۰ در انتظار بررسی» is a thing to stop reading —
     * and the digits are Persian for the eye while the `count-N` class keeps
     * the Latin number core's CSS hooks on. The screen-reader sentence is
     * separate because the bubble alone reads as a bare number.
     */
    private function bubble(int $count): string
    {
        if ($count <= 0) {
            return '';
        }
        $this->drewBubble = true;
        return sprintf(
            // Core's classes for the POSITION — they are what puts the bubble in
            // the collapsed menu and the responsive drawer — plus one class of
            // ours for the colour, which `printBubbleStyle()` is the rule for.
            ' <span class="update-plugins count-%1$d %4$s"><span class="update-count">%2$s</span>'
                . '<span class="screen-reader-text">%3$s</span></span>',
            $count,
            esc_html(PersianDigits::toPersian((string) $count)),
            esc_html(sprintf(
                /* translators: %s: how many products are waiting for a decision */
                __('%s مورد در انتظار بررسی', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $count)
            )),
            self::BUBBLE_CLASS
        );
    }

    private function remember(mixed $hook): void
    {
        if (is_string($hook) && $hook !== '' && !in_array($hook, $this->hookSuffixes, true)) {
            $this->hookSuffixes[] = $hook;
        }
    }

    /** @return list<string> */
    public function hookSuffixes(): array
    {
        return $this->hookSuffixes;
    }
}
