<?php
/*
 * The four people who have to be able to log in for a walkthrough.
 *
 *   wp eval-file tools/walkthrough-state.php roles
 *   wp eval-file tools/walkthrough-state.php reset-passwords
 *
 * ### Why this exists beside acceptance-path.php
 *
 * `acceptance-path.php run` builds the DATA — a shop, its staff, products, an
 * order, a return, a withdrawal. It says nothing about how a human signs in
 * as any of them, because nothing in it needs to: it runs as PHP.
 *
 * A walkthrough does need it. Screens are what the owner asked to see, and a
 * screen belongs to whoever is looking at it — a vendor page photographed
 * while signed in as an administrator is a picture nobody will ever see. So
 * this resolves the four accounts the acceptance path created, gives them
 * passwords that are known and obviously disposable, and prints one line per
 * role for the browser tool to read.
 *
 * (No `declare(strict_types=1)` here on purpose: `wp eval-file` evaluates
 * the body, and a declare must be the first statement of a real script.)
 *
 * ### It refuses to run anywhere real
 *
 * Every password here is a literal in a file in a public repository. That is
 * fine on a disposable container and catastrophic anywhere else, so the
 * target is checked FIRST and anything that is not provably the disposable
 * install is refused outright rather than warned about.
 *
 * The test is the same one `tools/disposable-site.sh` already trusts — the
 * database must be named `tmc_wp_test` — plus a local `home_url()`. Both
 * together, and deliberately NOT `wp_get_environment_type()`: that reports
 * `production` on this very container (nobody set the constant), so a guard
 * built on it would either refuse the disposable site or have to be loosened
 * until it stopped refusing anything. A name somebody has to have chosen and
 * a host nobody outside the container can reach are facts; a label that
 * defaults to `production` is not.
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipItems;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "walkthrough-state: run me through `wp eval-file`.\n");
    exit(1);
}

// Test-only, and named so nobody can mistake them for anything else.
const WALKTHROUGH_PASSWORD = 'TmcWalkthrough!2026';

// `$args` is provided by `wp eval-file` itself — every other tool here reads
// it the same way. Assigning it from $argv (which is wp-cli's own process
// argv) silently swallowed the verb and ran the default instead.
$command = (string) ($args[0] ?? 'roles');

$say = static function (string $line): void {
    echo $line . "\n";
};

// The disposable database, by the same name tools/disposable-site.sh
// requires. A real site is never called this.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    $say('refused=1 reason=database_is_not_the_disposable_one');
    fwrite(STDERR, "walkthrough-state: DB_NAME is not tmc_wp_test; refusing.\n");
    exit(2);
}
// AND a host nobody outside this container can reach. Two independent
// facts, because the thing being guarded is «write a published password
// onto a live account» and one check is one mistake away from nothing.
$home = (string) home_url();
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', $home)) {
    $say('refused=1 reason=home_url_is_not_local');
    fwrite(STDERR, "walkthrough-state: home_url() is {$home}; refusing.\n");
    exit(2);
}

$container = Bootstrap::container();
$vendors = $container->get(VendorRepositoryInterface::class);
$staffRepo = $container->get(StaffRepositoryInterface::class);

/** The shop that can actually trade, preferring the lowest id for stability. */
$sellableVendor = static function () use ($vendors): ?int {
    foreach ($vendors->listableVendorUserIds() as $vendorUserId) {
        $profile = $vendors->findProfileByUser($vendorUserId);
        if ($profile !== null && $profile->canSell) {
            return $vendorUserId;
        }
    }
    return null;
};

$setPassword = static function (int $userId) use ($say): bool {
    $user = get_user_by('id', $userId);
    if (!$user instanceof WP_User) {
        return false;
    }
    wp_set_password(WALKTHROUGH_PASSWORD, $userId);
    return true;
};

