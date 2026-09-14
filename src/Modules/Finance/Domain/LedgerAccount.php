<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * The separate accounts FIN-06 requires: «پرداخت مرکزی، مالیات وصول‌شده،
 * حق‌العمل و بدهی فروشنده در حساب‌های جدا با جمع متوازن».
 *
 * Keeping them apart is what makes the books checkable. A single "vendor
 * balance" column can be wrong in a way nobody can see; four accounts that
 * must sum to zero per event cannot.
 */
enum LedgerAccount: string
{
    /** Money the marketplace received centrally for an item. */
    case CentralPayment = 'central_payment';
    /** The marketplace's commission on that item. */
    case Commission = 'commission';
    /** What the vendor has earned, before release. */
    case VendorEarning = 'vendor_earning';
    /** Tax collected centrally; never part of the commission base. */
    case TaxCollected = 'tax_collected';
    /** A vendor owing the marketplace, e.g. a refund after a payout. */
    case VendorDebt = 'vendor_debt';
}
