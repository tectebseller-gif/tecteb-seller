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
 *  - **The number is asked for, never kept here.** `waiting()` calls each page's
 *    own callable while the menu is built, and this class stores nothing about
 *    what it was told. From `alpha.36` the review page's answer is «how many
 *    submissions has THIS manager not seen» rather than «how big is the queue» —
 *    a per-person view state that `ReviewSeen` owns and `DbReviewSeenStore`
 *    keeps in `tmc_review_seen`. Building the menu still writes nothing: the
 *    count is a question, and asking it is not reading. Only the review LIST
 *    and a product's own page record a view, because they are the only two
 *    screens that show the manager the submission.
 *  - **It is never asked for a user who cannot see the page.** The capability
 *    is checked before the callable runs, so a subscriber landing in wp-admin
 *    costs no query at all.
 *
 * ### The number on the page that lowered it (`alpha.37`)
 *
 * `alpha.36` built the bubble on `admin_menu` and recorded the view inside the
 * render callback, so the one screen that cleared the notification was the one
 * screen that still showed the old number: the manager opened «بررسی محصولات»,
 * read everything, and the menu beside them still said ۱۴ until they clicked
 * somewhere else. That is not a caching problem, it is an ORDER problem, and
 * WordPress's own order is what fixes it. In `wp-admin/admin.php`:
 *
 * ```
 * 163  require ABSPATH . 'wp-admin/menu.php';     // `admin_menu` → register()
 * 242  do_action( "load-{$page_hook}" );          // ← prepare() + refreshBubbles()
 * 244  require_once ABSPATH . 'wp-admin/admin-header.php';   // prints the bubble
 * 264  do_action( $page_hook );                   // render()
 * ```
 *
 * `wp-admin/menu-header.php` reads `global $menu, $submenu` at PRINT time, so a
 * page built on `load-{$hook}` — which is where `prepare` runs — can record the
 * view and then have this class repaint those two arrays before the header is
 * even required. Measured on the WordPress this is tested against (7.1).
 *
 * Three things follow from doing it this way rather than with a script:
 *
 *  - **No JavaScript at all.** The badge is correct in the HTML that is sent,
 *    so the no-JS path and the JS path are byte-identical and there is nothing
 *    to test separately. A number patched in the browser would have been a
 *    second source of truth for a figure that must agree with the database.
 *  - **The number is re-asked, never subtracted.** `refreshBubbles()` calls
 *    `waiting()` again from scratch. Subtracting «the rows I just marked» would
 *    have been a guess about the database, and the guess fails in exactly the
 *    case that matters: a `markSeen()` that did not write would still lower the
 *    badge, which is the false success the owner asked not to see. It is also
 *    what keeps a sibling notification alive — the parent is the SUM of every
 *    page's own count, so re-summing cannot drop another module's number.
 *  - **Zero removes the bubble**, because `bubble(0)` is the empty string and
 *    the label is rebuilt from the stored plain label rather than patched.
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

    /** Was `printBubbleStyle()` hooked already? The style is printed once. */
    private bool $styleHooked = false;

    /**
     * The menu labels WITHOUT a bubble, by slug, plus the parent's under ''.
     *
     * Kept so `refreshBubbles()` rebuilds a label instead of editing one.
     * Stripping the bubble back out of a rendered label would mean parsing our
     * own markup, and a label that is rebuilt cannot accumulate two bubbles.
     *
     * @var array<string,string>
     */
    private array $labels = [];

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

        $this->labels[''] = __('بازارگاه تک‌طب', 'tecteb-marketplace-core');
        $hook = add_menu_page(
            __('بازارگاه تک‌طب', 'tecteb-marketplace-core'),
            $this->labels[''] . $this->bubble(self::total($waiting)),
            DashboardPage::CAPABILITY,
            DashboardPage::SLUG,
            [$dashboard, 'render'],
            'dashicons-store',
            56
        );
        $this->remember($hook);

        // One list, in ONE order: this module's four pages and every page the
        // other modules contribute, sorted by where `AdminNavigation` puts
        // them. Until `alpha.35` the four came first and the rest followed in
        // whatever order the modules happened to register, so «راه‌اندازی» sat
        // above «بررسی محصولات» and the sidebar disagreed with the header about
        // what matters. The order now comes from the same definition the header
        // reads, which is what stops the two drifting again.
        //
        // A page no group claims lands at the end (`position()` answers
        // PHP_INT_MAX) rather than disappearing — `usort` is not stable across
        // equal keys, so pages within a group carry their index too.
        $rows = [];
        foreach ([$dashboard, $health, $settings, $modules] as $page) {
            $rows[] = [
                'slug' => $page::SLUG,
                'page_title' => $page::pageTitle(),
                'menu_label' => $page::menuLabel(),
                'capability' => $page::CAPABILITY,
                'render' => [$page, 'render'],
            ];
        }
        // Pages other modules contribute (product review, document types…).
        // They register HERE so their hook suffix lands in hookSuffixes() and
        // the stylesheet still loads on plugin screens only.
        foreach (AdminExtensions::pages() as $extra) {
            $rows[] = [
                'slug' => $extra['slug'],
                'page_title' => $extra['page_title'],
                'menu_label' => $extra['menu_label'],
                'capability' => $extra['capability'],
                'render' => $extra['render'],
                'prepare' => $extra['prepare'],
            ];
        }
        $ordered = [];
        foreach ($rows as $index => $row) {
            $ordered[] = [AdminNavigation::position((string) $row['slug']), $index, $row];
        }
        usort($ordered, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($ordered as [, , $row]) {
            $slug = (string) $row['slug'];
            $this->labels[$slug] = (string) $row['menu_label'];
            $hook = add_submenu_page(
                DashboardPage::SLUG,
                (string) $row['page_title'],
                $this->labels[$slug] . $this->bubble($waiting[$slug] ?? 0),
                (string) $row['capability'],
                $slug,
                $row['render']
            );
            $this->remember($hook);
            $prepare = $row['prepare'] ?? null;
            if (is_string($hook) && $hook !== '' && is_callable($prepare)) {
                // Build the page, then repaint. Priority 99 so anything else
                // this page hooks onto its own `load-` has already run: the
                // number must be the LAST thing decided before the header
                // prints it.
                add_action('load-' . $hook, $prepare, 10);
                add_action('load-' . $hook, [$this, 'refreshBubbles'], 99);
            }
        }

        $this->hookStyle();
    }

    /**
     * Re-ask every page what is waiting, and repaint the menu WordPress will
     * print in a moment.
     *
     * Public because it is a hook callback, and a no-op on any request where
     * core has not built the globals (an AJAX screen, a unit test) — which is
     * also why it reads them defensively rather than typing them: `$menu` rows
     * are core's own seven-element arrays and another plugin may have replaced
     * one with something else entirely.
     */
    public function refreshBubbles(): void
    {
        $waiting = $this->waiting();
        if (isset($GLOBALS['menu']) && is_array($GLOBALS['menu'])) {
            $this->repaint($GLOBALS['menu'], ['' => self::total($waiting)], DashboardPage::SLUG);
        }
        $submenu = $GLOBALS['submenu'][DashboardPage::SLUG] ?? null;
        if (is_array($submenu)) {
            $this->repaint($GLOBALS['submenu'][DashboardPage::SLUG], $waiting, null);
        }
        // A bubble can appear here that did not exist when the menu was built
        // — another page's count rising mid-request — so the style is hooked
        // from both places. It is printed in `admin_head`, which
        // `admin-header.php` fires AFTER this runs and before the menu.
        $this->hookStyle();
    }

    /**
     * Rewrite the menu label of every row whose slug we know.
     *
     * A row whose count is `null` — the page could not say — is LEFT AS IT IS,
     * bubble and all. That is the difference between «you have read everything»
     * and «we do not know», and printing the first for the second is the
     * false success this round exists to remove.
     *
     * @param array<int|string,mixed> $rows core's `$menu` or `$submenu[parent]`
     * @param array<string,?int> $counts    slug => count ('' for the parent)
     * @param ?string $parentSlug           the slug the '' count belongs to
     */
    private function repaint(array &$rows, array $counts, ?string $parentSlug): void
    {
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !isset($row[0], $row[2])) {
                continue;
            }
            $slug = (string) $row[2];
            $key = $parentSlug !== null && $slug === $parentSlug ? '' : $slug;
            if (!array_key_exists($key, $this->labels) || !array_key_exists($key, $counts)) {
                continue;
            }
            if ($counts[$key] === null) {
                continue;
            }
            $rows[$index][0] = $this->labels[$key] . $this->bubble($counts[$key]);
        }
    }

    /** Hook the one inline rule, at most once, and only if a bubble was drawn. */
    private function hookStyle(): void
    {
        if ($this->drewBubble && !$this->styleHooked) {
            $this->styleHooked = true;
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
     * **`null` is «could not be read», and it is not nought.** This is the
     * distinction the repaint turns on: a number that failed must leave the
     * label alone, because overwriting it with a zero is a screen telling a
     * manager they have read everything when nobody knows. Every count is
     * re-asked here on each call, so «it went down» is always the database's
     * answer and never arithmetic of ours.
     *
     * @return array<string,?int> slug => count, or null; only for pages this
     *         user may open
     */
    private function waiting(): array
    {
        $counts = [];
        foreach (AdminExtensions::pages() as $extra) {
            if ($extra['bubble'] === null || !current_user_can($extra['capability'])) {
                continue;
            }
            try {
                $counts[$extra['slug']] = (int) ($extra['bubble'])();
            } catch (\Throwable) {
                // A decoration on the menu is never worth the admin. The menu
                // is built on EVERY wp-admin request, including the ones where
                // this plugin's tables are mid-migration or the database is
                // answering nothing — and on those the whole back office would
                // go down for a red number. No bubble is drawn, which is not a
                // claim that nothing is waiting: the page itself still counts,
                // and says so in words.
                $counts[$extra['slug']] = null;
            }
        }
        return $counts;
    }

    /**
     * The parent's number: the sum of every page's own, or null if any of them
     * is unknown.
     *
     * A total that silently left out the page it could not read would be a
     * smaller number presented as a complete one.
     *
     * @param array<string,?int> $counts
     */
    private static function total(array $counts): ?int
    {
        $sum = 0;
        foreach ($counts as $count) {
            if ($count === null) {
                return null;
            }
            $sum += $count;
        }
        return $sum;
    }

    /**
     * WordPress's own red bubble, or nothing at all.
     *
     * Zero prints nothing — «۰ در انتظار بررسی» is a thing to stop reading —
     * and the digits are Persian for the eye while the `count-N` class keeps
     * the Latin number core's CSS hooks on. The screen-reader sentence is
     * separate because the bubble alone reads as a bare number.
     */
    private function bubble(?int $count): string
    {
        if ($count === null || $count <= 0) {
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
