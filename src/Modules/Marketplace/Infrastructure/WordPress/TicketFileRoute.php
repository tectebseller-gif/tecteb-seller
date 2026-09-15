<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Marketplace\Application\AttachTicketFile;

/**
 * The one door a ticket attachment leaves the server through.
 *
 * Hooked on `template_redirect` with a query argument rather than a rewrite
 * rule, for the same reason the wholesale screens are: installing or removing
 * this plugin must not flush permalinks or leave a site with a 404.
 *
 * Three checks before a byte is written, in this order, and the order matters:
 * the nonce for THIS attachment, then who is asking, then whether the file is
 * there. A stranger and a missing file get the same answer, so the route never
 * tells anybody which attachment ids exist.
 *
 * `X-Content-Type-Options: nosniff` and an explicit `Content-Disposition:
 * attachment` are not decoration: the allowlist lets an image and a PDF
 * through, and a browser that sniffs a crafted file into HTML would turn a
 * support thread into a stored-XSS hole on the site's own origin.
 */
final class TicketFileRoute
{
    public const QUERY_VAR = 'tmc_ticket_file';

    public static function register(ContainerInterface $container): void
    {
        add_action('template_redirect', static function () use ($container): void {
            $request = Request::capture();
            $attachmentId = $request->queryInt(self::QUERY_VAR);
            if ($attachmentId <= 0) {
                return;
            }
            self::serve($container, $request, $attachmentId);
        }, 5);
    }

    /** The URL that opens one attachment, nonce and all. */
    public static function url(int $attachmentId): string
    {
        return wp_nonce_url(
            add_query_arg(self::QUERY_VAR, (string) $attachmentId, home_url('/')),
            'tmc_ticket_file_' . $attachmentId
        );
    }

    private static function serve(ContainerInterface $container, Request $request, int $attachmentId): void
    {
        if (!$request->queryNonceOk('tmc_ticket_file_' . $attachmentId)) {
            self::deny();
        }
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId <= 0) {
            self::deny();
        }
        // One call does the ownership check, the read and the audit line —
        // there is no way to get the bytes that skips any of them.
        $file = $container->get(AttachTicketFile::class)->read($userId, $attachmentId);
        if ($file === null) {
            self::deny(404);
        }

        nocache_headers();
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . strlen($file['bytes']));
        header('Content-Disposition: attachment; filename="' . self::safeName($file['name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('Cache-Control: private, no-store, max-age=0');
        echo $file['bytes'];       // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * A file name safe to put in a header.
     *
     * The stored name is whatever the sender typed, and a quote or a newline
     * in it would break out of the `filename="…"` and let somebody add a
     * header of their own.
     */
    private static function safeName(string $name): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '', $name) ?? '';
        $clean = trim(str_replace(['"', "\r", "\n"], '', $clean));
        return $clean === '' ? 'attachment' : mb_substr($clean, 0, 120);
    }

    private static function deny(int $code = 403): void
    {
        status_header($code);
        nocache_headers();
        exit;
    }
}
