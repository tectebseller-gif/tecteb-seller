<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Finance\Application\RequestWithdrawal;
use Tecteb\Marketplace\Modules\Finance\Application\SettlementGate;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Presentation\VendorFinanceView;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/** /vendor/finance/ — this shop's money, and nothing else's. */
final class FinanceArea
{
    public const SLUG = 'finance';

    /** @var list<string> */
    public const ACTIONS = ['request_withdrawal', 'cancel_withdrawal'];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function render(VendorAreaView $view): string
    {
        $access = $this->container->get(StaffAccess::class);
        $vendorUserId = $access->storeFor($view->userId);
        if ($vendorUserId === null) {
            return '';
        }
        if (!$access->can($view->userId, $vendorUserId, StaffArea::Finance, StaffLevel::View)) {
            return VendorUi::notice('warning', __('نقش شما به بخش مالی این فروشگاه دسترسی ندارد.', 'tecteb-marketplace-core'));
        }
        $gate = $this->container->get(SettlementGate::class)->check();
        $withdrawals = $this->container->get(WithdrawalRepositoryInterface::class);

        return VendorFinanceView::render(
            $this->container->get(VendorBalance::class)->of($vendorUserId),
            $withdrawals->openFor($vendorUserId),
            $withdrawals->forVendor($vendorUserId, 20),
            $this->financeUrl(),
            $view->nonceField,
            $view->notice,
            $access->can($view->userId, $vendorUserId, StaffArea::Finance, StaffLevel::Edit),
            (bool) $gate['ready'],
            (string) $gate['detail']
        );
    }

    public function handle(string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }
        $vendorUserId = $this->container->get(StaffAccess::class)->storeFor($userId);
        if ($vendorUserId === null) {
            return new VendorAreaOutcome('not_a_vendor', $urls->dashboard());
        }
        $service = $this->container->get(RequestWithdrawal::class);
        $result = $action === 'request_withdrawal'
            ? $service->handle($userId, $vendorUserId)
            : $service->cancel($userId, $vendorUserId, $request->postInt('withdrawal_id'));

        return new VendorAreaOutcome($result->code, $this->financeUrl(), $result->context);
    }

    public function financeUrl(): string
    {
        return (string) get_option('permalink_structure', '') !== ''
            ? home_url('/vendor/' . self::SLUG . '/')
            : home_url('/?tmc_vendor=' . self::SLUG);
    }
}
