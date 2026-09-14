<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Vendor;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Vendor\Domain\InvitationToken;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffPermissions;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

/**
 * The permission matrix of Master Spec §3.1, asserted row by row.
 *
 * Transcribing a table into an enum is exactly the kind of work where a
 * plausible-looking mistake survives review, so every cell is checked against
 * the document rather than against the implementation.
 */
final class StaffPermissionsTest extends TestCase
{
    /** @return array<string,array{StaffRolePreset, array<string,string>}> */
    public static function matrix(): array
    {
        return [
            'store manager: everything but finance' => [StaffRolePreset::StoreManager,
                ['product' => 'edit', 'inventory' => 'edit', 'order' => 'edit', 'report' => 'view', 'finance' => 'none']],
            'product and inventory only' => [StaffRolePreset::ProductAndInventory,
                ['product' => 'edit', 'inventory' => 'edit', 'order' => 'none', 'report' => 'none', 'finance' => 'none']],
            'order and shipping sees stock, does not change it' => [StaffRolePreset::OrderAndShipping,
                ['product' => 'view', 'inventory' => 'view', 'order' => 'edit', 'report' => 'none', 'finance' => 'none']],
            'accountant reads money, touches nothing' => [StaffRolePreset::Accountant,
                ['product' => 'none', 'inventory' => 'none', 'order' => 'view', 'report' => 'view', 'finance' => 'view']],
            'support answers, does not edit' => [StaffRolePreset::CustomerSupport,
                ['product' => 'view', 'inventory' => 'none', 'order' => 'respond', 'report' => 'none', 'finance' => 'none']],
        ];
    }

    /**
     * @dataProvider matrix
     * @param array<string,string> $expected
     */
    public function testEachPresetMatchesTheSpecRow(StaffRolePreset $preset, array $expected): void
    {
        self::assertSame($expected, $preset->permissions()->toArray());
    }

    public function testNoPresetGrantsFinanceBeyondReading(): void
    {
        foreach (StaffRolePreset::presets() as $preset) {
            self::assertFalse(
                $preset->permissions()->allows(StaffArea::Finance, StaffLevel::Edit),
                $preset->value . ' must not be able to change money'
            );
        }
    }

    public function testRespondDoesNotImplyEdit(): void
    {
        $support = StaffRolePreset::CustomerSupport->permissions();

        self::assertTrue($support->allows(StaffArea::Order, StaffLevel::Respond));
        self::assertTrue($support->allows(StaffArea::Order, StaffLevel::View));
        self::assertFalse($support->allows(StaffArea::Order, StaffLevel::Edit), 'answering a customer is not cancelling their shipment');
    }

    public function testEditImpliesEverythingBelowIt(): void
    {
        $manager = StaffRolePreset::StoreManager->permissions();

        self::assertTrue($manager->allows(StaffArea::Order, StaffLevel::Edit));
        self::assertTrue($manager->allows(StaffArea::Order, StaffLevel::Respond));
        self::assertTrue($manager->allows(StaffArea::Order, StaffLevel::View));
    }

    public function testAnUnknownLevelIsReadAsNoAccess(): void
    {
        $permissions = StaffPermissions::of(['order' => 'superuser', 'finance' => 'edit']);

        self::assertSame(StaffLevel::None, $permissions->level(StaffArea::Order));
        self::assertSame(StaffLevel::Edit, $permissions->level(StaffArea::Finance));
    }

    public function testACustomSetWithNothingInItIsRecognisedAsEmpty(): void
    {
        self::assertTrue(StaffRolePreset::Custom->permissions()->isEmpty());
        self::assertFalse(StaffRolePreset::Accountant->permissions()->isEmpty());
    }

    public function testPermissionsSurviveStorageUnchanged(): void
    {
        foreach (StaffRolePreset::presets() as $preset) {
            $stored = $preset->permissions()->toArray();
            self::assertSame($stored, StaffPermissions::fromArray($stored)->toArray());
        }
    }

    public function testOnlyAnActiveMemberMayAct(): void
    {
        self::assertTrue(StaffStatus::Active->canAct());
        self::assertFalse(StaffStatus::Invited->canAct(), 'an invitation nobody accepted is not access');
        self::assertFalse(StaffStatus::Suspended->canAct(), 'suspension is immediate');
    }

    public function testAnInvitationIsStoredOnlyAsAHashAndMatchesInConstantTime(): void
    {
        $token = InvitationToken::issue();

        self::assertNotSame($token->plain, $token->hash);
        self::assertSame(64, strlen($token->hash));
        self::assertTrue(InvitationToken::looksWellFormed($token->plain));
        self::assertTrue(InvitationToken::matches($token->plain, $token->hash));
        self::assertFalse(InvitationToken::matches($token->plain . 'a', $token->hash));
        self::assertFalse(InvitationToken::matches('', $token->hash));
        self::assertFalse(InvitationToken::matches($token->plain, ''), 'an empty stored hash must never match');
    }

    public function testTwoInvitationsAreNeverTheSame(): void
    {
        self::assertNotSame(InvitationToken::issue()->plain, InvitationToken::issue()->plain);
    }
}
