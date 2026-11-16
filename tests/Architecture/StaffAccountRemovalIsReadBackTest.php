<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * THE ONE WRITE THAT CAN REMOVE A WORDPRESS USER IS NEVER TRUSTED, IT IS READ
 * BACK — AND THIS IS ASSERTED IN SOURCE BECAUSE NO BEHAVIOURAL TEST CAN REACH
 * IT.
 *
 * ### Why source, and not a behavioural test
 *
 * `WpStaffUsers::deleteCreated()` calls `wp_delete_user()` and then asks
 * `get_userdata()` whether the account is really gone, rather than returning
 * the boolean. Four of that method's five guards were falsified with a
 * contract test; this one SURVIVED, and the honest reason is that it is
 * unreachable in a healthy fixture rather than that it is unbreakable.
 *
 * Measured from WordPress's own source: `wp_delete_user( $id )` answers
 * `false` only when the user does not exist, and `deleteCreated()` has already
 * refused that case two statements earlier. So on a sane installation the
 * boolean and the read-back always agree, and a double that models the verb
 * faithfully cannot make them differ — which is exactly `alpha.105`'s case for
 * asserting the property here instead of writing a fixture that could only
 * disagree by lying about WordPress.
 *
 * The property is still worth keeping, and the reason is this repository's own
 * history: `alpha.28` found `WC_Product::save()` returning the product id
 * whether it had written anything or not, and `alpha.11` found
 * `WC_Order::save()` swallowing its own exception. A `delete_user` filter, a
 * plugin that re-creates the account, or a multisite install where the removal
 * is somebody else's verb all produce «true, and still there». The caller
 * acts on this answer by deciding whether to tell a manager an account was
 * left behind, so a wrong `true` is a sentence that hides an orphan.
 *
 * ⛔ Comments ARE stripped before matching (`alpha.22`): the docblock on the
 * method under test describes the read-back in words, and a rule that matched
 * prose would pass on a body that had thrown the read-back away and kept the
 * paragraph about it.
 */
final class StaffAccountRemovalIsReadBackTest extends TestCase
{
    private const FILE = __DIR__ . '/../../src/Modules/Vendor/Infrastructure/WordPress/WpStaffUsers.php';

    public function testTheRemovalReadsTheAccountBackInsteadOfTrustingWordPress(): void
    {
        $body = self::methodBody('deleteCreated');

        self::assertStringContainsString(
            'wp_delete_user($userId)',
            $body,
            'the method must actually do the removal, or there is nothing to read back'
        );
        self::assertStringContainsString(
            'return get_userdata($userId) === false;',
            $body,
            'the answer must come from reading the account back, not from the boolean'
        );
        self::assertStringNotContainsString(
            'return wp_delete_user(',
            $body,
            'returning WordPress\'s boolean is the defect this rule exists for'
        );
    }

    /**
     * And the read-back must be the LAST word: a `return true` after it would
     * leave the read in place and make it decide nothing, which is how a guard
     * becomes decoration.
     */
    public function testNothingAfterTheReadBackCanOverrideIt(): void
    {
        $body = self::methodBody('deleteCreated');
        $at = strpos($body, 'return get_userdata($userId) === false;');

        self::assertIsInt($at);
        $tail = substr($body, $at + strlen('return get_userdata($userId) === false;'));
        self::assertSame('', trim($tail), 'the read-back is the method\'s last statement');
    }

    /**
     * The role floor is here for the same reason, one class of defect apart:
     * `get_option('default_role')` is a SETTING, so a site can have it on
     * `administrator`. The contract suite falsifies the floor three ways; what
     * source adds is that the adapter may not reach for the raw option again
     * when somebody edits this file next year.
     */
    public function testTheRawDefaultRoleIsNotUsedForANewAccount(): void
    {
        $body = self::methodBody('create');

        self::assertStringContainsString("'role' => self::safeRole(),", $body);
        self::assertStringNotContainsString("get_option('default_role'", $body);
    }

    /** The body of one method, with comments stripped and braces matched. */
    private static function methodBody(string $name): string
    {
        $source = (string) file_get_contents(self::FILE);
        $stripped = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $stripped .= str_repeat(' ', strlen($token[1]));
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }

        $at = strpos($stripped, 'function ' . $name . '(');
        self::assertIsInt($at, 'method ' . $name . ' not found');
        $open = strpos($stripped, '{', $at);
        self::assertIsInt($open);

        $depth = 0;
        $length = strlen($stripped);
        for ($i = $open; $i < $length; $i++) {
            if ($stripped[$i] === '{') {
                $depth++;
            } elseif ($stripped[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($stripped, $open + 1, $i - $open - 1);
                }
            }
        }

        self::fail('unbalanced braces in ' . $name);
    }
}
