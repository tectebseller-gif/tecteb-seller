<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the category mapping can be decided by a person, and
 * applying it can touch exactly one column.
 *
 * Two findings, one round, and the first is the one that made the second
 * necessary. `CategoryMap::save()` had existed since `alpha.17` and had **no
 * caller anywhere in `src/`** — the shipped `alpha.79`'s only mention of the
 * mapping was the handover page naming `categories_mapped` as a blocker. A
 * function with no caller is not a capability (`alpha.77`), and a gate whose
 * only exit is somebody editing an option row is worse than no gate.
 *
 * ⛔ **Why these are source assertions.** A behaviour test can show that one
 * application wrote one category. It cannot show that no application will ever
 * write a status, an ownership flag or a ledger line: the next line added to
 * the method would be reachable and every behaviour test would stay green.
 * «There is no way from here to there» is a property of the source.
 *
 * ⛔ Comments are stripped first. Both files below have docblocks that name
 * the very things being forbidden — «not `link_ownership`, not the status» —
 * and a rule asserted against raw source is satisfied by the sentence
 * describing it (`alpha.22`).
 */
final class CategoryMappingHasOneWriterTest extends TestCase
{
    private const READER = 'src/Modules/Migration/Application/CategoryMapping.php';

    private const WRITER = 'src/Modules/Migration/Application/ApplyCategoryMapping.php';

    private const PAGE = 'src/Modules/Migration/Presentation/Admin/HandoverPage.php';

    private const COMPLETENESS = 'src/Modules/Migration/Application/MigrationCompleteness.php';

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

