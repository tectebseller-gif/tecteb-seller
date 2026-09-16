<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageReviews;
use Tecteb\Marketplace\Modules\Marketplace\Domain\RatingStatus;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\ReviewMessages;

/**
 * «نظرات — moderation و گزارش» (UX §12), the manager's side.
 *
 * Both halves in one queue, because a manager moderating reviews is doing one
 * job and «is this about a product or about a shop» is not a reason to make
 * them look in two places. They are still labelled and counted separately,
 * because they ARE separate (Master A.5) and a combined count would be the
 * number nobody asked for.
 *
 * Three decisions this page makes on purpose:
 *
 *  - **Rejecting needs a reason, approving does not.** A rejection is the one
 *    outcome somebody has to be able to explain later, and the reason is shown
 *    to the shop it concerns — so it is a required field rather than a note.
 *  - **Nothing is deleted.** A rejected rating keeps its text and its author;
 *    the shop can still read what was said about it. Moderation decides who
 *    sees something, not whether it was said.
 *  - **Product reviews are moderated THROUGH WooCommerce**, with
 *    `wp_set_comment_status()`. The button here is a shortcut into the
 *    marketplace's own subset of the comment queue, not a second store: a
 *    review approved here is approved in wp-admin's comment screen too,
 *    because it is the same row.
 */
final class ReviewsPage
{
    public const SLUG = 'tmc-reviews';
    public const CAPABILITY = Capabilities::MODERATE_REVIEWS;

    /** @var list<string> */
    public const ACTIONS = ['approve_rating', 'reject_rating', 'approve_review', 'hold_review'];

    private const NONCE = 'tmc_moderate_reviews';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('نظرات', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('اجازهٔ دیدن این صفحه را ندارید.', 'tecteb-marketplace-core'));
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $notice = $this->handlePost();

