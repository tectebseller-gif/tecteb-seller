<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * What a Dokan migration WOULD do, worked out without writing anything.
 *
 * The plan is the deliverable, not a side effect of the import: the owner
 * asked for «مهاجرت دکان ابتدا به‌صورت آزمایشی، با تطبیق داده‌ها و امکان
 * بازگشت», and a dry run that cannot be read is not a dry run.
 *
 * Every row carries its Dokan side, its marketplace side, and one of three
 * verdicts:
 *
 *   import    nothing in the way; this row would be created
 *   skip      already migrated, or already the marketplace's own
 *   conflict  something would be lost or duplicated, named so a person can
 *             decide — never resolved by guessing
 */
final class DokanMigrationPlan
{
    public const IMPORT = 'import';
    public const SKIP = 'skip';
    public const CONFLICT = 'conflict';

    /**
     * @param list<array{dokan_id:int, title:string, verdict:string, reason:string, target:string}> $vendors
     * @param list<array{dokan_id:int, title:string, verdict:string, reason:string, target:string}> $products
     * @param list<array{dokan_id:int, title:string, verdict:string, reason:string, target:string}> $orders
     */
    public function __construct(
        public readonly string $runId,
        public readonly array $vendors = [],
        public readonly array $products = [],
        public readonly array $orders = [],
        public readonly string $generatedAt = ''
    ) {
    }

    /** @return array{vendors:int, products:int, orders:int, conflicts:int, skipped:int} */
    public function summary(): array
    {
        $count = static function (array $rows, string $verdict): int {
            return count(array_filter($rows, static fn (array $row): bool => $row['verdict'] === $verdict));
        };
        return [
            'vendors' => $count($this->vendors, self::IMPORT),
            'products' => $count($this->products, self::IMPORT),
            'orders' => $count($this->orders, self::IMPORT),
            'conflicts' => $count($this->vendors, self::CONFLICT)
                + $count($this->products, self::CONFLICT)
                + $count($this->orders, self::CONFLICT),
            'skipped' => $count($this->vendors, self::SKIP)
                + $count($this->products, self::SKIP)
                + $count($this->orders, self::SKIP),
        ];
    }

    /** True when the plan can run without a person deciding something first. */
    public function isClean(): bool
    {
        return $this->summary()['conflicts'] === 0;
    }
}
