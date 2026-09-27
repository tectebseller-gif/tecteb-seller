<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation;

use Tecteb\Marketplace\Modules\Health\Application\HealthStatus;

/**
 * Small server-rendered components (UX §24). Every method returns escaped HTML.
 * Colour is never the only carrier of meaning: each status has text + icon.
 */
final class Components
{
    /**
     * The page shell: who we are, which group, which page.
     *
     * **Three rows, because twenty-two links in one row is not a menu.** Until
     * `alpha.35` this rendered every screen the plugin registers as a flat list
     * of pills — «همهٔ گزینه‌ها یک‌جا باز» — so the four things somebody opens
     * every day sat between a migration tool and an event log, and on a phone
     * the list was the page. Now: the name and the build badge; then the five
     * groups; then only the ACTIVE group's pages.
     *
     * **The active group is derived from the current slug, and nothing is
     * stored.** A direct link to `tmc-audit` opens with «تنظیمات و ابزارها»
     * already selected, because `AdminNavigation::groups()` is asked which group
     * owns that slug. There is no state, no cookie and no query parameter to
     * get out of step with the page actually on screen.
     *
     * **Every group is a real link to a real page.** A group is not a screen and
     * this round adds none, so «فروش و مالی» goes to the first page in it. That
     * is what makes the whole thing work with no JavaScript at all: clicking a
     * group navigates, the new page derives its own group, and the second row
     * is that group's pages. No script decides what is visible.
     *
     * It is still a real `<details>`, which is the native disclosure: keyboard
     * operable, announced as expandable, and collapsed WITHOUT any script.
     * A `<button aria-expanded>` would need JavaScript to do anything at all.
     *
     * Wide screens get it open, and that IS a script — measured, not assumed:
     * in Chromium 141 neither `details:not([open]) > * { display: block }` nor
     * `display: contents` on the details makes a closed panel visible, because
     * the UA hides the content through its own shadow slot. So `tmc-admin.js`
     * sets `open` when the shell is wide. With scripts off the menu is
     * collapsed on desktop too — one click away, never missing.
     */
    public static function shellOpen(string $title, string $current, string $badge = ''): string
    {
        $groups = AdminNavigation::groups(Navigation::items(), $current);

        $groupRow = '';
        $pageRow = '';
        $currentGroupLabel = '';
        $currentLabel = '';
        foreach ($groups as $group) {
            $groupRow .= '<li><a class="tmc-nav__group' . ($group['current'] ? ' is-current' : '') . '"'
                . ' href="' . esc_url($group['url']) . '"'
                // `true`, not `page`: this says «the current item in this set of
                // groups». The page link below is the one that is the page, and
                // two `aria-current="page"` in one header would be two answers
                // to one question.
                . ($group['current'] ? ' aria-current="true"' : '') . '>'
                . esc_html($group['label']) . '</a></li>';
            if (!$group['current']) {
                continue;
            }
            $currentGroupLabel = (string) $group['label'];
            foreach ($group['items'] as $item) {
                if ($item['current']) {
                    $currentLabel = (string) $item['label'];
                }
                $pageRow .= '<li><a class="tmc-nav__link' . ($item['current'] ? ' is-current' : '') . '"'
                    . ' href="' . esc_url($item['url']) . '"'
                    . ($item['current'] ? ' aria-current="page"' : '') . '>'
                    . esc_html($item['label']) . '</a></li>';
            }
        }

        $navLabel = __('بخش‌های بازارگاه', 'tecteb-marketplace-core');
        $groupsLabel = __('گروه‌های بازارگاه', 'tecteb-marketplace-core');
        $pagesLabel = $currentGroupLabel !== ''
            ? sprintf(
                /* translators: %s: the open group's name */
                __('صفحه‌های گروه %s', 'tecteb-marketplace-core'),
                $currentGroupLabel
            )
            : $navLabel;
        // Where you are, on the closed toggle: a collapsed menu that does not
        // say so trades one problem for another.
        $here = trim($currentGroupLabel . ($currentLabel !== '' ? ' › ' . $currentLabel : ''));

        // **Row one carries the build, on every page.** `$badge` is passed by
        // most callers and was left out by four — the Operations screens — so
        // «کدام نسخه را باز کرده‌ام» depended on which page you were on. The
        // channel word is the default rather than a required argument, and the
        // version number beside it comes from the plugin header, so neither can
        // be forgotten by a page added later.
        $channel = $badge !== '' ? $badge : __('نسخه آزمایشی', 'tecteb-marketplace-core');
        $version = defined('TMC_PLUGIN_VERSION') ? (string) constant('TMC_PLUGIN_VERSION') : '';

        return '<div class="wrap tmc-admin" dir="rtl" lang="fa">'
            . '<a class="tmc-skip" href="#tmc-main">' . esc_html__('پرش به محتوای اصلی', 'tecteb-marketplace-core') . '</a>'
            . '<header class="tmc-header">'
            . '<div class="tmc-header__top">'
            . '<div class="tmc-header__brand"><span class="tmc-header__product">' . esc_html__('بازارگاه تک‌طب', 'tecteb-marketplace-core') . '</span>'
            . ' <span class="tmc-badge tmc-badge--alpha">' . esc_html($channel) . '</span>'
            // The version is left in Latin digits on purpose: it is an
            // identifier somebody copies into a bug report, not a number they
            // read aloud, and «۰٫۱٫۰-alpha.۳۶» is not a string anybody can search.
            . ($version !== ''
                ? ' <span class="tmc-badge tmc-badge--version" dir="ltr">' . esc_html($version) . '</span>'
                : '')
            . '</div>'
            . '</div>'
            . '<details class="tmc-nav" id="tmc-nav">'
            . '<summary class="tmc-nav__toggle">'
            . '<span class="tmc-nav__toggle-icon" aria-hidden="true"></span>'
            . '<span class="tmc-nav__toggle-text">' . esc_html($navLabel) . '</span>'
            . ($here !== '' ? '<span class="tmc-nav__toggle-current">' . esc_html($here) . '</span>' : '')
            // The open/closed word, for the eye only: the browser already
            // announces a `<summary>` as collapsed or expanded, and a second
            // copy in the accessibility tree would be read twice.
            . '<span class="tmc-nav__state" aria-hidden="true">'
            . '<span class="tmc-nav__state-closed">' . esc_html__('نمایش', 'tecteb-marketplace-core') . '</span>'
            . '<span class="tmc-nav__state-open">' . esc_html__('بستن', 'tecteb-marketplace-core') . '</span>'
            . '</span>'
            . '</summary>'
            . '<div class="tmc-nav__panel">'
            . '<nav class="tmc-nav__groups" aria-label="' . esc_attr($groupsLabel) . '"><ul>' . $groupRow . '</ul></nav>'
            . '<nav class="tmc-nav__pages" aria-label="' . esc_attr($pagesLabel) . '"><ul>' . $pageRow . '</ul></nav>'
            . '</div>'
            . '</details>'
            . '</header>'
            . '<main id="tmc-main" class="tmc-main" tabindex="-1">'
            . '<h1 class="tmc-title">' . esc_html($title) . '</h1>';
    }