        echo '<div class="wrap tmc-admin"><h1>'
            . esc_html__('نظرها و امتیازها', 'tecteb-marketplace-core') . '</h1>';
        echo '<p class="tmc-field__desc">'
            . esc_html__('تا وقتی یک نظر تأیید نشده، در صفحهٔ عمومی دیده نمی‌شود — ولی فروشگاهِ مربوط از همان لحظه آن را می‌بیند. هیچ نظری پاک نمی‌شود؛ رد کردن یعنی نمایش داده نمی‌شود، نه اینکه گفته نشده.', 'tecteb-marketplace-core')
            . '</p>';
        if ($notice !== null) {
            echo Components::notice($notice[0], $notice[1]);
        }
        $this->renderRatingQueue($fa);
        $this->renderReviewQueue($fa);
        echo '</div>';
    }

    /** @param callable(string|int):string $fa */
    private function renderRatingQueue(callable $fa): void
    {
        $ratings = $this->container->get(ManageReviews::class)->ratingQueue(RatingStatus::Pending);
        echo '<section class="tmc-card"><h2>'
            . esc_html__('امتیاز به فروشگاه‌ها — در انتظار بررسی', 'tecteb-marketplace-core') . '</h2>';
        if ($ratings === []) {
            echo '<p>' . esc_html__('چیزی در انتظار بررسی نیست.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('امتیاز فروشگاه‌ها', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('امتیاز', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('متن', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تصمیم', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($ratings as $rating) {
            // `data-label` on every cell, because this table STACKS below the
            // container's breakpoint and the header row is hidden there. Without
            // it a phone shows a column of values behind bare colons and nobody
            // can tell the shop from the text. Measured at 390px.
            echo '<tr>'
                . '<th scope="row" data-label="' . esc_attr__('امتیاز', 'tecteb-marketplace-core') . '">'
                . esc_html($fa((string) $rating->stars) . ' ★') . '</th>'
                . '<td data-label="' . esc_attr__('متن', 'tecteb-marketplace-core') . '">'
                . esc_html($rating->body !== '' ? $rating->body : __('(بدون متن)', 'tecteb-marketplace-core')) . '</td>'
                . '<td data-label="' . esc_attr__('فروشگاه', 'tecteb-marketplace-core') . '">'
                . esc_html($this->shopName($rating->vendorUserId)) . '</td>'
                . '<td data-label="' . esc_attr__('تصمیم', 'tecteb-marketplace-core') . '">'
                . $this->ratingForm($rating->id) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function ratingForm(int $ratingId): string
    {
        $nonce = wp_nonce_field(self::NONCE, '_tmc_nonce', true, false);
        return '<form method="post" class="tmc-inline">' . $nonce
            . '<input type="hidden" name="rating_id" value="' . esc_attr((string) $ratingId) . '">'
            . '<button type="submit" name="tmc_review_action" value="approve_rating" class="button button-primary">'
            . esc_html__('تأیید', 'tecteb-marketplace-core') . '</button>'
            . '<label class="tmc-field"><span class="tmc-field__label">'
            . esc_html__('دلیل رد', 'tecteb-marketplace-core') . '</span>'
            . '<input type="text" name="reason" class="regular-text" value=""></label>'
            . '<button type="submit" name="tmc_review_action" value="reject_rating" class="button">'
            . esc_html__('رد', 'tecteb-marketplace-core') . '</button>'
            . '</form>';
    }

    /** @param callable(string|int):string $fa */
    private function renderReviewQueue(callable $fa): void
    {
        $reviews = $this->container->get(ManageReviews::class);
        $pending = $reviews->reviewQueue(false);
        echo '<section class="tmc-card"><h2>'
            . esc_html__('نظر روی محصول‌های بازارگاه — در انتظار بررسی', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-field__desc">'
            . esc_html__('این نظرها دیدگاه‌های خود ووکامرس‌اند و همین‌جا هم همان ردیف تغییر می‌کند؛ صفحهٔ دیدگاه‌های وردپرس همین را نشان می‌دهد. فقط محصولات بازارگاه اینجا می‌آیند — دیدگاه محصولات خود فروشگاه و دکان دست‌نخورده می‌مانند.', 'tecteb-marketplace-core')
            . '</p>';
        if ($pending === []) {
            echo '<p>' . esc_html__('چیزی در انتظار بررسی نیست.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('نظر محصول‌ها', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('امتیاز', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('متن', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('خرید تأییدشده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تصمیم', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($pending as $review) {
            echo '<tr>'
                . '<th scope="row" data-label="' . esc_attr__('امتیاز', 'tecteb-marketplace-core') . '">'
                . esc_html($fa((string) $review->stars) . ' ★') . '</th>'
                . '<td data-label="' . esc_attr__('متن', 'tecteb-marketplace-core') . '">'
                . esc_html($review->body) . '</td>'
                . '<td data-label="' . esc_attr__('محصول', 'tecteb-marketplace-core') . '">'
                . esc_html(get_the_title($review->wcProductId)) . '</td>'
                . '<td data-label="' . esc_attr__('خرید تأییدشده', 'tecteb-marketplace-core') . '">'
                . esc_html($review->verifiedBuyer
                    ? __('بله', 'tecteb-marketplace-core')
                    : __('خیر', 'tecteb-marketplace-core')) . '</td>'
                . '<td data-label="' . esc_attr__('تصمیم', 'tecteb-marketplace-core') . '">'
                . '<form method="post" class="tmc-inline">'
                . wp_nonce_field(self::NONCE, '_tmc_nonce', true, false)
                . '<input type="hidden" name="review_id" value="' . esc_attr((string) $review->id) . '">'
                . '<button type="submit" name="tmc_review_action" value="approve_review" class="button button-primary">'
                . esc_html__('نمایش بده', 'tecteb-marketplace-core') . '</button>'
                . '<button type="submit" name="tmc_review_action" value="hold_review" class="button">'
                . esc_html__('نگه دار', 'tecteb-marketplace-core') . '</button>'
                . '</form></td>'
                . '</tr>';
        }
        echo '</tbody></table></div></section>';
    }

    /**
     * @return array{0:string,1:string}|null tone and sentence, or nothing posted
     */
    private function handlePost(): ?array
    {
        $request = Request::capture();
        $action = $request->postKey('tmc_review_action');
        if ($action === '' || !in_array($action, self::ACTIONS, true)) {
            return null;
        }
        if (!wp_verify_nonce($request->postRaw('_tmc_nonce'), self::NONCE)) {
            return ['warning', __('درخواست معتبر نبود. دوباره تلاش کنید.', 'tecteb-marketplace-core')];
        }
        $reviews = $this->container->get(ManageReviews::class);
        $actorId = get_current_user_id();
        $result = match ($action) {
            'approve_rating' => $reviews->moderateRating($actorId, $request->postInt('rating_id'), RatingStatus::Approved),
            'reject_rating' => $reviews->moderateRating(
                $actorId,
                $request->postInt('rating_id'),
                RatingStatus::Rejected,
                $request->postText('reason')
            ),
            'approve_review' => $reviews->moderateReview($actorId, $request->postInt('review_id'), true),
            'hold_review' => $reviews->moderateReview($actorId, $request->postInt('review_id'), false),
        };
        return [
            $result->ok ? 'success' : 'warning',
            ReviewMessages::notice($result->code, $result->context) ?? $result->code,
        ];
    }

    /** The shop's display name, or its user id when it has no name yet. */
    private function shopName(int $vendorUserId): string
    {
        $user = get_userdata($vendorUserId);
        return $user ? (string) $user->display_name : '#' . $vendorUserId;
    }
}
