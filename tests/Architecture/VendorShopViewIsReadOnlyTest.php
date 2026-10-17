<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the manager's shop view can only READ.
 *
 * §4 asked for four things that are all about absence — «هیچ فروشگاهی ساخته
 * نشود و فروشنده‌ای تصادفی انتخاب نشود … هویت حساب مدیر تغییر نکند … انتخاب
 * فروشگاه به‌تنهایی نباید اجازهٔ ویرایش یا اقدام به‌جای فروشنده بدهد» — and
 * absence is exactly what a sentence in a docblock cannot guarantee. The rule
 * this repository has settled on for that is a test that reads the file
 * (`alpha.45`: «دستهٔ سومِ ابزارها وجود دارد: می‌خواند، ولی نمی‌تواند
 * بنویسد» — and its guard is a TEST, not a promise).
 *
 * The needles are the verbs that would make it something else: a write to a
 * repository, a capability grant, a user switch, a form. Each is named rather
 * than matched by a pattern, so a reader of this test can see what the page
 * is not allowed to become.
 */
final class VendorShopViewIsReadOnlyTest extends TestCase
{
    private const PAGE = __DIR__ . '/../../src/Modules/Vendor/Presentation/Admin/VendorShopsPage.php';

    /**
     * @return array<string,array{string}>
     */
    public static function forbidden(): array
    {
        return [
            // Writing anything about the shop.
            'repository save' => ['->save('],
            'repository create' => ['->create('],
            'repository record' => ['->record('],
            'store rename' => ['renameStore('],
            'name seeding' => ['seedName('],
            'approval' => ['ReviewApplication'],
            // Becoming somebody else, or granting anything.
            'user switch' => ['wp_set_current_user'],
            'capability grant' => ['->add_cap('],
            'role change' => ['->add_role('],
            'cookie' => ['setcookie'],
            // Acting as the vendor: the `/vendor/` area builds every page from
            // the signed-in user, so reaching into it from here would be the
            // one way this page could act for somebody.
            'vendor router' => ['VendorRoutes'],
            // A form on this page is a write waiting to happen.
            'form' => ['<form method="post"'],
            'nonce' => ['wp_nonce_field'],
        ];
    }

    /**
     * @dataProvider forbidden
     */
    public function testThePageContainsNoWriter(string $needle): void
    {
        $source = self::code();
        self::assertNotSame('', $source, 'the page must be readable for this test to mean anything');
        self::assertStringNotContainsString($needle, $source, $needle);
    }

    /**
     * The file with its comments blanked out.
     *
     * `alpha.22`'s rule, and it cost this very test one run: the docblock
     * above says «Nothing here calls `wp_set_current_user()`», and a byte
     * scan cannot tell prose from code. The tokenizer can.
     */
    private static function code(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(self::PAGE)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    /**
     * WHAT THIS PROVES: opening one shop writes an audit row, and the picker
     * shows a list rather than choosing for anybody.
     *
     * The positive half of the same rule: a read-only page that recorded
     * nothing would satisfy every assertion above and still fail §4's «ثبت
     * در ردِ ممیزی», and a page that redirected straight into a shop would
     * satisfy them too.
     */
    public function testOpeningOneShopIsAuditedAndNothingIsPreselected(): void
    {
        $source = self::code();
        self::assertStringContainsString('VENDOR_SHOP_VIEWED', $source);
        self::assertStringContainsString('AuditLogger', $source);
        // The shop comes from the ADDRESS, and with none the list is shown.
        self::assertStringContainsString("queryInt('shop')", $source);
        self::assertStringNotContainsString('wp_safe_redirect', $source);
        self::assertStringNotContainsString('wp_redirect', $source);
    }

    /**
     * WHAT THIS PROVES: it is behind its own capability, not the review one.
     *
     * «دسترسی مشاهده با مجوز مشخص مدیریت» — and a page that reused
     * `REVIEW_PRODUCTS` would give every product reviewer a shop's figures,
     * which is the thing a separate capability exists to let an owner refuse.
     */
    public function testItIsBehindItsOwnCapability(): void
    {
        $source = self::code();
        self::assertStringContainsString('Capabilities::VIEW_VENDOR_SHOPS', $source);
        self::assertStringNotContainsString('Capabilities::REVIEW_PRODUCTS', $source);
    }
}
