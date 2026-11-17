<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * The variable product's axes and its sellable combinations (A.1).
 *
 * Two things this class refuses, for the same reason the parent product
 * refuses them: a combination that names an option the attribute does not
 * offer, and a second variation for a combination that already exists. Both
 * would produce a product whose price depends on which row was read.
 *
 * Stock here behaves exactly as it does on the parent (ADR-008): the vendor's
 * edit is a write-through, and after a sale the number comes back FROM
 * WooCommerce. Nothing in this class pushes a remembered stock value.
 */
final class ManageVariations
{
    public const MAX_ATTRIBUTES = 4;
    public const MAX_VARIATIONS = 60;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly VariationRepositoryInterface $variations,
        private readonly ProductImageLibraryInterface $images,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        /**
         * Only to ASK whether the shop already holds this product's
         * combinations. Nullable so every existing caller and every test that
         * builds this service by hand keeps working (`alpha.32`); when it is
         * absent the guard below stands down, which is the pre-`alpha.105`
         * behaviour and is correct on a build with no storefront.
         */
        private readonly ?StorefrontVariationReaderInterface $storefront = null,
        /**
         * One unit of work for `buildVariations()`. Nullable so every existing
         * caller and every hand-built test service goes on working
         * (`alpha.32`) — and when it is absent the build REFUSES rather than
         * running unprotected, because a half-built set of combinations is
         * exactly what §4 forbids.
         */
        private readonly ?TransactionInterface $transaction = null,
        /**
         * The storefront door, for a product that is ALREADY on the shop.
         *
         * ⛔ **MEASURED GAP, NOT A TIDY-UP.** On a published product this
         * service wrote our tables and nothing else: `SyncCatalog::publish()`
         * is reached by approval, by a status transition, by the brand apply
         * and by the SEO edit — and by no attribute or variation edit at all.
         * Measured on the live fixture after this round's P0 fix:
         *
         *     resave_code=variation_saved      (our row moved to 125,000)
         *     child_16_price=120000            (the shop still said 120,000)
         *     wcattr_…جنس_variation=yes        (the shop still called it an axis)
         *
         * So the vendor's work was saved and the buyer never saw it, which is
         * the whole of §9 («نمایش واقعی در صفحه محصول») failing after the
         * service layer had already succeeded.
         *
         * Nullable and LAST, so every existing caller and every hand-built
         * test service goes on working (`alpha.32`); and when it is absent the
         * write still happens and the answer SAYS the shop was not told,
         * rather than claiming it was.
         */
        private readonly ?SyncCatalog $catalog = null
    ) {
    }

    /**
     * Push what we just wrote to the shop, and report what happened.
     *
     * ⛔ **THE ANSWER IS READ, NEVER ASSUMED** (§7: «نمایش نتیجه واقعی
     * عملیات، نه پیام موفقیت پیش از ذخیره»). The row is already written when
     * this runs, so a failed projection cannot be reported as a failed save —
     * it is a save the shop has not heard about, and that is its own code with
     * the projection's own reason inside it.
     *
     * Only for a product that is PUBLISHED and already linked. A draft
     * projects when it is approved, and projecting it early would put an
     * unapproved change on the shop — which is §11's question, not this
     * method's answer.
     */
    private function tellTheShop(Product $product, OperationResult $saved): OperationResult
    {
        if ($product->status !== ProductStatus::Published || !$product->isProjected()) {
            return $saved;
        }
        if ($this->catalog === null) {
            return OperationResult::success($saved->code, $saved->context + ['shop' => 'not_asked']);
        }
        $sync = $this->catalog->publish($product->id);
        if ($sync->ok) {
            return OperationResult::success($saved->code, $saved->context + ['shop' => 'updated']);
        }
        return OperationResult::failure('saved_but_shop_not_updated', $saved->context + [
            'saved' => $saved->code,
            'reason' => $sync->code,
        ]);
    }

    /**
     * ⛔ THE COMBINATIONS ARE IN WOOCOMMERCE, SO THIS PANEL MAY NOT MAKE MORE.
     *
     * An imported product has no row in `tmc_product_attributes` or
     * `tmc_product_variations` — `ImportFromDokan` writes neither — while
     * WooCommerce holds the real children. Before `alpha.105` the vendor's
     * panel said «هنوز ویژگی‌ای تعریف نشده است» about such a product, which is
     * an invitation to define the axes again; and had they accepted it, the
     * next projection would have created a SECOND set of variations beside the
     * first, because `existingVariationIds()` can only recognise children this
     * plugin stamped.
     *
     * So the five writing verbs refuse by name while that is the situation:
     * our tables are empty AND the shop's parent has children. One guard, in
     * the service, rather than one per route — a route that forgot to ask
     * would otherwise be a route that could still duplicate.
     *
     * It is deliberately NOT a permission and NOT a capability: the vendor may
     * edit this product. It is «not through THIS door» — and since §3 of the
     * owner's instruction there is another: `EditStorefrontVariations` writes
     * the real `WC_Product_Variation`'s price and stock, and refuses in
     * exactly the opposite direction. Both read one rule, so a product is
     * behind one door or the other and never both.
     */
    private function shopOwnsTheCombinations(int $productId, ?int $wcProductId): bool
    {
        // ⛔ THE RULE MOVED OUT, THE CALL SITES DID NOT.
        //
        // A second service now asks this same question from the other side —
        // `EditStorefrontVariations` writes the real `WC_Product_Variation`
        // and must refuse exactly when this one allows — and two copies of one
        // rule are how two doors end up open for one product (`alpha.78`). So
        // the answer lives in `StorefrontVariationOwnership`, which is built
        // from the two collaborators this service already has: no constructor
        // argument changed, so every caller and every hand-built test service
        // goes on working (`alpha.32`), and the five verbs below ask the same
        // private method they always did.
        // ⛔ `!oursConfirmed()`, NOT `shopOwns()`. The two are different on an
        // UNREADABLE shop, which is not-ours and not-shop at once: this door
        // must refuse there (the `alpha.106` fail-closed half) and the other
        // door must refuse there too. One shared negation cannot be right in
        // both directions, so each asks its own positive question — and
        // `ShopOwnedVariationDoorsAreExclusiveTest` pins both answers for all
        // three states.
        return !(new StorefrontVariationOwnership($this->variations, $this->storefront))
            ->oursConfirmed($productId, ProductType::VARIABLE, $wcProductId);
    }

    /**
     * @param list<string> $options
     * @param bool $visible   listed among the product's properties (§3)
     * @param bool $variation used to build combinations the buyer picks (§3)
     */
    public function saveAttribute(
        int $actorId,
        int $vendorUserId,
        int $productId,
        string $key,
        string $label,
        array $options,
        bool $visible = true,
        bool $variation = true
    ): OperationResult {
        $product = $this->editable($actorId, $vendorUserId, $productId);
        if ($product instanceof OperationResult) {
            return $product;
        }
        if ($product->details->type !== ProductType::VARIABLE) {
            return OperationResult::failure('not_variable');
        }
        $label = trim($label);
        if ($label === '') {
            return OperationResult::failure('incomplete_attribute');
        }
        // ⛔ **THE SYSTEM BUILDS THE KEY WHEN THE VENDOR DOES NOT.** §3:
        // «به‌جای درخواست دستی کلید انگلیسی پیچیده از فروشنده، در صورت امکان
        // کلید پایدار را خود سیستم بسازد.» A Persian label cannot survive
        // `normaliseKey()` — it strips everything outside `[a-z0-9_-]`, so
        // «تعداد در بسته» came out empty and the vendor had to invent an
        // English word. Derived from a hash of the label rather than from a
        // transliteration: it is STABLE for a given label, it is ASCII, it
        // cannot collide with a hand-typed key, and it never needs a
        // romanisation table that would disagree with itself between
        // releases.
        $key = $this->normaliseKey($key);
        if ($key === '') {
            $key = 'a' . substr(hash('sha256', $label), 0, 12);
        }
        $options = $this->cleanOptions($options);
        if ($options === []) {
            return OperationResult::failure('attribute_needs_options');
        }
        $existing = $this->variations->attributes($productId);
        $isNew = true;
        $was = null;
        foreach ($existing as $attribute) {
            if ($attribute->key === $key) {
                $isNew = false;
                $was = $attribute;
            }
        }
        if ($isNew && count($existing) >= self::MAX_ATTRIBUTES) {
            return OperationResult::failure('too_many_attributes', ['limit' => self::MAX_ATTRIBUTES]);
        }

        // ⛔ A ROLE THIS STORE CANNOT RECORD IS REFUSED, NOT WRITTEN AND
        // FORGOTTEN. Swapping the plugin's files does not run migration 40
        // (`UpgradeGate` does it on the next admin request, `alpha.67`), and a
        // catalogue read can fail. In either state the columns are not
        // writable, so a vendor who unticks a box and is told «ذخیره شد»
        // would get an attribute that is still an axis on the next
        // projection. Only a request that DIFFERS from the stored default is
        // refused: the ordinary case — both roles true — goes through
        // untouched, so nothing a vendor does today starts failing.
        if (($visible !== true || $variation !== true) && $this->variations->rolesSupported() !== true) {
            return OperationResult::failure('attribute_roles_unavailable', [
                'attribute' => $label,
                'supported' => $this->variations->rolesSupported() === null ? 'unknown' : 'absent',
            ]);
        }

        // ⛔ §8: AN AXIS WITH COMBINATIONS ON IT IS NOT DEMOTED IN SILENCE.
        //
        // Turning «استفاده برای ساخت تنوع» off for an axis that existing
        // variations name would leave every one of those combinations naming
        // something that is no longer an axis — a price reachable by nobody,
        // and on the next projection a parent whose children declare an
        // attribute it does not offer. «از حذف خودکار بدون هشدار جلوگیری
        // کند» and «تعداد تنوع‌های تحت تأثیر را نشان دهد»: so the count comes
        // back with the refusal and the vendor removes the combinations
        // first. Nothing is deleted here and nothing is guessed.
        if ($was !== null && $was->variation && !$variation) {
            $dependents = $this->variationsNaming($productId, $key);
            if ($dependents > 0) {
                return OperationResult::failure('axis_has_variations', [
                    'attribute' => $label,
                    'variations' => $dependents,
                ]);
            }
        }

        $id = $this->variations->saveAttribute(
            $productId,
            $key,
            $label,
            $options,
            $isNew ? count($existing) : $was->sort,
            $visible,
            $variation
        );
        if ($id <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->log($actorId, $vendorUserId, $productId, 'attribute_saved');
        return $this->tellTheShop($product, OperationResult::success('attribute_saved', [
            'attribute_id' => $id,
            // The key the vendor did NOT have to type. §3 asks the system to
            // build a stable key rather than demand an English one, so the
            // answer has to say which key it built — otherwise the next save
            // cannot name the same attribute.
            'key' => $key,
            'visible' => $visible ? 'yes' : 'no',
            'variation' => $variation ? 'yes' : 'no',
        ]));
    }

    /** How many of this product's combinations name this attribute key. */
    private function variationsNaming(int $productId, string $key): int
    {
        $count = 0;
        foreach ($this->variations->variations($productId) as $variation) {
            if (array_key_exists($key, $variation->attributes)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Removing an axis removes the variations that were defined along it,
     * because a combination naming an attribute that no longer exists is not
     * sellable and keeping it would leave an unreachable price in the table.
     */
    public function deleteAttribute(int $actorId, int $vendorUserId, int $productId, int $attributeId): OperationResult
    {
        $product = $this->editable($actorId, $vendorUserId, $productId);
        if ($product instanceof OperationResult) {
            return $product;
        }
        $attribute = null;
        foreach ($this->variations->attributes($productId) as $candidate) {
            if ($candidate->id === $attributeId) {
                $attribute = $candidate;
            }
        }
        if ($attribute === null) {
            return OperationResult::failure('not_found');
        }
        foreach ($this->variations->variations($productId) as $variation) {
            if (array_key_exists($attribute->key, $variation->attributes)) {
                $this->variations->deleteVariation($productId, $variation->id);
            }
        }
        if (!$this->variations->deleteAttribute($productId, $attributeId)) {
            return OperationResult::failure('storage_failed');
        }
        $this->log($actorId, $vendorUserId, $productId, 'attribute_deleted');
        return $this->tellTheShop($product, OperationResult::success('attribute_deleted'));
    }

    /**
     * @param array<string,string> $chosen attribute key => option
     */
    public function saveVariation(
        int $actorId,
        int $vendorUserId,
        int $productId,
        array $chosen,
        int $priceMinor,
        ?int $salePriceMinor,
        string $sku,
        int $stock,
        int $mediaId = 0,
        bool $enabled = true,
        ?UploadedFile $image = null
    ): OperationResult {
        $product = $this->editable($actorId, $vendorUserId, $productId);
        if ($product instanceof OperationResult) {
            return $product;
        }
        if ($product->details->type !== ProductType::VARIABLE) {
            return OperationResult::failure('not_variable');
        }
        $attributes = $this->variations->attributes($productId);
        if ($attributes === []) {
            return OperationResult::failure('attributes_first');
        }
        // ⛔ AXES, NOT ATTRIBUTES. An attribute the vendor declared as a fact
        // contributes nothing to a combination, so a product whose only
        // attributes are facts has nothing to vary along — a different
        // sentence from «no attributes at all», and the one §4 asks to be
        // said: «برای ساخت تنوع، ابتدا یک ویژگی تعریف کنید و گزینه استفاده
        // برای تنوع را فعال کنید.»
        if (ProductAttribute::axesOf($attributes) === []) {
            return OperationResult::failure('axes_first');
        }
        $verdict = $this->validateCombination($attributes, $chosen);
        if ($verdict !== null) {
            return $verdict;
        }
        if ($priceMinor <= 0) {
            return OperationResult::failure('bad_price');
        }
        if ($salePriceMinor !== null && ($salePriceMinor < 0 || $salePriceMinor > $priceMinor)) {
            return OperationResult::failure('bad_sale_price');
        }
        if ($stock < 0) {
            return OperationResult::failure('bad_stock');
        }

        $combination = ProductVariation::combinationOf($chosen);
        $existing = null;
        foreach ($this->variations->variations($productId) as $candidate) {
            if ($candidate->combination() === $combination) {
                $existing = $candidate;
            }
        }
        if ($existing === null && count($this->variations->variations($productId)) >= self::MAX_VARIATIONS) {
            return OperationResult::failure('too_many_variations', ['limit' => self::MAX_VARIATIONS]);
        }
        if ($image !== null && $image->tempPath !== '' && $image->errorCode !== UPLOAD_ERR_NO_FILE) {
            $stored = $this->images->store($image, $vendorUserId, $product->details->title);
            if (!$stored['ok']) {
                return OperationResult::failure($stored['code']);
            }
            $mediaId = $stored['media_id'];
        }
        if ($mediaId > 0 && !$this->images->ownedBy($mediaId, $vendorUserId)) {
            $mediaId = 0;      // somebody else's attachment never reaches a variation
        }

        $id = $this->variations->saveVariation($productId, new ProductVariation(
            $existing?->id ?? 0,
            $productId,
            $chosen,
            $priceMinor,
            $salePriceMinor,
            trim($sku),
            $stock,
            $mediaId,
            $enabled
        ));
        if ($id <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->log($actorId, $vendorUserId, $productId, $existing === null ? 'variation_added' : 'variation_saved');
        return $this->tellTheShop(
            $product,
            OperationResult::success($existing === null ? 'variation_added' : 'variation_saved', ['variation_id' => $id])
        );
    }

    /**
     * HOW MANY COMBINATIONS THE AXES DESCRIBE, AND HOW MANY ARE NEW — read only.
     *
     * §4: «قبل از ایجاد، تعداد ترکیب‌ها نمایش داده شود» and «اگر تعداد از سقف
     * ایمن فراتر رفت، عملیات رد شود و دلیل توضیح داده شود». A cap discovered
     * by hitting it is a cap the vendor finds out about after typing, so the
     * number is available before anything is written.
     *
     * ⛔ **THIS METHOD WRITES NOTHING** and takes no permission, because it
     * answers a question the panel already has the data to ask — it is the
     * same arithmetic `buildVariations()` does, factored out so the preview
     * and the act cannot disagree about the number (`alpha.78`). What it is
     * NOT is a promise: `buildVariations()` recomputes from the rows as they
     * are at that moment.
     *
     * @return array{total:?int, existing:int, missing:?int, over:bool, limit:int}
     */
    public function previewCombinations(int $productId): array
    {
        $attributes = $this->variations->attributes($productId);
        $total = ProductAttribute::combinationCount($attributes);
        $existing = [];
        foreach ($this->variations->variations($productId) as $variation) {
            $existing[$variation->combination()] = true;
        }
        if ($total === null) {
            // Over any cap this marketplace will talk about, so «how many are
            // new» has no printable answer either.
            return [
                'total' => null,
                'existing' => count($existing),
                'stale' => null,
                'missing' => null,
                'over' => true,
                'limit' => self::MAX_VARIATIONS,
            ];
        }
        $candidates = $this->combinationsOf(ProductAttribute::axesOf($attributes));
        if ($candidates === null) {
            // The walker gave up before applying every axis, so «how many are
            // new» has no answer — the same shape as `$total === null` above.
            return [
                'total' => null,
                'existing' => count($existing),
                'stale' => null,
                'missing' => null,
                'over' => true,
                'limit' => self::MAX_VARIATIONS,
            ];
        }
        // ⛔ **THREE NUMBERS THAT ADD UP, BECAUSE TWO DID NOT.** Measured by
        // rendering the real vendor form on a live site after a real POST
        // promoted a fact attribute back to an axis:
        //
        //     «ویژگی‌های انتخاب‌شده ۶ ترکیب می‌سازند و ۳ ترکیب تا حالا ثبت
        //      شده است.»  …  «با زدن این دکمه ۶ ترکیب تازه ساخته می‌شود.»
        //
        // 3 + 6 is not 6. Both figures were true about what they counted and
        // the sentence was false: the three stored rows carry the ONE-axis
        // combination `pack-count=X`, the candidates are now TWO-axis
        // `pack-count=X|material=Y`, so nothing matched. Adding an axis
        // orphans every combination that was complete before it — §8's worry
        // from the other direction, and no unit fixture could reach it
        // because every fixture declares its axes before its combinations.
        //
        // So `existing` counts only rows that ARE a combination of today's
        // axes, `stale` counts the rest, and `existing + stale` is the row
        // count while `existing + missing` is the total. Nothing is deleted
        // and nothing is guessed: the panel NAMES the stale rows and the
        // vendor decides (§1, §8).
        $wantedKeys = [];
        foreach ($candidates as $combination) {
            $wantedKeys[ProductVariation::combinationOf($combination)] = true;
        }
        $matching = 0;
        foreach (array_keys($existing) as $key) {
            if (isset($wantedKeys[$key])) {
                $matching++;
            }
        }
        return [
            'total' => $total,
            'existing' => $matching,
            'stale' => count($existing) - $matching,
            'missing' => count($wantedKeys) - $matching,
            'over' => $total > self::MAX_VARIATIONS,
            'limit' => self::MAX_VARIATIONS,
        ];
    }

    /**
     * BUILD THE MISSING COMBINATIONS FROM THE AXES — §4's own button.
     *
     * The cartesian product of the attributes marked «استفاده برای ساخت
     * تنوع», skipping every combination that already exists. Four things it
     * refuses to do, each because §4 names it:
     *
     *  - **no duplicate combination.** Existing rows are indexed by the
     *    canonical combination string before anything is built, and
     *    `tmc_variation_combo` is a unique key underneath — so «ساخت دوباره
     *    نباید تنوع قبلی را تکرار کند» holds twice over, once in this method
     *    and once in the schema.
     *  - **nothing above the cap.** Refused by NUMBER, before the first
     *    write, counting what is already there: a product with 55 rows and 12
     *    new ones is refused at 67 rather than silently stopping at 60.
     *  - **nothing for a product whose children live in WooCommerce.**
     *    `editable()` already asks `StorefrontVariationOwnership`, so this
     *    verb cannot make a second set beside the shop's (`alpha.105`).
     *  - **no half-built set.** Every row is written inside one transaction
     *    and a single failure rolls the lot back, because «خطای یک تنوع نباید
     *    باعث ثبت نیمه‌کاره مجموعه‌ای ناسازگار شود» — and a vendor told «۱۲
     *    ترکیب ساخته شد» about seven is worse than a refusal.
     *
     * ⛔ **THE NEW ROWS CARRY NO PRICE, AND THAT IS DELIBERATE.** A generated
     * combination has no figure anybody decided, and `ProductVariation`'s own
     * rule is that a price of nought is «قیمتی ثبت نشده» rather than free
     * (`alpha.65`). So they arrive priceless and unsellable, the readiness
     * check keeps the product off sale until the vendor prices them, and no
     * invented number ever reaches a buyer.
     */
    public function buildVariations(int $actorId, int $vendorUserId, int $productId): OperationResult
    {
        $product = $this->editable($actorId, $vendorUserId, $productId);
        if ($product instanceof OperationResult) {
            return $product;
        }
        if ($product->details->type !== ProductType::VARIABLE) {
            return OperationResult::failure('not_variable');
        }
        $attributes = $this->variations->attributes($productId);
        $axes = ProductAttribute::axesOf($attributes);
        if ($axes === []) {
            return OperationResult::failure('axes_first');
        }

        $existing = [];
        foreach ($this->variations->variations($productId) as $variation) {
            $existing[$variation->combination()] = true;
        }
        $candidates = $this->combinationsOf($axes);
        if ($candidates === null) {
            // ⛔ `would_be` and `new` are DELIBERATELY ABSENT, not nought. The
            // walker stopped before applying every axis, so this code does not
            // know how many combinations there would be — and `0` in a
            // financial-looking context is a claim, not a gap (`alpha.28`'s
            // rule about the empty string, one layer up). The message prints
            // the cap, which is the only number here that is true.
            return OperationResult::failure('too_many_variations', [
                'limit' => self::MAX_VARIATIONS,
                'existing' => count($existing),
            ]);
        }
        $wanted = [];
        foreach ($candidates as $combination) {
            $key = ProductVariation::combinationOf($combination);
            if ($key === '' || isset($existing[$key])) {
                continue;
            }
            $wanted[$key] = $combination;
        }
        $total = count($existing) + count($wanted);
        if ($total > self::MAX_VARIATIONS) {
            return OperationResult::failure('too_many_variations', [
                'limit' => self::MAX_VARIATIONS,
                'would_be' => $total,
                'existing' => count($existing),
                'new' => count($wanted),
            ]);
        }
        if ($wanted === []) {
            return OperationResult::success('variations_already_built', [
                'existing' => count($existing),
                'built' => 0,
            ]);
        }

        // ⛔ ONE UNIT OF WORK, and a build with no transaction is REFUSED
        // rather than run half-way (`alpha.49`'s rule: «تراکنشی که باز نشود
        // یک رد است، نه دلیلی برای اجرای نیمهٔ اول»).
        if ($this->transaction === null || !$this->transaction->begin()) {
            return OperationResult::failure('build_not_atomic');
        }
        foreach ($wanted as $combination) {
            $id = $this->variations->saveVariation($productId, new ProductVariation(
                0,
                $productId,
                $combination,
                0,              // no price: nobody has decided one
                null,
                '',
                0,
                0,
                true
            ));
            if ($id <= 0) {
                $this->transaction->rollback();
                return OperationResult::failure('storage_failed', [
                    'built' => 0,
                    'combination' => ProductVariation::combinationOf($combination),
                ]);
            }
        }
        // ⛔ A FAILED COMMIT IS «NOT CONFIRMED», NOT «NOTHING WAS WRITTEN».
        // The rows went to the gateway and the commit is what did not answer,
        // so this cannot honestly say `built => 0` — that would be a claim
        // about the database made from a failure to read it. `alpha.74` wrote
        // the rule for exactly this spot: the safe direction is to report
        // nothing confirmed and let the vendor look, and the number printed is
        // the number ATTEMPTED with its own name.
        if (!$this->transaction->commit()) {
            return OperationResult::failure('build_not_confirmed', [
                'attempted' => count($wanted),
            ]);
        }
        $this->log($actorId, $vendorUserId, $productId, 'variations_built');
        return $this->tellTheShop($product, OperationResult::success('variations_built', [
            'built' => count($wanted),
            'existing' => count($existing),
            'total' => $total,
        ]));
    }

    /**
     * The cartesian product of the axes' options, in the axes' own order.
     *
     * @param list<ProductAttribute> $axes
     * @return list<array<string,string>>
     */
    private function combinationsOf(array $axes): ?array
    {
        if ($axes === []) {
            return [];
        }
        $rows = [[]];
        foreach ($axes as $axis) {
            $next = [];
            foreach ($rows as $row) {
                foreach ($axis->options as $option) {
                    $next[] = $row + [$axis->key => $option];
                }
            }
            $rows = $next;
            // ⛔ **`null`, NOT THE ROWS BUILT SO FAR.** A ceiling on the
            // WORKING set is needed — four axes of thirty options is 810,000
            // arrays and nothing should build them in memory to then refuse
            // them — but the first draft of this method `return $rows` here,
            // and those rows are a PARTIAL cartesian product with only some
            // of the axes applied. Returning them is returning a half-built
            // set dressed as a complete one, which is the very thing §4
            // forbids about writing.
            //
            // The cap below happened to refuse every such set by arithmetic
            // (this early exit fires only above `MAX_VARIATIONS * 2`, which is
            // always past the cap), so nothing could have been written — but
            // that safety was a coincidence of two constants and would die
            // the moment `MAX_VARIATIONS` was raised. `null` says what is
            // true: more combinations than this marketplace will talk about.
            if (count($rows) > self::MAX_VARIATIONS * 2) {
                return null;
            }
        }
        return $rows;
    }

    public function deleteVariation(int $actorId, int $vendorUserId, int $productId, int $variationId): OperationResult
    {
        $product = $this->editable($actorId, $vendorUserId, $productId);
        if ($product instanceof OperationResult) {
            return $product;
        }
        if ($this->variations->findVariation($productId, $variationId) === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->variations->deleteVariation($productId, $variationId)) {
            return OperationResult::failure('storage_failed');
        }
        $this->log($actorId, $vendorUserId, $productId, 'variation_deleted');
        return $this->tellTheShop($product, OperationResult::success('variation_deleted'));
    }

    /**
     * A variation's stock, immediately — the same exception the parent
     * product's inventory gets, and for the same reason (A.1).
     */
    public function updateVariationStock(
        int $actorId,
        int $vendorUserId,
        int $productId,
        int $variationId,
        int $stock
    ): OperationResult {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Inventory, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        // ⛔ REPEATED HERE ON PURPOSE. This verb does not go through
        // `editable()`: it asks for the Inventory area, not Product (A.1), so
        // a guard that only sat in `editable()` would cover four verbs out of
        // five — which is no guard (`alpha.22`). And it is reachable: the
        // panel now PRINTS the shop's real child ids, so a stock update
        // naming 7701 would otherwise be answered «پیدا نشد» about a
        // combination that plainly exists.
        if (
            $product->details->type === ProductType::VARIABLE
            && $this->shopOwnsTheCombinations($productId, $product->wcProductId)
        ) {
            return OperationResult::failure('variations_live_in_shop', ['product_id' => $productId]);
        }
        if ($stock < 0) {
            return OperationResult::failure('bad_stock');
        }
        $variation = $this->variations->findVariation($productId, $variationId);
        if ($variation === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->variations->updateVariationStock($variationId, $stock)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_INVENTORY_CHANGED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'stock' => $stock,
        ]);
        return OperationResult::success('inventory_saved', ['variation_id' => $variationId, 'stock' => $stock]);
    }

    /**
     * @param list<ProductAttribute> $attributes
     * @param array<string,string> $chosen
     */
    private function validateCombination(array $attributes, array $chosen): ?OperationResult
    {
        // ⛔ **THE MEASURED P0 OF `alpha.108` WAS THIS LOOP.** It demanded a
        // value for EVERY attribute, so the moment a vendor declared a second
        // property — «جنس: پلاستیک، فلز», which §3 names as a fact — every
        // save on the product was refused. Measured on the installed
        // `alpha.108` bytes against WooCommerce 11.0.1, with a control that
        // isolates the cause:
        //
        //     one_axis_…_code=variation_added   ×3   (one axis: all three save)
        //     readd_fact_code=attribute_saved
        //     after_fact_resave_code=incomplete_combination
        //     after_fact_resave_context={"fields":"جنس"}
        //     variation_rows_after=3            (the three survive, frozen)
        //
        // So a published product's three combinations became uneditable —
        // their prices could not be changed at all — because of one attribute
        // that was never meant to be an axis
        // (`docs/evidence/alpha109-diagnosis/`).
        $axes = ProductAttribute::axesOf($attributes);
        $missing = [];
        foreach ($axes as $attribute) {
            $value = trim($chosen[$attribute->key] ?? '');
            if ($value === '') {
                $missing[] = $attribute->label;
                continue;
            }
            if (!$attribute->has($value)) {
                return OperationResult::failure('unknown_option', ['attribute' => $attribute->label]);
            }
        }
        if ($missing !== []) {
            return OperationResult::failure('incomplete_combination', ['fields' => implode('، ', $missing)]);
        }
        // A value offered for a FACT is refused by its own name rather than
        // as «unknown», because the attribute is perfectly well known and the
        // thing that is wrong is what the caller is trying to do with it.
        // Silently dropping it would store a combination the vendor did not
        // describe.
        $axisKeys = array_map(static fn (ProductAttribute $a): string => $a->key, $axes);
        $known = array_map(static fn (ProductAttribute $a): string => $a->key, $attributes);
        foreach (array_keys($chosen) as $key) {
            $key = (string) $key;
            if (in_array($key, $axisKeys, true)) {
                continue;
            }
            if (in_array($key, $known, true)) {
                return OperationResult::failure('not_an_axis', ['attribute' => $key]);
            }
            return OperationResult::failure('unknown_attribute', ['attribute' => $key]);
        }
        return null;
    }

    /** @return \Tecteb\Marketplace\Modules\Product\Domain\Product|OperationResult */
    private function editable(int $actorId, int $vendorUserId, int $productId): mixed
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if ($product->status === ProductStatus::Submitted) {
            return OperationResult::failure('in_review');
        }
        if ($product->status === ProductStatus::Archived) {
            return OperationResult::failure('product_archived');
        }
        // After the other refusals, because «you may not edit this at all» is
        // a different sentence from «these combinations live in the shop», and
        // the first one is the one to say when both are true.
        if (
            $product->details->type === ProductType::VARIABLE
            && $this->shopOwnsTheCombinations($productId, $product->wcProductId)
        ) {
            return OperationResult::failure('variations_live_in_shop', ['product_id' => $productId]);
        }
        return $product;
    }

    /** @param list<string> $options @return list<string> */
    private function cleanOptions(array $options): array
    {
        $clean = [];
        foreach ($options as $option) {
            $option = trim((string) $option);
            if ($option !== '' && !in_array($option, $clean, true)) {
                $clean[] = $option;
            }
        }
        return array_slice($clean, 0, ProductAttribute::MAX_OPTIONS);
    }

    private function normaliseKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = (string) preg_replace('/[^a-z0-9_\-]/', '-', $key);
        return substr(trim($key, '-'), 0, 64);
    }

    private function log(int $actorId, int $vendorUserId, int $productId, string $action): void
    {
        $this->audit->log(AuditEventCatalog::PRODUCT_SAVED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'status' => $action,
            'created' => false,
        ]);
    }
}