    /** One method's body, by matching braces — never «up to the next `function`». */
    private function methodBody(string $code, string $method): string
    {
        $start = strpos($code, 'function ' . $method . '(');
        self::assertIsInt($start, 'no such method: ' . $method);
        $open = strpos($code, '{', (int) $start);
        self::assertIsInt($open, $method . ' has no body');
        $depth = 0;
        $length = strlen($code);
        for ($i = (int) $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
                continue;
            }
            if ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . ': unbalanced braces');
    }

    /**
     * The decision is reachable from an admin page — the whole finding.
     *
     * Asserted on the CALL, not on the definition: that is the difference
     * between «the code exists» and «a person can take the decision», and it
     * is the distinction `alpha.77` was delivered for.
     */
    public function testSavingTheMappingIsReachableFromAnAdminPage(): void
    {
        $page = $this->code(self::PAGE);
        self::assertStringContainsString(
            'CategoryMap::class',
            $page,
            'the handover page must resolve the map it saves'
        );
        self::assertStringContainsString(
            '$map->save($merged)',
            $page,
            'nothing called CategoryMap::save() in the shipped alpha.79'
        );
        // And both halves of the one decision: setting it and unsetting it. A
        // mapping saved by mistake with no way back is a permanent wrong
        // answer on every product of that source category.
        foreach (["'save_category'", "'forget_category'", "'apply_categories'"] as $action) {
            self::assertStringContainsString(
                $action,
                $page,
                'the page must answer the action ' . $action
            );
        }
    }

    /**
     * The source category never travels through a form as itself.
     *
     * A Persian `product_cat` slug is percent-encoded, and WordPress's
     * `sanitize_key()` AND `sanitize_text_field()` both delete `%xx` octets —
     * so a form carrying the slug hands the handler a different category, or
     * an empty one, with nothing anywhere looking wrong. The same family as
     * `alpha.60`'s collation finding: a value transported through a filter
     * that rewrites it is not the value.
     */
    public function testTheSourceKeyTravelsAsADigest(): void
    {
        $page = $this->code(self::PAGE);
        self::assertStringContainsString(
            'function sourceRef(',
            $page,
            'the source key needs a transportable form'
        );
        self::assertStringContainsString(
            "substr(sha1(\$sourceKey), 0, 32)",
            $page,
            'the digest must be hex, which is what survives sanitize_key()'
        );
        $save = $this->methodBody($page, 'handleAction');
        self::assertStringContainsString(
            "self::sourceRef((string) \$row['key']) === \$ref",
            $save,
            'the digest must be resolved against the list that was drawn'
        );
        // And never read back out of the request as text.
        foreach (["postText('cat_ref')", "postMap('category_map')", "queryText('cat')"] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $page,
                $forbidden . ' would arrive with its %xx octets deleted'
            );
        }
    }

    /** The preview has no write method of any kind. */
    public function testThePreviewOnlyLooks(): void
    {
        $reader = $this->code(self::READER);
        foreach ([
            'function save(',
            'function apply(',
            'function set(',
            'function write(',
            'function update(',
            'function delete(',
            'function insert(',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $reader,
                'CategoryMapping is the half that only looks: ' . $forbidden
            );
        }
        // Nor may it reach a writer through something else.
        foreach (['->save(', '->setCategoryWhenEmpty(', '->updateStatus(', '->updateDetails('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $reader, 'CategoryMapping may not call ' . $forbidden);
        }
    }

    /**
     * The writer writes ONE column, through the one narrow repository method.
     *
     * Not `updateDetails()`, which rewrites every detail column from whatever
     * the caller read a moment ago — the title, the price, the stock, the
     * brand. That is why `setCategoryWhenEmpty()` exists.
     */
    public function testTheWriterTouchesTheCategoryAndNothingElse(): void
    {
        $writer = $this->code(self::WRITER);
        $body = $this->methodBody($writer, 'apply');
        self::assertStringContainsString(
            '$this->products->setCategoryWhenEmpty($productId, (string) $row[\'target\'])',
            $body,
            'the one write, with the target derived again rather than trusted'
        );
        foreach ([
            'updateDetails(' => 'would rewrite the title, price, stock and brand',
            'updateStatus(' => 'publication is not a migration decision',
            'setLinkOwnership(' => 'ownership is taken on its own screen',
            'mirrorStock(' => 'stock belongs to WooCommerce after the first projection',
            'updateInventory(' => 'inventory is not a category',
            'create(' => 'nothing here makes a product — no re-import, no duplicate',
            'enqueue(' => 'nothing here queues an import',
            'wp_insert_term' => 'this plugin never writes a taxonomy term',
            'wp_update_term' => 'this plugin never writes a taxonomy term',
            'wp_set_object_terms' => 'WooCommerce categories are not touched',
            'canSell' => 'selling permission is ReviewApplication\'s and nobody else\'s',
            'ledger' => 'a ledger line is money',
            'commission' => 'a commission is money',
            'withdraw' => 'a withdrawal is money',
        ] as $forbidden => $why) {
            self::assertStringNotContainsString($forbidden, $writer, 'ApplyCategoryMapping: ' . $why);
        }
    }

    /**
     * The gate reads BOTH halves, and the second one is the finding.
     *
     * `unmappedFor()` asks Dokan and empties the moment the option is saved.
     * `countImportedWithoutCategory()` asks our own table and does not. A gate
     * that reads only the first goes green on a saved mapping while every
     * imported row still carries `category_key = ''` — «صرف ذخیرهٔ option و
     * سبز شدن وضعیت کافی نیست».
     *
     * ⛔ The CONDITION is pinned, not only the call. `if (false)` leaves a
     * call's text exactly where it was, so containment on the call alone stays
     * green through a mutation that removes the guard (`alpha.79`).
     */
    public function testTheGateReadsTheProductsAndNotOnlyTheMapping(): void
    {
        $code = $this->code(self::COMPLETENESS);
        self::assertStringContainsString(
            '$this->products->countImportedWithoutCategory($vendorUserId)',
            $code,
            'the gate must read our own uncategorised rows'
        );
        $body = $this->methodBody($code, 'forVendor');
        self::assertStringContainsString(
            "'categories_mapped' => \$mappableUnmapped === [] && \$withoutCategory === 0,",
            $body,
            'both halves, and `=== 0` so a failed count (-1) keeps the gate open'
        );
        self::assertStringContainsString(
            "'products_without_category' => \$withoutCategory,",
            $body,
            'and the number reaches the page, so the reason can be printed'
        );
        // ⛔ And the no-source group is filtered OUT of the decision half, so
        // the gate has a door: no mapping can ever empty a group of products
        // that carry no source category, and waiting for it would leave
        // «invent a mapping for the empty key» as the only exit (`alpha.60`).
        self::assertStringContainsString(
            "static fn (array \$row): bool => trim((string) (\$row['key'] ?? '')) !== ''",
            $body,
            'the decision half counts only source categories somebody can map'
        );
        self::assertStringContainsString(
            "'unmapped_mappable' => \$mappableUnmapped,",
            $body,
            'and the page is given that list, not the raw one'
        );
    }
}
