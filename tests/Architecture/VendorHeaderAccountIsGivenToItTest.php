<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: §8's account menu asks for its three addresses and does
 * not build them, and the logout one is WordPress's own.
 *
 * ⛔ **THE SENTENCE THIS IS HERE FOR.** «خروج از حساب باید با مسیر استاندارد و
 * امن وردپرس انجام شود» has two halves and only one of them is visible in the
 * rendered page: a test of the markup sees a link, and a link to anything at
 * all passes it. The half that is not visible there is WHICH function made
 * that address — and `wp_logout_url()` is the only one that carries
 * WordPress's nonce, so a hand-built `wp-login.php?action=logout` would look
 * identical in the HTML and be a logout anybody could trigger with a forged
 * link (CSRF).
 *
 * ⛔ **AND WHY `VendorShell` MAY NOT CALL IT.** Presentation does not call
 * WordPress — the rule this codebase has held since `alpha.9` — so the shell
 * is handed the addresses and prints them. That is not a style preference
 * here: it is what lets `VendorHeaderTest` render the header with no WordPress
 * at all and assert what the vendor's browser gets.
 *
 * Both halves are asserted in both directions: the shell must NOT name those
 * functions, and `VendorRoutes` MUST.
 */
final class VendorHeaderAccountIsGivenToItTest extends TestCase
{
    private const SHELL = 'src/Modules/Vendor/Presentation/VendorShell.php';
    private const ROUTES = 'src/Modules/Vendor/Infrastructure/WordPress/VendorRoutes.php';

    public function testThePresentationShellBuildsNoneOfTheThreeAddresses(): void
    {
        $code = self::code(self::SHELL);
        foreach (['wp_logout_url', 'wp_login_url', 'home_url', 'site_url', 'admin_url', 'wp_nonce_url'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden . '(',
                $code,
                self::SHELL . ' must be handed its addresses, not build them'
            );
        }
    }

    public function testTheRouterBuildsTheLogoutAddressWithWordPressOwnFunction(): void
    {
        $body = self::methodBody(self::code(self::ROUTES), 'accountFor');
        self::assertNotSame('', $body, 'accountFor() has to exist to be read');

        // The standard, nonce-carrying path.
        self::assertStringContainsString("'logout_url' => wp_logout_url(", $body);
        // And the site is the site: a different key, a different function.
        self::assertStringContainsString("'site_url' => home_url(", $body);
        self::assertStringNotContainsString("'site_url' => wp_logout_url(", $body);
        // ⛔ AND NOT A HAND-BUILT LOGOUT. `wp-login.php?action=logout` written
        // out by hand omits the nonce and is the CSRF this guard exists for.
        self::assertStringNotContainsString('action=logout', $body);
    }

    /**
     * «مشاهده فروشگاه» is asked for, so it can be absent.
     *
     * `StorePage::publicShop()` applies the public page's own two conditions
     * and answers `null` when either is missing — the `alpha.17` finding,
     * where three addresses were listed and two were 404s. A router that
     * built the address itself would print a link to a 404.
     */
    public function testTheShopAddressComesFromThePublicShopReaderAndIsConditional(): void
    {
        $body = self::methodBody(self::code(self::ROUTES), 'accountFor');

        self::assertStringContainsString('StorePage::publicShop(', $body);
        self::assertStringContainsString('if ($shop !== null)', $body);
        // The key is set INSIDE that branch, so a shop with no public page
        // carries no `shop_url` at all rather than an empty one.
        self::assertSame(
            1,
            preg_match('/if \(\$shop !== null\) \{\s*\$account\[\'shop_url\'\]/', $body),
            "shop_url has to be set inside the `\$shop !== null` branch"
        );
    }

    /**
     * And the shell is actually HANDED them.
     *
     * ⛔ A METHOD WITH NO CALLER IS NOT A FEATURE (`alpha.80`, in as many
     * words: «تابعی که فراخوان ندارد قابلیت نیست»). `accountFor()` can be
     * perfect and the header still carry no menu, because the one thing that
     * reaches it is an argument at a call site — and `$userId` defaults to 0
     * there, which is the «no menu» answer. So the wiring is asserted, not
     * assumed: the render call passes the account array, and the two views
     * that have a logged-in vendor pass that vendor's id.
     *
     * The invitation page deliberately does NOT, which is why the count is
     * two and not three: it renders this shell for somebody who is not logged
     * in, and an account menu there would offer to log out a visitor.
     */
    public function testTheShellIsHandedTheAccountArrayAndTheVendorsId(): void
    {
        $code = self::code(self::ROUTES);

        self::assertStringContainsString('$this->accountFor($userId)', $code, 'the render call has to pass it');
        self::assertSame(
            1,
            preg_match('/VendorShell::render\((?:[^;]*?)\$this->accountFor\(\$userId\)/s', $code),
            'the account array has to reach VendorShell::render()'
        );
        // Two call sites pass the id; the invitation page is the third and
        // passes nothing, so the default 0 answers «no menu».
        self::assertSame(
            2,
            preg_match_all('/self::applicationTitle\(\$workspace\)\s*\),\s*\$userId\s*\)/', $code),
            'both vendor views have to hand renderShell() the vendor id'
        );
        self::assertSame(
            4,
            preg_match_all('/\$this->renderShell\(/', $code),
            'four call sites: two vendor views and the invitation page has two'
        );
    }

    /** The source with its comments blanked: a docblock is not code (`alpha.22`). */
    private static function code(string $path): string
    {
        $source = (string) file_get_contents(__DIR__ . '/../../' . $path);
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * ONE method's body, bounded by matching braces.
     *
     * Not «from the name to the next `function`» and not an N-character
     * window: a short method lets such a window run past its closing brace
     * into the next one, which is the `alpha.33` trap — and a `return` is
     * almost everywhere.
     */
    private static function methodBody(string $code, string $method): string
    {
        $at = strpos($code, 'function ' . $method . '(');
        if ($at === false) {
            return '';
        }
        $open = strpos($code, '{', $at);
        if ($open === false) {
            return '';
        }
        $depth = 0;
        $length = strlen($code);
        for ($i = $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }
        return '';
    }
}
