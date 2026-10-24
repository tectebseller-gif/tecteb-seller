<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: this plugin issues no outbound HTTP request anywhere.
 *
 * ### Why it is a test and not a sentence
 *
 * On staging with `alpha.74`, job #1 returns Request Timeout while progress
 * keeps advancing, and the host's only hypothesis was that something is
 * waiting on an external service. The first thing that had to be established
 * was whether the waiting could be OURS — and it could not: a search of
 * `src/` for every way PHP and WordPress reach the network comes back empty.
 *
 * That fact is load-bearing for the whole investigation
 * (`docs/import-timeout-findings-alpha74-fa.md` §3): it is the reason the
 * search moved to the hooks another plugin can register on, rather than to
 * our own code. A fact a diagnosis rests on cannot live in a paragraph that
 * the next round can silently falsify by adding one call — so it lives here.
 *
 * ### The boundary of the claim, written out
 *
 * What this proves is that **no file under `src/` contains a call that
 * reaches the network**. What it does NOT prove, and is not claimed:
 *
 *  - that no outbound request happens DURING an import. Every hook in the
 *    intake path is a door another plugin can register on, and
 *    `wp_generate_attachment_metadata` is the classic one. That is exactly
 *    what the read-only probe (`plugin-exit-inventory.php intake`) counts on
 *    the site, because this repository cannot know it.
 *  - anything about WordPress core's own requests — update checks, cron
 *    pings — which happen on any request.
 *
 * Comments are blanked first, because this file's own docblock names several
 * of the forbidden functions while explaining that the code contains none of
 * them, and a byte scan would fail on the explanation (`alpha.22`).
 */
final class ImportMakesNoOutboundRequestTest extends TestCase
{
    /**
     * Every way out. Named one by one rather than matched by a pattern, so
     * adding one has to be a deliberate act with a reason written beside it.
     *
     * @var list<string>
     */
    private const OUTBOUND = [
        // WordPress's HTTP API, in all its spellings.
        'wp_remote_get', 'wp_remote_post', 'wp_remote_head', 'wp_remote_request',
        'wp_safe_remote_get', 'wp_safe_remote_post', 'wp_safe_remote_head',
        'wp_safe_remote_request',
        'WP_Http', 'WpOrg\\Requests',
        // The media helpers that fetch a URL — the ones an import like this
        // would most plausibly have reached for.
        'download_url', 'media_sideload_image', 'media_handle_sideload',
        'wp_get_http_headers',
        // And PHP's own.
        'curl_init', 'curl_exec', 'curl_multi_init',
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_create',
    ];

    public function testNoFileUnderSrcReachesTheNetwork(): void
    {
        $found = [];
        foreach ($this->sources() as $path) {
            $code = $this->codeWithoutComments($path);
            foreach (self::OUTBOUND as $call) {
                if (str_contains($code, $call)) {
                    $found[] = $this->relative($path) . ' → ' . $call;
                }
            }
            // A URL opened as a stream is the same thing wearing a file API.
            if (preg_match('~(file_get_contents|fopen|readfile)\s*\(\s*[\'"]https?://~i', $code) === 1) {
                $found[] = $this->relative($path) . ' → a stream opened on an http URL';
            }
            // The bare `Requests::` facade, with a boundary in front of it.
            //
            // ⛔ Without the boundary this needle matched `ReviewChangeRequests::class`
            // in two files and reported a network call in a build that makes
            // none — the substring trap of `alpha.33`, in a guard written to
            // settle a diagnosis.
            if (preg_match('~(?<![A-Za-z0-9_\\\\])Requests::~', $code) === 1) {
                $found[] = $this->relative($path) . ' → Requests::';
            }
        }

        self::assertSame(
            [],
            $found,
            "this plugin makes no outbound request, and the import timeout diagnosis rests on that:\n"
                . implode("\n", $found)
        );
    }

    /**
     * And the finding points at the hooks instead, so the hooks have to be
     * the ones the import really reaches.
     *
     * A list of hook names in a document is a claim about code. This checks
     * the three the findings name as the doors an external request can enter
     * through are the three the intake path actually fires — by finding the
     * calls that fire them.
     */
    public function testTheIntakePathReachesTheHooksTheFindingsName(): void
    {
        $images = $this->codeWithoutComments(
            __DIR__ . '/../../src/Modules/Product/Infrastructure/WordPress/WpProductImages.php'
        );
        foreach (
            [
                // `wp_generate_attachment_metadata` and
                // `wp_update_attachment_metadata` are filters fired by these
                // two calls; `wp_insert_attachment` is what fires
                // `add_attachment` and the `save_post` family.
                'wp_generate_attachment_metadata',
                'wp_update_attachment_metadata',
                'wp_insert_attachment',
            ] as $call
        ) {
            self::assertStringContainsString(
                $call,
                $images,
                $call . ' is named in the findings as a door into the import; it must really be on the path'
            );
        }
    }

    /** @return list<string> */
    private function sources(): array
    {
        $root = realpath(__DIR__ . '/../../src');
        self::assertIsString($root);
        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($walk as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = (string) $file->getRealPath();
            }
        }
        sort($files);
        self::assertGreaterThan(400, count($files), 'the walk must have found the source tree');
        return $files;
    }

    private function relative(string $path): string
    {
        $root = (string) realpath(__DIR__ . '/../../');
        return ltrim(str_replace($root, '', $path), '/');
    }

    private function codeWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
