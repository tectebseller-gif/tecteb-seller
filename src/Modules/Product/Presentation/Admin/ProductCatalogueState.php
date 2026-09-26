<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Modules\Product\Domain\ProductSort;

/**
 * What the manager is currently looking at, and the one place a link to it is
 * built.
 *
 * Every control on the list — a status chip, the search box, the rows-per-page
 * select, the sort select, a page number, the «مشاهده و بررسی» link and the
 * way back from a product's own page — is the same address with one thing
 * changed. Written out control by control that is six chances to forget a
 * parameter, and forgetting one is not a cosmetic bug: it silently resets the
 * manager's filter, which on a catalogue of hundreds of products means the row
 * they were about to decide on is no longer on the screen.
 *
 * So `link()` starts from the state and takes only the overrides. Two rules
 * are built into it:
 *
 *   * **a new filter means page one.** `paged` is only ever in the result when
 *     the caller asked for it, so changing status, search, row count or sort
 *     cannot land on page 7 of a list that now has two pages — «تغییر جست‌وجو،
 *     وضعیت، تعداد ردیف یا مرتب‌سازی، صفحه را به اول برگرداند»;
 *   * **page one has one address.** `paged=1` is dropped, so the first page is
 *     not two URLs that a browser, a cache and a bookmark would each treat
 *     differently.
 *
 * Nothing here decides permission and nothing here reads a superglobal: the
 * page hands over values it has already read through `Request`.
 */
final class ProductCatalogueState
{
    /** The default, and the only value that is left out of an address. */
    public const PER_PAGE = 20;

    /**
     * What the manager may choose instead.
     *
     * A number that is not on this list is not honoured, because «۲۰ تا ۵۰ تا
     * ۱۰۰» is a choice between three known page sizes and `per_page=100000`
     * is the same request as «everything», which is what the limit exists to
     * refuse.
     *
     * @var list<int>
     */
    public const PER_PAGE_CHOICES = [20, 50, 100];

    /** The value of `revisions` that means «only unanswered proposals». */
    public const REVISIONS_PENDING = 'pending';

    /** @param array<string,int> $counts status value => how many, under this search */
    public function __construct(
        public readonly string $status,
        public readonly string $search,
        public readonly int $page,
        public readonly int $perPage,
        public readonly ProductSort $sort,
        public readonly bool $onlyRevisions,
        public readonly int $total,
        public readonly array $counts,
        public readonly int $pendingRevisions,
        public readonly string $baseUrl
    ) {
    }

    /**
     * How many rows to read, from whatever arrived in the URL.
     *
     * An unrecognised number is the default rather than an error: a stale
     * bookmark should show the list.
     */
    public static function perPage(int $raw): int
    {
        return in_array($raw, self::PER_PAGE_CHOICES, true) ? $raw : self::PER_PAGE;
    }

    /**
     * The same view, once the numbers have been counted.
     *
     * The filters come from the URL and the totals come from the database, and
     * they are read at two different moments: the query needs the page size
     * before anything has been counted. Rather than read the URL twice — two
     * readers of one request being two chances to read it differently — the
     * page builds the state once and fills the numbers in here.
     *
     * @param array<string,int> $counts
     */
    public function withCounts(int $total, array $counts, int $pendingRevisions): self
    {
        return new self(
            $this->status,
            $this->search,
            $this->page,
            $this->perPage,
            $this->sort,
            $this->onlyRevisions,
            $total,
            $counts,
            $pendingRevisions,
            $this->baseUrl
        );
    }

    /**
     * This same list with one or more things changed.
     *
     * A value of `null` or `''` removes that parameter — which is how the
     * «همه» chip is written, and why it cannot accidentally carry the status
     * it is meant to clear.
     *
     * @param array<string,string|int|null> $overrides
     */
    public function link(array $overrides = []): string
    {
        $args = [];
        if ($this->status !== '') {
            $args['status'] = $this->status;
        }
        if ($this->search !== '') {
            $args['q'] = $this->search;
        }
        if ($this->perPage !== self::PER_PAGE) {
            $args['per_page'] = $this->perPage;
        }
        if (!$this->sort->isDefault()) {
            $args['orderby'] = $this->sort->value;
        }
        if ($this->onlyRevisions) {
            $args['revisions'] = self::REVISIONS_PENDING;
        }
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($args[$key]);
                continue;
            }
            $args[$key] = $value;
        }
        if ((int) ($args['paged'] ?? 0) === 1) {
            unset($args['paged']);
        }
        return $args === [] ? $this->baseUrl : add_query_arg($args, $this->baseUrl);
    }

    /** The address of this exact view, page number included — the way back. */
    public function selfLink(): string
    {
        return $this->link(['paged' => $this->page]);
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    /** The first row's number in the whole result, 1-based; 0 when there are none. */
    public function firstRow(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    /** The last row's number in the whole result — never past the total. */
    public function lastRow(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    /** Whether anything is narrowing the list, which decides whether «پاک‌کردن فیلترها» is offered. */
    public function isFiltered(): bool
    {
        return $this->status !== '' || $this->search !== '' || $this->onlyRevisions;
    }
}
