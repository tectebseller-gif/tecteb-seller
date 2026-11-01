<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

/**
 * The manager's explicit acceptance that WooCommerce's own default term is a
 * product's category — stored, revocable, and never inferred.
 *
 * This port exists because of two things the owner's ruling forbids and one
 * it requires. It may NOT write `tmc_products.category_key` and it may NOT
 * write a `tmc_product_categories` row — those two mean «the spec template to
 * hold this product to» and «a `product_cat` relationship this marketplace
 * claims», and the second is written back to WooCommerce by the projector.
 * And it MUST record which term was accepted, so a later change to the term
 * or to the shop's `default_product_cat` setting cannot leave an old decision
 * «بی‌صدا معتبر».
 *
 * ⛔ **There is no method here that accepts anything implicitly, in bulk, or
 * for «every product in this state».** `accept()` takes ONE product id, and
 * `DefaultCategoryIsNeverAutomaticTest` asserts from the implementation's
 * bytes that nothing outside the one admin action reaches it.
 */
interface DefaultCategoryAcceptanceRepositoryInterface
{
    /**
     * Whether acceptances can be stored on this install, with THREE answers.
     *
     * `false` is schema 35 — the table is not there yet, so the feature does
     * not exist and the gate behaves exactly as `alpha.88` shipped. `null` is
     * «the read failed», which is NOT the same and must never be reported as
     * «no decision was taken»: it is the reason the screen says it could not
     * ask rather than printing a number (`alpha.68`).
     */
    public function supported(): ?bool;

    /**
     * The LIVE acceptance of each of these products, if any.
     *
     * Keyed by product id; a product with no live acceptance is absent from
     * the result. `null` means the read could not be done at all — and the
     * caller must not read that as «nobody accepted anything» (`alpha.8`).
     *
     * @param list<int> $productIds
     * @return array<int, array{term_id:int, decided_by:int, decided_at:string}>|null
     */
    public function liveFor(array $productIds): ?array;

    /**
     * Record ONE acceptance. `''` on success, otherwise a named refusal.
     *
     * Refusals: `acceptance_unsupported` (schema 35),
     * `acceptance_table_unreadable`, `already_accepted` (a live acceptance is
     * already there — read back rather than guessed from the failed write,
     * `alpha.28`), `storage_failed`.
     */
    public function accept(int $productId, int $termId, int $actorId): string;

    /**
     * Withdraw the live acceptance. `''` on success, otherwise a named refusal.
     *
     * The row is KEPT and only its live key is cleared, so «who accepted this
     * and when» survives the revocation — that is what makes one table answer
     * both «قابل لغو» and «ممیزی‌پذیر».
     *
     * Refusals: `acceptance_unsupported`, `acceptance_table_unreadable`,
     * `not_accepted` (there was no live acceptance), `storage_failed`.
     */
    public function revoke(int $productId, int $actorId): string;
}
