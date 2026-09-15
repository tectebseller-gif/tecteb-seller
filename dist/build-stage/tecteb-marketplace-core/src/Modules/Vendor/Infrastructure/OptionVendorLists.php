<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorListsInterface;

/**
 * Two short manager-defined lists, in options rather than tables: they are
 * read on nearly every store page and written about once a year.
 */
final class OptionVendorLists implements VendorListsInterface
{
    public const CARRIERS_OPTION = 'tmc_vendor_carriers';
    public const NETWORKS_OPTION = 'tmc_vendor_networks';

    private const MAX_ENTRIES = 30;

    public function __construct(private readonly OptionStoreInterface $options)
    {
    }

    public function carriers(): array
    {
        return $this->read(self::CARRIERS_OPTION);
    }

    public function networks(): array
    {
        return $this->read(self::NETWORKS_OPTION);
    }

    public function setCarriers(array $carriers): bool
    {
        return $this->options->set(self::CARRIERS_OPTION, $this->clean($carriers));
    }

    public function setNetworks(array $networks): bool
    {
        return $this->options->set(self::NETWORKS_OPTION, $this->clean($networks));
    }

    /** @return array<string,string> */
    private function read(string $key): array
    {
        $stored = $this->options->get($key, []);
        return is_array($stored) ? $this->clean($stored) : [];
    }

    /**
     * @param array<mixed,mixed> $raw
     * @return array<string,string>
     */
    private function clean(array $raw): array
    {
        $out = [];
        foreach ($raw as $slug => $label) {
            $slug = strtolower(trim((string) $slug));
            $label = trim((string) $label);
            if (preg_match('/^[a-z0-9\-]{2,32}$/', $slug) !== 1 || $label === '') {
                continue;
            }
            $out[$slug] = mb_substr($label, 0, 60);
            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
        }
        return $out;
    }
}
