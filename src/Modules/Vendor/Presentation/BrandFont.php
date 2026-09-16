<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

/**
 * The one `@font-face` this plugin declares, and the one font stack it uses.
 *
 * It lives here because two places need it — the vendor area's stylesheet and
 * the public store page's inline CSS — and a font declared twice is a font
 * that eventually differs between the two screens. Before this, both named
 * «Vazirmatn» in their stack and neither shipped it: the identity was a
 * preference for a font most visitors did not have, so the pages actually
 * rendered in whatever the browser fell back to, which on Windows is Tahoma
 * and on a phone is something else again.
 *
 * `font-display: swap` on purpose. A Persian page whose text is invisible
 * until a 109 KB download finishes is worse than one that reflows: the reader
 * can start reading immediately, and the swap is one repaint.
 *
 * `local()` first, so a visitor who already has Vazirmatn installed — many
 * Iranian desktops do — spends nothing at all.
 */
final class BrandFont
{
    public const FAMILY = 'Vazirmatn';

    /** Relative to the plugin root; the caller turns it into a URL. */
    public const FILE = 'assets/fonts/vazirmatn-variable.woff2';

    /**
     * The stack, in one string.
     *
     * Ends in `system-ui` rather than a specific fallback because the thing
     * after Vazirmatn should be whatever the reader's own system considers
     * readable, not a guess made here.
     */
    public const STACK = '"Vazirmatn", "Vazir", "IRANSans", "IRANYekan", "Segoe UI", Tahoma, "Noto Naskh Arabic", system-ui, sans-serif';

    /**
     * The declaration, given a URL for the font file.
     *
     * `font-weight: 100 900` is what makes one file cover the whole range —
     * this is the variable build, so a bold heading and a light caption come
     * from the same 109 KB rather than from seven separate downloads.
     */
    public static function faceRule(string $fontUrl): string
    {
        return '@font-face{font-family:"' . self::FAMILY . '";'
            . 'src:local("Vazirmatn"),url("' . $fontUrl . '") format("woff2-variations"),'
            . 'url("' . $fontUrl . '") format("woff2");'
            . 'font-weight:100 900;font-style:normal;font-display:swap;}';
    }
}
