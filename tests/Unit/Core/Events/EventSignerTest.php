<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Events;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Events\EventSigner;

/**
 * WHAT THIS PROVES: a receiver that re-encodes the payload gets the same
 * signature, whatever order the keys arrived in — and a changed payload does
 * not.
 */
final class EventSignerTest extends TestCase
{
    public function testKeyOrderDoesNotChangeTheSignature(): void
    {
        $a = ['event' => 'x', 'data' => ['b' => 2, 'a' => 1], 'id' => 5];
        $b = ['id' => 5, 'data' => ['a' => 1, 'b' => 2], 'event' => 'x'];
        self::assertSame(
            EventSigner::sign($a, 1700000000, 'secret'),
            EventSigner::sign($b, 1700000000, 'secret'),
            'a receiver re-encoding the same object must reach the same bytes'
        );
    }

    public function testListOrderDOESChangeIt(): void
    {
        // Position is meaning in a list. Sorting one would silently rewrite the
        // payload, so the canonicaliser must leave lists alone.
        $first = ['data' => ['items' => [1, 2, 3]]];
        $second = ['data' => ['items' => [3, 2, 1]]];
        self::assertNotSame(
            EventSigner::sign($first, 1700000000, 'secret'),
            EventSigner::sign($second, 1700000000, 'secret')
        );
    }

    public function testTheTimestampIsPartOfWhatIsSigned(): void
    {
        $payload = ['event' => 'x'];
        self::assertNotSame(
            EventSigner::sign($payload, 1700000000, 'secret'),
            EventSigner::sign($payload, 1700000001, 'secret'),
            'without this a captured delivery can be replayed for ever'
        );
    }

    public function testVerifyAcceptsTheRealOneAndRefusesEverythingElse(): void
    {
        $payload = ['event' => 'vendor.approved', 'data' => ['vendor_user_id' => 7]];
        $signature = EventSigner::sign($payload, 1700000000, 'secret');

        self::assertTrue(EventSigner::verify($payload, 1700000000, 'secret', $signature));
        self::assertFalse(EventSigner::verify($payload, 1700000000, 'other-secret', $signature));
        self::assertFalse(EventSigner::verify(['event' => 'vendor.suspended'], 1700000000, 'secret', $signature));
        self::assertFalse(EventSigner::verify($payload, 1700000001, 'secret', $signature));
    }

    public function testTheSignatureCarriesItsVersion(): void
    {
        // A receiver has to be able to tell v1 bytes from v2 bytes before it
        // decides how to verify them; a bare hex string cannot say.
        self::assertStringStartsWith('v1=', EventSigner::sign(['a' => 1], 1, 's'));
    }

    public function testPersianTextSurvivesTheCanonicalEncodingUnescaped(): void
    {
        $encoded = EventSigner::encode(['store_name' => 'داروخانهٔ مرکزی']);
        self::assertStringContainsString('داروخانهٔ مرکزی', $encoded, 'escaped unicode would make two encoders disagree');
    }
}