    public static function shellClose(): string
    {
        return '</main>'
            . '<footer class="tmc-footer"><p>'
            . esc_html__('تمام ارسال‌های خود افزونه در نسخه آزمایشی مسدود است. وضعیت سایر افزونه‌ها بررسی‌نشده است.', 'tecteb-marketplace-core')
            . '</p></footer></div>';
    }

    /** @param 'info'|'success'|'warning'|'error' $type */
    public static function notice(string $type, string $text, bool $live = false): string
    {
        $icon = match ($type) {
            'success' => '✓',
            'warning' => '!',
            'error' => '✕',
            default => 'i',
        };
        $role = $type === 'error' ? 'alert' : 'status';
        return '<div class="tmc-notice tmc-notice--' . esc_attr($type) . '" role="' . $role . '"' . ($live ? ' aria-live="polite"' : '') . '>'
            . '<span class="tmc-notice__icon" aria-hidden="true">' . $icon . '</span>'
            . '<p class="tmc-notice__text">' . esc_html($text) . '</p></div>';
    }

    public static function healthBadge(HealthStatus $status): string
    {
        [$class, $icon] = match ($status) {
            HealthStatus::Healthy => ['success', '✓'],
            HealthStatus::ActionRequired => ['warning', '!'],
            HealthStatus::Unknown => ['neutral', '?'],
        };
        return self::badge($class, $icon, Messages::healthStatus($status));
    }

    public static function badge(string $class, string $icon, string $text): string
    {
        return '<span class="tmc-status tmc-status--' . esc_attr($class) . '">'
            . '<span class="tmc-status__icon" aria-hidden="true">' . esc_html($icon) . '</span>'
            . '<span class="tmc-status__text">' . esc_html($text) . '</span></span>';
    }

