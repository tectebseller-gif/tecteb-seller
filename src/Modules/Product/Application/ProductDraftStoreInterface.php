<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

/**
 * Where a half-finished product form is kept between autosaves.
 *
 * **A draft belongs to one person, not to the product.** Two members of the
 * same shop editing the same product each have their own draft; merging them
 * into one would be the silent overwrite the revision check exists to prevent,
 * one step earlier.
 *
 * **A draft is not the product.** Nothing here reaches the catalogue, the
 * projector or WooCommerce. It is the text somebody had typed when their
 * browser was closed, and the only thing it can do is be offered back to them.
 * That is why autosave needs no permission beyond «may edit this shop»: it
 * changes nothing that anybody else can see.
 *
 * The stored revision is the token the form was rendered from, so restoring a
 * draft onto a product somebody else has since changed still hits the conflict
 * check rather than sailing past it.
 */
interface ProductDraftStoreInterface
{
    /**
     * @param array<string,mixed> $payload the submitted form, as given
     */
    public function put(int $userId, int $productId, array $payload, string $revision): bool;

    /** @return array{payload:array<string,mixed>, revision:string, saved_at:string}|null */
    public function get(int $userId, int $productId): ?array;

    /** Called when the real save succeeds: the draft has served its purpose. */
    public function forget(int $userId, int $productId): bool;

    /** @return list<array{product_id:int, saved_at:string}> */
    public function listFor(int $userId): array;
}
