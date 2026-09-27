<?php
/**
 * The vendor states `alpha.33`'s first screen has to be right in — on a real
 * install, through the plugin's own services.
 *
 * The owner's constraint on this round was «مسیرهای فروشندهٔ جدید، در انتظار،
 * نیازمند اصلاح، رد شده و تعلیق‌شده نباید بشکنند»: five paths that are not the
 * approved shop, plus the approved shop itself, plus a staff member with
 * narrower rights. A screenshot of one of them proves nothing about the other
 * five, so this tool can put the site into each and print what it did.
 *
 * Everything is written the way a person writes it — `ReviewApplication` for a
 * decision, `ManageProducts` for a correction — never by an UPDATE behind the
 * code's back. That is the `alpha.17` rule: a fixture that writes a status
 * column directly leaves «تأییدشده و ناتوان از فروش», a combination no real
 * path produces, and then measures the screen for a site nobody has.
 *
 * It PRINTS the facts the browser run asserts against (counts, the id of the
 * product sent back, the manager's words, the review-queue total for the menu
 * bubble) so those numbers live in one place — the `alpha.22` rule.
 *
 *   wp eval-file tools/vendor-dashboard-state.php report
 *   wp eval-file tools/vendor-dashboard-state.php seed
 *   wp eval-file tools/vendor-dashboard-state.php applicant <status>
 *   wp eval-file tools/vendor-dashboard-state.php publishing on|off
 *   wp eval-file tools/vendor-dashboard-state.php staff view|none
 *   wp eval-file tools/vendor-dashboard-state.php reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductDecisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\SaveApplicationDraft;
use Tecteb\Marketplace\Modules\Vendor\Application\SubmitApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\UploadApplicationDocument;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentTypeRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES: it creates a user, moves applications between statuses and
// sends products back for correction. On the owner's site that is not a
// fixture, it is damage.
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}

$command = (string) ($args[0] ?? 'report');
$argument = (string) ($args[1] ?? '');

const TMC_APPLICANT_LOGIN = 'demo-applicant';
const TMC_APPLICANT_PASS = 'demo-applicant-2026';
const TMC_NOTE = 'تصویر دوم تار است و برگهٔ استاندارد پیوست نشده.';

$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
// A fixture acts as two people in one process — the manager deciding, and the
// applicant filling in their own form — so the checker answers for whoever
// `wp_set_current_user()` says is acting. `Capabilities::all()` is the
// MANAGER's list and does not contain `tmc_apply_vendor`, which is why the
// first run of this tool refused its own applicant.
$c->bind(CapabilityCheckerInterface::class, static fn () => new class implements CapabilityCheckerInterface {
    public function can(string $capability): bool
    {
        return true;
    }

    public function currentUserId(): ?int
    {
        return (int) get_current_user_id();
    }
});

/** @var VendorRepositoryInterface $vendors */
$vendors = $c->get(VendorRepositoryInterface::class);
/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);
/** @var ProductDecisionRepositoryInterface $decisions */
$decisions = $c->get(ProductDecisionRepositoryInterface::class);
/** @var DatabaseInterface $db */
$db = $c->get(DatabaseInterface::class);

$vendorUserId = (int) (get_user_by('login', 'demo-vendor')?->ID ?? 0);
if ($vendorUserId <= 0) {
    echo "refused=1 reason=no_demo_vendor\n";
    return;
}

/** The applicant: a real user with a real application, created once. */
$applicantId = static function (): int {
    $user = get_user_by('login', TMC_APPLICANT_LOGIN);
    if ($user !== false) {
        return (int) $user->ID;
    }
    $id = wp_insert_user([
        'user_login' => TMC_APPLICANT_LOGIN,
        'user_pass' => TMC_APPLICANT_PASS,
        'user_email' => 'applicant@example.invalid',
        'display_name' => 'متقاضی نمونه',
        'role' => 'customer',
    ]);
    return is_wp_error($id) ? 0 : (int) $id;
};

/**
 * One of this shop's products, sent back with the manager's own words.
 *
 * Through `ManageProducts::requestChanges()` and not an UPDATE: the decision
 * row, the status and the vendor's notice all come from that one call, and a
 * fixture that writes only the column produces a product the dashboard finds
 * and cannot explain.
 */
