<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\ActionQueueMessages;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\TaskState;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorDashboard;
use Tecteb\Marketplace\Modules\Vendor\Application\WorkspaceTask;

/**
 * The vendor's first screen — two screens, really, and they are not the same
 * page.
 *
 * **Somebody still applying** opens on their application: it IS their job, so
 * the status comes first and the steps are the page.
 *
 * **An approved shop** opens on its work. Until `alpha.33` it did not: the
 * largest thing on the page was the list of registration steps, all of them
 * finished, and the approval was announced twice — once as «وضعیت درخواست شما»
 * and once again as the «نتیجه بررسی» task below it. The owner's words:
 * «پیشخوان فعلی بیشتر فضای خود را به مراحل ثبت‌نامِ انجام‌شده اختصاص می‌دهد و
 * تأیید فروشگاه را دوبار نمایش می‌دهد».
 *
 * The order for an approved shop is now the order of the questions somebody
 * asks when they open it:
 *
 *  1. which shop is this, and may it sell? — with the two things they came to
 *     do beside it;
 *  2. how many products, in what state? — four numbers, each a link into the
 *     list filtered to exactly that state;
 *  3. what do I have to do? — the manager's own words on the products sent
 *     back, and any paperwork that is genuinely missing;
 *  4. what is somebody else doing? — kept apart, because «۳ محصول در انتظار
 *     بررسی» is news, not a task;
 *  5. the paperwork, folded away, where it can be checked when somebody wants
 *     it rather than read again every morning.
 *
 * A warning look is reserved for something that actually needs attention: with
 * nothing to do the page says so in one plain sentence and moves on.
 */
final class DashboardView
{
    /**
     * @param list<array{key:string, count:int, tone:string, url:string}> $queue
     *        «صف اقدام» (UX §6). Empty is the normal case and prints nothing:
     *        a queue that says «۰ سفارش تازه» teaches people to stop reading
     *        it, so only buckets with something in them appear at all.
     */
    public static function render(
        VendorDashboard $dashboard,
        VendorUrls $urls,
        ?VendorNotice $notice = null,
        array $queue = []
    ): string {
        $workspace = $dashboard->workspace;
        $html = '';
        if ($notice !== null) {
            $html .= VendorUi::notice(
                VendorMessages::isErrorNotice($notice->code) ? 'warning' : 'success',
                VendorMessages::notice($notice->code, $notice->context)
            );
        }

        // The SHOP, not the viewer: a staff member has no application of their
        // own, and asking about theirs put «هنوز درخواستی ثبت نکرده‌اید» and a
        // «شروع درخواست فروشندگی» button above the real work of the shop they
        // already work in.
        if (!$dashboard->shopIsOpen()) {
            return $html . self::applicantPage($dashboard, $urls, $queue);
        }

        return $html
            . self::shopHeader($dashboard, $urls)
            . self::numbers($dashboard, $urls)
            . self::todo($dashboard, $urls, $queue)
            . self::waiting($dashboard, $urls, $queue)
            . self::quickLinks($dashboard, $urls)
            . self::paperwork($dashboard, $urls);
    }

