<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the manager's confirmation that a shop's Dokan records
 * came over is stored in its own table, written from exactly one place, never
 * issued automatically, and incapable of touching ownership, the selling
 * permission or any financial row.
 *
 * ⛔ **Why these are source assertions.** A behaviour test shows that one
 * confirmation wrote one row and moved nothing else. It cannot show that the
 * next line added to the service will not write `can_sell`: that line would be
 * reachable and every behaviour test would stay green. «There is no road from
 * here to there» is a property of the source (`alpha.77`).
 *
 * The owner's ruling is the whole reason:
 * «تأیید صریح مدیر، فقط پس از تطبیق کامل سوابق، همراه با تاریخچه ممیزی و
 * امکان ابطال. این تصمیم نباید خودکار ثبت شود و نباید مالکیت، اجازه فروش یا
 * داده‌های مالی را تغییر دهد.»
 *
 * ⛔ Comments are stripped first. Both files name the things being forbidden
 * out loud, and a rule checked against raw source is satisfied by the
 * sentence describing it (`alpha.22`).
 */
final class ShopRecordConfirmationIsNeverAutomaticTest extends TestCase
{
    private const REPOSITORY = 'src/Modules/Migration/Infrastructure/DbShopRecordConfirmationRepository.php';

    private const SERVICE = 'src/Modules/Migration/Application/ConfirmShopRecords.php';

    private const MIGRATION = 'src/Modules/Migration/Infrastructure/Migrations/M0037ShopRecordConfirmation.php';

    private const PORT = 'ShopRecordConfirmationRepositoryInterface';

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

