<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ONE LINE PROTECTS EVERY VENDOR-AREA POST, AND NOTHING WATCHED IT.
 *
 * `VendorRoutes::handlePost()` checks the nonce once, and that check covers
 * the six extension areas too, because `handleExtensionPost()` is called
 * AFTER it. So every write on `/vendor/…` — products, variations, CSV import,
 * shipping, returns, withdrawals, review replies, staff invitations — rests on
 * that single statement standing in that single position.
 *
 * A security audit of this round found no test asserting it. The capability
 * side is covered many times over in the application services; the nonce was
 * load-bearing and unmeasured, which is the shape of a guard that gets
 * refactored away by somebody tidying a method.
 *
 * ⛔ This is a SOURCE assertion on purpose. A behavioural test would have to
 * reach `handlePost()`, which redirects and `exit`s on refusal, and the thing
 * being asserted is an ORDER — the check before the dispatch — which is a
 * property of the text and not of one run (`alpha.77`).
 */
final class VendorPostsAreNonceGuardedTest extends TestCase
{
    private const ROUTES = 'src/Modules/Vendor/Infrastructure/WordPress/VendorRoutes.php';

    private function body(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::ROUTES;
        self::assertFileExists($path);
        $php = (string) file_get_contents($path);
        $code = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= ' ';
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return self::methodBody($code, 'handlePost');
    }

    /** The method's own braces, matched — not a window of N characters. */
    private static function methodBody(string $code, string $method): string
    {
        $at = strpos($code, 'function ' . $method . '(');
        self::assertNotFalse($at, $method . ' must exist');
        $open = strpos($code, '{', $at);
        self::assertNotFalse($open);
        $depth = 0;
        for ($i = $open; $i < strlen($code); $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }
        self::fail('unbalanced braces in ' . $method);
    }

    public function testEveryVendorPostIsNonceChecked(): void
    {
        self::assertStringContainsString(
            'nonceOk(self::NONCE_FIELD, self::NONCE_ACTION)',
            $this->body(),
            'the vendor POST dispatcher must verify its nonce'
        );
    }

    /**
     * And BEFORE anything is dispatched — the whole value of one check is that
     * nothing runs ahead of it. Measured by byte offset, because «the check is
     * present» is true of a build where it has drifted below the dispatch.
     */
    public function testTheNonceIsCheckedBeforeAnythingIsDispatched(): void
    {
        $body = $this->body();
        $nonce = strpos($body, 'nonceOk(self::NONCE_FIELD, self::NONCE_ACTION)');
        self::assertNotFalse($nonce);

        foreach (['handleExtensionPost(', 'match ($action)'] as $dispatch) {
            $at = strpos($body, $dispatch);
            self::assertNotFalse($at, $dispatch . ' must be in this method');
            self::assertLessThan(
                $at,
                $nonce,
                'the nonce check must come before ' . $dispatch
            );
        }
    }

    /** A failed nonce must stop the request, not fall through to the dispatch. */
    public function testAFailedNonceEndsTheRequest(): void
    {
        $body = $this->body();
        $nonce = strpos($body, 'nonceOk(self::NONCE_FIELD, self::NONCE_ACTION)');
        self::assertNotFalse($nonce);
        // The refusal block is the first `exit` after the check.
        $exit = strpos($body, 'exit;', $nonce);
        self::assertNotFalse($exit, 'the refusal must exit');
        self::assertLessThan(
            strpos($body, 'handleExtensionPost(') ?: PHP_INT_MAX,
            $exit,
            'and it must exit before any dispatch could run'
        );
    }
}
