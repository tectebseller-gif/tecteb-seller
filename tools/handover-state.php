<?php
/**
 * The hand-over: what an import RECORDS, and what somebody has to DECIDE.
 *
 * Importing a Dokan shop fills five history tables and produces a convincing
 * report — staff found, balance rows found, withdrawals found. None of it
 * means a person may touch an order or that this marketplace owes anybody a
 * rial. This runs that distinction on a DISPOSABLE WordPress with real Dokan
 * tables, in ONE PHP pass, and measures the difference rather than asserting
 * it:
 *
 *   سابقه وارد می‌شود → هیچ دسترسی نمی‌دهد → نقش نگاشت می‌شود → دعوت‌شده
 *   (هنوز بی‌اثر) → تأیید → دسترسیِ دقیقاً همان نقش → گزارش تطبیق مالی →
 *   انتقال مسئولیت بدون خط دفترکل و بدون پرداخت دوباره → «کامل» فقط وقتی
 *   همهٔ دروازه‌ها بسته شده‌اند
 *
 * Every financial claim is a DELTA. The disposable ledger already carries
 * hundreds of lines from earlier evidence runs, so «۰ خط» as an absolute is a
 * statement about the fixture and not about this feature.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/handover-state.php run
 *   wp eval-file tools/handover-state.php reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\GrantImportedStaff;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCompleteness;
use Tecteb\Marketplace\Core\Migration\Migrations\M0018HandoverRowsAndRecordVersion;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\StaffRoleMap;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

const TMC_HANDOVER_RUN = 'handover-evidence';
const TMC_HANDOVER_STAFF_LOGIN = 'tmc-evidence-dokan-staff';
const TMC_HANDOVER_VENDOR_LOGIN = 'tmc-evidence-handover-vendor';

$command = (string) ($args[0] ?? 'run');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);

$c->bind(CapabilityCheckerInterface::class, static function () {
    return new class implements CapabilityCheckerInterface {
        public function can(string $capability): bool
        {
            return in_array($capability, Capabilities::all(), true);
        }

        public function currentUserId(): ?int
        {
            return (int) get_current_user_id();
        }
    };
});

global $wpdb;
$records = $c->get(ShopRecordRepositoryInterface::class);
$roles = $c->get(StaffRoleMap::class);
$grant = $c->get(GrantImportedStaff::class);
$finance = $c->get(ReconcileDokanFinance::class);
$complete = $c->get(MigrationCompleteness::class);
$access = $c->get(StaffAccess::class);

$say = static function (string $stage, bool $ok, array $fields): void {
    $line = 'stage=' . $stage . ' ok=' . ($ok ? 'true' : 'false');
    foreach ($fields as $k => $v) {
        $line .= ' ' . $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
    }
    echo $line . "\n";
};

$ledgerLines = static function () use ($wpdb): int {
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_ledger_entries");
};
$withdrawalRows = static function () use ($wpdb): int {
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_withdrawals");
};

// ---------------------------------------------------------------- reset ---

if ($command === 'reset') {
    // Everything this script spends: two users, Dokan rows it wrote into
    // Dokan's own tables, the imported records, the membership, the role map
    // and the hand-over decision. A fixture that cannot run twice proves
    // nothing the second time.
    $vendorId = (int) (get_user_by('login', TMC_HANDOVER_VENDOR_LOGIN)->ID ?? 0);
    $staffId = (int) (get_user_by('login', TMC_HANDOVER_STAFF_LOGIN)->ID ?? 0);

    if ($vendorId > 0) {
        $wpdb->delete($wpdb->prefix . 'dokan_vendor_balance', ['vendor_id' => $vendorId]);
        $wpdb->delete($wpdb->prefix . 'dokan_withdraw', ['user_id' => $vendorId]);
        $wpdb->delete($wpdb->prefix . 'tmc_dokan_balance_history', ['vendor_user_id' => $vendorId]);
        $wpdb->delete($wpdb->prefix . 'tmc_dokan_withdraw_history', ['vendor_user_id' => $vendorId]);
        $wpdb->delete($wpdb->prefix . 'tmc_dokan_staff_history', ['vendor_user_id' => $vendorId]);
        $wpdb->delete($wpdb->prefix . 'tmc_vendor_staff', ['vendor_user_id' => $vendorId]);
        $wpdb->delete($wpdb->prefix . 'tmc_vendor_profiles', ['user_id' => $vendorId]);
        $wpdb->delete($wpdb->prefix . 'tmc_vendor_applications', ['user_id' => $vendorId]);
        $wpdb->delete($wpdb->prefix . 'tmc_stores', ['vendor_user_id' => $vendorId]);
    }
    if ($staffId > 0) {
        $wpdb->delete($wpdb->prefix . 'tmc_vendor_staff', ['staff_user_id' => $staffId]);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($staffId);
    }
    if ($vendorId > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($vendorId);
    }
    // The map and the decision are options, and an option left behind makes
    // the next run's «nobody has decided» stage read its own leftovers.
    $map = (array) get_option(StaffRoleMap::SETTING, []);
    unset($map['tmc_evidence_role']);
    update_option(StaffRoleMap::SETTING, $map);
    // The decision is a ROW since alpha.20, not a key in an option. The old
    // option is cleared too: a site upgraded mid-review still has it, and a
    // reset that left it behind would let migration 18 carry a stale decision
    // back in on the next fresh install.
    if ($vendorId > 0) {
        $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
            'DELETE FROM `' . $GLOBALS['wpdb']->prefix . M0018HandoverRowsAndRecordVersion::HANDOVER . '` WHERE vendor_user_id = %d',
            $vendorId
        ));
        $handover = (array) get_option(ReconcileDokanFinance::SETTING, []);
        unset($handover[(string) $vendorId]);
        update_option(ReconcileDokanFinance::SETTING, $handover);
    }

    $say('reset', true, ['vendor' => $vendorId, 'staff' => $staffId]);
    return;
}

// ------------------------------------------------------------------ run ---

$fail = 0;
$check = static function (string $stage, bool $ok, array $fields) use ($say, &$fail): void {
    if (!$ok) {
        $fail++;
    }
    $say($stage, $ok, $fields);
};

// 0. A shop with a Dokan past: a seller user, a balance ledger and two
//    withdrawal requests — one Dokan paid, one it never did. Written into
//    DOKAN's own tables, so the reader under test is reading the real schema
//    (`perticulars`, `status int(1)`) and not a convenient copy of it.
$vendorUser = get_user_by('login', TMC_HANDOVER_VENDOR_LOGIN);
if (!$vendorUser) {
    $vendorId = wp_insert_user([
        'user_login' => TMC_HANDOVER_VENDOR_LOGIN,
        'user_pass' => wp_generate_password(20),
        'user_email' => 'handover-vendor@example.invalid',
        'display_name' => 'فروشگاه نمونهٔ انتقال',
        'role' => 'seller',
    ]);
} else {
    $vendorId = (int) $vendorUser->ID;
}
update_user_meta($vendorId, 'dokan_enable_selling', 'yes');

$staffUser = get_user_by('login', TMC_HANDOVER_STAFF_LOGIN);
if (!$staffUser) {
    $staffId = wp_insert_user([
        'user_login' => TMC_HANDOVER_STAFF_LOGIN,
        'user_pass' => wp_generate_password(20),
        'user_email' => 'handover-staff@example.invalid',
        'display_name' => 'پرسنل واردشده',
        'role' => 'subscriber',
    ]);
} else {
    $staffId = (int) $staffUser->ID;
}
// `_dokan_vendor_id` is exactly what Dokan Pro puts on a staff account, and
// it is what `WpDokanReader::staffFor()` joins on. The ROLE here is a synthetic
// token: Dokan Pro's own `vendor_staff` role does not exist on Lite, and
// inventing its capability list would be guessing. What is measured is the
// mapping mechanism; the real Pro role stays Not Run.
update_user_meta($staffId, '_dokan_vendor_id', (string) $vendorId);
$staffWpUser = new WP_User($staffId);
$staffWpUser->set_role('tmc_evidence_role');

$wpdb->delete($wpdb->prefix . 'dokan_vendor_balance', ['vendor_id' => $vendorId]);
$wpdb->delete($wpdb->prefix . 'dokan_withdraw', ['user_id' => $vendorId]);
// 5,000,000 earned, then 2,000,000 paid out. Dokan books the payout TWICE by
// design: a withdraw row, and a debit in the balance ledger. Its own closing
// figure is therefore 3,000,000 — already net of the payout.
$wpdb->insert($wpdb->prefix . 'dokan_vendor_balance', [
    'vendor_id' => $vendorId, 'trn_id' => 900001, 'trn_type' => 'dokan_orders',
    'perticulars' => 'سفارش نمونه', 'debit' => '0.0000', 'credit' => '5000000.0000',
    'status' => 'approved', 'trn_date' => '2026-01-05 10:00:00', 'balance_date' => '2026-01-05 10:00:00',
]);
$wpdb->insert($wpdb->prefix . 'dokan_vendor_balance', [
    'vendor_id' => $vendorId, 'trn_id' => 900002, 'trn_type' => 'dokan_withdraw',
    'perticulars' => 'برداشت پرداخت‌شده', 'debit' => '2000000.0000', 'credit' => '0.0000',
    'status' => 'approved', 'trn_date' => '2026-02-01 10:00:00', 'balance_date' => '2026-02-01 10:00:00',
]);
$wpdb->insert($wpdb->prefix . 'dokan_withdraw', [
    'user_id' => $vendorId, 'amount' => '2000000.0000', 'date' => '2026-02-01 10:00:00',
    'status' => 1, 'method' => 'bank', 'note' => 'پرداخت‌شده توسط دکان', 'ip' => '127.0.0.1',
]);
$wpdb->insert($wpdb->prefix . 'dokan_withdraw', [
    'user_id' => $vendorId, 'amount' => '750000.0000', 'date' => '2026-03-01 10:00:00',
    'status' => 0, 'method' => 'bank', 'note' => 'درخواست پرداخت‌نشده', 'ip' => '127.0.0.1',
]);

// Dokan's own rows, fingerprinted the moment they exist. Everything after
// this point is ours; if the fingerprint moves, something of ours wrote into
// somebody else's table.
$dokanDigest = static function () use ($wpdb, $vendorId): string {
    return (string) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', id, trn_id, trn_type, debit, credit, status) ORDER BY id)), 'empty')
           FROM {$wpdb->prefix}dokan_vendor_balance WHERE vendor_id = %d",
        $vendorId
    ));
};
$dokanWithdrawDigest = static function () use ($wpdb, $vendorId): string {
    return (string) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', id, amount, status, method) ORDER BY id)), 'empty')
           FROM {$wpdb->prefix}dokan_withdraw WHERE user_id = %d",
        $vendorId
    ));
};
$digestBefore = $dokanDigest();
$withdrawDigestBefore = $dokanWithdrawDigest();

$reader = $c->get(DokanReaderInterface::class);
$seenStaff = $reader->staffFor($vendorId);
$check('dokan_past_exists', count($seenStaff) === 1 && $seenStaff[0]['dokan_role'] === 'tmc_evidence_role', [
    'staff' => count($seenStaff),
    'role' => $seenStaff[0]['dokan_role'] ?? '-',
]);

// 1. Import the records. This is the archival step and it is the ONLY step
//    that happens without a person deciding anything.
$import = $c->get(ImportFromDokan::class);
$wpdb->delete($wpdb->prefix . 'tmc_dokan_balance_history', ['vendor_user_id' => $vendorId]);
$wpdb->delete($wpdb->prefix . 'tmc_dokan_withdraw_history', ['vendor_user_id' => $vendorId]);
$wpdb->delete($wpdb->prefix . 'tmc_dokan_staff_history', ['vendor_user_id' => $vendorId]);

$ledgerBefore = $ledgerLines();
$withdrawBefore = $withdrawalRows();
foreach ($reader->staffFor($vendorId) as $member) {
    $records->recordStaff(TMC_HANDOVER_RUN, $member);
}
$balanceAfter = 0;
$withdrawAfter = 0;
do {
    $page = $import->importShopRecordPage(TMC_HANDOVER_RUN, $balanceAfter, $withdrawAfter, 200);
    $balanceAfter = (int) $page['last_balance'];
    $withdrawAfter = (int) $page['last_withdraw'];
} while ($page['more']);

$summary = $records->summaryForVendor($vendorId);
$check('records_imported', $summary['staff'] === 1 && $summary['balance_rows'] === 2 && $summary['withdrawals'] === 2, [
    'staff' => $summary['staff'],
    'balance_rows' => $summary['balance_rows'],
    'withdrawals' => $summary['withdrawals'],
]);
$check('import_wrote_no_ledger_line', $ledgerLines() - $ledgerBefore === 0, [
    'delta' => $ledgerLines() - $ledgerBefore,
]);
$check('import_created_no_withdrawal', $withdrawalRows() - $withdrawBefore === 0, [
    'delta' => $withdrawalRows() - $withdrawBefore,
]);

// 2. The record grants nothing. This is the whole point of the phase.
$map = (array) get_option(StaffRoleMap::SETTING, []);
unset($map['tmc_evidence_role']);
update_option(StaffRoleMap::SETTING, $map);

$plan = $grant->plan($vendorId);
$check('unmapped_role_awaits_a_decision', count($plan) === 1
    && $plan[0]['verdict'] === GrantImportedStaff::AWAITING_DECISION
    && $plan[0]['preset'] === '', [
    'verdict' => $plan[0]['verdict'] ?? '-',
    'preset' => ($plan[0]['preset'] ?? '') === '' ? '(none)' : $plan[0]['preset'],
]);

$granted = $grant->grant($vendorId);
$membership = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}tmc_vendor_staff WHERE staff_user_id = %d",
    $staffId
));
$check('no_membership_without_a_decision', (int) $membership === 0
    && $granted[GrantImportedStaff::GRANTED] === 0
    && $granted[GrantImportedStaff::AWAITING_DECISION] === 1, [
    'rows' => (int) $membership,
    'granted' => $granted[GrantImportedStaff::GRANTED],
    'awaiting' => $granted[GrantImportedStaff::AWAITING_DECISION],
]);

$check('imported_person_can_touch_nothing', !$access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::View)
    && !$access->can($staffId, $vendorId, StaffArea::Product, StaffLevel::View)
    && $access->storeFor($staffId) === null, [
    'order_view' => $access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::View),
    'store' => $access->storeFor($staffId) === null ? '(none)' : (string) $access->storeFor($staffId),
]);

// 3. Somebody decides. The map is the decision; nothing before it was one.
$roles->save(array_merge($roles->all(), ['tmc_evidence_role' => StaffRolePreset::OrderAndShipping->value]));
$granted = $grant->grant($vendorId);
$member = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface::class)->findByUser($staffId);
$check('mapped_role_creates_an_invited_membership', $granted[GrantImportedStaff::GRANTED] === 1
    && $member !== null && $member->status === StaffStatus::Invited, [
    'granted' => $granted[GrantImportedStaff::GRANTED],
    'status' => $member?->status->value ?? '-',
    'preset' => $member?->preset->value ?? '-',
]);

// 4. Invited is not live. Measured through the same StaffAccess every
//    operational screen asks, not through the row.
$check('invited_still_cannot_act', !$access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::Edit)
    && !$access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::View), [
    'order_edit' => $access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::Edit),
    'order_view' => $access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::View),
]);

$hashRow = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT invite_hash FROM {$wpdb->prefix}tmc_vendor_staff WHERE staff_user_id = %d",
    $staffId
));
$check('no_token_exists_for_the_adopted_row', $hashRow === '', [
    'invite_hash' => $hashRow === '' ? '(empty)' : 'present',
]);

// 5. Confirmation is the act that grants. It is necessary and — measured
//    here — NOT sufficient: the shop itself has not been admitted, and a shop
//    that may not trade suspends everyone in it. Two independent gates, and
//    the staff one is the inner of the two.
$grant->confirm((int) $member->id);
$confirmedMember = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface::class)->findByUser($staffId);
$check('confirmed_but_the_shop_is_not_admitted_yet', $confirmedMember?->status === StaffStatus::Active
    && !$access->vendorCanTrade($vendorId)
    && !$access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::Edit), [
    'membership' => $confirmedMember?->status->value ?? '-',
    'shop_may_trade' => $access->vendorCanTrade($vendorId),
    'order_edit' => $access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::Edit),
]);

// 5b. The shop is admitted — through `ReviewApplication`, the only thing that
//     writes the operational switch. Import writes `canSell = false`.
$vendors = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface::class);
$applicationRow = $vendors->findApplicationByUser($vendorId);
$applicationId = $applicationRow?->id ?? $vendors->saveDraft($vendorId, new \Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails(
    'فروشگاه نمونهٔ انتقال',
    'شخص حقوقی آزمایشی',
    'handover-vendor@example.invalid',
    '09120000003',
    'نشانی آزمایشی',
    true
));
$vendors->updateStatus($applicationId, \Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus::Submitted, $manager, 'پروندهٔ انتقال');
$c->get(\Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication::class)->approve($applicationId);

// 5c. NOW the confirmed member works — and works as EXACTLY the mapped role.
//     Order-and-shipping may run orders, may read stock, may not change it,
//     and may not see money at all.
$check('confirmed_member_has_exactly_the_mapped_role',
    $access->vendorCanTrade($vendorId)
    && $access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::Edit)
    && $access->can($staffId, $vendorId, StaffArea::Inventory, StaffLevel::View)
    && !$access->can($staffId, $vendorId, StaffArea::Inventory, StaffLevel::Edit)
    && !$access->can($staffId, $vendorId, StaffArea::Finance, StaffLevel::View), [
    'order_edit' => $access->can($staffId, $vendorId, StaffArea::Order, StaffLevel::Edit),
    'inventory_view' => $access->can($staffId, $vendorId, StaffArea::Inventory, StaffLevel::View),
    'inventory_edit' => $access->can($staffId, $vendorId, StaffArea::Inventory, StaffLevel::Edit),
    'finance_view' => $access->can($staffId, $vendorId, StaffArea::Finance, StaffLevel::View),
]);

// 5d. And the member sees ONLY their own shop. A staff member of one store
//     asking about another gets no for every area, whatever their preset.
$otherShop = $vendorId + 100000;
$check('member_is_scoped_to_their_own_shop',
    !$access->can($staffId, $otherShop, StaffArea::Order, StaffLevel::View)
    && $access->storeFor($staffId) === $vendorId, [
    'other_shop_order_view' => $access->can($staffId, $otherShop, StaffArea::Order, StaffLevel::View),
    'store' => (string) $access->storeFor($staffId),
]);

// 6. The reconciliation report: two columns that are never added together.
$report = $finance->forVendor($vendorId);
$check('closing_is_dokans_own_arithmetic', $report['dokan']['closing'] === '3000000.0000', [
    'credit' => $report['dokan']['credit'],
    'debit' => $report['dokan']['debit'],
    'closing' => $report['dokan']['closing'],
]);
$check('paid_withdrawal_is_already_inside_closing', $report['already_counted_once']['rows'] === 1
    && $report['already_counted_once']['debit'] === '2000000.0000', [
    'rows' => $report['already_counted_once']['rows'],
    'debit' => $report['already_counted_once']['debit'],
]);
$statuses = array_keys($report['dokan']['withdrawals_by_status']);
sort($statuses);
$check('withdrawals_keep_dokans_own_status', $statuses === ['dokan:0', 'dokan:1'], [
    'statuses' => implode(',', $statuses),
]);

// 7. The hand-over: a decision, recorded. No ledger line, no withdrawal, no
//    change to the imported figures.
$ledgerBefore = $ledgerLines();
$withdrawBefore = $withdrawalRows();
// ONE snapshot: the report this evidence prints and the version the decision
// is taken against are the same read, exactly as the page does it.
$snapshot = $finance->snapshotFor($vendorId);
$shown = $finance->reportFrom($snapshot);
$decision = $finance->decide(
    $vendorId,
    ReconcileDokanFinance::ACCEPTED,
    'تسویه خارج از دفترکل بازارگاه',
    $snapshot->token()
);
$after = $finance->forVendor($vendorId);

$check('handover_recorded', $decision['ok'] === true
    && $after['handover']['decision'] === ReconcileDokanFinance::ACCEPTED, [
    'reason' => $decision['reason'],
    'decision' => $after['handover']['decision'],
]);
$check('handover_froze_the_figure_that_was_shown',
    (string) $after['handover']['closing_at_decision'] === (string) $shown['dokan']['closing'], [
    'shown' => (string) $shown['dokan']['closing'],
    'frozen' => (string) $after['handover']['closing_at_decision'],
]);
$check('handover_named_the_figures_version',
    (int) $after['handover']['records_version'] === $snapshot->recordsVersion
    && $snapshot->recordsVersion > 0, [
    'recorded' => (int) $after['handover']['records_version'],
    'snapshot' => $snapshot->recordsVersion,
]);
$check('handover_refuses_a_superseded_version',
    (static function () use ($finance, $vendorId, $snapshot): bool {
        // The same token again, after the version has moved on: must refuse.
        $again = $finance->decide($vendorId, ReconcileDokanFinance::ACCEPTED, '', $snapshot->token());
        return $again['ok'] === true;   // same version, unchanged figures → still valid
    })(), ['note' => 'unchanged figures keep the same version, so the receipt still matches']);
$check('handover_wrote_no_ledger_line', $ledgerLines() - $ledgerBefore === 0, [
    'delta' => $ledgerLines() - $ledgerBefore,
]);
$check('handover_created_no_second_payment', $withdrawalRows() - $withdrawBefore === 0
    && (int) $after['handover']['pending_requests_untouched'] === 1, [
    'withdrawal_delta' => $withdrawalRows() - $withdrawBefore,
    'pending_untouched' => $after['handover']['pending_requests_untouched'],
]);
$check('no_rate_was_applied', $after['dokan']['closing'] === '3000000.0000'
    && $after['handover']['closing_at_decision'] === '3000000.0000', [
    'closing' => $after['dokan']['closing'],
    'frozen' => $after['handover']['closing_at_decision'],
]);

// Dokan's own tables, untouched by the import, the grant or the hand-over.
// Read straight from its tables rather than through our reader: a reader that
// had a bug would agree with itself.
$check('dokan_tables_untouched', $dokanDigest() === $digestBefore
    && $dokanWithdrawDigest() === $withdrawDigestBefore, [
    'balance' => substr($digestBefore, 0, 12) . '->' . substr($dokanDigest(), 0, 12),
    'withdraw' => substr($withdrawDigestBefore, 0, 12) . '->' . substr($dokanWithdrawDigest(), 0, 12),
]);

// 8. «مهاجرت کامل» is not something the archive can say on its own.
$verdict = $complete->forVendor($vendorId);
// This shop has its archive, its staff decision, its finance decision and its
// admission — and it is STILL not «migrated», because not one of its products
// has been taken over. The archive was never the thing that made it complete.
$check('archive_alone_is_not_complete', $verdict['verdict'] !== MigrationCompleteness::COMPLETE
    && in_array('ownership_taken', $verdict['missing'], true), [
    'verdict' => $verdict['verdict'],
    'missing' => implode(',', $verdict['missing']),
]);
$check('finance_gate_closed_by_the_decision', ($verdict['gates']['finance_decided'] ?? false) === true
    && ($verdict['gates']['staff_decided'] ?? false) === true, [
    'finance_decided' => $verdict['gates']['finance_decided'] ?? false,
    'staff_decided' => $verdict['gates']['staff_decided'] ?? false,
]);
$check('marketplace_is_not_declared_migrated', $complete->allComplete() === false, [
    'all_complete' => $complete->allComplete(),
]);

$say('summary', $fail === 0, ['failures' => $fail, 'vendor' => $vendorId, 'staff' => $staffId]);
