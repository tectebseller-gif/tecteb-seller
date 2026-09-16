<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;

/**
 * «نمودار روی گزارش‌ها» — built on the server out of HTML and CSS, with no
 * library, no script and no SVG.
 *
 * Master A.6 says the dashboard is server-rendered, «بدون SPA یا CDN سنگین». A
 * chart library would have been a script tag, a CDN and a render that happens
 * after the page; a chart that is already in the HTML prints, works with
 * JavaScript off, and cannot be a third-party request.
 *
 * **It was SVG first, and the accessibility suite rejected it.** An inline SVG
 * scales to its box, and everything inside scales with it — including the
 * text. Measured on a 320px viewport: the category labels rendered about 9
 * CSS pixels wide, roughly a 5px font, which `no-starved-text-box` caught and
 * was right to. SVG has no text wrapping and no way to keep text at the
 * reader's size while the drawing shrinks. So the drawing is a `<span>` whose
 * width is a percentage, and every word is ordinary HTML text that wraps,
 * zooms, translates and can be selected.
 *
 * **Horizontal bars, not columns.** With columns, the label sits under a
 * ~45px-wide slot on a phone and «آماده‌سازی» has nowhere to go. A row gives
 * each label a column of its own, wraps naturally, and reads right-to-left
 * without a single rotation.
 *
 * Four rules, each a real failure avoided rather than a preference:
 *
 *  1. **Colour is never the only channel** (WCAG 1.4.1). Every row carries its
 *     own label and its own printed value; remove the colour and the chart
 *     still says everything. That is also why there is no legend — a legend is
 *     the pattern that makes colour load-bearing.
 *  2. **The bar is decoration, the text is the data.** The track is
 *     `aria-hidden`, so a screen reader hears «منتشرشده ۳» and not a stray
 *     graphic. No `aria-label` overrides the real text either: an overriding
 *     label is one more string to keep in step with the numbers beside it.
 *  3. **Nothing is invented to fill the space.** A chart of nothing is a
 *     sentence saying so, not an empty axis whose baseline suggests zero.
 *     «صفر» and «هنوز چیزی نیست» are different facts — the same rule the
 *     reports themselves keep.
 *  4. **Money is never charted.** A bar is a proportion, and a balance shown
 *     as a proportion invites somebody to read «about half» off a picture. The
 *     finance card keeps its table, exact to the ریال. `NoticeMessages::chartRows()`
 *     is where that exclusion lives.
 */
final class Charts
{
    /** How many series colours exist before they repeat. */
    private const SERIES = 5;

    /**
     * One chart: a labelled row per value, longest bar at 100%.
     *
     * @param list<array{label:string, value:int}> $rows
     * @param string $empty what to say when every value is zero
     */
    public static function bars(array $rows, string $empty = '', string $cssClass = 'tv-chart'): string
    {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['label'] !== ''));
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (int) $row['value']);
        }
        if ($rows === [] || $max <= 0) {
            return '<p class="tv-hint">' . esc_html($empty !== '' ? $empty : self::nothingYet()) . '</p>';
        }

        $html = '<ul class="' . esc_attr($cssClass) . '__rows" role="list">';
        foreach ($rows as $i => $row) {
            $value = max(0, (int) $row['value']);
            $share = (int) round(($value / $max) * 100);
            // A non-zero value always gets a visible sliver, so «۱ از ۲۰۰» reads
            // as one rather than as nothing at all.
            if ($value > 0) {
                $share = max(2, $share);
            }
            $html .= '<li class="' . esc_attr($cssClass) . '__row">'
                . '<span class="' . esc_attr($cssClass) . '__label">' . esc_html($row['label']) . '</span>'
                // aria-hidden: the bar repeats what the two spans beside it
                // already say, and a screen reader reading it twice is noise.
                . '<span class="' . esc_attr($cssClass) . '__track" aria-hidden="true">'
                . '<span class="' . esc_attr($cssClass) . '__fill" data-series="' . ($i % self::SERIES) . '"'
                . ' style="inline-size:' . $share . '%"></span></span>'
                . '<span class="' . esc_attr($cssClass) . '__value">'
                . esc_html(PersianDigits::toPersian((string) $value)) . '</span>'
                . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * A whole chart with its heading and caption, ready to drop into a card.
     *
     * A `<figure>` with a `<figcaption>` rather than a bare div: the caption is
     * what says what is being counted, and it belongs to the chart rather than
     * floating near it.
     *
     * @param list<array{label:string, value:int}> $rows
     */
    public static function card(string $title, array $rows, string $caption = '', string $cssClass = 'tv-chart'): string
    {
        $html = '<figure class="' . esc_attr($cssClass) . '">'
            . '<figcaption class="' . esc_attr($cssClass) . '__title">' . esc_html($title) . '</figcaption>'
            . self::bars($rows, '', $cssClass);
        if ($caption !== '') {
            $html .= '<p class="' . esc_attr($cssClass) . '__caption">' . esc_html($caption) . '</p>';
        }
        return $html . '</figure>';
    }

    private static function nothingYet(): string
    {
        return __('هنوز عددی برای نمودار نیست. این یعنی چیزی ثبت نشده، نه اینکه همه‌چیز صفر است.', 'tecteb-marketplace-core');
    }
}
