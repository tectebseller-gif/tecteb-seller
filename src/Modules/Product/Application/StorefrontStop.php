<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;

/**
 * Takes the marketplace's OWN products out of sale, and brings back only the
 * ones that still qualify.
 *
 * This exists because "suspend the vendors first" is an instruction, and an
 * instruction is not a mechanism. Deactivating the plugin, or rolling the
 * package back, used to leave every projected product on sale in a shop whose
 * guard had just been removed — purchasable, with nothing recording the money.
 * Now deactivation runs stop(), and a manager can run it deliberately before
 * touching the package at all.
 *
 * Two properties matter more than anything else here:
 *
 *  - **Nothing is deleted.** A product goes to `draft` and out of the catalogue
 *    exactly as a suspension does, so every order that referenced it still
 *    resolves and every row on this side is untouched.
 *  - **Nothing that is not ours is read or written.** The loop is over
 *    `projected()` — marketplace rows carrying a WooCommerce link — so the
 *    shop's own products and Dokan's are never even looked at.
 *
 * And coming back is not the mirror image of going away. resume() republishes
 * only what still qualifies: the shop may trade, the product is still
 * published in the marketplace's own workflow, and it is still complete.
 * Anything else stays out of the shop and is reported by name, because a
 * reactivation that quietly puts a broken or suspended product back on sale is
 * the failure this class was written to stop.
 */
final class StorefrontStop
{
    public const REFUSED_VENDOR_STOPPED = 'vendor_stopped';
    public const REFUSED_NOT_PUBLISHED = 'not_published';
    public const REFUSED_INCOMPLETE = 'incomplete';
    public const REFUSED_STOREFRONT = 'storefront_failed';

