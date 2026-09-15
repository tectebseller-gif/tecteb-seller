<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;

/** Everything a vendor-area page needs to render itself, handed over once. */
final class VendorAreaView
{
    public function __construct(
        public readonly Request $request,
        public readonly int $userId,
        public readonly VendorUrls $urls,
        public readonly string $nonceField,
        public readonly ?VendorNotice $notice
    ) {
    }
}
