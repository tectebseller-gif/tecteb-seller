<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The order a list of products is read in.
 *
 * One list of cases, so the control that offers the choice and the query that
 * applies it cannot drift apart — the rule this repository learned twice
 * (`StorefrontField::REQUIRED`, `ProductStatus`): two lists mean two rules.
 * The SQL fragment is NOT here, because Domain does not know about SQL; the
 * repository maps each case in one `match` and the labels live in
 * Presentation.
 *
 * **Every order ends in the row id.** `updated_at` is a `DATETIME` with
 * second precision, so a batch that was written in the same second — an
 * import, a bulk action, a seed — has no order of its own, and a pager whose
 * second page re-sorts the same seconds differently shows the same product
 * twice and hides another one entirely. The id is unique and never reused,
 * which is exactly what a tiebreaker has to be.
 */
enum ProductSort: string
{
    /** What changed last, which is what a reviewer is looking for. */
    case LastChanged = 'updated';

    /** The oldest untouched thing — the queue's other end. */
    case OldestChanged = 'oldest';

    /** Alphabetical, for finding something by name in a long list. */
    case Title = 'title';

    /**
     * An unknown key is the default order, not an error: a stale bookmark or
     * a hand-edited URL should show the list, and a sort key is not a
     * permission.
     */
    public static function fromKey(string $key): self
    {
        return self::tryFrom($key) ?? self::LastChanged;
    }

    public function isDefault(): bool
    {
        return $this === self::LastChanged;
    }
}
