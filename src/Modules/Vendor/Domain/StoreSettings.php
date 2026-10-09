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
     * Which fields each tab of the settings page owns.
     *
     * **This map is the fix for the worst defect this round.** The page is
     * five tabs and five separate `<form>`s, and a browser posts only the
     * form that was submitted — so a save from «ارسال» carries no `city` and
     * no `intro`. Until `alpha.41` the route read EVERY field off every post
     * (`postText()` answers `''` for a field that is not there, never null),
     * handed all twelve to `fromInput()`, and `DbStoreRepository::save()`
     * wrote all twelve columns. The measured result on the owner's site: the
     * city and the introduction were saved, then saving the shipping tab
     * emptied them, and the preparation days went from four back to nought.
     *
     * One map, in the Domain, because the view renders from it and the write
     * path filters by it — and «دو فهرست یعنی دو قاعده» (`alpha.28`). A field
     * absent from its OWN tab's post is a deliberate clear (an unticked
     * checkbox sends nothing); a field whose tab was not the one submitted is
     * not in play at all. Only the tab tells those two apart, which is why
     * the fix cannot live in one layer alone.
     *
     * `bank` is here with no fields on purpose: it is a real tab that never
     * saves settings — the IBAN travels as a change request — so a
     * `save_store` naming it must write nothing rather than be treated as an
     * unknown tab.
     *
     * @var array<string,list<string>>
     */
    public const TAB_FIELDS = [
        'general' => ['city', 'intro', 'logo_id', 'banner_id'],
        'shipping' => ['preparation_days', 'origin_warehouse', 'carriers'],
        'closure' => ['closed', 'closed_from', 'closed_to', 'reopen_message'],
        'social' => ['social'],
        'bank' => [],
    ];

    /**
     * The three fields whose CONTROL sends nothing when it is cleared.
     *
     * A text input that has been emptied still arrives, as `''`. A checkbox
     * that has been unticked, and a checkbox group with nothing ticked, and a
     * map of URL inputs that are all empty, arrive as nothing at all — the
     * same way a field belonging to another tab arrives as nothing. So for
     * exactly these three, «absent on my own tab» has to be read as «none»,
     * and that is a property of the control rather than of the caller.
     *
     * @var list<string>
     */
    public const ABSENCE_IS_AN_ANSWER = ['carriers', 'social', 'closed'];

    /** Is this a tab the settings page actually has? */
    public static function isTab(string $tab): bool
    {
        return array_key_exists($tab, self::TAB_FIELDS);
    }

    /**
     * The posted values this tab is allowed to change, and nothing else.
     *
     * Two directions, and both matter. A field belonging to another tab is
     * dropped even when it is posted — so a hand-made request that adds
     * `city` to a shipping save cannot reach the write. And a field of THIS
     * tab that was not posted is still returned, as the empty value its
     * control sends when it is cleared, so clearing works.
     *
     * `carriers` and `social` are the exception and the reason this returns a
     * built array rather than an intersection: a checkbox group and a map of
     * URL inputs send NOTHING when every box is unticked and every box is
     * empty, so their key has to be put back deliberately.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function fieldsOfTab(string $tab, array $input): array
    {
        $allowed = self::TAB_FIELDS[$tab] ?? [];
        $out = [];
        foreach ($allowed as $field) {
            if (in_array($field, self::ABSENCE_IS_AN_ANSWER, true)) {
                // These three send nothing at all when they are cleared — an
                // unticked checkbox and a group with no box ticked are both
                // simply absent — so the key is put back with the «none»
                // value their own form means by it.
                $out[$field] = match ($field) {
                    'closed' => (bool) ($input['closed'] ?? false),
                    default => (array) ($input[$field] ?? []),
                };
                continue;
            }
            // Everything else passes through only when it was posted. The
            // route always posts every text control of the tab it is saving
            // (an empty one as `''`), so clearing works there; a caller that
            // names one field changes one field, which is the safe reading
            // for anything that is not the form.
            if (array_key_exists($field, $input)) {
                $out[$field] = $input[$field];
            }
        }
        return $out;
    }

    /**
     * Fields the vendor may edit freely, already cleaned. Returns the reasons
     * anything was rejected rather than silently fixing it, so the form can
     * say what happened.
     *
     * **Every field is read with `array_key_exists`, not with `??`.** Three of
     * them used to fall back to a CLEARING default — `carriers` to `[]`,
     * `social` to `[]`, `closed` to `false` — so a save that did not mention
     * them erased them. The scalars already fell back to `$current` and that
     * half was right; it simply never fired, because the route always supplied
     * a key. Both halves are fixed, and either one alone would have left the
     * defect reachable from the other side.
     *
     * @param array<string,mixed> $input
     * @param list<string> $allowedNetworks
     * @param list<string> $allowedCarriers
     * @return array{settings:self, problems:list<string>}
     */
    public static function fromInput(array $input, array $allowedNetworks, array $allowedCarriers, self $current): array
    {
        $problems = [];
        $given = static fn (string $key): bool => array_key_exists($key, $input);

        $days = $given('preparation_days') ? (int) $input['preparation_days'] : $current->preparationDays;
        if ($days < 0 || $days > self::MAX_PREPARATION_DAYS) {
            $problems[] = 'preparation_days';
            $days = $current->preparationDays;
        }

        $carriers = $current->carriers;
        if ($given('carriers')) {
            $carriers = [];
            foreach ((array) $input['carriers'] as $slug) {
                $slug = (string) $slug;
                if (in_array($slug, $allowedCarriers, true)) {
                    $carriers[] = $slug;
                } elseif ($slug !== '') {
                    $problems[] = 'carrier:' . $slug;
                }
            }
        }

        $social = $current->social;
        if ($given('social')) {
            $social = [];
            foreach ((array) $input['social'] as $network => $url) {
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
        }

        $closed = $given('closed') ? (bool) $input['closed'] : $current->closed;
        // The two dates travel WITH the tick: they are controls on the same
        // form, so a closure save that omits them has cleared them. A save
        // from any other tab does not mention them at all and keeps them.
        $from = $given('closed_from') ? self::date($input['closed_from']) : $current->closedFrom;
        $to = $given('closed_to') ? self::date($input['closed_to']) : $current->closedTo;
        if ($from !== null && $to !== null && $to < $from) {
            $problems[] = 'closure_range';
            $from = null;
            $to = null;
        }

        return [
            'settings' => new self(
                $current->storeName,          // renaming is a change request, never a save
                $given('city') ? (string) $input['city'] : $current->city,
                $given('intro') ? (string) $input['intro'] : $current->intro,
                $given('logo_id') ? (int) $input['logo_id'] : $current->logoId,
                $given('banner_id') ? (int) $input['banner_id'] : $current->bannerId,
                $days,
                $given('origin_warehouse') ? (string) $input['origin_warehouse'] : $current->originWarehouse,
                $carriers,
                $closed,
                $from,
                $to,
                $given('reopen_message') ? (string) $input['reopen_message'] : $current->reopenMessage,
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
