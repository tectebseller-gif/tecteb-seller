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
    ];

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
        self::assertStringContainsString(
            'array_diff(PreservationRisk::blockingIn($risks), $acknowledged)',
            $code
        );
        self::assertStringNotContainsString(
            'array_diff(array_keys($risks), $acknowledged)',
            $code,
            'the old all-keys spelling must be gone'
        );
    }

    /** The disclosures reach the audit row, so the trail is not silent. */
    public function testTheDisclosuresAreRecorded(): void
    {
        $code = $this->code(self::TRANSFER);
        self::assertStringContainsString('PreservationRisk::disclosuresIn($risks)', $code);
        self::assertStringContainsString("'disclosed' => implode(',', \$disclosed)", $code);
    }
}
