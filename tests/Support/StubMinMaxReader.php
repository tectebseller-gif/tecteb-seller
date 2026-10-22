<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Migration\Application\MinMaxReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Domain\MinMaxRule;

/**
 * A fixed page of Min Max source rules, so a test can put a named product in
 * the MIDDLE of a batch.
 *
 * It reads nothing and writes nothing, which is the whole of the real reader's
 * contract too. The cursor it returns is the page's end — deliberately PAST
 * the last row, the way the real reader's is when a product whose every row is
 * blank was read and dropped — because that is the value `migrateBatch()` must
 * not hand back after a failure.
 */
final class StubMinMaxReader implements MinMaxReaderInterface
{
    /** @var list<array{product_id:int,type:string,rule:MinMaxRule}> */
    public array $page = [];

    public int $pageCursor = 0;

    public bool $available = true;

    public bool $done = true;

    /** @var list<int> every `$afterId` it was asked for, in order */
    public array $asked = [];

    /**
     * What `counts()` should report, when that has to differ from the page.
     *
     * The real reader counts the source rows with one query and WALKS them
     * in pages, so the two can legitimately disagree — which is the whole of
     * `exitVerdict()`'s `unknown`. A stub that derives one from the other
     * cannot express the case the count exists for.
     */
    public ?int $totalOverride = null;

    public function withProducts(array $ids, string $rule = '4/10/2'): self
    {
        [$min, $max, $step] = explode('/', $rule);
        $this->page = [];
        foreach ($ids as $id) {
            $this->page[] = [
                'product_id' => (int) $id,
                'type' => 'product',
                'rule' => MinMaxRule::from(MinMaxRule::AT_PRODUCT, $min, $max, $step),
            ];
        }
        $this->pageCursor = $ids === [] ? 0 : (int) max($ids) + 5;
        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function mode(): array
    {
        return ['mode' => 'plain', 'prefix' => '', 'option' => 'wcmmq_minmaxstep'];
    }

    public function globalRule(): ?MinMaxRule
    {
        return null;
    }

    public function termRules(): array
    {
        return [];
    }

    public function productRulesAfter(int $afterId, int $limit = self::PAGE): array
    {
        $this->asked[] = $afterId;
        $rows = array_values(array_filter(
            $this->page,
            static fn (array $row): bool => $row['product_id'] > $afterId
        ));
        return ['rows' => $rows, 'cursor' => $this->pageCursor, 'done' => $this->done];
    }

    public function counts(): array
    {
        return [
            'product' => $this->totalOverride ?? count($this->page),
            'variation' => 0,
            'terms' => 0,
        ];
    }

    public function presentationSettings(): array
    {
        return [];
    }
}
