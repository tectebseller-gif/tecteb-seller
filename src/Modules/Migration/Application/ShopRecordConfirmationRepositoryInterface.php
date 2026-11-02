<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * The manager's explicit confirmation that a shop's Dokan records were fully
 * brought over — stored, revocable, and never inferred.
 *
 * This port exists because of what the owner's ruling forbids and what it
 * requires. It may NOT write `tmc_dokan_handover` — that row is the FINANCIAL
 * decision, which this decision is explicitly not allowed to change — and it
 * may not write ownership or `can_sell`. And it MUST record WHICH version of
 * the imported past was confirmed, so a later re-import, rollback or extension
 * cannot leave an old confirmation «بی‌صدا معتبر».
 *
 * ⛔ **There is no method here that confirms anything implicitly, in bulk, or
 * for «every shop whose records look settled».** `confirm()` takes ONE vendor
 * id, and `ShopRecordConfirmationIsNeverAutomaticTest` asserts from the
 * implementation's bytes that nothing but the one admin action reaches it.
 */
interface ShopRecordConfirmationRepositoryInterface
{
    /**
     * Whether confirmations can be stored on this install, with THREE answers.
     *
     * `false` is schema 36 — the table is not there, so the feature does not
     * exist and the record gate behaves exactly as `alpha.90` shipped. `null`
     * is «the read failed», which is NOT the same and must never be reported
     * as «nobody confirmed»: it is the reason the screen says it could not ask
     * rather than printing a verdict (`alpha.68`).
     */
    public function supported(): ?bool;

    /**
     * The LIVE confirmation for this shop, if any.
     *
     * `null` means either «nothing is confirmed» or «the read could not be
     * done», and the caller tells them apart by asking `supported()` first —
     * which is why that method has three answers and this one does not need
     * four.
     *
     * @return array{records_version:int, reconciliation:string, confirmed_by:int, confirmed_at:string}|null
     */
    public function liveFor(int $vendorUserId): ?array;

    /**
     * Record ONE confirmation. `''` on success, otherwise a named refusal.
     *
     * `$recordsVersion` is the shop's `records_version` at the moment of the
     * confirmation, and `$reconciliation` is what was reconciled, in words a
     * person reads. The version is the thing that is COMPARED later; the words
     * are never compared, because a fingerprint of verdicts would collide the
     * moment a rollback restored exactly what was there before (migration 18
     * made that argument for the counter itself).
     *
     * Refusals: `confirmation_unsupported` (schema 36),
     * `confirmation_table_unreadable`, `already_confirmed` (a live
     * confirmation is already there — read back rather than guessed from the
     * failed write, `alpha.28`), `storage_failed`.
     */
    public function confirm(int $vendorUserId, int $recordsVersion, string $reconciliation, int $actorId): string;

    /**
     * Withdraw the live confirmation. `''` on success, otherwise a named
     * refusal.
     *
     * The row is KEPT and only its live key is cleared, so «who confirmed this
     * and when» survives the revocation — that is what makes one table answer
     * both «قابل لغو» and «ممیزی‌پذیر».
     *
     * Refusals: `confirmation_unsupported`, `confirmation_table_unreadable`,
     * `not_confirmed` (there was no live confirmation), `storage_failed`.
     */
    public function revoke(int $vendorUserId, int $actorId): string;
}
