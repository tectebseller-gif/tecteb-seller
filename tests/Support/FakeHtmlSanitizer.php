<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\Html\HtmlSanitizerInterface;

/**
 * A sanitiser without WordPress.
 *
 * `wp_kses_post()` is a parser with a tag whitelist and reimplementing it
 * here would be a second, weaker one — the thing this repository refuses to
 * do. So this keeps only the one property the tests are about: a value that
 * arrives through a write path IS filtered, and the filter is the same object
 * for every path. It strips `<script>`, `<iframe>` and `on*` attributes, so a
 * test can tell «the importer sanitised» from «the importer stored the file's
 * bytes», and leaves `<p>`, `<ul>` and `<strong>` alone so a test about the
 * long description keeping its markup is not answered by a stub that strips
 * everything.
 *
 * It is NOT the production filter and a test that needs the real allowed-tag
 * list needs WordPress.
 */
final class FakeHtmlSanitizer implements HtmlSanitizerInterface
{
    public function sanitize(string $html): string
    {
        $out = (string) preg_replace('#<(script|iframe|style)\b[^>]*>.*?</\1>#is', '', $html);
        $out = (string) preg_replace('#</?(script|iframe|style)\b[^>]*>#i', '', $out);
        return (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $out);
    }
}
