<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * What one of the three vendor verbs would do to one product — decided, but
 * not yet done.
 *
 * It exists so the preview and the action cannot disagree. A preview that
 * re-checked the rules for itself would be a second set of rules, and on the
 * day the two drift apart the vendor is shown a list of forty green rows and
 * gets four refusals. So `ManageProducts` decides ONCE, here; the preview
 * stops with the plan in hand and the action carries it out.
 *
 * It is a forecast, not a promise: between reading this and acting on it
 * somebody else can save the same product. That is why the action re-plans
 * rather than trusting a plan it was handed.
 */
final class ProductActionPlan
{
    private function __construct(
        /** The refusal the action would return, or null when it would go. */
        public readonly ?OperationResult $refusal,
        public readonly ?Product $product,
        public readonly ?ProductStatus $target,
        /** The code the action would end with, on success. */
        public readonly string $code,
        /**
         * What the review note becomes. A fresh submission clears it — the
         * manager's «قیمت را اصلاح کنید» is about the version being replaced —
         * while archiving and restoring leave it where it is.
         */
        public readonly string $note,
        public readonly string $auditEvent
    ) {
    }

    public static function refused(OperationResult $refusal): self
    {
        return new self($refusal, null, null, $refusal->code, '', '');
    }

    public static function allowed(
        Product $product,
        ProductStatus $target,
        string $code,
        string $note,
        string $auditEvent
    ): self {
        return new self(null, $product, $target, $code, $note, $auditEvent);
    }

    public function ok(): bool
    {
        return $this->refusal === null;
    }

    /** The plan as the preview shows it: never an entity, never a write. */
    public function asResult(): OperationResult
    {
        if ($this->refusal !== null) {
            return $this->refusal;
        }
        return OperationResult::success($this->code, [
            'product_id' => $this->product?->id ?? 0,
            'from' => $this->product?->status->value ?? '',
            'to' => $this->target?->value ?? '',
        ]);
    }
}
