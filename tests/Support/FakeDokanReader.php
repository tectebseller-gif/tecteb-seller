<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Domain\RecordSource;

/**
 * Dokan's data without Dokan.
 *
 * It can only be read, exactly like the real one — there is no setter that
 * writes back, because the interface it implements has no such method and the
 * test would otherwise be able to prove a safety property the production code
 * does not have.
 */
final class FakeDokanReader implements DokanReaderInterface
{
    /** @var list<array{user_id:int, store_name:string, email:string, enabled:bool}> */
    public array $vendorRows = [];

    /** @var list<array{wc_product_id:int, vendor_user_id:int, title:string, sku:string, price_minor:int, stock:int, post_status?:string, price_recorded?:bool, price_not_exact?:bool}> */
    public array $productRows = [];

    /**
     * @var list<array{wc_order_id:int, vendor_user_id:int, status:string, total_minor:int, net_minor?:int, commission_minor?:int, refunded?:bool}>
     *
     * The three history keys are optional in a fixture and filled in by
     * `orders()`, so a test that only cares about ids does not have to state
     * money it is not testing.
     */
    public array $orderRows = [];

    public bool $available = true;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function vendors(): array
    {
        return $this->available ? $this->vendorRows : [];
    }

    public function products(): array
    {
        return $this->available ? $this->productRows : [];
    }

    public function orders(): array
    {
        if (!$this->available) {
            return [];
        }
        return array_map(static function (array $row): array {
            $total = (int) $row['total_minor'];
            // Unknown net is the total, never a computed share — the same rule
            // the real reader follows, so a fixture cannot accidentally prove a
            // recalculation the production code refuses to do.
            $row['net_minor'] ??= $total;
            $row['commission_minor'] ??= max(0, $total - (int) $row['net_minor']);
            $row['refunded'] ??= false;
            return $row;
        }, $this->orderRows);
    }

    /**
     * Keyset paging over the fixture rows, with the SAME semantics as the SQL:
     * strictly after the id, ascending, at most `$limit`.
     *
     * A fake that ignored the cursor and returned everything would make a
     * resumable-import test pass while the real reader re-imported page one for
     * ever — which is precisely the class of bug the cursor exists to prevent.
     */
    public function vendorsAfter(int $afterUserId, int $limit = DokanReaderInterface::PAGE): array
    {
        return $this->page($this->vendors(), 'user_id', $afterUserId, $limit);
    }

    public function productsAfter(int $afterId, int $limit = DokanReaderInterface::PAGE): array
    {
        return $this->page($this->products(), 'wc_product_id', $afterId, $limit);
    }

    public function ordersAfter(int $afterId, int $limit = DokanReaderInterface::PAGE): array
    {
        return $this->page($this->orders(), 'wc_order_id', $afterId, $limit);
    }

    /** @var list<array<string,mixed>> */
    public array $staffRows = [];

    /** @var list<array<string,mixed>> */
    public array $balanceRows = [];

    /** @var list<array<string,mixed>> */
    public array $withdrawRows = [];

    public function staffFor(int $vendorUserId): array
    {
        $out = [];
        foreach ($this->staffRows as $row) {
            if ((int) ($row['vendor_user_id'] ?? 0) !== $vendorUserId) {
                continue;
            }
            $out[] = [
                'staff_user_id' => (int) $row['staff_user_id'],
                'vendor_user_id' => $vendorUserId,
                'display_name' => (string) ($row['display_name'] ?? ''),
                'user_email' => (string) ($row['user_email'] ?? ''),
                'dokan_role' => (string) ($row['dokan_role'] ?? ''),
            ];
        }
        return $out;
    }

    public function balanceRowsAfter(int $afterId, int $limit = self::PAGE): array
    {
        return $this->pageOf($this->balanceRows, 'row_id', $afterId, $limit, static fn (array $row): array => [
            'row_id' => (int) $row['row_id'],
            'trn_id' => (int) $row['trn_id'],
            'vendor_user_id' => (int) $row['vendor_user_id'],
            'trn_type' => (string) ($row['trn_type'] ?? ''),
            'particulars' => (string) ($row['particulars'] ?? ''),
            // Strings throughout, exactly as the real reader returns them:
            // a fake that handed back floats would let a rounding bug pass.
            'debit' => (string) ($row['debit'] ?? '0.0000'),
            'credit' => (string) ($row['credit'] ?? '0.0000'),
            'status' => (string) ($row['status'] ?? ''),
            'trn_date' => (string) ($row['trn_date'] ?? ''),
        ]);
    }