    /**
     * The five states a screen can be in other than «here is your data»:
     * empty, error, loading, success and no-access.
     *
     * One component because they are one problem. A screen that renders
     * «چیزی یافت نشد» and stops has told the reader something true and
     * useless: they still do not know whether the data is missing, filtered
     * away, still arriving, or simply not theirs to see — and they do not know
     * what to press. So every state here carries a title, a sentence of
     * explanation, and the one action that makes sense from it.
     *
     * `loading` is the odd one: it renders skeleton bars where the rows will
     * be, so the page does not jump when they arrive. It is used by views that
     * render before their data (a queued report), not as a spinner — this
     * plugin's pages are server-rendered and mostly have their data already.
     *
     * @param 'empty'|'error'|'loading'|'success'|'denied' $kind
     * @param list<array{href:string, label:string, primary?:bool}> $actions
     */
    public static function state(string $kind, string $title, string $text = '', array $actions = []): string
    {
        $icon = match ($kind) {
            'error' => '✕',
            'success' => '✓',
            'denied' => '⌧',
            'loading' => '…',
            default => '∅',
        };
        // An error and a refusal are announced; an empty list is not. A page
        // that shouts «هیچ سفارشی نیست» at a screen reader on every visit is
        // noise, and noise is what gets switched off.
        $role = match ($kind) {
            'error' => ' role="alert"',
            'denied' => ' role="status"',
            default => '',
        };

        $html = '<div class="tmc-state tmc-state--' . esc_attr($kind) . '"' . $role . '>';
        if ($kind === 'loading') {
            $html .= '<p class="tmc-state__title">' . esc_html($title) . '</p>'
                . '<span class="tmc-skeleton"></span><span class="tmc-skeleton"></span><span class="tmc-skeleton"></span>'
                . '<span class="screen-reader-text">' . esc_html__('در حال بارگذاری', 'tecteb-marketplace-core') . '</span>'
                . '</div>';
            return $html;
        }

        $html .= '<span class="tmc-state__icon" aria-hidden="true">' . $icon . '</span>'
            . '<p class="tmc-state__title">' . esc_html($title) . '</p>';
        if ($text !== '') {
            $html .= '<p class="tmc-state__text">' . esc_html($text) . '</p>';
        }
        if ($actions !== []) {
            $html .= '<p class="tmc-state__actions">';
            foreach ($actions as $action) {
                $html .= '<a class="tmc-button' . (!empty($action['primary']) ? ' tmc-button--primary' : ' tmc-button--ghost') . '"'
                    . ' href="' . esc_url($action['href']) . '">' . esc_html($action['label']) . '</a>';
            }
            $html .= '</p>';
        }
        return $html . '</div>';
    }

    /** @param list<array{label:string, value:string, raw?:bool}> $rows */
    public static function dataList(array $rows): string
    {
        $html = '<dl class="tmc-datalist">';
        foreach ($rows as $row) {
            $value = !empty($row['raw']) ? $row['value'] : esc_html($row['value']);
            $html .= '<div class="tmc-datalist__row"><dt>' . esc_html($row['label']) . '</dt><dd>' . $value . '</dd></div>';
        }
        return $html . '</dl>';
    }

    /** @param list<array{href:string, message:string}> $errors */
    public static function errorSummary(array $errors): string
    {
        if ($errors === []) {
            return '';
        }
        $html = '<div class="tmc-error-summary" role="alert" tabindex="-1" id="tmc-error-summary" aria-labelledby="tmc-error-summary-title">'
            . '<h2 id="tmc-error-summary-title" class="tmc-error-summary__title">' . esc_html__('برخی مقادیر ذخیره نشدند', 'tecteb-marketplace-core') . '</h2><ul>';
        foreach ($errors as $e) {
            $html .= '<li><a href="' . esc_url($e['href']) . '">' . esc_html($e['message']) . '</a></li>';
        }
        return $html . '</ul></div>';
    }

    public static function bdi(string $text): string
    {
        return '<bdi dir="ltr">' . esc_html($text) . '</bdi>';
    }

    /**
     * A Latin identifier or version as a self-contained chip.
     *
     * `bdi` isolates the direction so an id never reorders the Persian
     * sentence around it; the class makes it one unbreakable token that
     * scrolls inside its own box. Both matter: on staging these strings were
     * laid out one character per line inside a starved grid column.
     */
    public static function code(string $text): string
    {
        return '<bdi class="tmc-code" dir="ltr">' . esc_html($text) . '</bdi>';
    }

    /** @param list<string> $items */
    public static function codes(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $html = '<ul class="tmc-codes">';
        foreach ($items as $item) {
            $html .= '<li>' . self::code($item) . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * Technical facts, collapsed. The page is read by a shop manager: ids,
     * versions and dependency lists are true but not what they came for, so
     * they are one click away instead of filling the card.
     *
     * @param list<array{label:string, value:string, raw?:bool}> $rows
     */
    public static function techDetails(array $rows, string $summary = ''): string
    {
        if ($rows === []) {
            return '';
        }
        $summary = $summary !== '' ? $summary : __('جزئیات فنی', 'tecteb-marketplace-core');
        return '<details class="tmc-tech"><summary>' . esc_html($summary) . '</summary>'
            . self::dataList($rows) . '</details>';
    }
}
