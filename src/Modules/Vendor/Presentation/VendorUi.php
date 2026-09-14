<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

/**
 * The handful of HTML pieces the vendor area repeats. Deliberately its own
 * copy rather than a reach into the admin module: the two areas share a
 * design language, not a dependency.
 *
 * Every method escapes its arguments; callers pass text, never markup.
 */
final class VendorUi
{
    /** @param 'success'|'warning'|'error'|'neutral' $tone */
    public static function chip(string $tone, string $text): string
    {
        $icon = match ($tone) {
            'success' => '✓',
            'warning' => '!',
            'error' => '✕',
            default => '…',
        };
        return '<span class="tv-chip tv-chip--' . esc_attr($tone) . '">'
            . '<span class="tv-chip__icon" aria-hidden="true">' . esc_html($icon) . '</span>'
            . '<span>' . esc_html($text) . '</span></span>';
    }

    /** @param 'success'|'warning'|'error'|'info' $tone */
    public static function notice(string $tone, string $text): string
    {
        $role = $tone === 'error' ? 'alert' : 'status';
        return '<div class="tv-notice tv-notice--' . esc_attr($tone) . '" role="' . $role . '">'
            . '<p>' . esc_html($text) . '</p></div>';
    }

    public static function button(string $href, string $label, string $variant = 'primary'): string
    {
        return '<a class="tv-btn tv-btn--' . esc_attr($variant) . '" href="' . esc_url($href) . '">' . esc_html($label) . '</a>';
    }

    public static function submit(string $label, string $variant = 'primary'): string
    {
        return '<button type="submit" class="tv-btn tv-btn--' . esc_attr($variant) . '">' . esc_html($label) . '</button>';
    }

    /**
     * One labelled input. Shared by every vendor form so a field looks and
     * behaves the same wherever it appears — including `dir`, which decides
     * whether an IBAN or an email reads correctly on an RTL page.
     */
    public static function input(
        string $name,
        string $label,
        string $value,
        bool $editable = true,
        string $type = 'text',
        string $dir = 'rtl',
        string $hint = ''
    ): string {
        $id = 'f-' . $name;
        return '<div class="tv-field">'
            . '<label class="tv-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<input class="tv-input" type="' . esc_attr($type) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"'
            . ' value="' . esc_attr($value) . '" dir="' . esc_attr($dir) . '"' . ($editable ? '' : ' readonly') . '>'
            . ($hint !== '' ? '<p class="tv-hint">' . esc_html($hint) . '</p>' : '')
            . '</div>';
    }

    public static function textarea(string $name, string $label, string $value, bool $editable = true, string $hint = ''): string
    {
        $id = 'f-' . $name;
        return '<div class="tv-field">'
            . '<label class="tv-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<textarea class="tv-input" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="3"'
            . ($editable ? '' : ' readonly') . '>' . esc_textarea($value) . '</textarea>'
            . ($hint !== '' ? '<p class="tv-hint">' . esc_html($hint) . '</p>' : '')
            . '</div>';
    }

    /** @param array<string,string> $options value => label */
    public static function select(string $name, string $label, array $options, string $selected, string $hint = ''): string
    {
        $id = 'f-' . $name;
        $html = '<div class="tv-field"><label class="tv-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<select class="tv-input" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
        foreach ($options as $value => $text) {
            $html .= '<option value="' . esc_attr((string) $value) . '"' . selected($selected, (string) $value, false) . '>'
                . esc_html($text) . '</option>';
        }
        return $html . '</select>'
            . ($hint !== '' ? '<p class="tv-hint">' . esc_html($hint) . '</p>' : '')
            . '</div>';
    }

    public static function checkbox(string $name, string $label, bool $checked, string $value = '1'): string
    {
        return '<div class="tv-field tv-field--check"><label>'
            . '<input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . checked($checked, true, false) . '> '
            . esc_html($label) . '</label></div>';
    }

    /**
     * Tabs as links, not JavaScript: each one is its own URL, so a half-filled
     * banking form cannot be lost by clicking «ارسال» and every tab is
     * reachable with a keyboard and bookmarkable.
     *
     * @param array<string,string> $tabs slug => label
     */
    public static function tabs(array $tabs, string $current, string $baseUrl): string
    {
        $html = '<nav class="tv-tabs" aria-label="' . esc_attr__('بخش‌های تنظیمات', 'tecteb-marketplace-core') . '"><ul>';
        foreach ($tabs as $slug => $label) {
            $isCurrent = $slug === $current;
            $html .= '<li><a class="tv-tab' . ($isCurrent ? ' is-current' : '') . '"'
                . ($isCurrent ? ' aria-current="page"' : '')
                . ' href="' . esc_url(add_query_arg('tab', $slug, $baseUrl)) . '">' . esc_html($label) . '</a></li>';
        }
        return $html . '</ul></nav>';
    }
}
