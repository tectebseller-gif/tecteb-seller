<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * What a vendor is allowed to know about the person who ordered (PRIV-01).
 *
 * Exactly four fields, and the two that are missing are the point: e-mail and
 * mobile are not stored here, not passed here, and not obtainable from here.
 * DEC-01 — whether a courier needs the mobile — is still open, and until it
 * is answered this object cannot carry one even by accident.
 */
final class OrderCustomerView
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $city = '',
        public readonly string $address = '',
        public readonly string $postcode = ''
    ) {
    }

    public function isEmpty(): bool
    {
        return trim($this->name . $this->city . $this->address . $this->postcode) === '';
    }
}