switch ($command) {
    case 'reset-passwords':
    case 'roles':
        $rows = [];

        // 1. the marketplace manager — REPORTED, never rewritten. Every other
        //    evidence script signs in as this account with the password in
        //    /root/.wp_pass, so resetting it here would quietly break the
        //    suites that run after this one.
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
        $admin = $admins[0] ?? null;
        $adminId = $admin instanceof WP_User ? (int) $admin->ID : 0;
        if ($admin instanceof WP_User) {
            $rows[] = ['manager', $adminId, $admin->user_login, 'wp-admin', false];
        }

        // 2. the vendor who can trade
        $vendorUserId = $sellableVendor();
        if ($vendorUserId !== null) {
            $vendorUser = get_user_by('id', $vendorUserId);
            if ($vendorUser instanceof WP_User) {
                $rows[] = ['vendor', $vendorUserId, $vendorUser->user_login, '/vendor/', true];
            }
        }

        // 3. one ACTIVE staff member of that shop. Invited-but-unaccepted rows
        //    cannot sign in, so photographing one would show a login screen.
        $staffId = 0;
        if ($vendorUserId !== null) {
            foreach ($staffRepo->forVendor($vendorUserId) as $member) {
                if ($member->status->value === 'active' && $member->staffUserId > 0) {
                    $staffUser = get_user_by('id', $member->staffUserId);
                    if ($staffUser instanceof WP_User) {
                        $rows[] = ['staff', $member->staffUserId, $staffUser->user_login, '/vendor/products/', true];
                        $staffId = $member->staffUserId;
                        break;
                    }
                }
            }
        }

        // 4. a buyer who has actually bought, so «my account» has an order in
        //    it. A fresh customer would photograph an empty page and prove
        //    nothing about the shopper's side.
        // A buyer must be a SHOPPER. The newest order on this site was placed
        // by the administrator while the acceptance path ran, and
        // photographing «the buyer's account» as an administrator shows the
        // admin bar and every capability — the same mistake as shooting a
        // vendor page as an admin, which this suite already refuses to make.
        // So anyone who can manage the site, own the shop, or work in it is
        // skipped, however recent their order.
        $excluded = array_filter([$adminId, $vendorUserId, $staffId]);
        $buyerId = 0;
        $orders = wc_get_orders(['limit' => 60, 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'ids']);
        foreach ((array) $orders as $orderId) {
            $order = wc_get_order($orderId);
            // `wc_get_orders()` also hands back refunds, and an OrderRefund
            // has no customer at all — asking one is a fatal, not an empty.
            if (!$order instanceof WC_Order) {
                continue;
            }
            $candidate = (int) $order->get_customer_id();
            if ($candidate <= 0 || in_array($candidate, $excluded, true)) {
                continue;
            }
            if (user_can($candidate, 'manage_options') || user_can($candidate, 'edit_posts')) {
                continue;               // an editor's «my account» is not a shopper's
            }
            $buyerId = $candidate;
            break;
        }
        if ($buyerId > 0) {
            $buyerUser = get_user_by('id', $buyerId);
            if ($buyerUser instanceof WP_User) {
                $rows[] = ['buyer', $buyerId, $buyerUser->user_login, '/my-account/', true];
            }
        }

        foreach ($rows as [$role, $userId, $login, $lands, $mayReset]) {
            $ok = $mayReset ? $setPassword($userId) : true;
            printf(
                "role=%s user_id=%d login=%s password=%s lands=%s ok=%s\n",
                $role,
                $userId,
                $login,
                $mayReset ? WALKTHROUGH_PASSWORD : '(unchanged: /root/.wp_pass)',
                $lands,
                $ok ? 'true' : 'false'
            );
        }
        // Said explicitly so a missing role is a visible gap rather than a
        // short list somebody has to count.
        printf("roles_found=%d of=4\n", count($rows));
        exit(count($rows) === 4 ? 0 : 1);

    case 'seed-activity':
        // A REAL action by the staff member, through the plugin's own service
        // — not a hand-written audit row. The activity card reads the audit
        // trail, so seeding the trail directly would be drawing the answer on
        // the page and calling it a measurement: the card would show work
        // that never happened, which is the one thing a staff-activity report
        // must never do.
        //
        // The fixture's staff member holds order rights (OrderAndShipping),
        // so shipping is the action they actually have. Anything else is
        // refused by StaffAccess, and rightly.
        $vendorUserId = $sellableVendor();
        if ($vendorUserId === null) {
            $say('seeded=0 reason=no_sellable_vendor');
            exit(1);
        }
        $staffUserId = 0;
        foreach ($staffRepo->forVendor($vendorUserId) as $member) {
            if ($member->status->value === 'active' && $member->staffUserId > 0) {
                $staffUserId = $member->staffUserId;
                break;
            }
        }
        if ($staffUserId === 0) {
            $say('seeded=0 reason=no_active_staff');
            exit(1);
        }

        $orderItems = $container->get(OrderItemRepositoryInterface::class);
        $ship = $container->get(ShipItems::class);
        $done = 0;
        foreach ($orderItems->forVendor($vendorUserId, null, 25, 0) as $item) {
            $remaining = (int) $item->quantity - (int) $item->shippedQuantity;
            if ($remaining <= 0) {
                continue;
            }
            $result = $ship->ship(
                $staffUserId,
                $vendorUserId,
                (int) $item->id,
                1,
                'post',
                'TMC-DEMO-' . (int) $item->id,
                '',
                'ارسال آزمایشی برای نمایش فعالیت پرسنل'
            );
            printf("shipped item=%d ok=%s code=%s\n", (int) $item->id, $result->ok ? 'true' : 'false', $result->code);
            if ($result->ok) {
                $done++;
            }
            if ($done >= 2) {
                break;
            }
        }
        $say('seeded=' . $done . ' by_staff_user=' . $staffUserId);
        exit($done > 0 ? 0 : 1);

    default:
        fwrite(STDERR, "usage: walkthrough-state.php roles|reset-passwords|seed-activity\n");
        exit(1);
}
