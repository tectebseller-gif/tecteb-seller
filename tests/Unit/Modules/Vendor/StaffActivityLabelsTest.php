<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Vendor;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;

/**
 * WHAT THIS PROVES: every audit event the staff-activity card claims to have
 * a Persian name for is an event that actually exists.
 *
 * `StaffView::eventLabel()` maps event keys to Persian. A key that no event
 * uses is not an error anywhere — the `match` simply never selects that arm,
 * and the page falls through to printing the raw key. So the first version of
 * that map shipped with `order.shipped`, which no event has ever been called,
 * and the commonest staff action of all — shipping — showed as
 * `order.item_shipped` on a Persian page. Nothing failed; it just quietly
 * stopped being translated.
 *
 * The map is read from source rather than called, because the method is
 * private and the thing under test is the KEYS, not the rendering. Same
 * technique as `ReportInvalidationTest`, and for the same reason: some
 * contracts are only visible in the text.
 */
final class StaffActivityLabelsTest extends TestCase
{
    public function testEveryLabelledEventKeyExistsInTheCatalogue(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Modules/Vendor/Presentation/StaffView.php'
        );
        $start = strpos($source, 'private static function eventLabel(');
        self::assertNotFalse($start, 'eventLabel() must exist — the card depends on it');
        $body = substr($source, $start);

        // The quoted keys on the left of each `=>` inside the match.
        preg_match_all("/^\s*'([a-z_]+\.[a-z_]+)'(?:,\s*'([a-z_]+\.[a-z_]+)')* =>/m", $body, $m);
        $keys = array_values(array_filter($m[1]));
        self::assertNotEmpty($keys, 'the label map must actually map something');

        $known = AuditEventCatalog::allowlist();
        foreach ($keys as $key) {
            self::assertArrayHasKey(
                $key,
                $known,
                "StaffView labels '{$key}', which is not an event this plugin ever writes — "
                . 'the arm can never be selected and the page will print the raw key'
            );
        }
    }

    /**
     * And the action a shipping clerk performs most is one of them.
     *
     * The general rule above would still pass a map that had drifted down to
     * one correct key, so the event this card exists to show is named.
     */
    public function testTheCommonestStaffActionIsLabelled(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Modules/Vendor/Presentation/StaffView.php'
        );
        self::assertStringContainsString(
            "'" . AuditEventCatalog::ORDER_ITEM_SHIPPED . "'",
            $source,
            'shipping is what the order-and-shipping role does; it must have a Persian name'
        );
    }
}