    /**
     * Which shop, whether it may sell, and the two things its owner came to do.
     *
     * «مشاهده فروشگاه» appears only when there is a page to open. A shop whose
     * settings row has not been created yet has an address that answers 404
     * (`StorePage` refuses it), and a button that leads to a 404 is worse than
     * no button: it reads as a broken site rather than as an unfinished setup.
     */
    private static function shopHeader(VendorDashboard $dashboard, VendorUrls $urls): string
    {
        // The SHOP's state, from the ONE mapping the applicant screen also
        // uses: «وضعیت واقعی تأیید». Reading it twice in two words is how the
        // two screens end up describing the same shop differently.
        $status = $dashboard->shopStatus;

        $html = '<section class="tv-card tv-shop" aria-labelledby="tv-shop-name">'
            . '<div class="tv-card__head">'
            . '<h2 id="tv-shop-name" class="tv-card__title">' . esc_html($dashboard->storeName) . '</h2>'
            . VendorUi::chip(VendorMessages::statusTone($status), VendorMessages::status($status))
            . '</div>';
        if (!$dashboard->shopCanSell) {
            // Approved on paper and unable to sell is a real combination, and
            // the chip above cannot say it: the status is the application's,
            // `canSell` is the operational key (`alpha.17`).
            $html .= '<p class="tv-hint tv-hint--warn">'
                . esc_html__('اجازهٔ فروش برای این فروشگاه صادر نشده است؛ محصول‌ها به فروش نمی‌روند.', 'tecteb-marketplace-core')
                . '</p>';
        }

        $actions = '';
        if ($dashboard->mayEditProducts) {
            $actions .= VendorUi::button($urls->product(0), __('افزودن محصول', 'tecteb-marketplace-core'));
        }
        if ($dashboard->storefrontUrl !== '') {
            $actions .= VendorUi::button($dashboard->storefrontUrl, __('مشاهده فروشگاه', 'tecteb-marketplace-core'), 'secondary');
        }
        if ($actions !== '') {
            $html .= '<p class="tv-actions">' . $actions . '</p>';
        }
        if (!$dashboard->canPublishDirectly && $dashboard->mayEditProducts) {
            // Said once, here, in the words the owner asked for — and nothing
            // about the permission itself changes. Only to somebody who can
            // actually save a product: it is a sentence about what happens
            // when you press «ارسال», and to a staff member who may not touch
            // products it is a rule about work they do not do.
            $html .= '<p class="tv-hint">' . esc_html__('محصولات شما پس از تأیید مدیر منتشر می‌شوند.', 'tecteb-marketplace-core') . '</p>';
        }
        return $html . '</section>';
    }

    /**
     * Four numbers, each one a way into the list that holds them.
     *
     * The counts come from the shop's own `countsByStatus()` — the same query
     * the product list's tabs use — so a card and the page it opens can never
     * show two different totals.
     */
    private static function numbers(VendorDashboard $dashboard, VendorUrls $urls): string
    {
        if (!$dashboard->mayViewProducts) {
            return '';
        }
        $html = '<section class="tv-card" aria-labelledby="tv-numbers">'
            . '<div class="tv-card__head">'
            . '<h2 id="tv-numbers" class="tv-card__title">' . esc_html__('محصولات شما', 'tecteb-marketplace-core') . '</h2>'
            . '</div>';
        if (!$dashboard->countsRead()) {
            // Four zeros would be a lie told to a shop with sixty products.
            return $html . VendorUi::state(
                'error',
                __('شمارش محصول‌ها خوانده نشد.', 'tecteb-marketplace-core'),
                __('فهرست محصول‌ها سر جایش است؛ فقط این چهار عدد در این لحظه خوانده نشدند.', 'tecteb-marketplace-core'),
                [['href' => $urls->products(), 'label' => __('رفتن به فهرست محصولات', 'tecteb-marketplace-core'), 'primary' => true]]
            ) . '</section>';
        }
        $html .= '<ul class="tv-numbers__grid">';
        foreach ([
            ProductStatus::Published,
            ProductStatus::Submitted,
            ProductStatus::ChangesRequested,
            ProductStatus::Draft,
        ] as $status) {
            $count = $dashboard->countOf($status);
            $html .= '<li><a class="tv-number' . ($count === 0 ? ' is-empty' : '')
                . ' tv-number--' . esc_attr(ProductMessages::statusTone($status)) . '"'
                . ' href="' . esc_url($urls->productsInStatus($status->value)) . '">'
                . '<span class="tv-number__value">' . esc_html(PersianDigits::toPersian((string) $count)) . '</span>'
                . '<span class="tv-number__label">' . esc_html(ProductMessages::status($status)) . '</span>'
                . '</a></li>';
        }
        return $html . '</ul></section>';
    }

