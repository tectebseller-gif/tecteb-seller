<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

/**
 * The lists a vendor chooses FROM, which only the manager may write.
 *
 * Same rule as the document types: the plugin ships none and suggests none.
 * Which couriers a medical marketplace may use, and which social networks it
 * is willing to link to, are the owner's decisions, and an empty list is the
 * honest default rather than a guess with Persian labels attached.
 */
interface VendorListsInterface
{
    /** @return array<string,string> slug => label */
    public function carriers(): array;

    /** @return array<string,string> slug => label */
    public function networks(): array;

    /** @param array<string,string> $carriers */
    public function setCarriers(array $carriers): bool;

    /** @param array<string,string> $networks */
    public function setNetworks(array $networks): bool;
}
