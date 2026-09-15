<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;

/**
 * "May this shop publish without the manager?" — the SECOND permission.
 *
 * Master Spec §4.1 is explicit that «اجازه فروش» and «اجازه انتشار مستقیم
 * محصول» are two separate grants, and A.1 repeats it: an approved vendor
 * publishes through review unless the marketplace separately says otherwise.
 * So the default is false, for everyone, and the grant is per shop and
 * revocable — never a role, never a capability a plugin could hand out.
 */
final class ProductPublishPolicy
{
    public const OPTION = 'tmc_product_direct_publish';

    public function __construct(private readonly OptionStoreInterface $options)
    {
    }

    public function mayPublishDirectly(int $vendorUserId): bool
    {
        return in_array($vendorUserId, $this->granted(), true);
    }

    /** @return list<int> */
    public function granted(): array
    {
        $stored = $this->options->get(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $stored)));
        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    public function grant(int $vendorUserId): bool
    {
        if ($vendorUserId <= 0) {
            return false;
        }
        $ids = $this->granted();
        if (in_array($vendorUserId, $ids, true)) {
            return true;
        }
        $ids[] = $vendorUserId;
        return $this->options->set(self::OPTION, $ids);
    }

    public function revoke(int $vendorUserId): bool
    {
        $ids = array_values(array_filter($this->granted(), static fn (int $id): bool => $id !== $vendorUserId));
        return $this->options->set(self::OPTION, $ids);
    }
}
