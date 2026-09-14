<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * Everything a vendor may set about their own shop (UX §11), minus the two
 * things they may not set alone: the store name and the bank account. Those
 * travel as change requests, because the spec makes them the manager's call.
 *
 * @param array<string,string> $social network slug → URL
 * @param list<string> $carriers slugs from the manager's list
 */
final class StoreSettings
{
    public function __construct(
        public readonly string $storeName = '',
        public readonly string $city = '',
        public readonly string $intro = '',
        public readonly int $logoId = 0,
        public readonly int $bannerId = 0,
        public readonly int $preparationDays = 1,
        public readonly string $originWarehouse = '',
        public readonly array $carriers = [],
        public readonly bool $closed = false,
        public readonly ?string $closedFrom = null,
        public readonly ?string $closedTo = null,
        public readonly string $reopenMessage = '',
        public readonly array $social = []
    ) {
    }

    public const MAX_PREPARATION_DAYS = 30;

    /**
     * Fields the vendor may edit freely, already cleaned. Returns the reasons
     * anything was rejected rather than silently fixing it, so the form can
     * say what happened.
     *
     * @param array<string,mixed> $input
     * @param list<string> $allowedNetworks
     * @param list<string> $allowedCarriers
     * @return array{settings:self, problems:list<string>}
     */
    public static function fromInput(array $input, array $allowedNetworks, array $allowedCarriers, self $current): array
    {
        $problems = [];

        $days = (int) ($input['preparation_days'] ?? $current->preparationDays);
        if ($days < 0 || $days > self::MAX_PREPARATION_DAYS) {
            $problems[] = 'preparation_days';
            $days = $current->preparationDays;
        }

        $carriers = [];
        foreach ((array) ($input['carriers'] ?? []) as $slug) {
            $slug = (string) $slug;
            if (in_array($slug, $allowedCarriers, true)) {
                $carriers[] = $slug;
            } elseif ($slug !== '') {
                $problems[] = 'carrier:' . $slug;
            }
        }

        $social = [];
        foreach ((array) ($input['social'] ?? []) as $network => $url) {
            $network = (string) $network;
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            if (!in_array($network, $allowedNetworks, true)) {
                $problems[] = 'network:' . $network;
                continue;
            }
            if (!self::isSafeUrl($url)) {
                $problems[] = 'url:' . $network;
                continue;
            }
            $social[$network] = $url;
        }

        $closed = (bool) ($input['closed'] ?? false);
        $from = self::date($input['closed_from'] ?? null);
        $to = self::date($input['closed_to'] ?? null);
        if ($from !== null && $to !== null && $to < $from) {
            $problems[] = 'closure_range';
            $from = null;
            $to = null;
        }

        return [
            'settings' => new self(
                $current->storeName,          // renaming is a change request, never a save
                (string) ($input['city'] ?? $current->city),
                (string) ($input['intro'] ?? $current->intro),
                (int) ($input['logo_id'] ?? $current->logoId),
                (int) ($input['banner_id'] ?? $current->bannerId),
                $days,
                (string) ($input['origin_warehouse'] ?? $current->originWarehouse),
                $carriers,
                $closed,
                $from,
                $to,
                (string) ($input['reopen_message'] ?? $current->reopenMessage),
                $social
            ),
            'problems' => $problems,
        ];
    }

    /** Only http(s), only an absolute URL with a host. No javascript:, no data:. */
    public static function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        return in_array(strtolower($parts['scheme']), ['http', 'https'], true) && $parts['host'] !== '';
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /** True while the shop should be shown as temporarily closed. */
    public function isClosedOn(string $today): bool
    {
        if (!$this->closed) {
            return false;
        }
        if ($this->closedFrom !== null && $today < $this->closedFrom) {
            return false;
        }
        if ($this->closedTo !== null && $today > $this->closedTo) {
            return false;
        }
        return true;
    }
}
