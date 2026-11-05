<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Domain\PreservationRisk;

/**
 * WHAT THIS PROVES: every preservation-risk key the reader can produce is
 * classified exactly once, the four the owner named stay blocking, and every
 * key called a DISCLOSURE is one the projector has no setter for.
 *
 * ⛔ **The last of those is the measurement the classification rests on.**
 * «این بازارگاه فیلدی برایش ندارد؛ مقدار فعلی دست‌نخورده می‌مانَد» is a
 * sentence; `set_shipping_class_id` appearing nowhere in the projector is a
 * fact. If a future round gives the projector a setter for one of these
 * fields, the sentence stays true-looking and the field starts being
 * overwritten — so this test fails the moment a setter arrives, which is the
 * moment the key must move to the blocking list.
 *
 * ⛔ Comments are stripped first (`alpha.22`): `PreservationRisk`'s own
 * docblock lists the setter names in a table, so a raw-source check for
 * «`set_tag_ids` is absent» would be satisfied by the prose explaining that
 * it is.
 */
final class PreservationRiskClassificationTest extends TestCase
{
    private const READER = 'src/Modules/Migration/Infrastructure/WordPress/WpCatalogueReader.php';

    private const PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php';

    private const TRANSFER = 'src/Modules/Migration/Application/TransferOwnership.php';

    /**
     * The WooCommerce setters each disclosure key is about.
     *
     * Named here rather than derived, because the mapping from «the key
     * `dimensions`» to «the three setters a projection would use» is a fact
     * about WooCommerce's API that no amount of reading our own source
     * reveals.
     *
     * @var array<string, list<string>>
     */
    private const SETTERS = [
        'dimensions' => ['set_length', 'set_width', 'set_height', 'set_dimensions'],
        'shipping_class' => ['set_shipping_class_id', 'set_shipping_class'],
        'tags' => ['set_tag_ids'],
        'related' => ['set_upsell_ids', 'set_cross_sell_ids'],
        'backorders' => ['set_backorders'],
        'sold_individually' => ['set_sold_individually'],
        // The two the projector DOES write, so the released-key test can
        // look them up as well as the disclosures.
        'catalog_visibility' => ['set_catalog_visibility'],
        'manage_stock' => ['set_manage_stock'],
    ];

    /** `project()`'s signature, whitespace-free, for `methodBody()`. */
    private const PROJECT =
        'publicfunctionproject(Product$product,array$attributes=[],array$variations=[]):array{';

    /** `writePrice()`'s, the other `applySale()` caller. */
    private const WRITE_PRICE = 'publicfunctionwritePrice(Product$product):array{';

    /** The variation loop, which writes the same stock flag on its own. */
    private const PROJECT_VARIATIONS = 'privatefunctionprojectVariations(int$wcProductId,'
        . 'Product$product,array$attributes,array$variations):array{';

    /**
     * One method's body, so a setter called on purpose elsewhere in the file
     * cannot answer a question about this one.
     *
     * Brace-matched rather than «up to the next `function`», which cuts a
     * method in half the moment it contains a closure (`alpha.77`).
     */
    private function methodBody(string $relative, string $signature): string
    {
        return $this->balancedBlockAfter($this->code($relative), $signature);
    }

