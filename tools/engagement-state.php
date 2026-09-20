<?php
/**
 * Ticket attachments, in-panel notices and the approved reports, driven from
 * the command line on a DISPOSABLE WordPress.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/engagement-state.php ticket <vendor-user> <subject>
 *   wp eval-file tools/engagement-state.php attach <actor> <ticket> <message> <path> [mime]
 *   wp eval-file tools/engagement-state.php files <actor> <ticket>
 *   wp eval-file tools/engagement-state.php read-file <actor> <attachment>
 *   wp eval-file tools/engagement-state.php hide-file <actor> <attachment> <reason>
 *   wp eval-file tools/engagement-state.php stored-path <attachment>
 *   wp eval-file tools/engagement-state.php reply <ticket> <body>
 *   wp eval-file tools/engagement-state.php inbox <user>
 *   wp eval-file tools/engagement-state.php mark-read <user> <notice>
 *   wp eval-file tools/engagement-state.php notify-twice <user>
 *   wp eval-file tools/engagement-state.php report-vendor <actor> <vendor>
 *   wp eval-file tools/engagement-state.php report-manager
 *   wp eval-file tools/engagement-state.php refund-record <actor> <return>
 *   wp eval-file tools/engagement-state.php refund-blockers <wc-order>
 *   wp eval-file tools/engagement-state.php seed-real-return <wc-product>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Marketplace\Application\AttachTicketFile;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageTickets;
use Tecteb\Marketplace\Modules\Marketplace\Application\Notify;
use Tecteb\Marketplace\Modules\Marketplace\Application\Reports;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Notification;
use Tecteb\Marketplace\Modules\Order\Application\ManageReturns;
use Tecteb\Marketplace\Modules\Order\Application\RefundRecorderInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

// --- refuses to run anywhere but the disposable install -------------------
//
// This file WRITES test data — shops, products, orders, refunds. On the
// owner's site that is not a seeding tool, it is damage. A docblock saying
// «DISPOSABLE» is documentation, not a guard: it stops nobody who pastes the
// command at the wrong shell.
//
// Two independent facts, the same pair tools/disposable-site.sh already
// trusts: the database must be the disposable one by name, and the site must
// be on a host nobody outside the container can reach. Deliberately NOT
// wp_get_environment_type(), which reports `production` on the disposable
// container itself because nobody set the constant.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


$command = (string) ($args[0] ?? '');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
// The checker answers for whoever the command is ACTING AS, not for everybody.
// The first version returned true for every capability regardless of the user,
// which made a vendor look like a manager and quietly turned one real check —
// «can the sender still open a hidden file?» — into a check of nothing.
//
// The acting id is the command's first argument where that argument is a user;
// otherwise the manager. This is a DISPOSABLE site's fixture, never shipped code.
$actingAs = in_array((string) ($args[0] ?? ''), ['attach', 'files', 'read-file', 'hide-file', 'inbox', 'mark-read', 'notify-twice', 'report-vendor', 'refund-record'], true)
    ? (int) ($args[1] ?? $manager)
    : $manager;
$c->bind(CapabilityCheckerInterface::class, static function () use ($actingAs, $manager) {
    return new class ($actingAs, $manager) implements CapabilityCheckerInterface {
        public function __construct(private $id, private $manager)
        {
        }

        public function can(string $capability): bool
        {
            // Only the administrator is the marketplace's side. A vendor has
            // their own rights through StaffAccess, never through this.
            return $this->id === $this->manager && in_array($capability, Capabilities::all(), true);
        }

        public function currentUserId(): ?int
        {
            return $this->id;
        }
    };
});

$tickets = $c->get(ManageTickets::class);
$files = $c->get(AttachTicketFile::class);
$notify = $c->get(Notify::class);

switch ($command) {
    case 'ticket':
        $vendorUserId = (int) ($args[1] ?? 0);
        $result = $tickets->open($vendorUserId, $vendorUserId, (string) ($args[2] ?? 'آزمایشی'), 'متن آزمایشی');
        printf(
            "ticket ok=%s code=%s ticket=%s message=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['ticket_id'] ?? '-'),
            (string) ($result->context['message_id'] ?? '-')
        );
        break;

    case 'attach':
        // A real temp file, copied the way PHP hands one over, because the
        // service moves it out of the temp path and a shared file would be
        // gone for the second call.
        $source = (string) ($args[4] ?? '');
        $temp = wp_tempnam('tmc-attach');
        copy($source, $temp);
        $result = $files->attach(
            (int) ($args[1] ?? 0),
            (int) ($args[2] ?? 0),
            (int) ($args[3] ?? 0),
            new UploadedFile(basename($source), $temp, (int) filesize($temp), (string) ($args[5] ?? mime_content_type($source)))
        );
        printf(
            "attach ok=%s code=%s attachment=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['attachment_id'] ?? '-')
        );
        break;

    case 'files':
        $list = $files->forTicket((int) ($args[1] ?? 0), (int) ($args[2] ?? 0));
        printf("files count=%d\n", count($list));
        foreach ($list as $file) {
            printf(
                "  file=%d name=%s mime=%s bytes=%d hidden=%s\n",
                $file->id, $file->originalName, $file->mime, $file->sizeBytes,
                $file->hidden ? 'true' : 'false'
            );
        }
        break;

    case 'read-file':
        $file = $files->read((int) ($args[1] ?? 0), (int) ($args[2] ?? 0));
        printf(
            "read ok=%s bytes=%s mime=%s\n",
            $file === null ? 'false' : 'true',
            $file === null ? '-' : (string) strlen($file['bytes']),
            $file === null ? '-' : $file['mime']
        );
        break;

    case 'hide-file':
        $result = $files->hide((int) ($args[1] ?? 0), (int) ($args[2] ?? 0), (string) ($args[3] ?? 'آزمایشی'));
        printf("hide ok=%s code=%s\n", $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'stored-path':
        // Where the bytes really are, so the evidence can prove no web URL
        // reaches them.
        $repository = $c->get(\Tecteb\Marketplace\Modules\Marketplace\Application\NotificationRepositoryInterface::class);
        $attachment = $repository->findAttachment((int) ($args[1] ?? 0));
        printf("stored path=%s\n", $attachment === null ? '-' : $attachment->storedPath);
        break;

    case 'reply':
        $result = $tickets->reply($manager, (int) ($args[1] ?? 0), (string) ($args[2] ?? 'پاسخ'), true);
        printf("reply ok=%s code=%s\n", $result->ok ? 'true' : 'false', $result->code);
        break;

    case 'inbox':
        $userId = (int) ($args[1] ?? 0);
        $list = $notify->inbox($userId);
        printf("inbox user=%d count=%d unread=%d\n", $userId, count($list), $notify->unreadCount($userId));
        foreach ($list as $notice) {
            printf("  notice=%d event=%s unread=%s\n", $notice->id, $notice->event, $notice->isUnread() ? 'true' : 'false');
        }
        break;

    case 'mark-read':
        printf(
            "mark ok=%s unread=%d\n",
            $notify->markRead((int) ($args[1] ?? 0), (int) ($args[2] ?? 0)) ? 'true' : 'false',
            $notify->unreadCount((int) ($args[1] ?? 0))
        );
        break;

    case 'notify-twice':
        // The same notice twice: the unique index must refuse the second.
        // A subject NOBODY has been told about before. Reusing a constant made
        // the first insert a duplicate of the previous run's and the check
        // failed for a reason that had nothing to do with the code.
        $userId = (int) ($args[1] ?? 0);
        $subject = 'probe-' . uniqid('', true);
        $first = $notify->toUser($userId, Notify::PRODUCT_APPROVED, Notification::SUBJECT_PRODUCT, $subject, ['title' => 'آزمایشی']);
        $second = $notify->toUser($userId, Notify::PRODUCT_APPROVED, Notification::SUBJECT_PRODUCT, $subject, ['title' => 'آزمایشی']);
        printf("twice first=%s second=%s\n", $first ? 'true' : 'false', $second ? 'true' : 'false');
        break;

    case 'report-vendor':
        $reports = $c->get(Reports::class)->forVendor((int) ($args[1] ?? 0), (int) ($args[2] ?? 0));
        printf("vendor-report cards=%d\n", count($reports));
        foreach ($reports as $key => $figures) {
            $parts = [];
            foreach ($figures as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            printf("  %s %s\n", $key, implode(' ', $parts));
        }
        break;

    case 'report-manager':
        // From the PROFILES, the same source the manager's page uses. Deriving
        // it from approved applications reported «۰ فروشگاه» on a site with
        // two of them, because a profile can exist without one.
        $ids = $c->get(VendorRepositoryInterface::class)->vendorUserIds();
        $reports = $c->get(Reports::class)->forManager($ids);
        printf("manager-report cards=%d vendors=%d\n", count($reports), count($ids));
        foreach ($reports as $key => $figures) {
            $parts = [];
            foreach ($figures as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            printf("  %s %s\n", $key, implode(' ', $parts));
        }
        break;

    case 'refund-record':
        $result = $c->get(ManageReturns::class)->recordWooCommerceRefund((int) ($args[1] ?? 0), (int) ($args[2] ?? 0));
        printf(
            "wc-refund ok=%s code=%s refund=%s did_money=%s blockers=%s\n",
            $result->ok ? 'true' : 'false',
            $result->code,
            (string) ($result->context['wc_refund_id'] ?? '-'),
            isset($result->context['did_money']) ? ($result->context['did_money'] ? 'true' : 'false') : '-',
            (string) ($result->context['money_blockers'] ?? '-')
        );
        break;

    case 'seed-real-return':
        // A return whose order is a REAL WooCommerce order.
        //
        // `return-state.php seed-line` invents an order id (90000 + random)
        // and never creates the order, which is fine for the ledger — it only
        // needs the marketplace's own rows — and useless here: a WooCommerce
        // refund is recorded AGAINST an order, and there was none. That gap
        // made this section skip itself for three runs.
        $wcProductId = (int) ($args[1] ?? 0);
        $product = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)
            ->findByWcProduct($wcProductId);
        if ($product === null) {
            echo "not a marketplace product\n";
            break;
        }
        $order = wc_create_order();
        $order->add_product(wc_get_product($wcProductId), 2);
        $order->calculate_totals();
        // Completed, so the order has a total WooCommerce will let a refund be
        // recorded against — `get_remaining_refund_amount()` is what decides.
        $order->set_status('completed');
        $order->save();
        $orderId = (int) $order->get_id();
        $itemId = 0;
        foreach ($order->get_items() as $id => $item) {
            $itemId = (int) $id;
        }
        $c->get(\Tecteb\Marketplace\Modules\Order\Application\CaptureOrder::class)->capture($orderId, [[
            'order_item_id' => $itemId,
            'wc_product_id' => $wcProductId,
            'variation_id' => null,
            'title' => $product->details->title,
            'sku' => $product->details->sku,
            'quantity' => 2,
            'line_total_minor' => $product->details->priceMinor * 2,
            'line_tax_minor' => 0,
        ]]);
        $line = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface::class)
            ->findByOrderItem($itemId);
        if ($line === null) {
            printf("seed-real order=%d item=0 return=0\n", $orderId);
            break;
        }
        $returns = $c->get(\Tecteb\Marketplace\Modules\Order\Application\ManageReturns::class);
        $opened = $returns->open($line->vendorUserId, $line->vendorUserId, $line->id, 1, 'آزمون ثبت refund ووکامرس');
        $returnId = (int) ($opened->context['return_id'] ?? 0);
        $returns->decide($manager, $returnId, \Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus::Approved);
        $returns->decide($manager, $returnId, \Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus::Received, '', true);
        $refunded = $returns->refund($manager, $returnId);
        printf(
            "seed-real order=%d item=%d return=%d refunded=%s\n",
            $orderId, $line->id, $returnId, $refunded->ok ? 'true' : 'false'
        );
        break;

    case 'refund-blockers':
        $recorder = $c->get(RefundRecorderInterface::class);
        $orderId = (int) ($args[1] ?? 0);
        printf(
            "blockers available=%s can_transfer=%s list=%s\n",
            $recorder->isAvailable() ? 'true' : 'false',
            $recorder->canTransferMoney($orderId) ? 'true' : 'false',
            implode(',', $recorder->moneyBlockers($orderId))
        );
        break;

    default:
        echo "unknown command\n";
}
