<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The four-step product form of §6, as one value object.
 *
 * Prices are integer minor units for the same reason the ledger's are: a
 * price that arrives as a float is a price that can be off by a rial, and it
 * is the number every commission is computed from.
 */
final class ProductDetails
{
    public function __construct(
        // 1. introduction
        public readonly string $title = '',
        public readonly string $type = ProductType::SIMPLE,
        public readonly string $categoryKey = '',
        public readonly string $brand = '',
        public readonly string $shortDescription = '',
        // 2. price and inventory
        public readonly int $priceMinor = 0,
        public readonly ?int $salePriceMinor = null,
        public readonly ?string $saleFrom = null,
        public readonly ?string $saleTo = null,
        public readonly string $sku = '',
        public readonly int $stock = 0,
        public readonly int $minPurchase = 1,
        public readonly ?int $maxPurchase = null,
        // 3. technical
        public readonly int $weightGrams = 0,
        public readonly string $dimensions = '',
        public readonly string $taxClass = '',
        /**
         * The vendor's long description — step 1's second tab, and WooCommerce's
         * own `description`.
         *
         * **Why it is `?string` and not `string`.** `null` means this product
         * predates the field: nobody has asked for anything, so the projector
         * leaves WooCommerce's long description ALONE. `''` means the vendor
         * cleared it on purpose, and that is written. A plain `string` would
         * collapse those two into «empty», and the first one would then empty
         * the long description of every product that already exists — a
         * manager's text and their SEO with it.
         *
         * Last in the list rather than beside `shortDescription`, where it
         * belongs by meaning: sixteen promoted parameters are addressed
         * positionally in places, and inserting one in the middle would
         * silently shift every argument after it. Appending cannot.
         */
        public readonly ?string $description = null,
        /**
         * The purchase step — new in `alpha.45`, and LAST for the same reason
         * `description` is: seventeen promoted parameters are addressed
         * positionally in places, and inserting one beside `maxPurchase`
         * where it belongs by meaning would silently shift every argument
         * after it. Appending cannot.
         *
         * `1` means no stepping and is the column default, so every row that
         * existed before this field reads as «any quantity». The full
         * semantics — and why the step counts from the MINIMUM rather than
         * from zero — are written in `PurchaseQuantity`.
         */
        public readonly int $purchaseStep = 1,
        /**
         * EVERY `product_cat` term this product is in — new in `alpha.82`.
         *
         * `categoryKey` above is still one key and still means what it meant:
         * the single key the spec-template lookup and the Dokan category
         * mapping read. This is the RELATIONSHIP, which WooCommerce keeps as a
         * set and which the owner's instruction requires be preserved whole:
         * «مجموعهٔ کامل دسته‌ها باید حفظ شود، حتی وقتی والد و فرزند هم‌زمان
         * متصل‌اند.»
         *
         * The two are not a duplicate of one fact. Measured on `alpha.81`'s
         * bytes, a product carrying a parent and its child stored `244` and
         * recorded `245` nowhere, and accepting that key DELETED `245` from
         * WooCommerce. The set is what the single column could never hold.
         *
         * An EMPTY set means «this product's categories are not recorded»,
         * which is true of every row that existed before migration 35 except
         * the single-category ones the backfill covered. It does not mean «no
         * categories» — those are different facts (`alpha.8`) and the readers
         * keep them apart.
         *
         * Normalise with {@see CategorySet::of()}; order carries no meaning.
         * Last in the list for the reason `description` and `purchaseStep`
         * are: these promoted parameters are addressed positionally in places,
         * and inserting one beside `categoryKey` where it belongs by meaning
         * would silently shift every argument after it.
         *
         * @var list<int>
         */
        public readonly array $categoryIds = []
    ) {
    }

    /**
     * The categories this product is in, as a normalised set.
     *
     * Falls back to the single key when the set is not recorded, so a row
     * from before migration 35 — and a build running on schema 34 — answers
     * exactly what `alpha.81` answered. That fallback can only ever NARROW,
     * never widen, which is the safe direction for a relationship.
     *
     * @return list<int>
     */
    public function categorySet(): array
    {
        if ($this->categoryIds !== []) {
            return CategorySet::of($this->categoryIds);
        }
        return CategorySet::of([$this->categoryKey]);
    }

    /**
     * The SET a sensitive set-valued field holds, by its name.
     *
     * One field answers this today, and the indirection is not generality for
     * its own sake: `SensitiveChange::SENSITIVE_SETS` is the list the approval
     * path and the review page both read, and a name that appears on that list
     * with no accessor here must fail LOUDLY. Returning `[]` for an unknown
     * name would make such a field read as «no change» on one side and «no
     * row» on the other — approvable and invisible, which is the exact fault
     * `alpha.82` closed for the category set.
     *
     * @return list<int>
     */
    public function setFor(string $field): array
    {
        return match ($field) {
            'categoryIds' => $this->categorySet(),
            default => throw new \InvalidArgumentException(
                'no set accessor for sensitive set field: ' . $field
            ),
        };
    }

    /** The purchase-quantity rule these details express. */
    public function purchaseQuantity(): PurchaseQuantity
    {
        return PurchaseQuantity::of($this->minPurchase, $this->maxPurchase, $this->purchaseStep);
    }

