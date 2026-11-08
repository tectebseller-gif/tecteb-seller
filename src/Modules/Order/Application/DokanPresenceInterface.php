<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * Whether Dokan is RUNNING on this request — the one question that decides
 * whether this marketplace may record an order at all.
 *
 * ### Why this is a question, and why it is this question
 *
 * Dokan attributes a product to a vendor by **`post_author`**, and
 * `TransferOwnership` deliberately never changes it (`alpha.90`): a
 * transferred product is still that Dokan seller's product as far as Dokan is
 * concerned. This marketplace asks a different thing —
 * `findByWcProduct()` filters on `link_ownership = 'marketplace'`
 * (`DbProductRepository::OWNED`). So for the 184 products of shop 102 **both
 * answers are yes at once**, and if both order paths run, one sale is
 * recorded twice: Dokan writes its sub-order and commission, and
 * `CaptureOrder` writes `tmc_order_items` plus a balanced ledger transaction.
 *
 * Until this round the only thing standing between that and a live site was
 * `OrderOperationsGate::OPEN_DECISIONS` — a `const` that cannot be changed
 * without a release. That is a real guard, and it is **not** the guard for
 * this hazard: it is a sequencing accident. The moment the owner closes
 * DEC-02 and DEC-04, or presses the trial switch on a staging site where
 * Dokan is active, the two paths run together with nothing in the way.
 *
 * ### ⛔ Why it does NOT ask whether Dokan's TABLES exist
 *
 * `DokanReaderInterface::isAvailable()` answers «installed **or** the tables
 * are there», which is right for a migration reader: it has to keep reading
 * the archive after Dokan is switched off. Reusing it here would be wrong in
 * a way that only shows up later — `DOKAN-EXIT-PLAN-FA.md` deliberately
 * KEEPS Dokan's tables (deactivation is not deletion, and the financial
 * archive is the evidence), so a table-shaped test would block order
 * recording **for ever after the exit**, which is the one state this whole
 * migration exists to reach.
 *
 * So the question is «is Dokan's code loaded, or active and failing to
 * load», and nothing else.
 *
 * ### Fail-closed
 *
 * Every signal is a positive detection of Dokan; the absence of all of them
 * is the only answer that lets an order be recorded. In particular the
 * active-plugin list is consulted, so a Dokan that is **active but broken**
 * — its first line `require`s an encrypted loader that may fatal, measured in
 * `alpha.53` — still closes the gate. A half-loaded plugin may already have
 * registered hooks, and «it crashed before I could tell» is not a licence to
 * record money.
 */
interface DokanPresenceInterface
{
    /** True when Dokan is loaded, or active and failing to load. */
    public function isRunning(): bool;

    /**
     * WHICH signal said so, for the screen and the audit row — or '' when
     * none did.
     *
     * A name rather than a boolean because the remedy differs: a loaded
     * Dokan is deactivated, while a Dokan that only appears in the active
     * list is a plugin that is failing to boot and needs looking at before
     * anything else.
     */
    public function signal(): string;
}
