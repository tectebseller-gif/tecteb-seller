<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The ownership transfer's two writes stay one unit, and the page keeps saying
 * which half failed.
 *
 * The behaviour test is `OwnershipTransferUnitOfWorkTest`, on real MariaDB,
 * and it is the one that matters. This is the other half, and it exists for a
 * reason the repository has paid for: `if (false)` keeps the text of a call
 * exactly where it was, so a guard asserted by containment alone survives
 * being switched off — the CONDITION has to be pinned too (`alpha.79`). The
 * page's half has no behaviour test at all: nothing drives
 * `MigrationPage::handleAction()` with a refused row write, so without this the
 * sentence that tells a manager the two halves disagree could be removed and
 * every suite would stay green.
 *
 * Comments are stripped first, because a rule asserted against raw source is
 * satisfied by the paragraph describing it (`alpha.22`) — and the method below
 * explains at length the very codes being asserted.
 */
final class OwnershipTransferIsOneUnitOfWorkTest extends TestCase
{
    private const SERVICE = 'src/Modules/Migration/Application/TransferOwnership.php';

    private const PAGE = 'src/Modules/Migration/Presentation/Admin/MigrationPage.php';

    private function code(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** One method's body, by matching braces from its signature. */
    private function methodBody(string $code, string $method): string
    {
        $start = strpos($code, 'function ' . $method . '(');
        self::assertIsInt($start, 'no such method: ' . $method);
        $open = strpos($code, '{', (int) $start);
        self::assertIsInt($open, $method . ' has no body');
        $depth = 0;
        $length = strlen($code);
        for ($i = (int) $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
                continue;
            }
            if ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . ': unbalanced braces');
    }

    /**
     * A failed row write puts the storefront stamp back, in both directions.
     *
     * Both halves, so that deleting the compensation is not green either: the
     * two calls must be there AND the branch that chooses between them must be
     * there, because one without the other is a transfer that compensates the
     * wrong way round.
     */
    public function testTheRowWriteFailurePathCompensatesTheStampBothWays(): void
    {
        $body = $this->methodBody($this->code(self::SERVICE), 'move');

        self::assertStringContainsString(
            '$this->catalog->releaseStorefrontPost($product, $wcProductId)',
            $body,
            'taking must be able to unstamp the post it just stamped'
        );
        self::assertStringContainsString(
            '$this->catalog->claimStorefrontPost($product, $wcProductId)',
            $body,
            'giving back must be able to stamp the post again'
        );
        self::assertStringContainsString(
            '$restored = !$postMoved ||',
            $body,
            'a row with no WooCommerce product has no first write to put back'
        );
    }

    /**
     * «It went back» and «it did not» are two answers, and the code says so.
     *
     * `alpha.31`'s rule: a restoration is a write too, so reporting one that
     * failed as «nothing changed» sends the manager away from the one product
     * that needs looking at.
     */
    public function testTheTwoFailureOutcomesAreSeparateCodes(): void
    {
        $body = $this->methodBody($this->code(self::SERVICE), 'move');

        self::assertStringContainsString(
            "\$restored ? 'storage_failed' : 'ownership_stamp_unrestored'",
            $body,
            'one ternary, so the two outcomes cannot be collapsed by accident'
        );
        self::assertStringContainsString(
            'DOKAN_OWNERSHIP_HALF_WRITTEN',
            $body,
            'and the disagreement is recorded, not only flashed'
        );
    }

    /**
     * The page distinguishes them, and the CONDITION is pinned, not the text.
     *
     * `if (false)` would leave every string in place; what has to survive is
     * the comparison that chooses the sentence.
     */
    public function testThePageNamesTheHalfWrittenOutcomeOnItsOwnBranch(): void
    {
        $body = $this->methodBody($this->code(self::PAGE), 'handleAction');

        self::assertStringContainsString(
            "\$result->code === 'ownership_stamp_unrestored'",
            $body,
            'the page must choose this sentence by the code, not carry it unreachably'
        );
    }
}
