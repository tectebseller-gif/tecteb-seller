<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\SensitiveChange;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * Everything a vendor (or their staff) does to their own catalogue.
 *
 * Three rules run through every method here:
 *
 *  - the shop is an ARGUMENT and the row is fetched with it, so a product id
 *    from another shop returns "not found" rather than somebody else's data
 *    (AC-PRIV);
 *  - a live product is never edited in place. A sensitive change becomes a
 *    revision and the published version stays exactly as buyers see it,
 *    while stock and its neighbours apply at once (§4.2, A.1);
 *  - nothing here publishes unless the marketplace granted that shop direct
 *    publishing separately from the right to sell.
 *
 * OperationResult and StaffAccess are the vendor module's: the same result
 * shape reaches the same notice renderer, and there is exactly one authority
 * on "may this user act in this shop" rather than a second, drifting copy.
 */
final class ManageProducts
{
    /** A gallery any larger is a mistake, not a catalogue. */
    public const MAX_IMAGES = 12;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SpecTemplateRepositoryInterface $templates,
        private readonly ProductRevisionRepositoryInterface $revisions,
        private readonly ProductReadiness $readiness,
        private readonly SyncCatalog $catalog,
        private readonly ProductImageLibraryInterface $images,
        private readonly StaffAccess $access,
        private readonly ProductPublishPolicy $publishing,
        private readonly ProductStateMachine $states,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Creates a draft or saves an existing one.
     *
     * @param array<string,string> $specs
     * @param list<int> $imageIds
     */
    /**
     * Saving somebody else's newer work is the one failure a form cannot
     * undo, so `$revision` exists to stop it.
     *
     * A shop with staff has two people who can open the same product, and the
     * old behaviour was last-write-wins with no trace: the second save
     * overwrote the first and neither person was told. The form carries the
     * `updated_at` it was rendered from, and a save whose token no longer
     * matches the row is REFUSED — with both values reported, so the editor
     * decides which is right. An empty token skips the check, because a form
     * from an older build has nothing to compare and refusing it would break
     * saving for anybody mid-edit across an upgrade.
     */
    public function save(
        int $actorId,
        int $vendorUserId,
        int $productId,
        ProductDetails $details,
        array $specs = [],
        array $imageIds = [],
        int $mainImageId = 0,
        string $revision = ''
    ): OperationResult {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $problem = $this->validate($vendorUserId, $details, $productId);
        if ($problem !== null) {
            return $problem;
        }
        $specProblem = $this->rejectImpossibleSpecs($details->categoryKey, $specs);
        if ($specProblem !== null) {
            return $specProblem;
        }
        [$imageIds, $mainImageId] = $this->cleanImages($imageIds, $mainImageId, $vendorUserId);

        if ($productId <= 0) {
            return $this->create($vendorUserId, $details, $specs, $imageIds, $mainImageId);
        }

        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if ($revision !== '' && $product->updatedAt !== '' && $revision !== $product->updatedAt) {
            // Refused, not merged. A merge would have to guess which of two
            // prices is the intended one, and a wrong guess about a price is
            // money. The context carries both stamps so the page can say who
            // changed it and when.
            return OperationResult::failure('stale_revision', [
                'product_id' => $productId,
                'submitted_revision' => $revision,
                'current_revision' => $product->updatedAt,
            ]);
        }
        return match (true) {
            $product->status === ProductStatus::Submitted => OperationResult::failure('in_review'),
            $product->status === ProductStatus::Archived => OperationResult::failure('product_archived'),
            $product->status->isEditableByVendor() => $this->saveInPlace($product, $details, $specs, $imageIds, $mainImageId),
            default => $this->saveAsRevision($product, $details, $specs, $imageIds, $mainImageId),
        };
    }