    public function withdrawalsAfter(int $afterId, int $limit = self::PAGE): array
    {
        return $this->pageOf($this->withdrawRows, 'withdraw_id', $afterId, $limit, static fn (array $row): array => [
            'withdraw_id' => (int) $row['withdraw_id'],
            'vendor_user_id' => (int) $row['vendor_user_id'],
            'amount' => (string) ($row['amount'] ?? '0.0000'),
            'status' => (string) ($row['status'] ?? ''),
            'method' => (string) ($row['method'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
            'requested_at' => (string) ($row['requested_at'] ?? ''),
        ]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):array<string,mixed> $shape
     * @return list<array<string,mixed>>
     */
    private function pageOf(array $rows, string $key, int $afterId, int $limit, callable $shape): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ((int) ($row[$key] ?? 0) <= $afterId) {
                continue;
            }
            $out[] = $shape($row);
            if (count($out) >= max(1, $limit)) {
                break;
            }
        }
        return $out;
    }

    /**
     * Record kinds whose source could not be READ — a failed statement.
     *
     * @var list<string> any of 'staff', 'balance', 'withdrawals'
     */
    public array $unreadableSources = [];

    /**
     * Record kinds whose source is NOT ON THIS INSTALL — no table, or no Pro.
     *
     * Kept apart from the list above on purpose. «I asked and the answer is
     * nothing» and «I could not ask» are different facts, and one name for
     * both is how a guard ends up wider than the defect (`alpha.68`).
     *
     * @var list<string>
     */
    public array $absentSources = [];

    public function recordSourceFor(int $vendorUserId): array
    {
        if (!$this->available) {
            $absent = RecordSource::dokanAbsent();
            return ['staff' => $absent, 'balance' => $absent, 'withdrawals' => $absent];
        }
        $source = function (string $kind, int $rows): RecordSource {
            if (in_array($kind, $this->unreadableSources, true)) {
                return RecordSource::unreadable();
            }
            if (in_array($kind, $this->absentSources, true)) {
                return RecordSource::sourceAbsent();
            }
            return RecordSource::counted($rows);
        };
        return [
            'staff' => $source('staff', count($this->staffFor($vendorUserId))),
            'balance' => $source('balance', $this->rowsForVendor($this->balanceRows, $vendorUserId)),
            'withdrawals' => $source('withdrawals', $this->rowsForVendor($this->withdrawRows, $vendorUserId)),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function rowsForVendor(array $rows, int $vendorUserId): int
    {
        $seen = 0;
        foreach ($rows as $row) {
            if ((int) ($row['vendor_user_id'] ?? 0) === $vendorUserId) {
                $seen++;
            }
        }
        return $seen;
    }

    /**
     * The scope the record import can touch, from the same fixture rows the
     * walk pages over.
     *
     * Derived rather than configured on purpose: the defect this method exists
     * for was two scopes that disagreed, so a fake whose scope came from its
     * own separate list could agree with the preview while the walk went
     * somewhere else — the fixture would then hide exactly the mismatch.
     * Sellers come from `vendorRows` because that is what `vendorsAfter()`
     * pages, and the other two from the record rows themselves.
     *
     * `unreadableSources` moves this answer too, so a test can ask what the
     * page says when the scope itself could not be enumerated.
     *
     * @return array{ids:list<int>, readable:bool, sources:array<string, array{ids:list<int>, readable:bool, present:bool}>}
     */
    public function recordOwnerIds(): array
    {
        if (!$this->available) {
            $none = ['ids' => [], 'readable' => false, 'present' => false];
            return [
                'ids' => [],
                'readable' => false,
                'sources' => ['sellers' => $none, 'balance' => $none, 'withdrawals' => $none],
            ];
        }

        $sellers = array_map(static fn (array $row): int => (int) ($row['user_id'] ?? 0), $this->vendors());
        $owners = function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $id = (int) ($row['vendor_user_id'] ?? 0);
                if ($id > 0) {
                    $out[$id] = $id;
                }
            }
            $out = array_values($out);
            sort($out);
            return $out;
        };

        $one = function (string $kind, array $ids): array {
            if (in_array($kind, $this->unreadableSources, true)) {
                return ['ids' => [], 'readable' => false, 'present' => true];
            }
            if (in_array($kind, $this->absentSources, true)) {
                return ['ids' => [], 'readable' => true, 'present' => false];
            }
            return ['ids' => $ids, 'readable' => true, 'present' => true];
        };

        $sources = [
            'sellers' => $one('staff', $owners(array_map(
                static fn (int $id): array => ['vendor_user_id' => $id],
                array_values(array_filter($sellers, static fn (int $id): bool => $id > 0))
            ))),
            'balance' => $one('balance', $owners($this->balanceRows)),
            'withdrawals' => $one('withdrawals', $owners($this->withdrawRows)),
        ];

        $ids = [];
        $readable = true;
        foreach ($sources as $source) {
            foreach ($source['ids'] as $id) {
                $ids[(int) $id] = (int) $id;
            }
            if ($source['readable'] === false) {
                $readable = false;
            }
        }
        $ids = array_values($ids);
        sort($ids);

        return ['ids' => $ids, 'readable' => $readable, 'sources' => $sources];
    }

    public function counts(): array
    {
        return [
            'vendors' => count($this->vendors()),
            'products' => count($this->products()),
            'orders' => count($this->orders()),
            'staff' => count($this->staffRows),
            'balance' => count($this->balanceRows),
            'withdrawals' => count($this->withdrawRows),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function page(array $rows, string $idKey, int $afterId, int $limit): array
    {
        $after = array_values(array_filter($rows, static fn (array $r): bool => (int) $r[$idKey] > $afterId));
        usort($after, static fn (array $a, array $b): int => (int) $a[$idKey] <=> (int) $b[$idKey]);
        return array_slice($after, 0, max(1, $limit));
    }
}
