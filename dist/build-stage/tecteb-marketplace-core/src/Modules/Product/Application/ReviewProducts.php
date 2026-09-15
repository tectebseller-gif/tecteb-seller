<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSeo;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * The manager's side of §14.2.
 *
 * The rule that shapes this class: rejecting a proposed CHANGE must not take
 * the live product down («رد تغییر، نسخه منتشرشده فعلی را حذف نمی‌کند»). So a
 * revision decision only ever touches the revision row, except on approval,
 * where the payload is copied onto the product.
 *
 * Every decision carries a reason and lands in the audit log, and a decision
 * that would skip a state is refused by the state machine rather than written.
 */
final class ReviewProducts
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ProductRevisionRepositoryInterface $revisions,
        private readonly SpecTemplateRepositoryInterface $templates,
        private readonly ProductReadiness $readiness,
        private readonly SyncCatalog $catalog,
        private readonly ProductPublishPolicy $publishing,
        private readonly ProductStateMachine $states,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities
    ) {
    }

    public function approve(int $productId): OperationResult
    {
        return $this->decide($productId, ProductStatus::Published, '');
    }

    public function requestChanges(int $productId, string $note): OperationResult
    {
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        return $this->decide($productId, ProductStatus::ChangesRequested, $note);
    }

    /**
     * Rejection archives rather than deletes: the vendor keeps the draft, the
     * marketplace keeps the record, and nothing a buyer ever saw disappears.
     */
    public function reject(int $productId, string $note): OperationResult
    {
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        return $this->decide($productId, ProductStatus::Archived, $note);
    }

    /** Takes a live product out of the shop without touching its history. */
    public function suspend(int $productId, string $note): OperationResult
    {
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        return $this->decide($productId, ProductStatus::Suspended, $note);
    }

    public function republish(int $productId): OperationResult
    {
        return $this->decide($productId, ProductStatus::Published, '');
    }

    /**
     * Approving a revision is the ONLY path that rewrites a live product.
     * The payload was captured when the vendor proposed it, so what the
     * manager saw in the diff is what gets written.
     */
    public function approveRevision(int $revisionId): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_PRODUCTS)) {
            return OperationResult::failure('forbidden');
        }
        $revision = $this->revisions->find($revisionId);
        if ($revision === null) {
            return OperationResult::failure('not_found');
        }
        if (!$revision->isPending()) {
            return OperationResult::failure('already_decided');
        }
        $product = $this->products->find($revision->productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        $details = $this->detailsFromPayload($revision->payload, $product->details);
        if (!$this->products->updateDetails($product->id, $details)) {
            return OperationResult::failure('storage_failed');
        }
        $specs = $revision->payload['specs'] ?? [];
        if (is_array($specs)) {
            $template = $this->templates->findByCategory($details->categoryKey);
            $this->products->saveSpecs($product->id, array_map('strval', $specs), $template?->schemaVersion ?? 0);
        }
        $images = $revision->payload['images'] ?? null;
        if (is_array($images)) {
            $this->products->saveImages(
                $product->id,
                array_values(array_map('intval', $images)),
                (int) ($revision->payload['main_image_id'] ?? 0)
            );
        }
        // The product is live, and the proposal has just been written onto it.
        // If that made it unpublishable — a required field emptied, the last
        // picture removed — say so rather than leaving a broken product on the
        // site; the revision stays pending so the manager can refuse it.
        $updated = $this->products->find($product->id);
        if ($updated !== null) {
            $verdict = $this->readiness->check($updated);
            if (!$verdict->ok) {
                $this->products->updateDetails($product->id, $product->details);
                $this->products->saveSpecs($product->id, $product->specs, $product->specSchemaVersion);
                $this->products->saveImages($product->id, $product->imageIds, $product->mainImageId);
                return $verdict;
            }
        }
        // The proposal is now the product, so the storefront copy has to be
        // the proposal too.
        if ($product->status === ProductStatus::Published) {
            $this->catalog->publish($product->id);
        }
        $reviewer = $this->capabilities->currentUserId();
        $this->revisions->decide($revisionId, ProductRevision::APPROVED, $reviewer, '');
        $this->audit->log(AuditEventCatalog::PRODUCT_REVISION_REVIEWED, $reviewer, 'product', (string) $product->id, [
            'vendor_id' => $product->vendorUserId,
            'product_id' => $product->id,
            'revision_id' => $revisionId,
            'decision' => ProductRevision::APPROVED,
            'has_note' => false,
        ]);
        return OperationResult::success('revision_approved', ['product_id' => $product->id]);
    }

    /** Refusing a change leaves the published product exactly as it is. */
    public function rejectRevision(int $revisionId, string $note): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_PRODUCTS)) {
            return OperationResult::failure('forbidden');
        }
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        $revision = $this->revisions->find($revisionId);
        if ($revision === null) {
            return OperationResult::failure('not_found');
        }
        if (!$revision->isPending()) {
            return OperationResult::failure('already_decided');
        }
        $reviewer = $this->capabilities->currentUserId();
        if (!$this->revisions->decide($revisionId, ProductRevision::REJECTED, $reviewer, $note)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_REVISION_REVIEWED, $reviewer, 'product', (string) $revision->productId, [
            'vendor_id' => $revision->vendorUserId,
            'product_id' => $revision->productId,
            'revision_id' => $revisionId,
            'decision' => ProductRevision::REJECTED,
            'has_note' => true,
        ]);
        return OperationResult::success('revision_rejected', ['product_id' => $revision->productId]);
    }

    /**
     * The product's SEO — the manager's alone (Master Spec §6: «SEO فقط مدیر»).
     *
     * The vendor's form never renders these fields and the vendor's save path
     * never carries them, so this is the only way they can change. Saving
     * re-projects immediately, because a slug that is not on the storefront
     * is not a slug.
     */
    public function setSeo(int $productId, string $slug, string $title, string $description): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_PRODUCTS)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        $seo = new ProductSeo(
            ProductSeo::normaliseSlug($slug),
            trim($title),
            trim($description)
        );
        if (!$this->products->updateSeo($productId, $seo)) {
            return OperationResult::failure('storage_failed');
        }
        if ($product->status === ProductStatus::Published) {
            $this->catalog->publish($productId);
        }
        $this->audit->log(
            AuditEventCatalog::PRODUCT_SEO_CHANGED,
            $this->capabilities->currentUserId(),
            'product',
            (string) $productId,
            ['product_id' => $productId, 'has_slug' => $seo->slug !== '', 'has_title' => $seo->title !== '']
        );
        return OperationResult::success('seo_saved', ['product_id' => $productId]);
    }

    /** The second permission of §4.1, granted and revoked on its own. */
    public function setDirectPublishing(int $vendorUserId, bool $granted): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_PRODUCTS)) {
            return OperationResult::failure('forbidden');
        }
        $ok = $granted ? $this->publishing->grant($vendorUserId) : $this->publishing->revoke($vendorUserId);
        if (!$ok) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(
            AuditEventCatalog::PRODUCT_PUBLISH_PERMISSION_CHANGED,
            $this->capabilities->currentUserId(),
            'vendor',
            (string) $vendorUserId,
            ['vendor_id' => $vendorUserId, 'granted' => $granted]
        );
        return OperationResult::success($granted ? 'direct_publish_granted' : 'direct_publish_revoked');
    }

    private function decide(int $productId, ProductStatus $to, string $note): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_PRODUCTS)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->states->canTransition($product->status, $to)) {
            return OperationResult::failure('invalid_transition', [
                'from' => $product->status->value,
                'to' => $to->value,
            ]);
        }
        if ($to === ProductStatus::Published) {
            // The manager's approval is not a way around the marketplace's own
            // rules: a product with no picture or an empty required medical
            // field is refused here exactly as the vendor's submit refuses it.
            $verdict = $this->readiness->check($product);
            if (!$verdict->ok) {
                return $verdict;
            }
        }
        if (!$this->products->updateStatus($productId, $to, $note)) {
            return OperationResult::failure('storage_failed');
        }
        // The storefront follows the decision immediately: a product the
        // manager just approved has to be buyable, and one they suspended has
        // to stop being buyable in the same request (ADR-008).
        if ($to === ProductStatus::Published) {
            $this->catalog->publish($productId);
        } else {
            $this->catalog->withdraw($productId, $note);
        }
        $reviewer = $this->capabilities->currentUserId();
        $this->audit->log(AuditEventCatalog::PRODUCT_REVIEWED, $reviewer, 'product', (string) $productId, [
            'vendor_id' => $product->vendorUserId,
            'product_id' => $productId,
            'decision' => $to->value,
            'has_note' => trim($note) !== '',
        ]);
        return OperationResult::success('product_reviewed', ['product_id' => $productId, 'to' => $to->value]);
    }

    /**
     * A payload field that is absent keeps the product's current value, so an
     * older revision written by an older build can still be approved without
     * blanking a column that did not exist when it was proposed.
     *
     * @param array<string,mixed> $payload
     */
    private function detailsFromPayload(array $payload, ProductDetails $current): ProductDetails
    {
        $d = $payload['details'] ?? [];
        if (!is_array($d)) {
            return $current;
        }
        $str = static fn (string $key, string $fallback): string => isset($d[$key]) ? (string) $d[$key] : $fallback;
        $int = static fn (string $key, int $fallback): int => isset($d[$key]) ? (int) $d[$key] : $fallback;
        $nullableInt = static fn (string $key, ?int $fallback): ?int => array_key_exists($key, $d)
            ? ($d[$key] === null ? null : (int) $d[$key])
            : $fallback;
        $nullableStr = static fn (string $key, ?string $fallback): ?string => array_key_exists($key, $d)
            ? ($d[$key] === null || $d[$key] === '' ? null : (string) $d[$key])
            : $fallback;

        return new ProductDetails(
            $str('title', $current->title),
            $str('type', $current->type),
            $str('categoryKey', $current->categoryKey),
            $str('brand', $current->brand),
            $str('shortDescription', $current->shortDescription),
            $int('priceMinor', $current->priceMinor),
            $nullableInt('salePriceMinor', $current->salePriceMinor),
            $nullableStr('saleFrom', $current->saleFrom),
            $nullableStr('saleTo', $current->saleTo),
            // The immediate fields are taken from the LIVE product, never from
            // the proposal: stock moves with every sale, and writing back the
            // number that was true when the revision was written would undo
            // days of selling the moment a manager clicks approve.
            $current->sku,
            $current->stock,
            $current->minPurchase,
            $current->maxPurchase,
            $int('weightGrams', $current->weightGrams),
            $str('dimensions', $current->dimensions),
            $str('taxClass', $current->taxClass)
        );
    }
}
