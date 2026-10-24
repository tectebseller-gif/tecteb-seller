<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The criterion for «this is a shop of ours» may not be a row we wrote
 * ourselves.
 *
 * ⛔ **What this guards, and why a behaviour test cannot.** On the shipped
 * `alpha.78`, `MigrationCompleteness::vendorUserIds()` began with
 * `records->vendorsWithRecords()`, so an id's own imported history counted as
 * an «import trace». Before the record import, staging's ids 2, 4, 6, 8 and 16
 * were correctly outside the completeness table; the instant the import wrote
 * their balance rows they were inside it, labelled «فروشگاه واردشده», and put
 * through the gates that decide whether a shop may be admitted. The gate
 * decided its own subject.
 *
 * The behaviour test for it exists (`ShopRecordScopeTest`, after the real run)
 * and it is the one that matters. This one is the other half: a property of
 * the source, so that the next trace added to the shop criterion cannot
 * quietly be a record table again. Both halves, the `alpha.76` rule — the
 * claim is forbidden AND the true answer is required, because deleting the
 * method entirely would otherwise be green.
 *
 * Comments are stripped first: a rule asserted against raw source is
 * satisfied by the paragraph describing it (`alpha.22`), and the methods below
 * have docblocks naming the very thing being forbidden.
 */
final class BeingAShopIsNotOwningRecordsTest extends TestCase
{
    private const COMPLETENESS = 'src/Modules/Migration/Application/MigrationCompleteness.php';

    private const SCOPE = 'src/Modules/Migration/Application/ShopRecordScope.php';

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
     * The shop list is built from marketplace traces, and from nothing in our
     * three history tables.
     */
    public function testTheShopCriterionNeverReadsTheRecordTables(): void
    {
        $body = $this->methodBody($this->code(self::COMPLETENESS), 'shopVendorUserIds');
        foreach (['$this->records', 'vendorsWithRecords'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $body,
                'a history row is a copy of somebody else\'s past: it cannot be what makes a shop ours'
            );
        }
        // And the true answer is required, or deleting the two traces would
        // satisfy the prohibition above with an empty method.
        self::assertStringContainsString(
            'vendorsFromImportRuns()',
            $body,
            'the product stamp (migration 14) is one of the two marketplace traces'
        );
        self::assertStringContainsString(
            'idsFromImportRun(',
            $body,
            'the profile stamp (migration 16) is the other, and it was never backfilled'
        );
    }

    /** The gates run on the shops, not on the page's wider universe. */
    public function testTheGatesAreAskedOfTheShopListOnly(): void
    {
        $body = $this->methodBody($this->code(self::COMPLETENESS), 'all');
        self::assertStringContainsString(
            '$this->shopVendorUserIds()',
            $body,
            'the completeness table is about shops'
        );
        self::assertStringNotContainsString(
            '$this->vendorUserIds()',
            $body,
            'the wider list includes record owners, and a record owner is not judged as a shop'
        );
    }

    /** And the scope's `IMPORTED` label comes from the same criterion. */
    public function testTheImportedLabelComesFromTheShopCriterion(): void
    {
        $rows = $this->methodBody($this->code(self::SCOPE), 'rows');
        self::assertStringContainsString(
            '$this->shops()',
            $rows,
            'the badge saying «فروشگاه واردشده» must mean the shop criterion'
        );
        self::assertStringNotContainsString(
            'vendorUserIds()',
            $rows,
            'the label must not be derived from the list that contains record owners'
        );
        $shops = $this->methodBody($this->code(self::SCOPE), 'shops');
        self::assertStringContainsString('shopVendorUserIds()', $shops);
        $owners = $this->methodBody($this->code(self::SCOPE), 'recordOwners');
        self::assertStringContainsString('recordOwnerVendorUserIds()', $owners);
    }

    /**
     * No count opens the record gate, in any state.
     *
     * ⛔ The second half of the same mistake, and the one the owner found
     * still standing in the `alpha.78` ZIP: `records_imported` fell back to
     * `(balance + withdrawals + staff) > 0 || products > 0` whenever the
     * question could not be asked. Holding a product, or some of the records,
     * is not proof that the rest arrived.
     */
    public function testTheRecordGateIsNeverAnsweredFromACount(): void
    {
        $body = $this->methodBody($this->code(self::COMPLETENESS), 'forVendor');
        $start = strpos($body, "'records_imported' =>");
        self::assertIsInt($start, 'the gate must be there to be checked');
        $end = strpos($body, "'categories_mapped' =>", (int) $start);
        self::assertIsInt($end);
        $gate = substr($body, (int) $start, (int) $end - (int) $start);

        foreach (['balance_rows', 'withdrawals', 'marketplace', 'observed', 'staff_rows', '> 0'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $gate,
                'the record gate may not be answered from a row count: ' . $needle
            );
        }
        // Exactly the two successful reads that close it, named.
        self::assertStringContainsString('self::RECORDS_SETTLED', $gate);
        self::assertStringContainsString('self::RECORDS_NO_SOURCE', $gate);
        self::assertStringContainsString('default => false', $gate);
    }

    /**
     * «نمی‌دانم» is three facts and keeps three names.
     *
     * A name on a list nobody writes is the other half of the same mistake,
     * so each state is required to be produced somewhere in the method that
     * decides them.
     */
    public function testTheThreeUnknownCausesAreKeptApart(): void
    {
        $body = $this->methodBody($this->code(self::COMPLETENESS), 'recordsState');
        foreach ([
            'self::RECORDS_DOKAN_ABSENT' => 'dokan_not_running',
            'self::RECORDS_UNVERIFIABLE' => 'record_store_not_installed',
            'self::RECORDS_NO_SOURCE' => 'count($unchecked) === count($verdicts)',
        ] as $state => $because) {
            self::assertStringContainsString($state, $body, $state . ' must be reachable');
            self::assertStringContainsString(
                $because,
                $body,
                $state . ' must be decided from the preview\'s own reason, not from one shared label'
            );
        }
    }
}
