<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts\Html;

/**
 * The one place markup is made safe to store.
 *
 * `description` (migration 23) is the only vendor-supplied value in this
 * plugin that keeps its tags: the long product text is paragraphs and lists,
 * and stripping them would make the field useless. Every other field is read
 * as plain text and escaped at render time.
 *
 * That makes it the one field where «which path did this value arrive by»
 * decides whether a `<script>` reaches the storefront, and there are two
 * paths: the form and the CSV importer. The form runs inside WordPress and
 * could call `wp_kses_post()` where it stands; the importer is in the
 * Application layer, which is not allowed to call WordPress at all (the
 * `alpha.9` rule — not even `__()`). A filter that only one of the two ends
 * applies is the same asymmetry this repository has written down twice
 * before, so the rule is a dependency instead: anything that accepts markup
 * takes this interface, and a build that forgets to bind it does not start.
 *
 * Sanitising on the way IN rather than on the way out is deliberate. The
 * approval baseline compares the stored value against what WooCommerce reads
 * back; if the filter ran on the way out, the round-trip value would never
 * equal the stored one and «مدیر عوض کرده» would be reported for ever about a
 * field nobody touched.
 */
interface HtmlSanitizerInterface
{
    /**
     * Returns the markup with everything a post author may not write removed.
     *
     * It is not reversible and it is not idempotent in general — a value that
     * has already been through it may still change shape — so it runs once,
     * at the boundary, and the result is what gets stored.
     */
    public function sanitize(string $html): string;
}
