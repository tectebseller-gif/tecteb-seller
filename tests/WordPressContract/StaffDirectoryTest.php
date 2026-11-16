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

    /**
     * ⛔ THIS TEST USED TO WRITE DOWN THE DEFECT.
     *
     * It asserted `get_option('default_role')` and its name said «the account
     * gets the site's default role» — the same sentence that was in the
     * adapter, and the reason nobody looked: `default_role` is a SETTING, and
     * a site can have it on `administrator`. §7 claim 6 says a staff account
     * must not be handed the manager's access, so the rule is now a floor and
     * it is pinned in BOTH directions below, not just the ordinary one.
     */
    public function testAnOrdinaryDefaultRoleIsUsedUnchanged(): void
    {
        State::$roles['customer'] = ['read' => true];
        update_option('default_role', 'customer');
        $id = (new WpStaffUsers())->create('ali', 'ali@example.test', 'علی', 'رضایی');

        $stored = State::$users[$id] ?? [];
        self::assertSame('customer', $stored['role'] ?? '');
        self::assertArrayNotHasKey('caps', $stored, 'marketplace permissions live in our own table, never in a WordPress role');
    }

    public function testADefaultRoleThatCanRunTheSiteIsRefusedAndTheFloorIsUsed(): void
    {
        update_option('default_role', 'administrator');
        $id = (new WpStaffUsers())->create('mina', 'mina@example.test', 'مینا', 'احمدی');

        self::assertSame('subscriber', State::$users[$id]['role'] ?? '');
    }

    /**
     * The question is asked of the role's CAPABILITIES, not of its name, so a
     * site that calls its god-role anything at all is answered the same way.
     * Pinning a list of names would have been a guard that the first custom
     * role walks past.
     */
    public function testACustomRoleIsJudgedByWhatItCanDoNotByItsName(): void
    {
        State::$roles['shop-boss'] = ['read' => true, 'edit_users' => true];
        update_option('default_role', 'shop-boss');
        $id = (new WpStaffUsers())->create('kaveh', 'kaveh@example.test', 'کاوه', 'نوری');

        self::assertSame('subscriber', State::$users[$id]['role'] ?? '');
    }

    public function testADefaultRoleThatDoesNotExistFallsToTheFloor(): void
    {
        update_option('default_role', 'no-such-role');
        $id = (new WpStaffUsers())->create('nima', 'nima@example.test', 'نیما', 'شریفی');

        self::assertSame('subscriber', State::$users[$id]['role'] ?? '');
    }

    /**
     * ⛔ FOUR REFUSALS, AND THE ACCOUNT HAS TO GET PAST ALL OF THEM.
     *
     * `deleteCreated()` is the only write in this plugin that can remove a
     * WordPress user, and what makes it safe is not the caller — it is that
     * the verb itself refuses anything but the inert thing the same request
     * just made. Each row below is one way the id could be something else.
     */
    public function testTheAccountThisCallJustMadeCanBeTakenBack(): void
    {
        $directory = new WpStaffUsers();
        $id = $directory->create('taken-back', 'taken-back@example.test', 'نام', 'خانوادگی');

        self::assertTrue($directory->deleteCreated($id));
        self::assertFalse($directory->exists($id), 'and it is really gone, read back from the directory');
        self::assertFalse($directory->emailTaken('taken-back@example.test'), 'so the same person can be invited again');
    }

    public function testAnAccountThatCanRunPartOfTheSiteIsNotTakenBack(): void
    {
        State::$roles['editorish'] = ['read' => true, 'edit_others_posts' => true];
        update_option('default_role', 'customer');
        $directory = new WpStaffUsers();
        $id = $directory->create('editorish-user', 'ed@example.test', 'نام', 'خانوادگی');
        State::$users[$id]['role'] = 'editorish';

        self::assertFalse($directory->deleteCreated($id));
        self::assertTrue($directory->exists($id));
    }

    public function testAnAccountThatHasWrittenSomethingIsNotTakenBack(): void
    {
        $directory = new WpStaffUsers();
        $id = $directory->create('author-user', 'author@example.test', 'نام', 'خانوادگی');
        State::$posts[91001] = ['status' => 'publish', 'author' => $id];

        self::assertFalse($directory->deleteCreated($id));
        self::assertTrue($directory->exists($id));
    }

    public function testAnAccountThatHasSignedInIsNotTakenBack(): void
    {
        $directory = new WpStaffUsers();
        $id = $directory->create('signed-in', 'signed@example.test', 'نام', 'خانوادگی');
        update_user_meta($id, 'session_tokens', ['abc' => ['expiration' => 1]]);

        self::assertFalse($directory->deleteCreated($id));
        self::assertTrue($directory->exists($id));
    }

    public function testAnIdThatIsNotThereIsReportedAsNotTakenBackRatherThanAsDone(): void
    {
        self::assertFalse((new WpStaffUsers())->deleteCreated(987654));
        self::assertFalse((new WpStaffUsers())->deleteCreated(0));
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
