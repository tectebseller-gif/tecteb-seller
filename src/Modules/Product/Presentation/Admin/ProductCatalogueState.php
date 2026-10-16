<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueFacets;
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

    /**
     * The value of `review` (and of the older `revisions`) that means «only
     * the things waiting for a decision».
     *
     * The query key was `revisions` until `alpha.48`, when the scope stopped
     * being about revisions alone: it is now the one «needs review» set —
     * submitted products, unanswered change proposals and open brand requests
     * — defined once in `ReviewQueueSql::waiting()`. The old key is still
     * read, so a link a manager bookmarked keeps working, and `review` is what
     * this page now writes.
     */
    public const REVIEW_PENDING = 'pending';

    /** @deprecated since `alpha.48`; read for old links, never written. */
    public const REVISIONS_PENDING = self::REVIEW_PENDING;

    /** @param array<string,int> $counts status value => how many, under this search */
    public function __construct(
        public readonly string $status,
        public readonly string $search,
        public readonly int $page,
        public readonly int $perPage,
        public readonly ProductSort $sort,
        public readonly bool $onlyNeedsReview,
        public readonly int $total,
        public readonly array $counts,
        public readonly int $needsReview,
        public readonly string $baseUrl,
        /**
         * The page that was ASKED for, when it no longer exists.
         *
         * A manager decides on the last product of page seven and the list
         * becomes six pages long; the browser then asks for a page that is
         * past the end, and an unguarded query answers with no rows — an empty
         * screen with no way back, after an action that worked. Null means the
         * page asked for is the page shown.
         */
        public readonly ?int $outOfRangePage = null,
        /**
         * Where the row comes from: a WooCommerce product with a marketplace
         * row, one without, or a marketplace row with no product.
         *
         * Appended with a default, like every field added to this class since
         * `alpha.45`, so nothing that builds a state positionally has to be
         * rewritten to keep compiling.
         */
        public readonly string $source = '',
        /** WooCommerce's own `post_status` — never our status, never derived from it. */
        public readonly string $wcStatus = '',
        /** Whether this plugin runs the product, only watches it, or has no row. */
        public readonly string $management = '',
        /**
         * How many rows each facet value would show, under the current
         * search.
         *
         * @var array{source?:array<string,int>, wc_status?:array<string,int>, management?:array<string,int>}
         */
        public readonly array $facets = []
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
     * @param array<string,array<string,int>> $facets empty keeps the ones already held
     */
    public function withCounts(int $total, array $counts, int $needsReview, array $facets = []): self
    {
        // The page is clamped HERE, before the rows are read, so the query
        // asks for a page that exists rather than for an offset past the end
        // and the list then explaining an emptiness it created. A result with
        // no rows at all is a different state and is left alone: «this page is
        // gone» and «nothing matches» send the reader to two different places.
        $pages = max(1, (int) ceil($total / max(1, $this->perPage)));
        $outOfRange = $total > 0 && $this->page > $pages ? $this->page : null;

        return new self(
            $this->status,
            $this->search,
            $outOfRange === null ? $this->page : $pages,
            $this->perPage,
            $this->sort,
            $this->onlyNeedsReview,
            $total,
            $counts,
            $needsReview,
            $this->baseUrl,
            $outOfRange,
            $this->source,
            $this->wcStatus,
            $this->management,
            $facets === [] ? $this->facets : $facets
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
        if ($this->onlyNeedsReview) {
            $args['review'] = self::REVIEW_PENDING;
        }
        // The three facets of `alpha.56`'s one list. Each is carried
        // independently, because each answers a different question and a
        // chip that silently dropped one of the others would reset a filter
        // the manager is still standing in.
        if ($this->source !== '') {
            $args['source'] = $this->source;
        }
        if ($this->wcStatus !== '') {
            $args['wc_status'] = $this->wcStatus;
        }
        if ($this->management !== '') {
            $args['management'] = $this->management;
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

    /**
     * The filters as the catalogue reader wants them — one translation, here.
     *
     * The page reads the URL, this object validates it, and the reader is
     * handed the result. Written at the call site it would be a second place
     * that decides what `source` may be, and the control that offers the
     * choice would eventually offer one the query does not honour.
     *
     * @return array{search:string, source:string, wc_status:string, management:string}
     */
    public function readerFilters(): array
    {
        return [
            'search' => $this->search,
            'source' => CatalogueFacets::source($this->source),
            'wc_status' => CatalogueFacets::wcStatus($this->wcStatus),
            'management' => CatalogueFacets::management($this->management),
        ];
    }

    /** Whether anything is narrowing the list, which decides whether «پاک‌کردن فیلترها» is offered. */
    public function isFiltered(): bool
    {
        return $this->status !== '' || $this->search !== '' || $this->onlyNeedsReview
            || $this->source !== '' || $this->wcStatus !== '' || $this->management !== '';
    }
}
