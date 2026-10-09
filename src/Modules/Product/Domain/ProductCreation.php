<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * What one submitted create form actually did: a row, and whether WE made it.
 *
 * `create()` has always answered with an id, and for the token path an id is
 * not enough. When two requests carrying the same `create_token` arrive before
 * either has committed, both get past the «has this token already made a
 * product?» read, and the unique key decides: one insert lands, the other is
 * refused and resolves to the winner's row. Both callers then held the same
 * integer and could not tell each other apart — so the loser carried on and
 * wrote the specifications and the gallery over a row it had not created,
 * which on a replay sent minutes later means over whatever the vendor has
 * edited since.
 *
 * An id cannot carry that, so this does. `created` is false for the loser and
 * for a replay, and the one rule hanging off it is that nothing is written to
 * a row this request did not create.
 */
final class ProductCreation
{
    private function __construct(
        public readonly int $productId,
        public readonly bool $created
    ) {
    }

    /** This request's insert landed. */
    public static function made(int $productId): self
    {
        return new self($productId, true);
    }

    /**
     * The row was already there — a replay, or the losing half of two
     * simultaneous submissions of one form.
     */
    public static function resolved(int $productId): self
    {
        return new self($productId, false);
    }

    /** No row, and none to resolve to. */
    public static function failed(): self
    {
        return new self(0, false);
    }

    public function ok(): bool
    {
        return $this->productId > 0;
    }
}
