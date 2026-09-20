<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * One shop's imported figures, read once, and the version they were read at.
 *
 * ### Why this type exists at all
 *
 * Until `alpha.19` the report, the token that travelled with its form, and the
 * figure the decision froze were each produced by their own pass over the
 * repository. Three passes are three different moments, so the number printed
 * on the page, the number the token stood for and the number written into the
 * record were free to disagree — and the disagreement would look like nothing
 * at all, because every one of them was individually correct about the instant
 * it was read.
 *
 * A snapshot is read once and then answers every one of those questions from
 * the same values. The report shows `closing`; the token is a digest of the
 * same array; the decision freezes the same `closing`. They cannot drift
 * apart, because there is nothing left for them to drift between.
 *
 * ### What the token is, and what it is not
 *
 * It is a **version match**: a digest of this shop's figures together with the
 * counter the repository moves on every write to them. `decide()` recomputes
 * it from a snapshot taken under the version row's lock, and a mismatch means
 * the imported past moved between the reading and the decision.
 *
 * It is **not** evidence that a person looked at anything. A token proves a
 * form was rendered from some version of these figures, which a script can do
 * as easily as a manager can; what it can prove is that the version it was
 * rendered from is still the version being decided against. Nothing in this
 * codebase can establish that a human read a number, and a check that claimed
 * to would be worse than none, because it would be believed.
 *
 * `records_version` is inside the digest on purpose. Two different states of
 * an import can sum to the same figures — a rollback that restores exactly
 * what was removed is the obvious one — and «the numbers came back» is still a
 * change that a decision taken in between must not be carried silently across.
 */
final class DokanFinanceSnapshot
{
    /**
     * @param array{staff:int, balance_rows:int, debit:string, credit:string, withdrawals:int, withdrawn:string} $summary
     * @param array<string, array{count:int, total:string}> $byStatus
     * @param array<string, array{count:int, debit:string, credit:string}> $byType
     */
    private function __construct(
        public readonly int $vendorUserId,
        public readonly int $recordsVersion,
        public readonly array $summary,
        public readonly array $byStatus,
        public readonly array $byType,
        public readonly string $closing
    ) {
    }

    /**
     * Read every figure this shop's report shows, in one pass.
     *
     * The version is taken FIRST and passed in by the caller, because the two
     * callers need it taken differently: the report reads it plainly, and
     * `decide()` takes it under a write lock so that nothing can move while it
     * makes up its mind. Reading it here would rob the second caller of that
     * choice, and a lock acquired after the figures is a lock over the wrong
     * moment.
     */
    public static function read(
        ShopRecordRepositoryInterface $records,
        int $vendorUserId,
        int $recordsVersion
    ): self {
        $byStatus = $records->withdrawalsByStatus($vendorUserId);
        $byType = $records->balanceByType($vendorUserId);
        ksort($byStatus);
        ksort($byType);

        return new self(
            $vendorUserId,
            $recordsVersion,
            $records->summaryForVendor($vendorUserId),
            $byStatus,
            $byType,
            $records->closingBalance($vendorUserId)
        );
    }

    /**
     * The digest of these figures at this version.
     *
     * Every number the report puts on screen is in here. A figure a manager
     * can read must be a figure that can invalidate the decision, or the
     * receipt is for a different document than the one they were handed.
     */
    public function token(): string
    {
        return substr(hash('sha256', (string) json_encode([
            'vendor' => $this->vendorUserId,
            'records_version' => $this->recordsVersion,
            'closing' => $this->closing,
            'credit' => (string) $this->summary['credit'],
            'debit' => (string) $this->summary['debit'],
            'balance_rows' => (int) $this->summary['balance_rows'],
            'withdrawal_rows' => (int) $this->summary['withdrawals'],
            'by_status' => $this->byStatus,
            'by_type' => $this->byType,
        ])), 0, 32);
    }

    /** The overlap the report names rather than implies. @return array{count:int, debit:string, credit:string} */
    public function withdrawOverlap(string $withdrawType): array
    {
        return $this->byType[$withdrawType] ?? ['count' => 0, 'debit' => '0', 'credit' => '0'];
    }
}
