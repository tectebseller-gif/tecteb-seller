<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;
use Tecteb\Marketplace\Modules\Product\Domain\ProductCategory;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WpProductCategoryLinkReader;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;

/**
 * WHAT THIS PROVES: the existing `product_cat` relationship is read from the
 * real relationship tables, by term id, and a read that fails says so.
 *
 * Against real MariaDB rather than a stub, because the thing being checked is
 * a join across `term_relationships` and `term_taxonomy` plus a separate
 * `COUNT(*)`, and a stub that answers from an array cannot show either a
 * taxonomy filter that works or a short read that does not (`alpha.59`: a
 * double that does not model the verb makes an incomplete guard look
 * complete).
 */
final class CategoryLinkReadTest extends DatabaseTestCase
{
    private const PRODUCT_CAT = 'product_cat';

    /** The real tables, with the real taxonomy column — nothing about them is faked. */
    private function resetTermTables(): void
    {
        $pdo = $this->wpdb->pdo();
        foreach (['term_relationships', 'term_taxonomy'] as $suffix) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $this->wpdb->prefix . $suffix . '`');
        }
        $pdo->exec(
            'CREATE TABLE `' . $this->wpdb->prefix . 'term_taxonomy` (
                term_taxonomy_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                taxonomy VARCHAR(32) NOT NULL DEFAULT \'\',
                parent BIGINT UNSIGNED NOT NULL DEFAULT 0,
                count BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (term_taxonomy_id),
                UNIQUE KEY term_id_taxonomy (term_id, taxonomy)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'
        );
        $pdo->exec(
            'CREATE TABLE `' . $this->wpdb->prefix . 'term_relationships` (
                object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                term_taxonomy_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                term_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (object_id, term_taxonomy_id),
                KEY term_taxonomy_id (term_taxonomy_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'
        );
    }

    /** @param list<int> $termIds */
    private function attach(int $wcProductId, array $termIds, string $taxonomy = self::PRODUCT_CAT): void
    {
        $pdo = $this->wpdb->pdo();
        foreach ($termIds as $termId) {
            $find = $pdo->prepare(
                'SELECT term_taxonomy_id FROM `' . $this->wpdb->prefix
                . 'term_taxonomy` WHERE term_id = ? AND taxonomy = ?'
            );
            $find->execute([$termId, $taxonomy]);
            $ttid = $find->fetchColumn();
            if ($ttid === false) {
                $pdo->prepare(
                    'INSERT INTO `' . $this->wpdb->prefix
                    . 'term_taxonomy` (term_id, taxonomy) VALUES (?, ?)'
                )->execute([$termId, $taxonomy]);
                $ttid = $pdo->lastInsertId();
            }
            $pdo->prepare(
                'INSERT IGNORE INTO `' . $this->wpdb->prefix
                . 'term_relationships` (object_id, term_taxonomy_id, term_order) VALUES (?, ?, 0)'
            )->execute([$wcProductId, $ttid]);
        }
    }

    /**
     * A directory that knows three ids and nothing else.
     *
     * Narrow on purpose: an id this does not return is an id a term was
     * deleted for, which is the owner's «دستهٔ حذف‌شده» case, and the reader
     * has to call it invalid rather than a category.
     */
    private function directory(): ProductCategoryDirectoryInterface
    {
        return new class implements ProductCategoryDirectoryInterface {
            public const KNOWN = [
                165 => 'تجهیزات پزشکی › تزریق',
                166 => 'تجهیزات پزشکی › چسب',
                167 => 'مواد مصرفی › چسب',
            ];

            public function search(string $query, int $limit = 50): array
            {
                return [];
            }

            public function countMatches(string $query): int
            {
                return 0;
            }

            public function find(string $value): ?ProductCategory
            {
                $id = (int) $value;
                return isset(self::KNOWN[$id])
                    ? new ProductCategory($id, 'c' . $id, self::KNOWN[$id])
                    : null;
            }

            public function explainNoMatch(string $query): array
            {
                return ['words' => [], 'missing' => [], 'found' => []];
            }

            public function total(): int
            {
                return count(self::KNOWN);
            }
        };
    }

    private function reader(?\Tecteb\Marketplace\Contracts\DatabaseInterface $db = null): WpProductCategoryLinkReader
    {
        return new WpProductCategoryLinkReader($db ?? new WpDatabase($this->wpdb), $this->directory());
    }

    /**
     * WooCommerce's own option, in the store `get_option()` really reads.
     *
     * ⛔ Not `State::$options`: this suite sets `optionsBackedByWpdb`, so the
     * stub reads the real options table and an array written beside it is a
     * value nothing consults — a fixture that sets the wrong store measures
     * nothing and looks like a failing guard.
     */
    private function setDefaultTerm(int $termId): void
    {
        update_option('default_product_cat', $termId);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTermTables();
        $this->setDefaultTerm(15);
    }

    public function testOneProductsRelationshipIsReadByTermId(): void
    {
        $this->attach(900, [166]);

        $links = $this->reader()->linksFor([900]);

        self::assertArrayHasKey(900, $links);
        self::assertSame(CategoryLink::DEFINITE, $links[900]->state);
        self::assertSame('166', $links[900]->definiteKey());
        self::assertSame('تجهیزات پزشکی › چسب', $links[900]->paths[166]);
    }

    public function testAProductInThreeCategoriesKeepsAllThreeAndOffersNoKey(): void
    {
        $this->attach(901, [165, 166, 167]);

        $link = $this->reader()->linksFor([901])[901];

        self::assertSame(CategoryLink::MULTIPLE, $link->state);
        self::assertSame([165, 166, 167], $link->validIds);
        self::assertSame('', $link->definiteKey());
        self::assertCount(3, $link->pathList());
    }

    /**
     * Terms from another taxonomy are not this product's categories.
     *
     * `term_relationships` holds every taxonomy — tags, brands, attributes —
     * against the same `object_id`, so a join without the taxonomy filter
     * answers about something else entirely. That is the `alpha.56` mistake
     * (`post_type` unbounded) one table over.
     */
    public function testATagOnTheSameProductIsNotACategory(): void
    {
        $this->attach(902, [166]);
        $this->attach(902, [165], 'product_tag');

        $link = $this->reader()->linksFor([902])[902];

        self::assertSame(CategoryLink::DEFINITE, $link->state);
        self::assertSame([166], $link->validIds);
        self::assertSame('166', $link->definiteKey());
    }

    public function testADeletedTermIsInvalidRatherThanACategory(): void
    {
        $this->attach(903, [999]);

        $link = $this->reader()->linksFor([903])[903];

        self::assertSame(CategoryLink::INVALID, $link->state);
        self::assertSame([999], $link->invalidIds);
        self::assertSame('', $link->definiteKey());
    }

    public function testTheShopsDefaultTermIsReadAsNoCategory(): void
    {
        $this->attach(904, [15]);

        $link = $this->reader()->linksFor([904])[904];

        self::assertSame(CategoryLink::DEFAULT_ONLY, $link->state);
        self::assertTrue($link->defaultAttached);
        self::assertSame('', $link->definiteKey());
    }

    public function testAProductWithNoRelationshipIsNoneAndStillPresent(): void
    {
        $links = $this->reader()->linksFor([905]);

        self::assertArrayHasKey(905, $links, 'every id asked for comes back');
        self::assertSame(CategoryLink::NONE, $links[905]->state);
    }

    /**
     * The cost does not grow with the number of products.
     *
     * Asserted as an INVARIANT rather than against a number I chose: «two
     * statements» would be a sentence about today's implementation detail
     * (the option read makes it three in this suite), and a test pinned to my
     * own example is the shape `alpha.46` had to unpick. What matters is that
     * 500 products do not cost 500 queries, so the same batch is measured at
     * two sizes and the difference has to be nil.
     */
    public function testTheCostDoesNotGrowWithTheNumberOfProducts(): void
    {
        $this->attach(906, [166]);
        $this->attach(907, [165, 167]);
        $this->attach(908, [15]);
        for ($id = 920; $id < 960; $id++) {
            $this->attach($id, [166, 167]);
        }

        $small = $this->wpdb->num_queries;
        $links = $this->reader()->linksFor([906, 907, 908, 909]);
        $smallSpent = $this->wpdb->num_queries - $small;

        $big = $this->wpdb->num_queries;
        $many = $this->reader()->linksFor(array_merge([906, 907, 908, 909], range(920, 959)));
        $bigSpent = $this->wpdb->num_queries - $big;

        self::assertCount(4, $links);
        self::assertSame(
            [CategoryLink::DEFINITE, CategoryLink::MULTIPLE, CategoryLink::DEFAULT_ONLY, CategoryLink::NONE],
            [$links[906]->state, $links[907]->state, $links[908]->state, $links[909]->state]
        );
        self::assertCount(44, $many);
        self::assertSame($smallSpent, $bigSpent, 'eleven times the products, the same number of queries');
        self::assertLessThan(5, $bigSpent, 'and a handful in absolute terms');
    }

    /**
     * A failed read is unreadable for the whole batch — never an empty set.
     *
     * The dangerous shape is the quiet one: `getResults()` gives the same
     * empty array for «no rows» and «the statement failed» (`alpha.8`), so
     * without the separate count a failure would read as «this product has no
     * categories» and the preview would offer a human a decision about
     * nothing.
     */
    public function testAFailedCountMakesTheWholeBatchUnreadable(): void
    {
        $this->attach(910, [165, 166, 167]);

        $failing = new FailingDatabase(new WpDatabase($this->wpdb));
        $failing->failReadWhen(['COUNT(*)', 'term_relationships']);
        $link = $this->reader($failing)->linksFor([910])[910];

        self::assertSame(CategoryLink::UNREADABLE, $link->state);
        self::assertNotSame(CategoryLink::NONE, $link->state);
        self::assertSame('', $link->definiteKey());
    }

    public function testAFailedListingMakesTheWholeBatchUnreadable(): void
    {
        $this->attach(911, [165, 166]);

        $failing = new FailingDatabase(new WpDatabase($this->wpdb));
        // ⛔ Needles unique to the LISTING. The count statement contains both
        // `tr.object_id` and `term_taxonomy_id` too, so the obvious pair
        // refuses the count as well — and then the `$expected === null` check
        // catches it and the short-read guard is never reached. Falsification
        // found that: with the guard reverted this test still passed, which
        // is the `alpha.68` shape (a fixture satisfying the OLD condition
        // measures nothing). `AS wc_id` appears only in the listing.
        $failing->failReadWhen(['AS wc_id']);
        $link = $this->reader($failing)->linksFor([911])[911];

        self::assertSame(CategoryLink::UNREADABLE, $link->state);
    }

    public function testTheDefaultTermIsReadFromWooCommercesOwnOption(): void
    {
        $this->setDefaultTerm(42);
        $this->attach(912, [42]);

        $reader = $this->reader();

        self::assertSame(42, $reader->defaultTermId());
        self::assertSame(CategoryLink::DEFAULT_ONLY, $reader->linksFor([912])[912]->state);
    }

    public function testNothingIsAskedForAnEmptyBatch(): void
    {
        $before = $this->wpdb->num_queries;

        self::assertSame([], $this->reader()->linksFor([]));
        self::assertSame($before, $this->wpdb->num_queries);
    }
}
