<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The retired intake address is redirected on the hook that actually fires.
 *
 * `RetiredIntakeAddressTest` proves what `redirectOldAddress()` DOES; this
 * proves it is reached. The two are not the same test, and the gap between
 * them cost a round:
 *
 * `alpha.56` first registered the redirect on `admin_init`, which reads
 * correctly — `wp-admin/admin.php` fires `admin_init` at line 180 and looks
 * the plugin page hook up at 189, so a slug nothing registered would be
 * redirected before anyone noticed. It never ran. Line 163 of that same file
 * requires `wp-admin/menu.php` FIRST, and `wp-admin/includes/menu.php:375`
 * ends with:
 *
 *     if ( ! user_can_access_admin_page() ) {
 *         do_action( 'admin_page_access_denied' );
 *         wp_die( __( 'Sorry, you are not allowed to access this page.' ), 403 );
 *     }
 *
 * So the request is over before `admin_init` exists. Measured in a browser on
 * the upgrade path: `GET /wp-admin/admin.php?page=tmc-product-intake`
 * answered **403** with that exact sentence — the one thing the other test
 * exists to prevent — while every suite stayed green. `alpha.48`'s rule in a
 * new place: rendering, or registering, says nothing about what a request
 * does.
 *
 * Token-level with comments blanked first (`alpha.22`), because this file's
 * own prose names the hook it forbids, and so does the module's.
 */
final class RetiredAddressHookTest extends TestCase
{
    private const MODULE = '/src/Modules/Migration/MigrationModule.php';

    /** Source with every comment and docblock blanked out. */
    private static function code(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    /** The hook names a registration of `redirectOldAddress()` sits on. */
    private static function hooksAround(string $code): array
    {
        $hooks = [];
        $offset = 0;
        while (($at = strpos($code, 'redirectOldAddress', $offset)) !== false) {
            $offset = $at + 1;
            $before = substr($code, 0, $at);
            $call = strrpos($before, 'add_action');
            if ($call === false) {
                continue;
            }
            if (preg_match("~add_action\\(\\s*'([a-z_]+)'~", substr($code, $call, 60), $m) === 1) {
                $hooks[] = $m[1];
            }
        }
        return $hooks;
    }

    public function testTheRedirectIsRegisteredBeforeCoreRefusesThePage(): void
    {
        $hooks = self::hooksAround(self::code(dirname(__DIR__, 2) . self::MODULE));

        self::assertNotSame([], $hooks, 'nothing registers the retired address at all');
        self::assertContains(
            'admin_page_access_denied',
            $hooks,
            'the redirect must sit on the hook core fires immediately before its own 403'
        );
    }

    public function testItIsNotRegisteredOnAHookThatRunsTooLate(): void
    {
        $hooks = self::hooksAround(self::code(dirname(__DIR__, 2) . self::MODULE));

        // Named rather than «anything but the right one»: `admin_init` is the
        // hook that looked right and was measured not to run, and a future
        // reader moving the registration back to it is the regression this
        // test is for.
        self::assertNotContains(
            'admin_init',
            $hooks,
            'wp-admin/includes/menu.php:375 has already answered 403 by the time admin_init fires'
        );
    }
}