    /**
     * How many owned products one stop or resume looks at.
     *
     * ⛔ **STATED, AND THE OVERFLOW IS REPORTED.** This was the default
     * argument of `projected()` and nothing compared it with the real count,
     * so on a shop with more owned products than this the stop withdrew the
     * first five hundred, left the rest ON SALE, and named none of them —
     * not even in `stuck`, which only holds products the stop TRIED and
     * failed. Silence on this path is the worst outcome there is: after
     * deactivation nothing guards those products and nothing records the
     * money when they sell.
     *
     * The cap itself is kept rather than raised. Deactivation cannot be
     * refused and cannot be resumed; a loop long enough to time out inside it
     * would leave a WORSE half-done state than a loop that stops and says so.
     */
    public const SCAN_LIMIT = 500;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SyncCatalog $catalog,
        private readonly ProductReadiness $readiness,
        private readonly StaffAccess $access,
        private readonly StorefrontSwitch $switch,
        private readonly AuditLogger $audit,
        private readonly UnpaidOrderGuardInterface $unpaidOrders
    ) {
    }

    public function isStopped(): bool
    {
        return $this->switch->isStopped();
    }

    /**
     * Unpaid orders whose mailed pay link still takes money.
     *
     * Read-only, so the manager's screen can say "this is not finished"
     * without doing anything about it.
     *
     * @return list<int>
     */
    public function payableOrders(): array
    {
        return $this->unpaidOrders->stillPayable();
    }

    /**
     * What the last stop could not close, as it was written down.
     *
     * @return array{products:list<int>, orders:list<int>}
     */
    public function stuck(): array
    {
        return $this->switch->stuck();
    }

    /**
     * Every projected product out of the shop, and the flag set.
     *
     * The flag is set even when some withdrawals failed — a marketplace that
     * could not empty its shelf is precisely one that must keep refusing to
     * sell — but the flag is NOT the point of this method, and saying so is
     * the difference between this version and the last one.
     *
     * **A partial stop is a failure.** The flag is read only by this plugin,
     * while it is running. The whole reason a manager presses this button is
     * that they are about to take this plugin away: replace the package, roll
     * back, deactivate. From that moment the only thing standing between a
     * shopper and an unrecorded sale is `post_status = draft` on the product
     * post — a fact WooCommerce honours by itself. A product that did not get
     * there is a product that WILL be sold with nothing recording the money.
     *
     * So `failed > 0` is reported as a failure, by id, and every caller — the
     * admin screen, the CLI, deactivation — says so instead of «انجام شد».
     *
     * @return array{withdrawn:int, failed:int, total:int, stuck:list<int>,
     *               orders_held:int, orders_examined:int, orders_stuck:list<int>}
     */
    public function stop(string $reason, int $actorId = 0): array
    {
        $withdrawn = 0;
        $stuck = [];
        $projected = $this->products->projected(self::SCAN_LIMIT);
        $unseen = $this->unseen(count($projected));
        foreach ($projected as $product) {
            if ($this->catalog->withdraw($product->id, $reason)->ok) {
                $withdrawn++;
                continue;
            }
            $stuck[] = $product->id;
        }
        // The one door drafting a product does not close: a pay link that was
        // mailed before the stop. See UnpaidOrderGuardInterface for why this
        // is a separate act and not a filter.
        $orders = $this->unpaidOrders->hold($reason);
        $this->switch->markStopped($reason, $actorId, $withdrawn);
        // The durable note. Deactivation cannot refuse to happen — WordPress
        // gives a plugin no veto — so what it could not close is written down
        // in a place that outlives the run and is read on the next admin page.
        $this->switch->markStuck($stuck, $orders['stuck']);
        $this->switch->markUnseen($unseen);
        // A real stop answers the «left on sale» note, whoever wrote it: the
        // shelf is down now, so the sentence about it being up is no longer
        // true. Through the switch rather than `delete_option()`, because
        // Application may not call WordPress (`alpha.9`) — the switch is the
        // thing holding the option store.
        $this->switch->clearLeftOnSale();
        $this->audit->log(AuditEventCatalog::STOREFRONT_STOPPED, $actorId, 'storefront', 'marketplace', [
            'reason' => $reason,
            'withdrawn' => $withdrawn,
            'failed' => count($stuck),
            'unseen' => $unseen,
            'total' => count($projected),
            'orders_held' => $orders['held'],
            'orders_stuck' => count($orders['stuck']),
        ]);
        return [
            'withdrawn' => $withdrawn,
            'failed' => count($stuck) + count($orders['stuck']),
            'unseen' => $unseen,
            'total' => count($projected),
            'stuck' => $stuck,
            'orders_held' => $orders['held'],
            'orders_examined' => $orders['examined'],
            'orders_stuck' => $orders['stuck'],
        ];
    }

    /**
     * Back on sale — but only what still qualifies, one product at a time.
     *
     * @return array{published:int, refused:array<int,string>, unseen:int, total:int,
     *               orders_released:int, orders_stuck:list<int>}
     */
    public function resume(int $actorId = 0): array
    {
        $published = 0;
        $refused = [];
        $projected = $this->products->projected(self::SCAN_LIMIT);
        $unseen = $this->unseen(count($projected));
        foreach ($projected as $product) {
            $reason = $this->refusalFor($product->id);
            if ($reason !== null) {
                $refused[$product->id] = $reason;
                continue;
            }
            if (!$this->catalog->publish($product->id)->ok) {
                $refused[$product->id] = self::REFUSED_STOREFRONT;
                continue;
            }
            $published++;
        }
        // …and the pay links that were retired are handed back — the very
        // links, not new ones, so a customer's mail still works.
        $orders = $this->unpaidOrders->release();
        // The flag lifts even when some products were refused: the
        // marketplace may sell again, and the ones that may not are named.
        $this->switch->markResumed();
        // ⛔ The unseen count SURVIVES a resume rather than being cleared with
        // the stop flag: those products were never looked at on the way out
        // and are not looked at on the way back either, so the manager's
        // question — «کدام‌ها را ندیدی؟» — is still unanswered.
        $this->switch->markUnseen($unseen);
        $this->audit->log(AuditEventCatalog::STOREFRONT_RESUMED, $actorId, 'storefront', 'marketplace', [
            'published' => $published,
            'refused' => count($refused),
            'unseen' => $unseen,
            'total' => count($projected),
            'orders_released' => $orders['released'],
            'orders_reconcile' => count($orders['reconcile']),
        ]);
        return [
            'published' => $published,
            'refused' => $refused,
            'unseen' => $unseen,
            'total' => count($projected),
            'orders_released' => $orders['released'],
            'orders_stuck' => $orders['stuck'],
        ];
    }

    /**
     * How many owned products this scan never reached.
     *
     * Three answers, not two. A count that could not be read is `null`
     * (`alpha.8`), and reading it as nought would say «I saw everything» on
     * exactly the failure where that is least likely to be true — so an
     * unreadable count is reported as a scan that filled its cap, which is
     * the conservative direction: it tells the manager to look.
     */
    private function unseen(int $seen): int
    {
        return self::unseenFrom($this->products->countProjected(), $seen, self::SCAN_LIMIT);
    }

    /**
     * The rule itself, pure — and public so it can be MEASURED.
     *
     * ⛔ **WHY THIS IS NOT INLINE ANY MORE.** The branch that matters is «the
     * count could not be read AND the scan filled its cap», and reaching it
     * through `stop()` needs five hundred and one real products. A mutation
     * that turned that branch into `return 0` — the exact silence this round
     * removed — SURVIVED the whole suite, because every fixture sits far
     * below the cap and both versions answer nought there. `alpha.77`'s
     * remedy: make the branch reachable rather than leave it unguarded.
     *
     * Nought when the count is known and the scan saw it all; the difference
     * when it did not; and on an unreadable count, «there is something I did
     * not see» ONLY when the scan filled up — which is the conservative
     * direction, because it tells the manager to look.
     */
    public static function unseenFrom(?int $total, int $seen, int $limit): int
    {
        if ($total === null) {
            return $seen >= $limit ? 1 : 0;
        }
        return max(0, $total - $seen);
    }

    /**
     * Whether ONE product may go on sale right now.
     *
     * The same per-product question resume() asks, plus the marketplace-wide
     * one: while selling is stopped, nothing goes live — not a reinstated
     * vendor's catalogue, not a newly approved product. resume() does not use
     * this method, because resume() is the act of lifting that very stop.
     */
    public function mayGoLive(int $productId): bool
    {
        return !$this->switch->isStopped() && $this->refusalFor($productId) === null;
    }

    /** @return string|null the reason it may not go back, or null when it may */
    private function refusalFor(int $productId): ?string
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return self::REFUSED_NOT_PUBLISHED;
        }
        if (!$this->access->vendorCanTrade($product->vendorUserId)) {
            return self::REFUSED_VENDOR_STOPPED;
        }
        if ($product->status !== ProductStatus::Published) {
            return self::REFUSED_NOT_PUBLISHED;
        }
        // The same completeness check the vendor's submit and the manager's
        // approve run (F-10). A product that lost its last image while the
        // shop was closed does not come back just because the shop reopened.
        if (!$this->readiness->check($product)->ok) {
            return self::REFUSED_INCOMPLETE;
        }
        return null;
    }

    /**
     * Hands back the orders this stop held — WITHOUT reopening the shop.
     *
     * A separate act from resume(), and the separation is the whole point. A
     * manager rolling back to a previous package has to be able to give
     * customers their unpaid orders back while the marketplace stays shut; a
     * release that only came bundled with «از سرگیری فروش» would force them to
     * reopen buying for a moment to do it, which is the opposite of safe.
     *
     * The flag is untouched, the products stay out of the shop, and only the
     * orders this plugin put on hold are moved — one at a time, restoring the
     * status each of them had.
     */
    public function releaseOrders(int $actorId = 0): OperationResult
    {
        $outcome = $this->unpaidOrders->release();
        // A stuck order and one that needs reconciliation are both «not
        // finished», so both keep the stop's own list warm. They are reported
        // apart because they need different things: one a retry, the other a
        // person.
        $this->switch->markStuck(
            $this->switch->stuck()['products'],
            array_values(array_unique(array_merge($outcome['stuck'], $outcome['reconcile'])))
        );
        $this->audit->log(AuditEventCatalog::STOREFRONT_ORDERS_RELEASED, $actorId, 'storefront', 'marketplace', [
            'released' => $outcome['released'],
            'stuck' => count($outcome['stuck']),
            'moved_on' => count($outcome['moved_on']),
            'reconcile' => count($outcome['reconcile']),
        ]);
        if ($outcome['reconcile'] !== []) {
            // Deliberately ahead of `stuck`: an order whose stock did not come
            // back is the more expensive of the two to leave unread.
            return OperationResult::failure('orders_need_reconciliation', [
                'released' => $outcome['released'],
                'reconcile' => implode('، ', array_map('strval', $outcome['reconcile'])),
            ]);
        }
        if ($outcome['stuck'] !== []) {
            return OperationResult::failure('orders_release_incomplete', [
                'released' => $outcome['released'],
                'stuck' => implode('، ', array_map('strval', $outcome['stuck'])),
            ]);
        }
        return OperationResult::success('orders_released', [
            'released' => $outcome['released'],
            'moved_on' => count($outcome['moved_on']),
        ]);
    }

    /**
     * Orders whose stock did not come back where it started.
     *
     * Asked by the manager's screen, which has to be able to say «این‌ها را
     * دستی بررسی کنید» without changing anything.
     *
     * @return list<int>
     */
    public function needingReconciliation(): array
    {
        return $this->unpaidOrders->needsReconciliation();
    }

    /**
     * A manager records what they did about one flagged order.
     *
     * The only path that clears a reconcile mark, and it needs a note: an
     * unexplained resolution is the same as no resolution, because the next
     * person to look will find a cleared flag and no idea what happened.
     */
    public function resolveReconciliation(int $orderId, int $actorId, string $note): OperationResult
    {
        // Capability is the SCREEN's gate here, the same as `releaseOrders()`
        // and `stop()`: this service has no capability checker, and inventing
        // one for a single method would put the same rule in two places and
        // let them drift.
        if (trim($note) === '') {
            return OperationResult::failure('reconcile_note_required');
        }
        if (!$this->unpaidOrders->resolveReconciliation($orderId, $actorId, $note)) {
            return OperationResult::failure('reconcile_failed', ['order_id' => $orderId]);
        }
        $this->audit->log(AuditEventCatalog::STOREFRONT_RECONCILED, $actorId, 'order', (string) $orderId, [
            'order_id' => $orderId,
            'has_note' => true,
        ]);
        // The stop's own «still not finished» list is recomputed from what is
        // left, so resolving the last one is what finally clears the notice.
        $current = $this->switch->stuck();
        $this->switch->markStuck(
            $current['products'],
            array_values(array_diff($current['orders'], [$orderId]))
        );
        return OperationResult::success('reconcile_recorded', ['order_id' => $orderId]);
    }

    /**
     * Result-shaped wrapper, for the screens that report to a person.
     *
     * Succeeds only when the shelf is actually empty. One product left on sale
     * is not a footnote on a success message — it is the failure, because the
     * person reading it is about to remove the code that would have guarded it.
     */
    public function stopAsResult(string $reason, int $actorId = 0): OperationResult
    {
        $outcome = $this->stop($reason, $actorId);
        // ⛔ `unseen` COUNTS AS INCOMPLETE, and until this round it did not.
        //
        // `failed` was `count($stuck) + count($orders['stuck'])` only, so on a
        // shop with more products than `SCAN_LIMIT` and no per-product
        // failure this returned a plain success — «done» on the screen while
        // the 501st product was still `publish`. The number was persisted and
        // raised as a notice elsewhere, which is not the same as the operator
        // who pressed the button being told.
        //
        // Unreachable at 184 products, and it is exactly the defect §6 names:
        // «هیچ سقف پنهان مانند ۵۰۰ نباید باعث باقی‌ماندن محصولات فروش‌پذیر
        // شود.» A cap is allowed; a cap that reports success is not.
        if ($outcome['failed'] > 0 || $outcome['unseen'] > 0) {
            return OperationResult::failure('storefront_stop_incomplete', [
                'withdrawn' => $outcome['withdrawn'],
                'failed' => $outcome['failed'],
                'unseen' => $outcome['unseen'],
                'total' => $outcome['total'],
                'stuck' => implode('، ', array_map('strval', $outcome['stuck'])),
                'orders_stuck' => implode('، ', array_map('strval', $outcome['orders_stuck'])),
            ]);
        }
        return OperationResult::success('storefront_stopped', [
            'withdrawn' => $outcome['withdrawn'],
            'total' => $outcome['total'],
            'orders_held' => $outcome['orders_held'],
        ]);
    }

    /**
     * Resuming reports success even with refusals, and that asymmetry is
     * deliberate: a product kept OFF sale is the safe outcome, so naming it is
     * information. A product left ON sale is the unsafe one, so it is an error.
     */
    public function resumeAsResult(int $actorId = 0): OperationResult
    {
        $outcome = $this->resume($actorId);
        return OperationResult::success('storefront_resumed', [
            'published' => $outcome['published'],
            'refused' => count($outcome['refused']),
            'total' => $outcome['total'],
            'orders_released' => $outcome['orders_released'],
        ]);
    }
}
