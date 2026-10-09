<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\Html\HtmlSanitizerInterface;

/**
 * `wp_kses_post()`, behind the contract.
 *
 * The allowed tag list is WordPress's own `post` context — what a user with
 * `unfiltered_html` revoked may write in a post body. It is deliberately not
 * a list of our own: the long description ends up in `post_content`, so the
 * value this plugin stores and the value an editor could have typed into the
 * same field are held to one standard, and WordPress keeps that standard up
 * to date for us.
 */
final class WpHtmlSanitizer implements HtmlSanitizerInterface
{
    public function sanitize(string $html): string
    {
        return (string) wp_kses_post($html);
    }
}
