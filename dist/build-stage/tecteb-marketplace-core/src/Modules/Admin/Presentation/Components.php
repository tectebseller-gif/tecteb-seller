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
    public static function shellOpen(string $title, string $current, string $badge = ''): string
    {
        $nav = '';
        foreach (Navigation::items() as $item) {
            $isCurrent = $item['slug'] === $current;
            $nav .= '<li><a class="tmc-nav__link' . ($isCurrent ? ' is-current' : '') . '" href="' . esc_url($item['url']) . '"'
                . ($isCurrent ? ' aria-current="page"' : '') . '>' . esc_html($item['label']) . '</a></li>';
        }
        return '<div class="wrap tmc-admin" dir="rtl" lang="fa">'
            . '<a class="tmc-skip" href="#tmc-main">' . esc_html__('پرش به محتوای اصلی', 'tecteb-marketplace-core') . '</a>'
            . '<header class="tmc-header">'
            . '<div class="tmc-header__brand"><span class="tmc-header__product">' . esc_html__('بازارگاه تک‌طب', 'tecteb-marketplace-core') . '</span>'
            . ($badge !== '' ? ' <span class="tmc-badge tmc-badge--alpha">' . esc_html($badge) . '</span>' : '')
            . '</div>'
            . '<nav class="tmc-nav" aria-label="' . esc_attr__('بخش‌های بازارگاه', 'tecteb-marketplace-core') . '"><ul>' . $nav . '</ul></nav>'
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
