<?php
/**
 * Seeds ONE synthetic vendor application through the plugin's own application
 * services — not through raw SQL — so the rows that the upgrade/rollback
 * rehearsal later checks for survival were written by the real code path.
 *
 * Run with WP-CLI on a DISPOSABLE site only:
 *   wp eval-file tools/upgrade-seed.php <admin-id> <vendor-id>
 *
 * Prints one `key=value` line per step; any failure prints `…=FAILED:<reason>`
 * and exits non-zero so the caller can stop.
 */

// No declare(strict_types=1): WP-CLI's eval-file wraps this in eval(), where a
// declare() is a fatal error. The plugin code it calls is strict either way.

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Vendor\Application\ConfigureDocumentTypes;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentTypeRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\SaveApplicationDraft;
use Tecteb\Marketplace\Modules\Vendor\Application\SubmitApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\UploadApplicationDocument;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;

// --- refuses to run anywhere but the disposable install -------------------
//
// This file touches WordPress and writes. On the owner's site that is not a
// tool, it is damage, and a docblock saying «disposable» stops nobody who
// pastes the command at the wrong shell. Two independent facts, the same
// pair tools/disposable-site.sh trusts — NOT wp_get_environment_type(),
// which reports `production` on the disposable container itself.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


$adminId  = (int) ($args[0] ?? 1);
$vendorId = (int) ($args[1] ?? 0);
if ($vendorId <= 0) {
    fwrite(STDERR, "usage: wp eval-file tools/upgrade-seed.php <admin-id> <vendor-id>\n");
    exit(2);
}

$c = Bootstrap::container();

/** Fails loudly: a seed that silently did nothing would fake the whole test. */
$must = static function (string $label, OperationResult $result): void {
    if (!$result->ok) {
        echo $label . '=FAILED:' . $result->code . "\n";
        exit(1);
    }
    echo $label . '=ok:' . $result->code . "\n";
};

// --- manager defines one required document type -------------------------
wp_set_current_user($adminId);
$types = $c->get(ConfigureDocumentTypes::class);
$must('doctype_added', $types->add(
    'پروانه کسب (نمونه آزمایشی)',
    true,
    ['application/pdf'],
    2 * 1024 * 1024,
    'فقط برای تمرین ارتقا؛ داده واقعی نیست.'
));

// --- applicant saves a draft --------------------------------------------
wp_set_current_user($vendorId);
$draft = $c->get(SaveApplicationDraft::class);
$must('draft_saved', $draft->handle($vendorId, new ApplicantDetails(
    'داروخانه نمونه ارتقا',
    'شرکت نمونه ارتقا',
    'upgrade-rehearsal@example.test',
    '09120000000',
    'تهران، نشانی نمونه',
    true
)));

// --- applicant uploads the required document ----------------------------
$tmp = sys_get_temp_dir() . '/tmc-upgrade-seed-' . bin2hex(random_bytes(4)) . '.pdf';
file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
$upload = $c->get(UploadApplicationDocument::class);
$slug = null;
foreach ($c->get(DocumentTypeRepositoryInterface::class)->requirementSet()->types() as $type) {
    $slug = $type->slug;
    break;
}
echo 'doctype_slug=' . (string) $slug . "\n";
$must('document_uploaded', $upload->handle($vendorId, (string) $slug, new UploadedFile(
    'parvane-namune.pdf',
    $tmp,
    (int) filesize($tmp),
    'application/pdf'
)));

// --- applicant submits ---------------------------------------------------
$must('submitted', $c->get(SubmitApplication::class)->handle($vendorId));

echo "seed=complete\n";
