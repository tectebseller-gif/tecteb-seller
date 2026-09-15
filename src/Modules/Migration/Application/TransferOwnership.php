<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * The moment this marketplace starts deciding about somebody else's product —
 * and the only way it can happen.
 *
 * An imported row is `observed`: it knows which WooCommerce product it
 * corresponds to, and nothing follows from that. Transferring makes it
 * `marketplace`, and everything follows at once: the purchase guard starts
 * answering about that product, the storefront stop will draft it, the
 * projector will write to it. On a live site that means a product a Dokan
 * vendor is selling today becomes a product this plugin can take off sale.
 *
 * So it is a separate service with a separate button and a separate audit
 * line, it needs the manager's capability, and it is reversible by the same
 * explicit act in the other direction. Nothing infers it, nothing does it in
 * passing, and no import has ever performed it.
 *
 * It writes exactly two things to the WooCommerce post, and nothing else: the
 * link meta that says which marketplace row owns it, and the vendor meta. That
 * write is unavoidable and it is the point — without it the projector keeps
 * refusing the post (`owns()` asks for exactly that meta), so a "transfer"
 * would be a label that changed nothing and a later stop would report itself
 * incomplete forever. Measured: before this write, taking ownership and then
 * stopping left the product published and the stop failing.
 *
 * The product's title, price, stock, status and author are NOT touched. Giving
 * ownership back deletes the same two keys, so the post returns to exactly the
 * state it was in.
 */
final class TransferOwnership
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly CatalogProjectorInterface $catalog,
        private readonly AuditLogger $audit,
        private readonly ?CapabilityCheckerInterface $capabilities = null
    ) {
    }

    /** Observed → marketplace: this plugin now decides about the product. */
    public function take(int $productId): OperationResult
    {
        return $this->move($productId, LinkOwnership::Observed, LinkOwnership::Marketplace, 'ownership_taken');
    }

    /** Marketplace → observed: it goes back to being a note in a ledger. */
    public function giveBack(int $productId): OperationResult
    {
        return $this->move($productId, LinkOwnership::Marketplace, LinkOwnership::Observed, 'ownership_given_back');
    }

    public function currentOwnership(int $productId): LinkOwnership
    {
        return $this->products->linkOwnership($productId);
    }

    private function move(int $productId, LinkOwnership $from, LinkOwnership $to, string $code): OperationResult
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_VENDOR)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        $current = $this->products->linkOwnership($productId);
        if ($current !== $from) {
            // Said plainly rather than as a generic failure: the person is
            // asking about who controls a product, and "it is already the
            // other way round" is the answer they need.
            return OperationResult::failure('ownership_already', [
                'product_id' => $productId,
                'ownership' => $current->value,
            ]);
        }
        $wcProductId = (int) ($product->wcProductId ?? 0);
        if ($wcProductId > 0) {
            // The post first: if this fails the row must NOT change, or the
            // marketplace would claim a product it cannot actually act on.
            $claimed = $to === LinkOwnership::Marketplace
                ? $this->catalog->claimStorefrontPost($product, $wcProductId)
                : $this->catalog->releaseStorefrontPost($product, $wcProductId);
            if (!$claimed) {
                return OperationResult::failure('storefront_refused', [
                    'product_id' => $productId,
                    'wc_product_id' => $wcProductId,
                ]);
            }
        }
        if (!$this->products->setLinkOwnership($productId, $to)) {
            return OperationResult::failure('storage_failed');
        }
        $actorId = $this->capabilities->currentUserId() ?? 0;
        $this->audit->log(AuditEventCatalog::DOKAN_OWNERSHIP_CHANGED, $actorId, 'product', (string) $productId, [
            'product_id' => $productId,
            'wc_product_id' => (int) ($product->wcProductId ?? 0),
            'from' => $from->value,
            'to' => $to->value,
        ]);
        return OperationResult::success($code, [
            'product_id' => $productId,
            'wc_product_id' => (int) ($product->wcProductId ?? 0),
        ]);
    }
}
