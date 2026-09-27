<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

/**
 * What an approved shop's first screen is about.
 *
 * Until `alpha.33` the vendor dashboard was the APPLICATION's screen with the
 * shop's work bolted on top: the largest thing on it was a list of
 * registration steps that were all finished months ago, and the approval was
 * announced twice — once as a status card and once as a «نتیجه بررسی» task.
 * A shop that has been selling for a month opens this page to find out what to
 * do today.
 *
 * So the page is built from this, and every number on it is read once, here:
 *
 *  - the product counters come from `countsByStatus()`, which is the SAME
 *    query the vendor's own product list builds its tabs from, so a card and
 *    the list it links to cannot disagree;
 *  - «نیازمند اقدام» is the products the manager asked to be corrected, each
 *    with the manager's own words, read through the vendor-scoped decision
 *    query — not a second store of messages;
 *  - what is waiting on the MANAGER is kept apart from what is waiting on the
 *    vendor, because «۳ محصول در انتظار بررسی» is not a task and a screen that
 *    mixes the two teaches people to ignore both.
 *
 * Nothing here calls WordPress and nothing here decides permission: the route
 * asks `StaffAccess` and hands the answers over, so a staff member sees the
 * cards their rights cover and nothing else.
 */
final class VendorDashboard
{
    /**
     * Task keys that are `Waiting` on something that is not a person's
     * decision, and so are not «منتظر تصمیم مدیر».
     *
     * @var list<string>
     */
    private const NOT_A_PERSONS_DECISION = ['mobile'];

    /**
     * @param array<string,int>|null $productCounts status value => how many, this
     *        shop only. **`null` means «not read»**, which is not the same thing
     *        as four zeros: a site with the product module switched off, or a
     *        query that failed, must say so rather than tell a shop with sixty
     *        products that it has none.
     * @param list<array{id:int,title:string,note:string}> $needsWork products the manager sent back
     */
    public function __construct(
        public readonly VendorWorkspace $workspace,
        public readonly string $storeName,
        /** '' when the shop has no page a customer could open — see `StorePage`. */
        public readonly string $storefrontUrl,
        public readonly ?array $productCounts,
        public readonly array $needsWork,
        /**
         * The SHOP's own application status — not the viewer's.
         *
         * A staff member has no application of their own, and branching on
         * `$workspace->isApprovedVendor()` (which asks about the VIEWER) showed
         * a shop's employee «وضعیت درخواست شما: پیش‌نویس — هنوز درخواستی ثبت
         * نکرده‌اید» above that shop's real work: an invitation to apply for a
         * shop they already work in. Measured on the installed package.
         */
        public readonly ApplicationStatus $shopStatus,
        /** Whether the SHOP may trade — the operational key, not the paper one. */
        public readonly bool $shopCanSell,
        public readonly bool $isOwner,
        public readonly bool $mayViewProducts,
        public readonly bool $mayEditProducts,
        public readonly bool $canPublishDirectly,
        /** Where support lives, when that module registered a page. */
        public readonly string $supportUrl = '',
        /**
         * The VIEWER's own standing in this shop, when they are on its roster.
         *
         * `null` for the shop's owner and for somebody who works nowhere. It is
         * set from `StaffAccess::membershipFor()`, which asks about membership
         * and not about permission — the three booleans above are still the
         * only permissions this object carries, and they still come from
         * `can()`.
         *
         * It exists because «may act» and «is a member» are different questions
         * and `alpha.33` answered the second with the first: a suspended shop's
         * employee derives no rights, so `storeFor()` said `null`, so the screen
         * concluded they belonged to no shop and offered them a registration
         * form for the shop they work in.
         */
        public readonly ?VendorStaffStanding $staffStanding = null
    ) {
    }

    /** Is the person reading this on the shop's staff roster rather than its owner? */
    public function viewerIsStaff(): bool
    {
        return $this->staffStanding !== null;
    }

    /**
     * Is the viewer's OWN access live — separately from whether the shop is?
     *
     * Two halves, and a screen needs both: a live member of a suspended shop and
     * a suspended member of a working shop are in different situations and only
     * one of them has anything to wait for.
     */
    public function viewerCanAct(): bool
    {
        return $this->staffStanding === null || $this->staffStanding->canAct();
    }

    /** Were the counters read at all? See the constructor on `null`. */
    public function countsRead(): bool
    {
        return $this->productCounts !== null;
    }

    public function countOf(ProductStatus $status): int
    {
        return (int) (($this->productCounts ?? [])[$status->value] ?? 0);
    }

    /** Products the manager is holding: submitted, waiting for a decision. */
    public function waitingOnManager(): int
    {
        return $this->countOf(ProductStatus::Submitted);
    }

    /** @return list<WorkspaceTask> the vendor's own unfinished paperwork */
    public function openTasks(): array
    {
        return array_values(array_filter(
            $this->workspace->tasks(),
            static fn (WorkspaceTask $task): bool => $task->state === TaskState::Todo
        ));
    }

    /**
     * @return list<WorkspaceTask> the rows the MANAGER is holding
     *
     * «منتظر تصمیم مدیر» has to be about the manager. `mobile` is `Waiting`
     * too, and it waits on an SMS adapter that does not exist — listing it
     * under a heading that names the manager tells the vendor to go and ask
     * somebody about something nobody can do anything about. It stays in the
     * paperwork fold, where its own sentence says no action is needed.
     */
    public function waitingOnManagerTasks(): array
    {
        return array_values(array_filter(
            $this->workspace->tasks(),
            static fn (WorkspaceTask $task): bool => $task->state === TaskState::Waiting
                && !in_array($task->key, self::NOT_A_PERSONS_DECISION, true)
        ));
    }

    /** @return list<WorkspaceTask> everything already done — the fold's contents */
    public function settledTasks(): array
    {
        return array_values(array_filter(
            $this->workspace->tasks(),
            static fn (WorkspaceTask $task): bool => $task->state !== TaskState::Todo
        ));
    }

    /**
     * Is there anything for the VENDOR to do?
     *
     * Deliberately not «is anything happening»: a shop with three products in
     * review has plenty happening and nothing to do, and telling it otherwise
     * is how a warning stops meaning anything.
     */
    public function hasWork(): bool
    {
        return $this->needsWork !== [] || ($this->isOwner && $this->openTasks() !== []);
    }

    /**
     * Is this a working shop's screen, or somebody's application?
     *
     * Asked about the SHOP, so a staff member of an approved shop gets the
     * shop's screen even though they have never applied for anything.
     */
    public function shopIsOpen(): bool
    {
        return $this->shopStatus === ApplicationStatus::Approved && $this->shopCanSell;
    }
}
