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
     * The page shell, with a menu that does not eat the top of a phone.
     *
     * This marketplace now registers more than twenty manager screens. As a
     * flat list that is a wall of links above every page on a narrow viewport,
     * and the owner said so: «منوی موبایل جمع‌شونده باشد تا ابتدای صفحه را
     * اشغال نکند».
     *
     * It is a real `<details>`, which is the native disclosure: keyboard
     * operable, announced as expandable, and collapsed WITHOUT any script.
     * A `<button aria-expanded>` would need JavaScript to do anything at all,
     * and this shell's rule is that every page works with none.
     *
     * Wide screens get the menu open, and that IS a script — measured, not
     * assumed: in Chromium 141 neither `details:not([open]) > * { display:
     * block }` nor `display: contents` on the details makes a closed panel
     * visible, because the UA hides the content through its own shadow slot.
     * So `tmc-admin.js` sets `open` when the shell is wide. With scripts off
     * the menu is collapsed on desktop too — one click away, never missing.
     */
    public static function shellOpen(string $title, string $current, string $badge = ''): string
    {
        $nav = '';
        $currentLabel = '';
        foreach (Navigation::items() as $item) {
            $isCurrent = $item['slug'] === $current;
            if ($isCurrent) {
                $currentLabel = (string) $item['label'];
            }
            $nav .= '<li><a class="tmc-nav__link' . ($isCurrent ? ' is-current' : '') . '" href="' . esc_url($item['url']) . '"'
                . ($isCurrent ? ' aria-current="page"' : '') . '>' . esc_html($item['label']) . '</a></li>';
        }
        $navLabel = __('بخش‌های بازارگاه', 'tecteb-marketplace-core');
        return '<div class="wrap tmc-admin" dir="rtl" lang="fa">'
            . '<a class="tmc-skip" href="#tmc-main">' . esc_html__('پرش به محتوای اصلی', 'tecteb-marketplace-core') . '</a>'
            . '<header class="tmc-header">'
            . '<div class="tmc-header__brand"><span class="tmc-header__product">' . esc_html__('بازارگاه تک‌طب', 'tecteb-marketplace-core') . '</span>'
            . ($badge !== '' ? ' <span class="tmc-badge tmc-badge--alpha">' . esc_html($badge) . '</span>' : '')
            . '</div>'
            . '<details class="tmc-nav" id="tmc-nav">'
            . '<summary class="tmc-nav__toggle">'
            . '<span class="tmc-nav__toggle-icon" aria-hidden="true"></span>'
            . '<span class="tmc-nav__toggle-text">' . esc_html($navLabel) . '</span>'
            . ($currentLabel !== ''
                ? '<span class="tmc-nav__toggle-current">' . esc_html($currentLabel) . '</span>'
                : '')
            . '</summary>'
            . '<nav class="tmc-nav__panel" aria-label="' . esc_attr($navLabel) . '"><ul>' . $nav . '</ul></nav>'
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