    /** One method's body, by matching braces — never «up to the next `function`». */
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
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . ' is unbalanced');
    }

    /**
     * The repository writes ONE table and reads no other.
     *
     * `tmc_dokan_handover` is named first because it is the one the previous
     * round's document proposed living in, and the one the owner's instruction
     * puts out of bounds: it IS the financial decision.
     */
    public function testTheRepositoryTouchesNoOtherTable(): void
    {
        $code = $this->code(self::REPOSITORY);
        foreach ([
            'tmc_dokan_handover',
            'tmc_dokan_shop_version',
            'tmc_vendor_profiles',
            'tmc_products',
            'tmc_ledger',
            'tmc_withdrawals',
            'dokan_vendor_balance',
            'dokan_withdraw',
            'dokan_orders',
            'postmeta',
            'FinanceHandoverRepositoryInterface',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'the confirmation store may not name ' . $forbidden
            );
        }
        // And the one it does write, by the migration's own constant rather
        // than a literal — a literal here and a literal there is two names
        // for one table.
        self::assertStringContainsString('M0037ShopRecordConfirmation', $code);
    }

    /**
     * The service cannot transfer ownership, grant selling, or move money.
     *
     * Verbs, not sentences: every one of these is the NAME of a writer
     * somewhere in this plugin, and the absence of the name is the absence of
     * the road.
     */
    public function testTheServiceCannotChangeOwnershipSellingOrMoney(): void
    {
        $code = $this->code(self::SERVICE);
        foreach ([
            'setLinkOwnership',
            'claimStorefrontPost',
            'releaseStorefrontPost',
            'TransferOwnership',
            'DecideImportedShopSelling',
            'ReviewApplication',
            'canSell',
            'can_sell',
            'FinanceHandoverRepositoryInterface',
            'LedgerRepositoryInterface',
            'WithdrawalRepositoryInterface',
            'ReconcileDokanFinance',
            'closing_at_decision',
            'ImportFromDokan',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'the record confirmation may not name ' . $forbidden
            );
        }
    }

    /** The migration creates a table and seeds nothing. */
    public function testTheMigrationBackfillsNothing(): void
    {
        $up = $this->methodBody($this->code(self::MIGRATION), 'up');
        foreach (['INSERT', 'UPDATE ', 'REPLACE'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $up,
                'migration 37 must create the table and write no row: ' . $forbidden
            );
        }
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $up);
    }

    /**
     * Only four files in `src/` may know the port exists.
     *
     * The service that writes it, the implementation, the container wiring,
     * and the one gate that reads it. A fifth would be a second caller of a
     * decision that is supposed to have exactly one door — the counting rule
     * `alpha.80` arrived at from the other side, where a function had no
     * caller at all.
     */
    public function testOnlyTheOneServiceAndTheOneGateKnowThePort(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $seen = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(dirname(__DIR__, 2) . '/', '', (string) $file->getPathname());
            if (strpos($this->code($relative), self::PORT) !== false) {
                $seen[] = $relative;
            }
        }
        sort($seen);
        self::assertSame([
            'src/Modules/Migration/Application/ConfirmShopRecords.php',
            'src/Modules/Migration/Application/MigrationCompleteness.php',
            'src/Modules/Migration/Application/ShopRecordConfirmationRepositoryInterface.php',
            'src/Modules/Migration/Infrastructure/DbShopRecordConfirmationRepository.php',
            'src/Modules/Migration/MigrationModule.php',
        ], $seen);
    }

    /**
     * `confirm()` asks the eligibility again, and the eligibility names the
     * one condition that cannot be waived.
     *
     * ⛔ Both halves. Pinning only the call would pass with a body of
     * `if (false)` around it, which keeps the text and removes the guard
     * (`alpha.79`), so the CONDITION is pinned too.
     */
    public function testTheConfirmationIsRefusedUnlessTheReconciliationIsComplete(): void
    {
        $code = $this->code(self::SERVICE);
        $confirm = $this->methodBody($code, 'confirm');
        self::assertStringContainsString('$this->eligibility($vendorUserId)', $confirm);
        self::assertStringContainsString("if (!\$gate['eligible'])", $confirm);

        $eligibility = $this->methodBody($code, 'eligibility');
        self::assertStringContainsString('RECORDS_SETTLED', $eligibility);
        self::assertStringContainsString('RECORDS_NO_SOURCE', $eligibility);
        self::assertStringContainsString("'records_not_settled'", $eligibility);
        self::assertStringContainsString('recordsState($vendorUserId)', $eligibility);
        // And it is a REFUSAL, not a note: the state check must sit in a
        // negated `in_array` that returns.
        self::assertStringContainsString('if (!in_array($state,', $eligibility);
    }

    /**
     * `revoke()` asks nothing about the shop's present state.
     *
     * A decision you can only withdraw while the world still looks the way it
     * did is not revocable, and «امکان ابطال» is the owner's word. So the
     * method must not consult the eligibility or the records state.
     */
    public function testRevocationDoesNotDependOnTheCurrentState(): void
    {
        $revoke = $this->methodBody($this->code(self::SERVICE), 'revoke');
        foreach (['eligibility(', 'recordsState(', 'RECORDS_SETTLED', 'RECORDS_NO_SOURCE'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $revoke,
                'revocation may not depend on ' . $forbidden
            );
        }
        self::assertStringContainsString('$this->confirmations->revoke(', $revoke);
    }

    /**
     * The audit line is written AFTER the confirmed write, in both methods.
     *
     * An audit row for a decision the database refused is a record of
     * something that never happened (`alpha.86`), and position in the file is
     * how that is asserted rather than hoped.
     */
    public function testTheAuditLineFollowsTheWrite(): void
    {
        $code = $this->code(self::SERVICE);
        foreach (['confirm' => 'confirmations->confirm(', 'revoke' => 'confirmations->revoke('] as $method => $write) {
            $body = $this->methodBody($code, $method);
            $at = strpos($body, $write);
            $audit = strpos($body, 'audit?->log(');
            self::assertIsInt($at, $method . ' must write');
            self::assertIsInt($audit, $method . ' must audit');
            self::assertGreaterThan((int) $at, (int) $audit, $method . ' must audit after the write');
            // And the refusal returns before the audit, so a failed write
            // cannot reach it.
            self::assertStringContainsString("if (\$code !== '')", $body);
        }
    }
}