$sendBack = static function () use ($c, $products, $decisions, $vendorUserId): array {
    // Already done? Then done. A fixture that SPENDS something on every run is
    // a fixture that runs out: this one moved a submitted product to
    // «نیازمند اصلاح» each time, and eleven runs took the review queue from
    // fourteen to two — so the badge checks were measuring a smaller and
    // smaller number until they would have measured nothing (the `alpha.12`
    // rule about fixtures that spend).
    foreach ($products->forVendor($vendorUserId, ProductStatus::ChangesRequested, 10) as $existing) {
        if (($decisions->latestForVendor($existing->id, $vendorUserId)?->note ?? '') === TMC_NOTE) {
            return ['id' => $existing->id, 'note' => TMC_NOTE, 'code' => 'already'];
        }
    }
    // A SUBMITTED product, because that is the transition a manager actually
    // makes. Asking a product that is already `changes_requested` to become
    // `changes_requested` is not a decision, and the refusal leaves no row —
    // which the first run of this tool reported as «note_recorded=no» about a
    // product that was in the right status for the wrong reason.
    $waiting = $products->forVendor($vendorUserId, ProductStatus::Submitted, 1);
    $product = $waiting[0] ?? null;
    if ($product === null) {
        return ['id' => 0, 'note' => '', 'code' => 'no_submitted_product'];
    }
    $result = $c->get(ReviewProducts::class)->requestChanges($product->id, TMC_NOTE);
    $latest = $decisions->latestForVendor($product->id, $vendorUserId);
    return ['id' => $product->id, 'note' => $latest?->note ?? '', 'code' => $result->code];
};

$report = static function (string $stage) use ($products, $vendors, $decisions, $vendorUserId, $c): void {
    $counts = $products->countsByStatus($vendorUserId);
    $line = 'stage=' . $stage;
    foreach (['published', 'submitted', 'changes_requested', 'draft'] as $status) {
        $line .= ' ' . $status . '=' . (int) ($counts[$status] ?? 0);
    }
    $profile = $vendors->findProfileByUser($vendorUserId);
    $line .= ' can_sell=' . ($profile !== null && $profile->canSell ? 'yes' : 'no');
    $line .= ' publish_directly=' . ($profile !== null && $profile->canPublishDirectly ? 'yes' : 'no');
    // What the manager's menu bubble should read: products, never rows.
    $line .= ' awaiting_review=' . $products->countAwaitingReview();
    $needsWork = $products->forVendor($vendorUserId, ProductStatus::ChangesRequested, 1);
    $first = $needsWork[0] ?? null;
    $line .= ' needs_work_id=' . ($first?->id ?? 0);
    $line .= ' needs_work_note=' . ($first === null
        ? '-'
        : str_replace(' ', '_', (string) ($decisions->latestForVendor($first->id, $vendorUserId)?->note ?? '-')));
    $applicant = get_user_by('login', TMC_APPLICANT_LOGIN);
    $application = $applicant === false ? null : $vendors->findApplicationByUser((int) $applicant->ID);
    $line .= ' applicant_user=' . ($applicant === false ? 0 : (int) $applicant->ID);
    $line .= ' applicant_status=' . ($application?->status->value ?? '-');
    echo $line . "\n";
};