    /**
     * What the VENDOR has to do — and nothing that is merely happening.
     *
     * @param list<array{key:string, count:int, tone:string, url:string}> $queue
     */
    private static function todo(VendorDashboard $dashboard, VendorUrls $urls, array $queue): string
    {
        $rows = '';
        foreach ($dashboard->needsWork as $product) {
            $rows .= '<li class="tv-todo__row">'
                . '<h3 class="tv-todo__title">' . esc_html($product['title'] !== ''
                    ? $product['title']
                    : sprintf(
                        /* translators: %s: the product's id */
                        __('محصول %s', 'tecteb-marketplace-core'),
                        PersianDigits::toPersian((string) $product['id'])
                    )) . '</h3>'
                . '<p class="tv-todo__reason">'
                . ($product['note'] !== ''
                    ? esc_html(sprintf(
                        /* translators: %s: the manager's own words */
                        __('مدیر نوشته است: «%s»', 'tecteb-marketplace-core'),
                        $product['note']
                    ))
                    : esc_html__('مدیر این محصول را برای اصلاح برگردانده است. دلیلش بالای فرم ویرایش آمده است.', 'tecteb-marketplace-core'))
                . '</p>'
                . '<div class="tv-actions">'
                . VendorUi::button($urls->product($product['id']), __('اصلاح این محصول', 'tecteb-marketplace-core'))
                . '</div></li>';
        }

        // The ones not named above, in one line. The counter card already says
        // the true total, so this exists to make the list's INCOMPLETENESS
        // visible — a screen that shows five of seven and says nothing is a
        // screen that hides two products.
        $shown = count($dashboard->needsWork);
        $total = $dashboard->countsRead() ? $dashboard->countOf(ProductStatus::ChangesRequested) : $shown;
        if ($total > $shown) {
            $rows .= '<li class="tv-todo__row tv-todo__row--queue"><a href="'
                . esc_url($urls->productsInStatus(ProductStatus::ChangesRequested->value)) . '">'
                . esc_html(sprintf(
                    /* translators: %s: how many more products the manager sent back */
                    __('و %s محصول دیگر که مدیر برای اصلاح برگردانده است', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) ($total - $shown))
                )) . '</a></li>';
        }

        // The vendor's own unfinished paperwork — and only the owner's, since a
        // staff member cannot edit the application at all.
        if ($dashboard->isOwner) {
            foreach ($dashboard->openTasks() as $task) {
                $rows .= self::taskRow($task, $urls);
            }
        }

        // Rows from the shop's live queue that the vendor can act on. The
        // «محصول نیازمند اصلاح» bucket is deliberately left out: those products
        // are listed above, one by one, with the reason — showing the count as
        // well would be the same news twice.
        foreach ($queue as $row) {
            if ((string) $row['key'] === 'products_need_work') {
                continue;
            }
            $rows .= '<li class="tv-todo__row tv-todo__row--queue"><a href="' . esc_url((string) $row['url']) . '">'
                . VendorUi::chip((string) $row['tone'], PersianDigits::toPersian((string) (int) $row['count']))
                . ' ' . esc_html(ActionQueueMessages::label((string) $row['key'])) . '</a></li>';
        }

