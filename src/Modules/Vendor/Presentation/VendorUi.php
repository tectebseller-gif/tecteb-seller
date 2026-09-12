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
}
