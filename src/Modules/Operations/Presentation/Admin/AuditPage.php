<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Presentation\Admin;

use Tecteb\Marketplace\Contracts\AuditRecord;
use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Operations\Presentation\OperationsMessages;

/**
 * «ممیزی» — the trail, finally readable.
 *
 * Every manager action in this plugin has been recorded since the first
 * release, and until now none of it could be looked at from inside wp-admin:
 * `AuditRepositoryInterface` had `insert()` and nothing else. A write-only
 * audit table is a promise, not a feature — it satisfies a checklist and
 * answers no question anybody actually asks, such as «who approved this vendor,
 * and when».
 *
 * **Filters, not a search box.** The table's indexes are on `event_type`,
 * `actor_id` and `(object_type, object_id)`, so those filters are index seeks
 * however big the trail gets. The free-text field searches the payload with a
 * LIKE and is therefore a scan — which is why it is offered LAST and next to
 * the others rather than instead of them: «this order id, in June» stays fast
 * because the date and the object narrow it first.
 *
 * **Reading the trail is its own capability.** `tmc_view_audit` is not implied
 * by any other permission. The trail records what every manager did, so the
 * people who can read it may reasonably be a shorter list than the people who
 * appear in it.
 *
 * **Nothing here can change anything.** There is no delete, no edit, no export
 * that re-writes. That is not an oversight to fill in later: an audit trail
 * somebody can tidy is not one.
 */
final class AuditPage
{
    public const SLUG = 'tmc-audit';
    public const CAPABILITY = Capabilities::VIEW_AUDIT;
    private const PER_PAGE = 30;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('ممیزی', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('اجازهٔ دیدن این صفحه را ندارید.', 'tecteb-marketplace-core'));
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $request = Request::capture();
        $filters = $this->filters($request);
        $page = max(1, $request->queryInt('paged'));

        echo Components::shellOpen(self::menuLabel(), self::SLUG);
        echo '<p class="tmc-field__desc">'
            . esc_html__('این فهرست فقط خوانده می‌شود. هیچ ردیفی از این صفحه — یا هیچ صفحهٔ دیگری — حذف یا ویرایش نمی‌شود؛ ردِ کاری که می‌شود مرتبش کرد، رد نیست.', 'tecteb-marketplace-core')
            . '</p>';

        $this->renderFilters($filters);

        $repo = $this->audit();
        $total = $repo->count($filters);
        $records = $repo->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html(sprintf(
            /* translators: %s: number of matching rows */
            __('%s رویداد', 'tecteb-marketplace-core'),
            $fa((string) $total)
        )) . '</h2>';

        if ($records === []) {
            echo '<p class="tmc-field__desc">'
                . esc_html__('با این فیلترها چیزی ثبت نشده است.', 'tecteb-marketplace-core')
                . '</p></section>' . Components::shellClose();
            return;
        }

        echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('رویدادهای ممیزی', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('زمان', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('رویداد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('کاربر', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('موضوع', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('جزئیات', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($records as $record) {
            echo $this->row($record, $fa);       // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</tbody></table></div>';
        echo $this->pager($page, $total, $filters, $fa);   // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</section>' . Components::shellClose();
    }

    /** @param callable(string|int):string $fa */
    private function row(AuditRecord $record, callable $fa): string
    {
        $actor = $record->actorId === null || $record->actorId === 0
            ? __('سامانه', 'tecteb-marketplace-core')
            : $this->userLabel($record->actorId);
        $subject = $record->objectType === null || $record->objectType === ''
            ? '—'
            : $record->objectType . ':' . (string) $record->objectId;

        return '<tr><td data-label="' . esc_attr__('زمان', 'tecteb-marketplace-core') . '">'
            . esc_html($fa($record->createdAtUtc->format('Y-m-d H:i'))) . '</td>'
            . '<td data-label="' . esc_attr__('رویداد', 'tecteb-marketplace-core') . '">'
            . esc_html(OperationsMessages::auditEvent($record->eventType)) . '</td>'
            . '<td data-label="' . esc_attr__('کاربر', 'tecteb-marketplace-core') . '">'
            . esc_html($actor) . '</td>'
            . '<td data-label="' . esc_attr__('موضوع', 'tecteb-marketplace-core') . '">'
            . Components::code($subject) . '</td>'
            . '<td data-label="' . esc_attr__('جزئیات', 'tecteb-marketplace-core') . '">'
            . Components::techDetails($this->details($record), __('دیدن مقادیر', 'tecteb-marketplace-core'))
            . '</td></tr>';
    }

    /**
     * The payload as rows `Components::dataList()` can actually draw.
     *
     * This used to return a MAP (`key => value`), while `dataList()` has
     * always taken a LIST of `{label, value}`. So every row it was handed was
     * a bare string, and `$row['label']` on a string is a TypeError in PHP 8
     * — the page fatalled the moment it had a single line to show.
     *
     * It went unseen because the only thing rendering it was a test that
     * opens every registered page against an EMPTY audit table: with no
     * records, `row()` never runs and the broken call is never reached. The
     * page was «rendered» and proved nothing about rendering a row. Same
     * lesson as `alpha.13`'s — a test that checks registration does not check
     * behaviour — one level further in.
     *
     * @return list<array{label:string, value:string}>
     */
    private function details(AuditRecord $record): array
    {
        $rows = [];
        foreach ($record->payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $rows[] = ['label' => (string) $key, 'value' => (string) ($value ?? '')];
            }
        }
        if ($record->correlationId !== null && $record->correlationId !== '') {
            $rows[] = ['label' => 'correlation_id', 'value' => $record->correlationId];
        }
        return $rows === [] ? [['label' => '—', 'value' => '']] : $rows;
    }

    /** @param array<string,mixed> $filters */
    private function renderFilters(array $filters): void
    {
        $types = $this->audit()->eventTypes();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('محدود کردن فهرست', 'tecteb-marketplace-core') . '</h2>';
        echo '<form method="get" class="tmc-filter-form">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';

        echo '<p class="tmc-field"><label class="tmc-field__label" for="tmc-audit-event">'
            . esc_html__('رویداد', 'tecteb-marketplace-core') . '</label>'
            . '<select class="tmc-select" id="tmc-audit-event" name="event">'
            . '<option value="">' . esc_html__('همه', 'tecteb-marketplace-core') . '</option>';
        foreach ($types as $type) {
            echo '<option value="' . esc_attr($type) . '"' . selected($filters['event'] ?? '', $type, false) . '>'
                . esc_html(OperationsMessages::auditEvent($type)) . '</option>';
        }
        echo '</select></p>';

        foreach ([
            ['actor', __('شناسهٔ کاربر', 'tecteb-marketplace-core'), 'number'],
            ['object_type', __('نوع موضوع', 'tecteb-marketplace-core'), 'text'],
            ['object_id', __('شناسهٔ موضوع', 'tecteb-marketplace-core'), 'text'],
            ['from', __('از تاریخ', 'tecteb-marketplace-core'), 'date'],
            ['to', __('تا تاریخ', 'tecteb-marketplace-core'), 'date'],
            ['search', __('جست‌وجو در مقادیر', 'tecteb-marketplace-core'), 'search'],
        ] as [$name, $label, $type]) {
            echo '<p class="tmc-field"><label class="tmc-field__label" for="tmc-audit-' . esc_attr($name) . '">'
                . esc_html($label) . '</label>'
                . '<input class="tmc-input" id="tmc-audit-' . esc_attr($name) . '" type="' . esc_attr($type) . '"'
                . ' name="' . esc_attr($name) . '" value="' . esc_attr((string) ($filters[$name] ?? '')) . '"></p>';
        }

        echo '<p class="tmc-field__desc">'
            . esc_html__('«جست‌وجو در مقادیر» متن آزاد است و کل جدول را می‌خواند، پس روی ردِ بزرگ کند می‌شود؛ اگر با تاریخ یا موضوع همراهش کنید، سریع می‌ماند.', 'tecteb-marketplace-core')
            . '</p>';
        echo '<button type="submit" class="tmc-button">' . esc_html__('اعمال', 'tecteb-marketplace-core') . '</button>';
        echo ' <a class="tmc-button tmc-button--quiet" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
            . esc_html__('پاک‌کردن فیلترها', 'tecteb-marketplace-core') . '</a>';
        echo '</form></section>';
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        return [
            'event' => $request->queryKey('event'),
            'actor' => $request->queryInt('actor'),
            'object_type' => $request->queryKey('object_type'),
            'object_id' => $request->queryText('object_id'),
            'from' => $request->queryText('from'),
            'to' => $request->queryText('to'),
            'search' => $request->queryText('search'),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @param callable(string|int):string $fa
     */
    private function pager(int $page, int $total, array $filters, callable $fa): string
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $query = array_filter(
            array_map(static fn ($v): string => (string) $v, $filters),
            static fn (string $v): bool => $v !== '' && $v !== '0'
        );
        $base = admin_url('admin.php?' . http_build_query(array_merge(['page' => self::SLUG], $query)));
        $out = '<nav class="tmc-pager" aria-label="' . esc_attr__('صفحه‌بندی ممیزی', 'tecteb-marketplace-core') . '">';
        if ($page > 1) {
            $out .= '<a class="tmc-button tmc-button--quiet" href="' . esc_url($base . '&paged=' . ($page - 1)) . '">'
                . esc_html__('صفحهٔ قبل', 'tecteb-marketplace-core') . '</a>';
        }
        $out .= '<span class="tmc-field__desc">' . esc_html(sprintf(
            /* translators: 1: current page, 2: total pages */
            __('صفحهٔ %1$s از %2$s', 'tecteb-marketplace-core'),
            $fa((string) $page),
            $fa((string) $pages)
        )) . '</span>';
        if ($page < $pages) {
            $out .= '<a class="tmc-button tmc-button--quiet" href="' . esc_url($base . '&paged=' . ($page + 1)) . '">'
                . esc_html__('صفحهٔ بعد', 'tecteb-marketplace-core') . '</a>';
        }
        return $out . '</nav>';
    }

    private function userLabel(int $userId): string
    {
        $user = get_userdata($userId);
        return $user === false ? '#' . $userId : (string) $user->display_name;
    }

    private function audit(): AuditRepositoryInterface
    {
        return $this->container->get(AuditRepositoryInterface::class);
    }
}
