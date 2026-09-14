<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\WpStaffUsers;
use TmcWpStubs\State;

/**
 * The adapter that creates staff accounts, against WordPress's real return
 * values.
 *
 * `username_exists()` and `email_exists()` return an id or FALSE — never
 * null. An adapter that compares against the wrong sentinel reports every
 * name as taken, no invitation can ever be created, and no PHP test notices
 * because none of them calls WordPress. That is exactly what happened; these
 * assertions are the cheap version of the browser run that found it.
 */
final class StaffDirectoryTest extends ContractTestCase
{
    public function testAFreshUsernameIsNotReportedAsTaken(): void
    {
        $directory = new WpStaffUsers();

        self::assertFalse($directory->usernameTaken('nobody-has-this'));
        self::assertFalse($directory->emailTaken('nobody@example.test'));
    }

    public function testAUsedUsernameAndEmailAreReportedAsTaken(): void
    {
        $directory = new WpStaffUsers();
        $id = $directory->create('sara', 'sara@example.test', 'سارا', 'محمدی');

        self::assertGreaterThan(0, $id);
        self::assertTrue($directory->usernameTaken('sara'));
        self::assertTrue($directory->emailTaken('sara@example.test'));
        self::assertTrue($directory->exists($id));
    }

    public function testTheAccountGetsTheSitesDefaultRoleAndNoMarketplaceRole(): void
    {
        $directory = new WpStaffUsers();
        $id = $directory->create('ali', 'ali@example.test', 'علی', 'رضایی');

        $stored = State::$users[$id] ?? [];
        self::assertSame(get_option('default_role', 'subscriber'), $stored['role'] ?? '');
        self::assertArrayNotHasKey('caps', $stored, 'marketplace permissions live in our own table, never in a WordPress role');
    }

    public function testAPasswordIsOnlySetForAUserThatExists(): void
    {
        $directory = new WpStaffUsers();
        $id = $directory->create('reza', 'reza@example.test', 'رضا', 'کریمی');

        self::assertTrue($directory->setPassword($id, 'a-long-enough-password'));
        self::assertFalse($directory->setPassword(999999, 'a-long-enough-password'));
    }

    public function testTheInvitationDeadlineIsInTheFuture(): void
    {
        self::assertGreaterThan(gmdate('Y-m-d H:i:s'), (new WpStaffUsers())->timestampInSeconds(3600));
    }
}