    private function code(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * Every key `preservationRisksFrom()` can write is in exactly one list.
     *
     * Read from the reader's own source, so a key added there and forgotten
     * in the classification fails HERE rather than becoming waivable in
     * silence. `isBlocking()`'s fallback catches it at runtime; this catches
     * it at build time, which is where a classification belongs.
     */
    public function testEveryKeyTheReaderProducesIsClassifiedExactlyOnce(): void
    {
        $code = $this->code(self::READER);
        $body = $code;
        preg_match_all("/\\\$risks\\['([a-z_]+)'\\]/", $body, $matches);
        $keys = array_values(array_unique($matches[1]));
        // Plus the one it returns whole when the product cannot be read.
        $keys[] = 'product';
        $keys = array_values(array_unique($keys));
        self::assertGreaterThanOrEqual(10, count($keys), 'the reader should still name ten risks');

        $classified = array_merge(PreservationRisk::BLOCKING, PreservationRisk::DISCLOSURE);
        foreach ($keys as $key) {
            self::assertContains(
                $key,
                $classified,
                'risk key not classified in PreservationRisk: ' . $key
            );
        }
        // And nothing is classified that the reader cannot produce — a list
        // carrying a key nobody writes is a rule about nothing.
        foreach ($classified as $key) {
            self::assertContains($key, $keys, 'classified key the reader never writes: ' . $key);
        }
    }

    /** The owner's §3, in the code rather than in a document. */
    public function testTheFourProtectedKeysStayBlocking(): void
    {
        foreach (['manage_stock', 'catalog_visibility', 'price_scale', 'type'] as $key) {
            self::assertContains($key, PreservationRisk::BLOCKING, $key);
            self::assertNotContains($key, PreservationRisk::DISCLOSURE, $key);
        }
    }

    /**
     * ⛔ THE MEASUREMENT: a disclosure's field has no setter in the projector.
     *
     * This is why those keys may stop blocking a transfer. It fails the day
     * one of them gains a setter, and the failure is the signal to move the
     * key — not to change this test.
     */
    public function testNoDisclosedFieldHasASetterInTheProjector(): void
    {
        $code = $this->code(self::PROJECTOR);
        foreach (PreservationRisk::DISCLOSURE as $key) {
            self::assertArrayHasKey($key, self::SETTERS, 'unmapped disclosure key: ' . $key);
            foreach (self::SETTERS[$key] as $setter) {
                self::assertStringNotContainsString(
                    '->' . $setter . '(',
                    $code,
                    $key . ' is called a disclosure but the projector calls ' . $setter
                );
            }
        }
    }

    /**
     * And the two keys the projector DOES write are blocking — the control.
     *
     * Without it, a classification that called everything a disclosure would
     * pass the test above (`alpha.46`).
     */
    public function testTheFieldsTheProjectorWritesAreBlocking(): void
    {
        $code = $this->code(self::PROJECTOR);
        self::assertStringContainsString('->set_manage_stock(', $code);
        self::assertStringContainsString('->set_catalog_visibility(', $code);
        self::assertContains('manage_stock', PreservationRisk::BLOCKING);
        self::assertContains('catalog_visibility', PreservationRisk::BLOCKING);
    }

    /**
     * `take()` asks for an acknowledgement of the BLOCKING keys only.
     *
     * The exact text, and the old spelling's absence: `array_keys($risks)`
     * there is what made a shipping class as blocking as stock management.
     */
    public function testTheGuardAsksForBlockingKeysOnly(): void
    {
        $code = $this->code(self::TRANSFER);
        // From `alpha.93` the split reads the row's stamp, so the pinned text
        // is the stamp-aware call. Both older spellings must be gone:
        // `array_keys($risks)` made a shipping class as blocking as stock
        // management, and `blockingIn($risks)` kept blocking a stamped row on
        // a catalogue-visibility write that cannot happen.
        self::assertStringContainsString(
            'PreservationRisk::blockingGiven($risks, $statusHeld)',
            $code
        );
        self::assertStringNotContainsString(
            'array_diff(array_keys($risks), $acknowledged)',
            $code,
            'the old all-keys spelling must be gone'
        );
        self::assertStringNotContainsString(
            'array_diff(PreservationRisk::blockingIn($risks), $acknowledged)',
            $code,
            'the stamp-blind spelling must be gone'
        );
    }

    /** The disclosures reach the audit row, so the trail is not silent. */
    public function testTheDisclosuresAreRecorded(): void
    {
        $code = $this->code(self::TRANSFER);
        self::assertStringContainsString(
            'PreservationRisk::disclosuresGiven($risks, $statusHeld)',
            $code
        );
        self::assertStringContainsString("'disclosed' => implode(',', \$disclosed)", $code);
    }

    /**
     * ⛔ THE MEASUREMENT BEHIND `HELD_WITH_STATUS`.
     *
     * A key may be released by the stamp only if the projector writes it
     * INSIDE `if (!$statusHeld) { … }`. The block is extracted by matching
     * braces rather than read to the next `function` (`alpha.77`), so a
     * setter moved one line below the closing brace fails here instead of
     * becoming silently waivable.
     */
    public function testEveryStampReleasedKeyIsWrittenOnlyInsideTheStampGuard(): void
    {
        $code = $this->code(self::PROJECTOR);
        $block = $this->balancedBlockAfter($code, 'if(!$statusHeld){');
        self::assertNotSame('', $block, 'the stamp guard block was not found in the projector');

        self::assertNotSame([], PreservationRisk::HELD_WITH_STATUS, 'the list should not be empty');
        foreach (PreservationRisk::HELD_WITH_STATUS as $key) {
            // Released keys must be keys that were blocking in the first
            // place — releasing a disclosure would be a rule about nothing.
            self::assertContains($key, PreservationRisk::BLOCKING, $key);
            self::assertArrayHasKey($key, self::SETTERS, 'unmapped released key: ' . $key);
            foreach (self::SETTERS[$key] as $setter) {
                self::assertStringContainsString(
                    '->' . $setter . '(',
                    $block,
                    $key . ' is released by the stamp but ' . $setter . ' is not inside the guard'
                );
            }
        }
    }

    /**
     * The control: `manage_stock` is NOT inside that guard.
     *
     * Without it, a guard block that happened to contain every setter would
     * pass the test above and every risk would become stamp-releasable
     * (`alpha.46`). `set_manage_stock(true)` is unconditional, which is
     * exactly why it stays blocking for a stamped row too.
     */
    public function testManageStockIsNotReleasedByTheStamp(): void
    {
        $code = $this->code(self::PROJECTOR);
        $block = $this->balancedBlockAfter($code, 'if(!$statusHeld){');
        self::assertStringNotContainsString('->set_manage_stock(', $block);
        self::assertNotContains('manage_stock', PreservationRisk::HELD_WITH_STATUS);
        self::assertTrue(PreservationRisk::isBlockingGiven('manage_stock', true));
        self::assertTrue(PreservationRisk::isBlockingGiven('price_scale', true));
        self::assertTrue(PreservationRisk::isBlockingGiven('type', true));
        self::assertTrue(PreservationRisk::isBlockingGiven('product', true));
    }

    /**
     * ⛔ `alpha.94`: every key released by «the product exists» is written
     * ONLY inside `project()`'s `$isNew` arm.
     *
     * The same measurement as the stamp's, one fact over. `manage_stock` is
     * released because `set_manage_stock(true)` moved inside `if ($isNew)` —
     * so a product this marketplace did not create keeps its own setting —
     * and the day somebody moves it back out, this test fails rather than the
     * comment becoming wrong in silence (`alpha.90`).
     *
     * Scoped to `project()`'s own body, because the vendor's stock edit
     * (`writeStock()`) and the variation loop call the same setter on
     * purpose: there somebody has just typed a number.
     */
    public function testEveryKeyReleasedByAnExistingProductIsWrittenOnlyWhenWeCreateIt(): void
    {
        $body = $this->methodBody(self::PROJECTOR, self::PROJECT);
        self::assertNotSame('', $body, 'project() must be findable');
        $created = $this->balancedBlockAfter($body, 'if($isNew){');
        self::assertNotSame('', $created, 'project() must still have an $isNew arm');

        foreach (PreservationRisk::HELD_BY_EXISTING_PRODUCT as $key) {
            self::assertArrayHasKey($key, self::SETTERS, 'unmapped released key: ' . $key);
            foreach (self::SETTERS[$key] as $setter) {
                self::assertStringContainsString(
                    '->' . $setter . '(',
                    $created,
                    $key . ' is released by an existing product but ' . $setter
                        . ' is not inside the $isNew arm'
                );
                self::assertSame(
                    1,
                    substr_count($body, '->' . $setter . '('),
                    $setter . ' is called more than once in project(), so one of them is'
                        . ' outside the arm and the release is a licence'
                );
            }
        }
    }

    /**
     * ⛔ AND THE VARIATION LOOP OBEYS THE SAME RULE.
     *
     * The automated security review of `alpha.94` found the identical
     * unconditional write still standing one method along: `$isNew` guarded
     * only the quantity beside it, exactly as in `project()` before this
     * round. It is unreachable for an intaken row — an observed row holds no
     * variations, so the loop never runs for one — which is precisely why
     * only source can say it (`alpha.77`): the contract suite's WooCommerce
     * stub has no `WC_Product_Variation` at all, so no behaviour here can
     * reach it.
     *
     * Half of a fix is not a fix, and a release that says «a product we did
     * not create keeps its own stock setting» has to be true of its
     * variations too.
     */
    public function testTheVariationLoopWritesStockOnlyWhenItCreatesTheVariation(): void
    {
        $body = $this->methodBody(self::PROJECTOR, self::PROJECT_VARIATIONS);
        self::assertNotSame('', $body, 'projectVariations() must be findable');
        $created = $this->balancedBlockAfter($body, 'if($isNew){');
        self::assertNotSame('', $created, 'the loop must still have an $isNew arm');

        foreach (['set_manage_stock', 'set_stock_quantity'] as $setter) {
            self::assertStringContainsString('->' . $setter . '(', $created, $setter);
            self::assertSame(
                1,
                substr_count($body, '->' . $setter . '('),
                $setter . ' is called more than once in projectVariations(), so one of'
                    . ' them is outside the arm'
            );
        }

        // ⛔ `set_stock_status` is the one that appears TWICE on purpose, and
        // the first draft of this test demanded once and failed on correct
        // code. The second call retires a variation the marketplace no longer
        // has — `$stale->set_stock_status('outofstock')` — which is a
        // variation WE created and are withdrawing, not somebody else's
        // setting. So the assertion is: inside the arm for the live one, and
        // every other occurrence on `$stale`.
        self::assertStringContainsString('->set_stock_status(', $created);
        self::assertSame(
            substr_count($body, '->set_stock_status('),
            substr_count($created, '->set_stock_status(')
                + substr_count($body, '$stale->set_stock_status('),
            'a set_stock_status outside the $isNew arm must be the stale-variation one'
        );
    }

    /**
     * The control: the catalogue visibility is NOT released by an existing
     * product, and stock management is NOT released by the stamp.
     *
     * Each key answers to the one fact that makes its own write impossible.
     * Without this, a context that released everything would satisfy both
     * tests above and the four keys the owner's §3 protects would stop
     * protecting anything (`alpha.46`).
     */
    public function testEachKeyIsReleasedByItsOwnFactAndNoOther(): void
    {
        self::assertNotContains('catalog_visibility', PreservationRisk::HELD_BY_EXISTING_PRODUCT);
        self::assertNotContains('manage_stock', PreservationRisk::HELD_WITH_STATUS);
        self::assertTrue(PreservationRisk::isBlockingGiven('catalog_visibility', false, true));
        self::assertTrue(PreservationRisk::isBlockingGiven('manage_stock', true, false));
        foreach (['price_scale', 'type', 'product'] as $key) {
            self::assertTrue(PreservationRisk::isBlockingGiven($key, true, true), $key);
        }

        // ⛔ And the key the derivation reads is the key the reader writes.
        // `storefrontReadable()` answers «could we read it» from the absence
        // of one name; renaming that name in the reader without renaming the
        // constant would make every unreadable product look readable, which
        // is the one direction this must never fail in (`alpha.89`).
        self::assertSame('product', PreservationRisk::UNREADABLE_KEY);
        self::assertStringContainsString(
            "return['" . PreservationRisk::UNREADABLE_KEY . "'=>",
            (string) preg_replace('/\s+/', '', $this->code(self::READER)),
            'the reader must answer an unreadable product with exactly that key'
        );
    }

    /**
     * ⛔ `alpha.94`: the sale price is cleared only when our value is FRESH,
     * and the two callers pass opposite answers on purpose.
     *
     * A projection mirrors a row that may be months old, so it may not remove
     * a sale price it never recorded — the `price_scale` risk's sale half.
     * `writePrice()` is the vendor's own change, one statement after they
     * typed it, so there the removal is theirs to make (`alpha.83`). One
     * rule, two callers, and the text of each call pinned so they cannot
     * drift apart (`alpha.78`).
     */
    public function testTheSalePriceIsClearedOnlyWhenOurValueIsFresh(): void
    {
        $project = $this->methodBody(self::PROJECTOR, self::PROJECT);
        self::assertStringContainsString('$this->applySale($wcProduct,$product,false);', $project);
        self::assertStringNotContainsString('$this->applySale($wcProduct,$product,true);', $project);

        $write = $this->methodBody(self::PROJECTOR, self::WRITE_PRICE);
        self::assertNotSame('', $write, 'writePrice() must be findable');
        self::assertStringContainsString('$this->applySale($wcProduct,$product,true);', $write);

        // And the guard is inside the CLEARING branch, which is the only one
        // that takes something away.
        $clearing = $this->balancedBlockAfter(
            $this->code(self::PROJECTOR),
            'if($details->salePriceMinor===null){'
        );
        self::assertStringContainsString('if(!$oursIsFresh&&', $clearing);
        self::assertStringContainsString('->set_sale_price(\'\');', $clearing);

        // ⛔ AND IT READS THE SALE PRICE, which only source can say.
        //
        // Measured: through `project()` the sale and the regular price give
        // the SAME answer, because a product whose regular price is empty
        // never reaches this method at all — `hasPrice()` is false, the whole
        // price block is skipped and `$priceHeld` is set instead. So a guard
        // reading `get_regular_price()` behaves identically today and is
        // wrong for the next caller, and the falsification loop found exactly
        // that: the mutation survived every behavioural suite (`alpha.77` —
        // assert in source what no behaviour can reach).
        self::assertStringContainsString('$wcProduct->get_sale_price()', $clearing);
        self::assertStringNotContainsString('$wcProduct->get_regular_price()', $clearing);
    }

    /**
     * The body of the first brace-balanced block following `$needle`.
     *
     * `$code` is already comment-stripped and whitespace-free, so the needle
     * is written without spaces.
     */
    private function balancedBlockAfter(string $code, string $needle): string
    {
        $bare = preg_replace('/\s+/', '', $code) ?? '';
        $at = strpos($bare, $needle);
        if ($at === false) {
            return '';
        }
        $from = $at + strlen($needle);
        $depth = 1;
        for ($i = $from, $n = strlen($bare); $i < $n; $i++) {
            $char = $bare[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($bare, $from, $i - $from);
                }
            }
        }
        return '';
    }
}
