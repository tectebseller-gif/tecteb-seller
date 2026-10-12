<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * Brands: the vendor chooses from the manager's list, or asks for a new name.
 *
 * §4 in one paragraph. The approved list is WooCommerce's own `product_brand`
 * taxonomy (`BrandDirectoryInterface` says why it is not a table of ours). A
 * vendor picks from it; free text cannot add to it; a name that is missing is
 * REQUESTED, and only a manager's decision puts it in the list. After that the
 * brand is written onto the product, which is the path the owner measured
 * working — WooCommerce → Product data → Attributes → Brand — and that path is
 * not changed by any of this.
 *
 * **The two halves are separate because the owner asked for them to be.**
 * «ثبت برند و اعمال آن روی محصول باید قابل تفکیک و تلاش دوباره باشد». So
 * approving does two writes and records what each one did: the brand reaching
 * the list is `registered`, the brand reaching the product is `approved`, and a
 * failure in between leaves a request whose `outstanding()` says exactly which
 * is left. Nothing reports success for a half that did not happen.
 */
final class ManageBrands
{
    public function __construct(
        private readonly BrandDirectoryInterface $brands,
        private readonly BrandProposalRepositoryInterface $proposals,
        private readonly ProductRepositoryInterface $products,
        private readonly SyncCatalog $catalog,
        private readonly StaffAccess $access,
        private readonly CapabilityCheckerInterface $capabilities,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * The picker's own list, and whether the vendor may ask for more.
     *
     * @return array{available:bool, brands:list<\Tecteb\Marketplace\Modules\Product\Domain\Brand>, total:int}
     */
    public function pick(string $query, int $limit = 30): array
    {
        return [
            'available' => $this->brands->isAvailable(),
            'brands' => $this->brands->search($query, $limit),
            'total' => $this->brands->count(),
        ];
    }

    /**
     * The one question every door that accepts a brand asks.
     *
     * The vendor form, the admin panel and the CSV importer all come here, so
     * there is ONE rule rather than three sentences that happen to agree today
     * (the `alpha.46` rule). It answers in three ways:
     *
     *  - **null** — the brand is fine. Either it is in the list, or it is the
     *    value the row already holds, which is how a legacy free-text brand
     *    goes on being saved without being declared approved.
     *  - **a refusal naming the proposal path** — the name is not in the list.
     *  - **a refusal naming the list itself** — there is no list to check
     *    against, which happens only without WooCommerce. Accepting the name
     *    silently there is what creates the duplicate official brands §4
     *    forbids, so it is refused with its own code and its own sentence.
     *
     * @param string $current the brand the row holds now
     */
    public function refusalFor(string $proposed, string $current): ?OperationResult
    {
        $proposed = BrandName::display($proposed);
        if ($proposed === '') {
            return null;                        // no brand is a valid answer
        }
        if (BrandName::same($proposed, $current)) {
            return null;                        // unchanged: never re-judged
        }
        if (!$this->brands->isAvailable()) {
            return OperationResult::failure('brand_list_unavailable', ['brand' => $proposed]);
        }
        if ($this->brands->findByKey(BrandName::key($proposed)) !== null) {
            return null;
        }
        return OperationResult::failure('brand_not_approved', ['brand' => $proposed]);
    }

    /** The approved brand this name is, for storing the term id beside it. */
    public function approvedTermFor(string $brand): ?int
    {
        $found = $this->brands->findByKey(BrandName::key($brand));
        return $found?->termId;
    }

    /**
     * The vendor's typed name, when it is not a brand in the list.
     *
     * **This is no longer its own button.** `alpha.48` gave it a field and a
     * submit of its own and the owner rejected that shape: one box, one save.
     * So this is called from the ordinary «ذخیره و ادامه», after the product
     * itself has been stored, and the product's own brand is NOT touched —
     * «تا تأیید، برند فعلی محصول حفظ شود» is that line and nothing else.
     *
     * **Pressing save twice must not ask twice.** The owner asked for exactly
     * that: «ذخیرهٔ تکراری، دوبار کلیک یا تلاش مجدد پس از خطا نباید درخواست یا
     * برند تکراری بسازد». So the open request is read first and three things
     * can be true of it:
     *
     *  - it asks for this same normalised name — nothing is written, and the
     *    answer says the request is still waiting rather than reporting an
     *    error about a save that worked;
     *  - it asks for something else — it is SUPERSEDED and the new one opened,
     *    as one unit of work, because the vendor retyping is the vendor
     *    changing their mind and the latest typing is what they asked for;
     *  - there is none — one is opened.
     *
     * A request that was already REGISTERED can be superseded too, and the
     * cost is stated: the brand stays in the manager's list, because that half
     * was asked for and granted and nothing here removes an approved brand;
     * what stops is applying it to this product, which is what the vendor just
     * said they did not want.
     */
    public function propose(int $actorId, int $vendorUserId, int $productId, string $name): OperationResult
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        $display = BrandName::display($name);
        if (!BrandName::isValid($display)) {
            return OperationResult::failure('brand_name_invalid');
        }
        if (!$this->brands->isAvailable()) {
            return OperationResult::failure('brand_list_unavailable', ['brand' => $display]);
        }
        // Asked before anything is recorded: a name that IS already a brand
        // needs no request, and recording one would put a decision in front of
        // a manager that has nothing to decide. The form resolves this case
        // before calling, so reaching it means a brand was approved between
        // the render and the save — which is a reason to use it, not to ask.
        $existing = $this->brands->findByKey(BrandName::key($display));
        if ($existing !== null) {
            return OperationResult::failure('brand_already_approved', [
                'brand' => $existing->name,
                'term_id' => $existing->termId,
            ]);
        }
        $open = $this->proposals->openFor($productId);
        $replaced = $this->proposals->replaceOpen(
            $productId,
            $vendorUserId,
            $display,
            BrandName::key($display),
            $open?->id ?? 0
        );
        if ($replaced['code'] === 'unchanged') {
            // ZERO WRITES, and said as the saving it is: the product was
            // stored, and the request the vendor already made is still waiting.
            return OperationResult::success('brand_request_unchanged', [
                'brand' => $display,
                'proposal_id' => $replaced['id'],
            ]);
        }
        if ($replaced['code'] !== 'replaced') {
            // Each of these has written nothing. `taken` is the unique index
            // answering that somebody else is asking for this brand — an
            // answer, not a storage error (`alpha.39`) — and the vendor's
            // earlier request, if they had one, is untouched.
            return OperationResult::failure(match ($replaced['code']) {
                'taken' => 'brand_request_elsewhere',
                'not_atomic' => 'brand_request_not_atomic',
                'superseded_elsewhere' => 'brand_request_decided',
                default => 'storage_failed',
            }, ['brand' => $display]);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_SAVED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'brand_proposal_id' => $replaced['id'],
        ]);
        return OperationResult::success(
            $open === null ? 'brand_requested' : 'brand_request_replaced',
            ['proposal_id' => $replaced['id'], 'brand' => $display]
        );
    }

    /**
     * The manager's decision, from ONE field: attach, or correct and add.
     *
     * The owner asked for three things to be possible in the product's own
     * review path — «آن را به یک برند موجود متصل کند»، «نام پیشنهادی را اصلاح
     * و برند جدید را تأیید کند»، «پیشنهاد را رد کند» — and the first two are
     * the same gesture with a different answer, so they are one box and one
     * button rather than two of each. Whatever name is in the box is resolved
     * against the list exactly as the vendor's typing is:
     *
     *  - **it IS an approved brand** → that term is used and NOTHING is
     *    registered. This is «connect it to an existing brand», and it is also
     *    what makes correcting a misspelling of a brand that already exists do
     *    the right thing instead of creating its twin.
     *  - **it is not** → it is registered, under the manager's spelling.
     *
     * An empty box means «the name as asked», so the common case is one click.
     *
     * Then the two halves, in that order, recorded between them. Registering
     * first means a failure to apply still leaves the brand in the list for
     * every shop — the half that was asked for and granted — and the request
     * says `apply_to_product` is outstanding so `finish()` can try again.
     * Applying first would be a product pointing at a brand in no list.
     */
    public function approve(int $proposalId, string $name = ''): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_PRODUCTS)) {
            return OperationResult::failure('forbidden');
        }
        $proposal = $this->proposals->find($proposalId);
        if ($proposal === null) {
            return OperationResult::failure('not_found');
        }
        if (!$proposal->isPending()) {
            return OperationResult::failure('already_decided');
        }
        $chosen = BrandName::display($name);
        if ($chosen === '') {
            $chosen = $proposal->name;
        }
        if (!BrandName::isValid($chosen)) {
            return OperationResult::failure('brand_name_invalid', ['brand' => $chosen]);
        }
        // ATTACH OR REGISTER, decided by the list rather than by which button
        // was pressed. `register()` adopts an existing term for the same key
        // anyway, so this is not about avoiding a duplicate — it is about the
        // audit record saying which of the two a manager actually did.
        $attached = $this->brands->findByKey(BrandName::key($chosen));
        $brand = $attached ?? $this->brands->register($chosen);
        if ($brand === null) {
            // NOT recorded as anything. A refused write is not an absence, and
            // marking the request decided here would lose it.
            return OperationResult::failure('brand_not_registered', ['brand' => $chosen]);
        }
        $reviewer = $this->capabilities->currentUserId() ?? 0;
        if (!$this->proposals->decide($proposalId, BrandProposal::REGISTERED, $reviewer, '', $brand->termId, BrandProposal::PENDING)) {
            // The brand IS in the list now and the record of it is not. Said
            // plainly, because the two are different facts and a manager who
            // is told «it failed» would add the brand by hand a second time.
            return OperationResult::failure('brand_registered_not_recorded', [
                'brand' => $brand->name,
                'term_id' => $brand->termId,
            ]);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_REVISION_REVIEWED, $reviewer, 'product', (string) $proposal->productId, [
            'vendor_id' => $proposal->vendorUserId,
            'product_id' => $proposal->productId,
            'brand_proposal_id' => $proposalId,
            'decision' => BrandProposal::REGISTERED,
            'attached' => $attached !== null,
            'renamed' => !BrandName::same($brand->name, $proposal->name),
            'has_note' => false,
        ]);
        $done = $this->finish($proposalId);
        if (!$done->ok) {
            return $done;
        }
        return OperationResult::success($done->code, $done->context + [
            'attached' => $attached !== null ? '1' : '',
            'asked' => $proposal->name,
        ]);
    }

    /**
     * The second half, on its own, so it can be retried.
     *
     * Writes the approved brand onto the product and — when the product is
     * live — pushes it to the shop and READS the answer. «همگام‌سازی ناقص
     * نباید موفق گزارش شود» is the `alpha.30` rule, here at a new write.
     */
    public function finish(int $proposalId): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_PRODUCTS)) {
            return OperationResult::failure('forbidden');
        }
        $proposal = $this->proposals->find($proposalId);
        if ($proposal === null) {
            return OperationResult::failure('not_found');
        }
        if ($proposal->status !== BrandProposal::REGISTERED) {
            return OperationResult::failure('nothing_to_finish', ['status' => $proposal->status]);
        }
        $brand = $this->brands->find($proposal->termId);
        if ($brand === null) {
            return OperationResult::failure('brand_not_in_list', ['term_id' => $proposal->termId]);
        }
        $product = $this->products->find($proposal->productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->products->setBrandTerm($product->id, $brand->name, $brand->termId)) {
            return OperationResult::failure('brand_not_applied', [
                'brand' => $brand->name,
                'proposal_id' => $proposalId,
            ]);
        }
        if ($product->status === ProductStatus::Published) {
            $sync = $this->catalog->publish($product->id);
            if (!$sync->ok && $sync->code !== 'woocommerce_missing') {
                // The row carries the brand and the shop does not. The request
                // stays `registered`, so the manager can try again, and the
                // code says which half is missing rather than «it worked».
                return OperationResult::failure('brand_not_synced', [
                    'brand' => $brand->name,
                    'proposal_id' => $proposalId,
                    'reason' => $sync->code,
                ]);
            }
        }
        $reviewer = $this->capabilities->currentUserId() ?? 0;
        if (!$this->proposals->decide($proposalId, BrandProposal::APPROVED, $reviewer, '', $brand->termId, BrandProposal::REGISTERED)) {
            return OperationResult::failure('brand_applied_not_recorded', [
                'brand' => $brand->name,
                'proposal_id' => $proposalId,
            ]);
        }
        return OperationResult::success('brand_approved', [
            'brand' => $brand->name,
            'term_id' => $brand->termId,
            'product_id' => $product->id,
        ]);
    }

    /** Refusing a request leaves the product's brand exactly as it is. */
    public function reject(int $proposalId, string $note): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_PRODUCTS)) {
            return OperationResult::failure('forbidden');
        }
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        $proposal = $this->proposals->find($proposalId);
        if ($proposal === null) {
            return OperationResult::failure('not_found');
        }
        if (!$proposal->isPending()) {
            return OperationResult::failure('already_decided');
        }
        $reviewer = $this->capabilities->currentUserId() ?? 0;
        if (!$this->proposals->decide($proposalId, BrandProposal::REJECTED, $reviewer, $note, 0, BrandProposal::PENDING)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_REVISION_REVIEWED, $reviewer, 'product', (string) $proposal->productId, [
            'vendor_id' => $proposal->vendorUserId,
            'product_id' => $proposal->productId,
            'brand_proposal_id' => $proposalId,
            'decision' => BrandProposal::REJECTED,
            'has_note' => true,
        ]);
        return OperationResult::success('brand_rejected', ['proposal_id' => $proposalId]);
    }
}
