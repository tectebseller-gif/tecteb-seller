<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/** A rule of the vendor domain was broken. Never carries user input verbatim. */
final class VendorDomainException extends \RuntimeException
{
}
