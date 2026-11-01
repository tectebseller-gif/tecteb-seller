<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the manager's acceptance of a default term is stored in
 * its own table, written from exactly one place, never issued automatically,
 * and incapable of touching `category_key`, the link table or WooCommerce.
 *
 * ⛔ **Why these are source assertions.** A behaviour test shows that one
 * acceptance wrote one row and moved nothing else. It cannot show that the
 * next line added to the service will not write a key: that line would be
 * reachable and every behaviour test would stay green. «There is no road from
 * here to there» is a property of the source.
 *
 * The owner's constraints are the whole reason:
 *  - «`category_key` و مجموعه پیوندهای دسته صرفاً برای بستن دروازه دست‌کاری
 *    نشوند.»
 *  - «هیچ تأییدی خودکار صادر نشود.»
 *
 * ⛔ Comments are stripped first. Every one of these files names the things
 * being forbidden out loud, and a rule checked against raw source is
 * satisfied by the sentence describing it (`alpha.22`).
 */
final class DefaultCategoryAcceptanceIsIndependentTest extends TestCase
{
    private const REPOSITORY = 'src/Modules/Product/Infrastructure/DbDefaultCategoryAcceptanceRepository.php';

    private const SERVICE = 'src/Modules/Product/Application/AcceptDefaultCategory.php';

    private const MIGRATION = 'src/Modules/Product/Infrastructure/Migrations/M0036DefaultCategoryAcceptance.php';

    private const PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php';

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
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . ' has an unbalanced body');
    }

    public function testTheRepositoryNamesNoTableButItsOwn(): void
    {
        $code = $this->code(self::REPOSITORY);
        foreach ([
            'tmc_products' => 'the product table carries `category_key`',
            'tmc_product_categories' => 'a link row IS a relationship the projector writes back',
            'term_relationships' => 'that is WooCommerce\'s own relationship table',
            'term_taxonomy' => 'that is WooCommerce\'s own taxonomy table',
            'posts' => 'a product post is not this table\'s business',
            'postmeta' => 'post meta is not this table\'s business',
        ] as $needle => $why) {
            self::assertStringNotContainsString(
                $needle,
                $code,
                self::REPOSITORY . ' must not name `' . $needle . '`: ' . $why
            );
        }
        // And it is the acceptance table it DOES name — otherwise the rule
        // above would also pass on an empty file.
        self::assertStringContainsString('M0036DefaultCategoryAcceptance', $code);
    }

    public function testTheServiceCannotWriteAnythingButAnAcceptance(): void
    {
        $code = $this->code(self::SERVICE);
        foreach ([
            'setCategoryWhenEmpty(',
            'recordCategorySet(',
            'updateDetails(',
            'updateStatus(',
            'updateInventory(',
            'setLinkOwnership(',
            'saveBaseline(',
            'wp_set_object_terms(',
            'wp_insert_term(',
            'wp_update_term(',
            'wp_delete_term(',
            'set_category_ids(',
            'update_option(',
            'update_post_meta(',
            '->execute(',
        ] as $verb) {
            self::assertStringNotContainsString(
                $verb,
                $code,
                self::SERVICE . ' must not reach `' . $verb . '`'
            );
        }
        // The two writes it IS allowed, both on the acceptance port.
        self::assertStringContainsString('$this->acceptances->accept(', $code);
        self::assertStringContainsString('$this->acceptances->revoke(', $code);
    }

    public function testTheMigrationSeedsNoAcceptance(): void
    {
        $body = $this->methodBody($this->code(self::MIGRATION), 'up');
        self::assertStringNotContainsString(
            'INSERT',
            $body,
            'migration 36 must create no acceptance: «هیچ تأییدی خودکار صادر نشود»'
        );
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $body);
    }

    public function testTheProjectorNeverReadsAnAcceptance(): void
    {
        $code = $this->code(self::PROJECTOR);
        foreach ([
            'M0036DefaultCategoryAcceptance',
            'tmc_product_default_category',
            'DefaultCategoryAcceptance',
        ] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $code,
                'an accepted default term must never become a `product_cat` write'
            );
        }
    }

    public function testOnlyTheHandoverPageReachesTheService(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $callers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
            if ($relative === self::SERVICE) {
                continue;
            }
            // ⛔ COMMENTS STRIPPED FIRST, and this test paid for it on its
            // own first run: migration 36's docblock explains that the only
            // `INSERT` lives in `AcceptDefaultCategory::accept()`, and the
            // scan counted that sentence as a caller (`alpha.22`, on my own
            // prose again).
            if (str_contains($this->code($relative), 'AcceptDefaultCategory')) {
                $callers[] = $relative;
            }
        }
        sort($callers);
        // ⛔ The page that offers the button, and the module that binds it.
        // Anything else would be a second door to a decision the owner said
        // must be explicit, and no behaviour test would notice.
        self::assertSame(
            [
                'src/Modules/Migration/Presentation/Admin/HandoverPage.php',
                'src/Modules/Product/ProductModule.php',
            ],
            $callers
        );
    }
}