    /**
     * The same details with a few fields replaced.
     *
     * Sixteen readonly promoted properties make a hand-written copy a place
     * for a field to go missing — and the manager's correction is exactly the
     * write where losing one would be silent. Unknown keys are ignored rather
     * than guessed at.
     *
     * @param array<string,mixed> $changes
     */
    public function with(array $changes): self
    {
        $pick = fn (string $key, mixed $current): mixed => array_key_exists($key, $changes) ? $changes[$key] : $current;
        return new self(
            (string) $pick('title', $this->title),
            (string) $pick('type', $this->type),
            (string) $pick('categoryKey', $this->categoryKey),
            (string) $pick('brand', $this->brand),
            (string) $pick('shortDescription', $this->shortDescription),
            (int) $pick('priceMinor', $this->priceMinor),
            ($v = $pick('salePriceMinor', $this->salePriceMinor)) === null ? null : (int) $v,
            ($v = $pick('saleFrom', $this->saleFrom)) === null ? null : (string) $v,
            ($v = $pick('saleTo', $this->saleTo)) === null ? null : (string) $v,
            (string) $pick('sku', $this->sku),
            (int) $pick('stock', $this->stock),
            (int) $pick('minPurchase', $this->minPurchase),
            ($v = $pick('maxPurchase', $this->maxPurchase)) === null ? null : (int) $v,
            (int) $pick('weightGrams', $this->weightGrams),
            (string) $pick('dimensions', $this->dimensions),
            (string) $pick('taxClass', $this->taxClass),
            // `null` is a VALUE here, not «unset»: a caller that asks for
            // `description => null` is saying «this product has never had
            // one», and `$pick` distinguishes that from not naming the key at
            // all. The three nullable price fields above read the same way.
            ($v = $pick('description', $this->description)) === null ? null : (string) $v,
            (int) $pick('purchaseStep', $this->purchaseStep),
            CategorySet::of((array) $pick('categoryIds', $this->categoryIds))
        );
    }

    /**
     * Fields that must be filled before the product can be submitted. Stock
     * is NOT here: a product may legitimately be submitted out of stock, and
     * zero stock stops the sale rather than the paperwork (A.1).
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $missing = [];
        if (trim($this->title) === '') {
            $missing[] = 'title';
        }
        // A RECORDED SET IS AN ANSWER, even with no single key.
        //
        // «مدیر نباید مجبور به انتخاب مجدد دسته‌های موجود شود.» A product that
        // WooCommerce already puts in two categories has its category
        // question answered; demanding one more pick is asking a human to
        // re-decide something the shop has recorded. So this is missing only
        // when neither the set nor the single key says anything.
        //
        // ⛔ And this is not what the owner saw on screen: measured on
        // `alpha.81`, `missingFields()` was ALREADY empty for all nine
        // products, because intake had quietly stored the first id. The
        // sentence they read is the preview bucket's
        // (`HandoverPage::heldReason()`), and it is dealt with there.
        // ⛔ AND THE SINGLE KEY STILL COUNTS, even when it is not a term id.
        //
        // `categorySet()` drops anything that is not a run of digits, because
        // a term id is a number — and `category_key` was a SLUG before
        // `alpha.25` moved it to ids. A product still carrying `syringe`
        // answers `[]` from the set and has, beyond any doubt, had its
        // category question answered; `AliasingSpecTemplateRepository` is the
        // thing that still understands those keys. Asking that vendor to pick
        // again would be this round's own defect, one field over.
        if ($this->categorySet() === [] && trim($this->categoryKey) === '') {
            $missing[] = 'category';
        }
        if (!$this->hasPrice()) {
            $missing[] = 'price';
        }
        return $missing;
    }

    /**
     * WHETHER THIS PRODUCT HAS A PRICE AT ALL — the one definition.
     *
     * `0` is not a price in this marketplace, and that was already true
     * before this method existed: `missingFields()` has named `price` for
     * `<= 0` since the four-step form was built, so a product carrying nought
     * can never be approved or published here. What it means is «قیمتی ثبت
     * نشده», not «رایگان».
     *
     * It is a method rather than a comparison repeated at each site because
     * the projector read it the other way and wrote the nought out to the
     * shop. Measured on the installed `alpha.65` bytes: a published
     * WooCommerce product with no `_regular_price` row came out of one
     * projection with `_regular_price = '0'` and a price of «۰ تومان» where
     * there had been nothing at all. Two readings of one fact is the shape
     * this repository keeps paying for (`alpha.46`, `alpha.52`), so there is
     * now one.
     */
    public function hasPrice(): bool
    {
        return $this->priceMinor > 0;
    }

    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    /**
     * The price a buyer would pay right now — the base every commission is
     * calculated from (FIN-01: after discount, before tax).
     */
    public function effectivePriceMinor(string $today): int
    {
        if ($this->salePriceMinor === null || $this->salePriceMinor < 0) {
            return $this->priceMinor;
        }
        if ($this->saleFrom !== null && $today < $this->saleFrom) {
            return $this->priceMinor;
        }
        if ($this->saleTo !== null && $today > $this->saleTo) {
            return $this->priceMinor;
        }
        return $this->salePriceMinor;
    }

    /**
     * Zero stock stops the purchase. Backorder and pre-order are not
     * "disabled by default" — they do not exist (A.1), so there is no setting
     * that could turn them on.
     */
    public function isPurchasable(string $today): bool
    {
        return $this->stock > 0 && $this->effectivePriceMinor($today) > 0;
    }
}
