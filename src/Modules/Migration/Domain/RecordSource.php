<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Domain;

/**
 * What a read of Dokan's own record tables actually established — and, when it
 * established nothing, why not.
 *
 * ### Why this type exists at all
 *
 * Every reader of Dokan's records answers `[]`. `WpDokanReader::staffFor()`
 * returns it when Dokan is not running; `balanceRowsAfter()` returns it when
 * `wp_dokan_vendor_balance` is not there; and `DatabaseInterface::getResults()`
 * returns it when the statement itself failed — the rule this repository has
 * carried since `alpha.8`. Three different facts, one empty array, and a
 * caller that counts it reports **zero records** for all three.
 *
 * Zero is a claim about the shop. «I could not look» is a claim about us. A
 * screen that prints the first when the second is true tells a manager their
 * vendor has no balance history, which is the single most expensive sentence
 * this module can say wrongly.
 *
 * ⛔ The precedent is `M0033MoneyUnitOnRows::unitColumnState()`, and so is the
 * reasoning: a failed read is **not an answer, it is a reason to ask again a
 * different way**. The docblock there records what the two-answer version
 * cost — a balance of ۸۸٫۳۷۵ تومان printed as ۸۸۳٬۷۵۰ because one blinded read
 * answered `false` and a caller called that conservative.
 *
 * ### Four states, not three, and never a count without `COUNTED`
 *
 * `count` is readable **only** in `COUNTED`. The other three carry `null`, so
 * a caller cannot accidentally add an unreadable source into a total: there
 * is no number there to add. That is a property of the type rather than a
 * rule somebody has to remember.
 *
 *  - `COUNTED`      — the read succeeded. `count` is the truth, and nought is
 *                     a perfectly good truth.
 *  - `SOURCE_ABSENT`— the table or the feature is not on this install. Dokan
 *                     Lite has no vendor staff at all, so «none found here» is
 *                     not «this shop has none» — the reader's own docblock has
 *                     said so since it was written, and until now nothing
 *                     could carry the distinction upwards.
 *  - `DOKAN_ABSENT` — Dokan is not running, so there was nothing to ask.
 *  - `UNREADABLE`   — a read was attempted and failed.
 *
 * `SOURCE_ABSENT` and `DOKAN_ABSENT` are kept apart because the remedy
 * differs: the first may be the honest end of the matter on a Lite install,
 * and the second means the question was asked at the wrong time. Collapsing
 * them under one name would repeat this file's own lesson one level up
 * (`alpha.68`: two «don't know»s sharing a name make a guard too wide).
 */
final class RecordSource
{
    public const COUNTED = 'counted';

    public const SOURCE_ABSENT = 'source_absent';

    public const DOKAN_ABSENT = 'dokan_absent';

    public const UNREADABLE = 'unreadable';

    private function __construct(
        public readonly string $state,
        public readonly ?int $count
    ) {
    }

    public static function counted(int $rows): self
    {
        return new self(self::COUNTED, max(0, $rows));
    }

    public static function sourceAbsent(): self
    {
        return new self(self::SOURCE_ABSENT, null);
    }

    public static function dokanAbsent(): self
    {
        return new self(self::DOKAN_ABSENT, null);
    }

    public static function unreadable(): self
    {
        return new self(self::UNREADABLE, null);
    }

    /** Whether there is a number here at all. */
    public function isCounted(): bool
    {
        return $this->state === self::COUNTED;
    }

    /**
     * The source was read and holds nothing.
     *
     * ⛔ False for every state but `COUNTED`, which is the whole point: an
     * unreadable source is not an empty one.
     */
    public function isEmpty(): bool
    {
        return $this->state === self::COUNTED && $this->count === 0;
    }

    /** @return array{state:string, count:int|null} for a report or a probe */
    public function toArray(): array
    {
        return ['state' => $this->state, 'count' => $this->count];
    }
}