    /**
     * Stock, SKU and purchase limits: applied at once, at every status.
     *
     * A.1 puts inventory on the immediate side deliberately — a vendor who
     * has just sold their last unit must be able to say so without waiting
     * for a manager, and a queue between a sale and its stock is how a shop
     * oversells.
     */
    public function updateInventory(
        int $actorId,
        int $vendorUserId,
        int $productId,
        int $stock,
        string $sku,
        int $minPurchase,
        ?int $maxPurchase
    ): OperationResult {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Inventory, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if ($product->status === ProductStatus::Archived) {
            return OperationResult::failure('product_archived');
        }
        if ($stock < 0) {
            return OperationResult::failure('bad_stock');
        }
        $quantities = $this->validateQuantities($minPurchase, $maxPurchase);
        if ($quantities !== null) {
            return $quantities;
        }
        if ($sku !== '' && $this->products->skuTaken($vendorUserId, $sku, $productId)) {
            return OperationResult::failure('sku_taken', ['sku' => $sku]);
        }
        if (!$this->products->updateInventory($productId, $stock, $sku, $minPurchase, $maxPurchase)) {
            return OperationResult::failure('storage_failed');
        }
        // Write-through, not a mirror write: this is the one direction stock
        // is allowed to travel outward, because it is the vendor saying so
        // rather than a sync remembering (ADR-008).
        $this->catalog->pushStock($productId, $stock);
        $this->audit->log(AuditEventCatalog::PRODUCT_INVENTORY_CHANGED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'stock' => $stock,
        ]);
        return OperationResult::success('inventory_saved', ['product_id' => $productId, 'stock' => $stock]);
    }

    /**
     * Step 4 of the form: the vendor says it is ready.
     *
     * Everything the marketplace demands is checked HERE rather than at each
     * keystroke, so a half-filled draft is always savable and only a
     * submission has to be complete.
     */
    /** @var list<string> the bulk verbs, each one an existing single-row action */
    public const BULK_ACTIONS = ['submit', 'archive', 'restore'];

    /** Rows one POST may carry. Above it the request is refused, not truncated. */
    public const BULK_LIMIT = 100;

    public function submit(int $actorId, int $vendorUserId, int $productId): OperationResult
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if (!$product->status->isEditableByVendor()) {
            return OperationResult::failure('not_submittable');
        }
        $verdict = $this->readiness->check($product);
        if (!$verdict->ok) {
            return $verdict;
        }

        $direct = $this->publishing->mayPublishDirectly($vendorUserId);
        $target = $direct ? ProductStatus::Published : ProductStatus::Submitted;
        if (!$this->states->vendorMayMove($product->status, $target, $direct)) {
            return OperationResult::failure('invalid_transition', [
                'from' => $product->status->value,
                'to' => $target->value,
            ]);
        }
        if (!$this->products->updateStatus($productId, $target, '')) {
            return OperationResult::failure('storage_failed');
        }
        if ($target === ProductStatus::Published) {
            $this->catalog->publish($productId);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_SUBMITTED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'to' => $target->value,
        ]);
        return OperationResult::success(
            $direct ? 'product_published' : 'product_submitted',
            ['product_id' => $productId]
        );
    }

    /** Off the shelf without deleting anything: history and orders stay. */
    public function archive(int $actorId, int $vendorUserId, int $productId): OperationResult
    {
        return $this->move($actorId, $vendorUserId, $productId, ProductStatus::Archived, 'product_archived_ok');
    }

    public function restore(int $actorId, int $vendorUserId, int $productId): OperationResult
    {
        return $this->move($actorId, $vendorUserId, $productId, ProductStatus::Draft, 'product_restored');
    }

    /**
     * The same three actions, over a selection, with a verdict per row.
     *
     * **A refused row does not stop the batch, and it does not disappear.**
     * A vendor selecting forty products and pressing «ارسال برای بررسی» has
     * some that are ready and some that are missing a price; stopping at the
     * first incomplete one would make the feature useless on exactly the
     * catalogue that needs it, and skipping it silently would tell them
     * everything went out when four things did not. So every row gets its own
     * line in the answer, with the same code the single-row action would have
     * returned.
     *
     * **It calls the single-row methods.** Not a faster bulk query — every
     * ownership check, every readiness rule and every audit line is the one
     * that already exists. A bulk path with its own rules is a second set of
     * rules, and the looser one always wins the day they differ.
     *
     * **The selection is capped.** A POST carrying ten thousand ids would
     * time out half way, which is the failure the job queue exists for; until
     * bulk work is queued, the honest answer is to refuse the oversized
     * request rather than start one that cannot finish.
     *
     * @param list<int> $productIds
     * @return OperationResult context: rows, ok, failed
     */
    public function bulk(int $actorId, int $vendorUserId, string $action, array $productIds): OperationResult
    {
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            return OperationResult::failure('unknown_bulk_action');
        }
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $productIds = array_values(array_unique(array_filter($productIds, static fn (int $id): bool => $id > 0)));
        if ($productIds === []) {
            return OperationResult::failure('nothing_selected');
        }
        if (count($productIds) > self::BULK_LIMIT) {
            return OperationResult::failure('bulk_too_large', [
                'selected' => count($productIds),
                'limit' => self::BULK_LIMIT,
            ]);
        }

        $rows = [];
        $ok = 0;
        $failed = 0;
        foreach ($productIds as $productId) {
            $result = match ($action) {
                'submit' => $this->submit($actorId, $vendorUserId, $productId),
                'archive' => $this->archive($actorId, $vendorUserId, $productId),
                default => $this->restore($actorId, $vendorUserId, $productId),
            };
            $rows[] = ['product_id' => $productId, 'ok' => $result->ok, 'code' => $result->code];
            $result->ok ? $ok++ : $failed++;
        }

        // `ok` is true when the batch RAN, not when every row succeeded. The
        // caller reads the rows to find out what happened; a false here would
        // have made «۳۶ از ۴۰ رفت» look like a failure of the whole thing.
        return OperationResult::success('bulk_done', [
            'action' => $action,
            'rows' => $rows,
            'ok' => $ok,
            'failed' => $failed,
        ]);
    }

    /**
     * Whether this product could be submitted right now, and if not, why.
     *
     * Step 4 of the form calls this to show the list before the button is
     * pressed; submit() calls the same object, so the preview and the answer
     * can never disagree.
     */
    public function readiness(Product $product): OperationResult
    {
        return $this->readiness->check($product);
    }

    /**
     * Puts one picture in the media library for this shop and hands back its
     * id; the gallery itself is only ever changed by save(), so an upload on
     * a live product still goes through the revision rule.
     */
    public function uploadImage(int $actorId, int $vendorUserId, UploadedFile $file, string $title = ''): OperationResult
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $stored = $this->images->store($file, $vendorUserId, $title);
        if (!$stored['ok']) {
            return OperationResult::failure($stored['code']);
        }
        return OperationResult::success('image_uploaded', ['media_id' => $stored['media_id']]);
    }

    // ------------------------------------------------------------- internals

    /** @param array<string,string> $specs @param list<int> $imageIds */
    private function create(int $vendorUserId, ProductDetails $details, array $specs, array $imageIds, int $mainImageId): OperationResult
    {
        $productId = $this->products->create($vendorUserId, $details, ProductStatus::Draft);
        if ($productId <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->persistSpecsAndImages($productId, $details->categoryKey, $specs, $imageIds, $mainImageId);
        $this->audit->log(AuditEventCatalog::PRODUCT_SAVED, $vendorUserId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'status' => ProductStatus::Draft->value,
            'created' => true,
        ]);
        return OperationResult::success('product_created', ['product_id' => $productId]);
    }

    /** @param array<string,string> $specs @param list<int> $imageIds */
    private function saveInPlace(Product $product, ProductDetails $details, array $specs, array $imageIds, int $mainImageId): OperationResult
    {
        if (!$this->products->updateDetails($product->id, $details)) {
            return OperationResult::failure('storage_failed');
        }
        $this->persistSpecsAndImages($product->id, $details->categoryKey, $specs, $imageIds, $mainImageId);
        $this->audit->log(AuditEventCatalog::PRODUCT_SAVED, $product->vendorUserId, 'product', (string) $product->id, [
            'vendor_id' => $product->vendorUserId,
            'product_id' => $product->id,
            'status' => $product->status->value,
            'created' => false,
        ]);
        return OperationResult::success('product_saved', ['product_id' => $product->id]);
    }

    /**
     * A live product's sensitive edit becomes a proposal.
     *
     * The immediate fields are still written straight through: refusing to
     * update stock because the title also changed would be the queue between
     * a sale and its inventory that A.1 forbids.
     *
     * @param array<string,string> $specs
     * @param list<int> $imageIds
     */
    private function saveAsRevision(Product $product, ProductDetails $details, array $specs, array $imageIds, int $mainImageId): OperationResult
    {
        $changed = SensitiveChange::between($product->details, $details);
        $specsDiffer = SensitiveChange::specsChanged($product->specs, $specs);
        $imagesDiffer = $product->imageIds !== $imageIds || $product->mainImageId !== $mainImageId;

        $inventoryWritten = $this->products->updateInventory(
            $product->id,
            $details->stock,
            $details->sku,
            $details->minPurchase,
            $details->maxPurchase
        );
        if (!$inventoryWritten) {
            return OperationResult::failure('storage_failed');
        }
        $this->catalog->pushStock($product->id, $details->stock);

        if ($changed === [] && !$specsDiffer && !$imagesDiffer) {
            return OperationResult::success('inventory_saved', ['product_id' => $product->id]);
        }

        // One pending proposal per product. A newer one replaces the older
        // rather than queueing behind it, so the manager reviews what the
        // vendor actually wants — and the older proposal stays on record as
        // superseded instead of vanishing.
        $existing = $this->revisions->pendingFor($product->id);
        if ($existing !== null) {
            $this->revisions->decide($existing->id, ProductRevision::SUPERSEDED, 0, '');
        }
        $revisionId = $this->revisions->create($product->id, $product->vendorUserId, [
            'details' => $this->detailsToArray($details),
            'specs' => $specs,
            'images' => $imageIds,
            'main_image_id' => $mainImageId,
        ]);
        if ($revisionId <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_REVISION_REQUESTED, $product->vendorUserId, 'product', (string) $product->id, [
            'vendor_id' => $product->vendorUserId,
            'product_id' => $product->id,
            'revision_id' => $revisionId,
            'fields' => count($changed) + ($specsDiffer ? 1 : 0) + ($imagesDiffer ? 1 : 0),
        ]);
        return OperationResult::success('revision_requested', [
            'product_id' => $product->id,
            'revision_id' => $revisionId,
        ]);
    }

    private function move(int $actorId, int $vendorUserId, int $productId, ProductStatus $to, string $successCode): OperationResult
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        $direct = $this->publishing->mayPublishDirectly($vendorUserId);
        if (!$this->states->vendorMayMove($product->status, $to, $direct)) {
            return OperationResult::failure('invalid_transition', [
                'from' => $product->status->value,
                'to' => $to->value,
            ]);
        }
        if (!$this->products->updateStatus($productId, $to, $product->reviewNote)) {
            return OperationResult::failure('storage_failed');
        }
        if ($to === ProductStatus::Published) {
            $this->catalog->publish($productId);
        } elseif ($product->status === ProductStatus::Published) {
            $this->catalog->withdraw($productId);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_STATUS_CHANGED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'from' => $product->status->value,
            'to' => $to->value,
        ]);
        return OperationResult::success($successCode, ['product_id' => $productId]);
    }

    /** @param array<string,string> $specs @param list<int> $imageIds */
    private function persistSpecsAndImages(int $productId, string $categoryKey, array $specs, array $imageIds, int $mainImageId): void
    {
        $template = $this->templates->findByCategory($categoryKey);
        // The version a product was filled against, recorded with the answers
        // (MED-01): tightening a rule later must not silently re-judge it.
        $this->products->saveSpecs($productId, $specs, $template?->schemaVersion ?? 0);
        $this->products->saveImages($productId, $imageIds, $mainImageId);
    }

    private function validate(int $vendorUserId, ProductDetails $details, int $productId): ?OperationResult
    {
        if (!ProductType::isValid($details->type)) {
            return OperationResult::failure('bad_type');
        }
        if ($details->priceMinor < 0) {
            return OperationResult::failure('bad_price');
        }
        if ($details->salePriceMinor !== null) {
            if ($details->salePriceMinor < 0 || $details->salePriceMinor > $details->priceMinor) {
                return OperationResult::failure('bad_sale_price');
            }
            if ($details->saleFrom !== null && $details->saleTo !== null && $details->saleFrom > $details->saleTo) {
                return OperationResult::failure('bad_sale_window');
            }
        }
        if ($details->stock < 0) {
            return OperationResult::failure('bad_stock');
        }
        $quantities = $this->validateQuantities($details->minPurchase, $details->maxPurchase);
        if ($quantities !== null) {
            return $quantities;
        }
        if ($details->sku !== '' && $this->products->skuTaken($vendorUserId, $details->sku, $productId)) {
            return OperationResult::failure('sku_taken', ['sku' => $details->sku]);
        }
        return null;
    }

    private function validateQuantities(int $minPurchase, ?int $maxPurchase): ?OperationResult
    {
        if ($minPurchase < 1) {
            return OperationResult::failure('bad_quantity');
        }
        if ($maxPurchase !== null && $maxPurchase < $minPurchase) {
            return OperationResult::failure('bad_quantity');
        }
        return null;
    }

    /**
     * Values a field could never accept are refused on the way in; values a
     * REQUIRED field is missing are not, because a draft is allowed to be
     * unfinished. Only submit() demands completeness.
     *
     * @param array<string,string> $specs
     */
    private function rejectImpossibleSpecs(string $categoryKey, array $specs): ?OperationResult
    {
        $template = $this->templates->findByCategory($categoryKey);
        if ($template === null) {
            return null;
        }
        $invalid = [];
        foreach ($template->askedFields() as $field) {
            $value = trim($specs[$field->key] ?? '');
            if ($value !== '' && !$field->type->accepts($value, $field->options)) {
                $invalid[] = $field->label;
            }
        }
        return $invalid === []
            ? null
            : OperationResult::failure('invalid_specs', ['fields' => implode('، ', $invalid)]);
    }

    /**
     * Drops duplicates, anything the shop does not own, and anything past the
     * limit.
     *
     * The ownership test is the important one: image ids arrive in a form
     * field, so without it a vendor could post the id of somebody else's
     * attachment and publish it on their own product page (AC-PRIV).
     *
     * @param list<int> $imageIds
     * @return array{0:list<int>,1:int}
     */
    private function cleanImages(array $imageIds, int $mainImageId, int $vendorUserId): array
    {
        $ids = [];
        foreach ($imageIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true) && $this->images->ownedBy($id, $vendorUserId)) {
                $ids[] = $id;
            }
        }
        $ids = array_slice($ids, 0, self::MAX_IMAGES);
        if ($ids === []) {
            return [[], 0];
        }
        // The main image must be one of the gallery's, or the product card
        // would show a picture the gallery does not contain.
        return [$ids, in_array($mainImageId, $ids, true) ? $mainImageId : $ids[0]];
    }

    /** @return array<string,mixed> */
    private function detailsToArray(ProductDetails $d): array
    {
        return [
            'title' => $d->title,
            'type' => $d->type,
            'categoryKey' => $d->categoryKey,
            'brand' => $d->brand,
            'shortDescription' => $d->shortDescription,
            'priceMinor' => $d->priceMinor,
            'salePriceMinor' => $d->salePriceMinor,
            'saleFrom' => $d->saleFrom,
            'saleTo' => $d->saleTo,
            'sku' => $d->sku,
            'stock' => $d->stock,
            'minPurchase' => $d->minPurchase,
            'maxPurchase' => $d->maxPurchase,
            'weightGrams' => $d->weightGrams,
            'dimensions' => $d->dimensions,
            'taxClass' => $d->taxClass,
        ];
    }
}
