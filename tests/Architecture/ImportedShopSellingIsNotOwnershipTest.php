<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: granting the right to sell cannot move a product.
 *
 * «این اقدام نباید به‌طور ضمنی مالکیت محصولات را منتقل کند» is a statement
 * about ABSENCE, and a docblock cannot guarantee absence — the rule this
 * repository settled on is a test that reads the file. The behaviour test
 * beside it reads the ownership tally before and after a real grant; this one
 * says the capability is not there to begin with, so a future edit has to
 * delete this test to get it back.
 *
 * The needles are named one at a time rather than matched by a pattern, so a
 * reader can see exactly what the service is not allowed to become.
 */
final class ImportedShopSellingIsNotOwnershipTest extends TestCase
{
    private const SERVICE = __DIR__ . '/../../src/Modules/Vendor/Application/DecideImportedShopSelling.php';

    /** @return array<string,array{string}> */
    public static function forbidden(): array
    {
        return [
            // Ownership, in every spelling that could move one.
            'ownership writer' => ['setLinkOwnership'],
            'ownership transfer service' => ['TransferOwnership'],
            'ownership enum' => ['LinkOwnership'],
            // Products at all: this service decides about a PERMISSION.
            'product repository' => ['ProductRepositoryInterface'],
            'product status' => ['ProductStatus'],
            // The suspension route drafts a shop's products. This must not.
            'withdraw vendor' => ['withdrawVendor'],
            'republish vendor' => ['republishVendor'],
            // Creating paperwork instead of deciding: «بدون ایجاد درخواست
            // فروشندگی صوری».
            'application draft' => ['saveDraft'],
            'application status write' => ['updateStatus'],
            // Writing the profile wholesale would rewrite the name and the
            // direct-publish switch as a side effect of a permission change.
            'whole-profile write' => ['upsertProfile'],
            // Becoming somebody else.
            'user switch' => ['wp_set_current_user'],
            'capability grant' => ['->add_cap('],
        ];
    }

    /** @dataProvider forbidden */
    public function testTheServiceCannotDoIt(string $needle): void
    {
        $source = self::code();
        self::assertNotSame('', $source, 'the service must be readable for this test to mean anything');
        self::assertStringNotContainsString($needle, $source, $needle);
    }

    /**
     * WHAT THIS PROVES: it shares the review capability and mints none.
     *
     * The positive half. Writing `can_sell` is byte for byte what approving an
     * application writes, so a SECOND capability for it would let an owner
     * grant this route to somebody they denied the review route — a
     * privilege-separation hole that minting the key creates. Pinned in both
     * directions: the review capability must be asked for, and no other may
     * stand in for it.
     */
    public function testItAsksForTheReviewCapabilityAndNoOtherOne(): void
    {
        $source = self::code();
        self::assertStringContainsString('VendorCapabilities::REVIEW', $source);
        self::assertStringContainsString('capabilities->can(', $source);
        foreach ([
            'VIEW_VENDOR_SHOPS',
            'VIEW_VENDOR_FINANCE',
            'REVIEW_PRODUCTS',
            'MANAGE_SETTINGS',
            'REVIEW_WITHDRAWALS',
        ] as $other) {
            self::assertStringNotContainsString($other, $source, $other);
        }
    }

    /**
     * WHAT THIS PROVES: the trail is written after the confirmed write.
     *
     * Asserted by POSITION in the file, because the direction is the whole
     * point: the shop view logs before it prints on purpose — a read that
     * fails halfway is still a read — and a write is the opposite case. A
     * trail that records a permission change which never landed is worse than
     * no trail, because somebody will read it as one.
     */
    public function testTheAuditLineComesAfterTheWriteAndItsCheck(): void
    {
        $source = self::code();
        $write = strpos($source, 'setSellingPermission(');
        $notStored = strpos($source, "'not_stored'");
        $audit = strpos($source, 'audit->log(');
        self::assertIsInt($write);
        self::assertIsInt($notStored);
        self::assertIsInt($audit);
        self::assertGreaterThan($write, $audit, 'the audit line must follow the write');
        self::assertGreaterThan($notStored, $audit, 'and follow the check that the write landed');
    }

    /** The file with its comments blanked out — `alpha.22`'s rule. */
    private static function code(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(self::SERVICE)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