        // The warning look is on `has-work` and nowhere else: «ظاهر هشدارگونه
        // فقط برای مواردی که واقعاً توجه لازم دارند». An empty card wearing an
        // amber edge every morning is how the edge stops meaning anything.
        $html = '<section class="tv-card tv-todo' . ($rows !== '' ? ' has-work' : '') . '" aria-labelledby="tv-todo">'
            . '<h2 id="tv-todo" class="tv-card__title">' . esc_html__('نیازمند اقدام', 'tecteb-marketplace-core') . '</h2>';
        if ($rows === '') {
            // Plain, and deliberately not a success banner: nothing happened,
            // so nothing is being celebrated.
            return $html . '<p class="tv-lead">'
                . esc_html__('در حال حاضر کاری از طرف شما لازم نیست.', 'tecteb-marketplace-core')
                . '</p></section>';
        }
        return $html . '<ul class="tv-todo__list">' . $rows . '</ul></section>';
    }

    private static function taskRow(WorkspaceTask $task, VendorUrls $urls): string
    {
        $text = VendorMessages::task($task);
        $html = '<li class="tv-todo__row">'
            . '<h3 class="tv-todo__title">' . esc_html($text['title']) . '</h3>'
            . '<p class="tv-todo__reason">' . esc_html($text['detail']) . '</p>';
        if ($task->action !== null && $text['action'] !== '') {
            $html .= '<div class="tv-actions">'
                . VendorUi::button($urls->forRoute($task->action), $text['action'])
                . '</div>';
        }
        return $html . '</li>';
    }

    /**
     * What somebody ELSE is holding — separated on purpose.
     *
     * «منتظر تصمیم مدیر» is not a task and must not look like one: no buttons,
     * no warning colour, and it disappears entirely when there is nothing
     * waiting.
     *
     * @param list<array{key:string, count:int, tone:string, url:string}> $queue
     */
    private static function waiting(VendorDashboard $dashboard, VendorUrls $urls, array $queue): string
    {
        $rows = '';
        $submitted = $dashboard->countsRead() ? $dashboard->waitingOnManager() : 0;
        if ($submitted > 0 && $dashboard->mayViewProducts) {
            $rows .= '<li><a href="' . esc_url($urls->productsInStatus(ProductStatus::Submitted->value)) . '">'
                . esc_html(sprintf(
                    /* translators: %s: how many products are in review */
                    __('%s محصول در انتظار بررسی مدیر است.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $submitted)
                )) . '</a></li>';
        }
        if ($dashboard->isOwner) {
            foreach ($dashboard->waitingOnManagerTasks() as $task) {
                $text = VendorMessages::task($task);
                $rows .= '<li><strong>' . esc_html($text['title']) . '</strong> — ' . esc_html($text['detail']) . '</li>';
            }
        }
        if ($rows === '') {
            return '';
        }
        return '<section class="tv-card tv-waiting" aria-labelledby="tv-waiting">'
            . '<h2 id="tv-waiting" class="tv-card__title">' . esc_html__('منتظر تصمیم مدیر', 'tecteb-marketplace-core') . '</h2>'
            . '<ul class="tv-waiting__list">' . $rows . '</ul></section>';
    }

    private static function quickLinks(VendorDashboard $dashboard, VendorUrls $urls): string
    {
        $links = '';
        if ($dashboard->mayViewProducts) {
            $links .= VendorUi::button($urls->products(), __('مدیریت محصولات', 'tecteb-marketplace-core'), 'secondary');
        }
        if ($dashboard->supportUrl !== '') {
            $links .= VendorUi::button($dashboard->supportUrl, __('پشتیبانی', 'tecteb-marketplace-core'), 'secondary');
        }
        if ($links === '') {
            return '';
        }
        return '<section class="tv-card" aria-labelledby="tv-quick">'
            . '<h2 id="tv-quick" class="tv-card__title">' . esc_html__('دسترسی سریع', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-actions">' . $links . '</p></section>';
    }

    /**
     * The registration, folded away — with the link to the file inside it.
     *
     * A `<details>` and not a script: the panel renders no executable markup,
     * and paperwork that needs JavaScript to be read is paperwork that is gone
     * on the day JavaScript fails. Anything still genuinely missing is NOT in
     * here — it is in «نیازمند اقدام» above, where it can be acted on.
     */
    private static function paperwork(VendorDashboard $dashboard, VendorUrls $urls): string
    {
        if (!$dashboard->isOwner) {
            // A staff member has no application of their own to look at, and
            // the shop's file is the owner's paperwork.
            return '';
        }
        $html = '<details class="tv-card tv-fold">'
            . '<summary class="tv-fold__summary">' . esc_html__('اطلاعات و مدارک فروشگاه', 'tecteb-marketplace-core') . '</summary>'
            . '<ul class="tv-fold__list">';
        foreach ($dashboard->settledTasks() as $task) {
            $text = VendorMessages::task($task);
            $html .= '<li class="tv-fold__row">'
                . VendorUi::chip(VendorMessages::taskStateTone($task->state), VendorMessages::taskStateLabel($task->state))
                . ' <strong>' . esc_html($text['title']) . '</strong> — ' . esc_html($text['detail'])
                . '</li>';
        }
        return $html . '</ul>'
            . '<p class="tv-fold__link"><a href="' . esc_url($urls->application()) . '">'
            . esc_html__('پروندهٔ فروشندگی و مدارک', 'tecteb-marketplace-core') . '</a></p>'
            . '</details>';
    }

    /**
     * Somebody still applying: the application is the page, exactly as before.
     *
     * @param list<array{key:string, count:int, tone:string, url:string}> $queue
     */
    private static function applicantPage(VendorDashboard $dashboard, VendorUrls $urls, array $queue): string
    {
        $workspace = $dashboard->workspace;
        $status = $workspace->status();
        $hasApplication = $workspace->application !== null;

        $html = '<section class="tv-card tv-card--status" aria-labelledby="tv-status">'
            . '<div class="tv-card__head">'
            . '<h2 id="tv-status" class="tv-card__title">' . esc_html__('وضعیت درخواست شما', 'tecteb-marketplace-core') . '</h2>'
            . VendorUi::chip(VendorMessages::statusTone($status), VendorMessages::status($status))
            . '</div>'
            . '<p class="tv-lead">' . esc_html(VendorMessages::statusHeadline($status, $hasApplication)) . '</p>';

        $note = $workspace->application?->reviewNote;
        if ($note !== null && trim($note) !== '' && in_array($status->value, ['changes_requested', 'rejected', 'suspended'], true)) {
            $html .= '<div class="tv-note"><h3>' . esc_html__('یادداشت مدیر بازارگاه', 'tecteb-marketplace-core') . '</h3>'
                . '<p>' . esc_html($note) . '</p></div>';
        }

        $primary = self::primaryAction($workspace, $urls);
        if ($primary !== '') {
            $html .= '<div class="tv-actions">' . $primary . '</div>';
        }
        $html .= '</section>' . self::actionQueue($queue);

        $html .= '<section class="tv-card" aria-labelledby="tv-tasks">'
            . '<h2 id="tv-tasks" class="tv-card__title">' . esc_html__('کارهای شما', 'tecteb-marketplace-core') . '</h2>'
            . '<ul class="tv-tasks">';
        foreach ($workspace->tasks() as $task) {
            $text = VendorMessages::task($task);
            $html .= '<li class="tv-task tv-task--' . esc_attr($task->state->value) . '">'
                . '<div class="tv-task__head">'
                . '<h3 class="tv-task__title">' . esc_html($text['title']) . '</h3>'
                . VendorUi::chip(VendorMessages::taskStateTone($task->state), VendorMessages::taskStateLabel($task->state))
                . '</div>'
                . '<p class="tv-task__detail">' . esc_html($text['detail']) . '</p>';
            if ($task->action !== null && $text['action'] !== '') {
                $variant = $task->state === TaskState::Todo ? 'primary' : 'secondary';
                $html .= '<div class="tv-actions">' . VendorUi::button($urls->forRoute($task->action), $text['action'], $variant) . '</div>';
            }
            $html .= '</li>';
        }
        return $html . '</ul></section>';
    }

    /**
     * @param list<array{key:string, count:int, tone:string, url:string}> $queue
     */
    private static function actionQueue(array $queue): string
    {
        if ($queue === []) {
            return '';
        }
        $fa = static fn (int $v): string => PersianDigits::toPersian((string) $v);
        $html = '<section class="tv-card" aria-labelledby="tv-queue">'
            . '<h2 id="tv-queue" class="tv-card__title">'
            . esc_html__('صف اقدام', 'tecteb-marketplace-core') . '</h2><ul class="tv-queue">';
        foreach ($queue as $row) {
            $html .= '<li class="tv-queue__row"><a href="' . esc_url((string) $row['url']) . '">'
                . VendorUi::chip((string) $row['tone'], $fa((int) $row['count']))
                . ' ' . esc_html(ActionQueueMessages::label((string) $row['key'])) . '</a></li>';
        }
        return $html . '</ul></section>';
    }

    private static function primaryAction(\Tecteb\Marketplace\Modules\Vendor\Application\VendorWorkspace $workspace, VendorUrls $urls): string
    {
        if ($workspace->application === null) {
            return VendorUi::button($urls->application(), __('شروع درخواست فروشندگی', 'tecteb-marketplace-core'));
        }
        return match ($workspace->status()->value) {
            'draft' => VendorUi::button($urls->application(), __('ادامه تکمیل درخواست', 'tecteb-marketplace-core')),
            'changes_requested' => VendorUi::button($urls->application(), __('اصلاح و ارسال دوباره', 'tecteb-marketplace-core')),
            default => VendorUi::button($urls->application(), __('مشاهده درخواست', 'tecteb-marketplace-core'), 'secondary'),
        };
    }
}
