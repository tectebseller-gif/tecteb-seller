<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * Which product transitions are allowed, and by whom.
 *
 * Two rules here are the ones that would be expensive to get wrong:
 * a vendor can never move a product to Published themselves unless the
 * marketplace granted direct publishing, and a published product is never
 * edited in place — a sensitive change becomes a revision so the live version
 * stays live until the manager approves (§4.2).
 */
final class ProductStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'draft' => ['submitted', 'archived', 'published'],
        'submitted' => ['changes_requested', 'published', 'draft', 'archived'],
        'changes_requested' => ['submitted', 'draft', 'archived'],
        'published' => ['suspended', 'archived'],
        'suspended' => ['published', 'archived'],
        'archived' => ['draft'],
    ];

    public function canTransition(ProductStatus $from, ProductStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /**
     * A vendor may publish only with the marketplace's separate permission
     * (A.1), and only from a state that was theirs to move.
     */
    public function vendorMayMove(ProductStatus $from, ProductStatus $to, bool $mayPublishDirectly): bool
    {
        if (!$this->canTransition($from, $to)) {
            return false;
        }
        if ($to === ProductStatus::Published) {
            return $mayPublishDirectly;
        }
        return in_array($to, [ProductStatus::Submitted, ProductStatus::Draft, ProductStatus::Archived], true);
    }
}
