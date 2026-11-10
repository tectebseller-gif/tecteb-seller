<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Application\DeactivationStopPolicy;

/**
 * The stored answer, as a pure function.
 *
 * The behavioural half — that deactivation actually CONSULTS this, and that a
 * no really leaves `post_status` alone — is
 * `ActivationFlowTest::testDeactivationLeavesTheShelfAloneUnlessItWasToldTo`.
 * A green test here says nothing about the wiring (`alpha.80`).
 */
final class DeactivationStopPolicyTest extends TestCase
{
    public function testAbsenceIsNo(): void
    {
        self::assertFalse(DeactivationStopPolicy::decide(null));
        self::assertFalse(DeactivationStopPolicy::decide(false));
    }

    public function testAnExplicitYesInEveryShapeWordPressStoresItAs(): void
    {
        foreach ([true, 1, '1', 'yes', 'true', 'on', 'YES', ' On '] as $stored) {
            self::assertTrue(
                DeactivationStopPolicy::decide($stored),
                var_export($stored, true) . ' is a yes'
            );
        }
    }

    public function testAnExplicitNoInEveryShape(): void
    {
        foreach ([false, 0, '0', '', 'no', 'false', 'off', '  '] as $stored) {
            self::assertFalse(
                DeactivationStopPolicy::decide($stored),
                var_export($stored, true) . ' is a no'
            );
        }
    }

    /**
     * §6's default is that deactivation changes nothing, so a value nobody can
     * account for is not a decision. An array left by another plugin under a
     * colliding key, a float, an object — none of them stop a catalogue.
     */
    public function testAValueNobodyWroteOnPurposeIsNo(): void
    {
        foreach ([[], ['1'], 1.0, 2, -1, new \stdClass()] as $stored) {
            self::assertFalse(
                DeactivationStopPolicy::decide($stored),
                gettype($stored) . ' is not an answer'
            );
        }
    }

    /** The two options are different questions and must not share a key. */
    public function testTheTwoOptionNamesAreDistinctAndPrefixed(): void
    {
        self::assertNotSame(DeactivationStopPolicy::OPTION, DeactivationStopPolicy::LEFT_ON_SALE_OPTION);
        self::assertStringStartsWith('tmc_', DeactivationStopPolicy::OPTION);
        self::assertStringStartsWith('tmc_', DeactivationStopPolicy::LEFT_ON_SALE_OPTION);
    }
}
