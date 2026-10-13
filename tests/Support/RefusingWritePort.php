<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

/**
 * The destination, refusing the write for ONE named product.
 *
 * So a batch can fail in the MIDDLE of a page, which is the only shape that
 * shows whether the cursor steps over the failure — the `alpha.35` rule, and
 * `alpha.53`'s measured defect (`middle_id=374 cursor_after=375
 * cursor_past_failure=yes`).
 */
final class RefusingWritePort extends RecordingQuantityPort
{
    public function __construct(public int $refuseFor = 0)
    {
    }

    public function write(int $postId, string $min, string $max, string $step): array
    {
        if ($postId === $this->refuseFor) {
            return ['min' => '', 'max' => '', 'step' => '', 'stored' => false];
        }
        return parent::write($postId, $min, $max, $step);
    }
}