switch ($command) {
    case 'seed':
        $userId = $applicantId();
        if ($userId <= 0) {
            echo "refused=1 reason=applicant_not_created\n";
            return;
        }
        // Acting as the applicant, because that is who fills this form in — and
        // `SaveApplicationDraft` refuses anyone without `APPLY`.
        wp_set_current_user($userId);
        $draft = $c->get(SaveApplicationDraft::class)->handle($userId, new ApplicantDetails(
            storeName: 'فروشگاه در انتظار بررسی',
            legalName: 'شرکت متقاضی نمونه',
            contactEmail: 'applicant@example.invalid',
            contactMobile: '09120000002',
            address: 'تهران، خیابان نمونه، شمارهٔ ۱',
            termsAccepted: true
        ));
        // A real document through the real upload path. Copying a row from
        // another application would make this applicant «complete» while the
        // file on disk belongs to somebody else — a fixture that tests the
        // screen for a state the site cannot reach.
        $uploaded = '-';
        foreach ($c->get(DocumentTypeRepositoryInterface::class)->requirementSet()->types() as $type) {
            if (!$type->required) {
                continue;
            }
            $temp = (string) tempnam(sys_get_temp_dir(), 'tmcdoc');
            // A one-page PDF, written here rather than committed: the bytes are
            // the smallest thing `finfo` calls `application/pdf`.
            file_put_contents(
                $temp,
                "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
                . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
                . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
                . "trailer<</Root 1 0 R>>\n%%EOF\n"
            );
            $result = $c->get(UploadApplicationDocument::class)->handle($userId, $type->slug, new UploadedFile(
                'sample.pdf',
                $temp,
                (int) filesize($temp),
                'application/pdf'
            ));
            $uploaded = $type->slug . '=' . ($result->ok ? 'ok' : $result->code);
            @unlink($temp);
        }
        $submitted = $c->get(SubmitApplication::class)->handle($userId);
        wp_set_current_user($manager);
        echo 'applicant=' . $userId
            . ' draft=' . ($draft->ok ? 'ok' : $draft->code)
            . ' document=' . $uploaded
            . ' submit=' . ($submitted->ok ? 'ok' : $submitted->code) . "\n";
        $back = $sendBack();
        echo 'sent_back=' . $back['id'] . ' code=' . $back['code']
            . ' note_recorded=' . ($back['note'] === TMC_NOTE ? 'yes' : 'no') . "\n";
        $report('seed');
        break;

    case 'applicant':
        $user = get_user_by('login', TMC_APPLICANT_LOGIN);
        if ($user === false) {
            echo "refused=1 reason=no_applicant\n";
            return;
        }
        $application = $vendors->findApplicationByUser((int) $user->ID);
        if ($application === null) {
            echo "refused=1 reason=no_application\n";
            return;
        }
        $review = $c->get(ReviewApplication::class);
        $note = 'کد اقتصادی ناخوانا است؛ تصویر روشن‌تری بفرستید.';
        $result = match ($argument) {
            'approved' => $review->approve($application->id),
            'rejected' => $review->reject($application->id, $note),
            'changes_requested' => $review->requestChanges($application->id, $note),
            'suspended' => $review->suspend($application->id, 'تعلیق آزمایشی برای سنجش صفحه.'),
            default => null,
        };
        echo 'applicant_moved=' . $argument . ' ok=' . ($result === null ? 'unknown_status' : ($result->ok ? 'yes' : $result->code)) . "\n";
        $report('applicant-' . $argument);
        break;

    case 'publishing':
        // Written through the repository that owns the column, and only this
        // one flag: the two permissions are separate decisions (Master §4.1).
        // Through the service that owns the decision, not the column: the two
        // permissions are separate (Master §4.1) and it writes the audit line.
        $granted = $c->get(ReviewProducts::class)->setDirectPublishing($vendorUserId, $argument === 'on');
        echo 'set_direct_publishing=' . ($granted->ok ? 'ok' : $granted->code) . "\n";
        $report('publishing-' . $argument);
        break;

    case 'staff':
        // The staff row's own permission map. `none` on products is what makes
        // «no counters at all» measurable rather than argued about.
        $table = $GLOBALS['wpdb']->prefix . 'tmc_vendor_staff';
        $permissions = $argument === 'none'
            ? '{"product":"none","inventory":"none","order":"edit","report":"none","finance":"none"}'
            : '{"product":"view","inventory":"view","order":"edit","report":"none","finance":"none"}';
        $db->execute(
            'UPDATE `' . $table . '` SET permissions = %s WHERE vendor_user_id = %d',
            [$permissions, $vendorUserId]
        );
        echo 'staff_products=' . $argument . "\n";
        break;

    case 'reset':
        $user = get_user_by('login', TMC_APPLICANT_LOGIN);
        if ($user !== false) {
            $application = $vendors->findApplicationByUser((int) $user->ID);
            if ($application !== null) {
                $prefix = $GLOBALS['wpdb']->prefix;
                $db->execute('DELETE FROM `' . $prefix . 'tmc_vendor_documents` WHERE application_id = %d', [$application->id]);
                $db->execute('DELETE FROM `' . $prefix . 'tmc_vendor_applications` WHERE id = %d', [$application->id]);
                $db->execute('DELETE FROM `' . $prefix . 'tmc_vendor_profiles` WHERE user_id = %d', [(int) $user->ID]);
                $db->execute('DELETE FROM `' . $prefix . 'tmc_vendor_stores` WHERE user_id = %d', [(int) $user->ID]);
            }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $user->ID);
        }
        echo "reset=ok\n";
        $report('reset');
        break;

    default:
        $report('report');
}
