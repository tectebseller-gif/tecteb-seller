<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageReviews;
use Tecteb\Marketplace\Modules\Marketplace\Domain\ProductReview;
use Tecteb\Marketplace\Modules\Marketplace\Domain\VendorRating;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\Charts;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\ReviewMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * /vendor/reviews/ — «نظرات» (UX §4.1), and the whole of what a shop may do
 * with them.
 *
 * The page has exactly one control: a reply box. No approve, no reject, no
 * edit, no delete, and no way to reach one by changing a hidden field — the
 * service behind it has no such method to reach. «فروشنده فقط پاسخ می‌دهد»
 * (Master A.5) is a sentence about a shop's power, so it is enforced where
 * power lives rather than by leaving a button out of a template.
 *
 * The two standings sit at the top, apart and labelled, because «امتیاز محصول
 * و فروشنده جداست» is invisible if the numbers are printed next to each other
 * without saying so.
 *
 * A pending rating IS shown to the shop, with its state. A shop learning what
 * a customer said only once the public already knows would be the wrong way
 * round — and a shop that can see it early cannot do anything about it except
 * answer, which is the point.
 */
final class ReviewArea
{
    public const SLUG = 'reviews';

    /** @var list<string> */
    public const ACTIONS = ['reply_review', 'reply_rating'];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function render(VendorAreaView $view): string
    {
        $vendorUserId = $this->container->get(StaffAccess::class)->storeFor($view->userId);
        if ($vendorUserId === null) {
            return '';
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $html = '';
        if ($view->notice !== null) {
            $html .= VendorUi::notice(
                in_array($view->notice->code, ReviewMessages::errorCodes(), true) ? 'warning' : 'success',
                ReviewMessages::notice($view->notice->code, $view->notice->context) ?? $view->notice->code
            );
        }
        $html .= VendorUi::notice('info', ReviewMessages::vendorScopeNote());
        return $html
            . $this->standingSection($vendorUserId, $fa)
            . $this->productReviewSection($view, $vendorUserId, $fa)
            . $this->vendorRatingSection($view, $vendorUserId, $fa);
    }

    /** @param callable(string|int):string $fa */
    private function standingSection(int $vendorUserId, callable $fa): string
    {
        $standing = $this->container->get(ManageReviews::class)->standing($vendorUserId);
        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('امتیاز فروشگاه شما', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">' . esc_html(ReviewMessages::separateAveragesNote()) . '</p>'
            . '<dl class="tv-figures">';

        $html .= '<div class="tv-figure"><dt>' . esc_html__('امتیاز محصول‌ها', 'tecteb-marketplace-core') . '</dt><dd>';
        if (!$standing['product']['available']) {
            // Not «۰»: a figure nobody could read is not a figure of zero.
            $html .= esc_html__('خوانده نشد', 'tecteb-marketplace-core');
        } else {
            $html .= esc_html(ReviewMessages::stars($standing['product']['average_hundredths'])
                . ' (' . ReviewMessages::count($standing['product']['count']) . ')');
        }
        $html .= '</dd></div>';

        $html .= '<div class="tv-figure"><dt>' . esc_html__('امتیاز خودِ فروشگاه', 'tecteb-marketplace-core') . '</dt><dd>'
            . esc_html(ReviewMessages::stars($standing['vendor']['average_hundredths'])
                . ' (' . ReviewMessages::count($standing['vendor']['count']) . ')')
            . '</dd></div></dl>';

        // The histogram, which is the one place «۴٫۲» stops being a number and
        // starts being something a shop can act on: four fives and one one is
        // a different shop from five fours.
        $html .= $this->distribution($standing['vendor']['distribution'], $standing['vendor']['count'], $fa);
        return $html . '</section>';
    }

    /**
     * The histogram, drawn by the same component the reports use.
     *
     * One chart implementation in this plugin, not two. An earlier draft of
     * this page had its own CSS bars, which would have meant two things to
     * keep accessible, two things to keep RTL and two things to fix the next
     * time one of them was wrong.
     *
     * @param array<int,int> $distribution
     * @param callable(string|int):string $fa
     */
    private function distribution(array $distribution, int $total, callable $fa): string
    {
        if ($total <= 0) {
            return '<p class="tv-hint">' . esc_html__('هنوز امتیاز تأییدشده‌ای برای فروشگاه ثبت نشده است.', 'tecteb-marketplace-core') . '</p>';
        }
        $rows = [];
        for ($star = VendorRating::MAX_STARS; $star >= VendorRating::MIN_STARS; $star--) {
            $rows[] = [
                'label' => sprintf(
                    /* translators: %s: a star count from 1 to 5 */
                    __('%s ستاره', 'tecteb-marketplace-core'),
                    $fa((string) $star)
                ),
                'value' => (int) ($distribution[$star] ?? 0),
            ];
        }
        return Charts::card(
            __('پراکندگی امتیاز فروشگاه', 'tecteb-marketplace-core'),
            $rows,
            __('چند نفر چه امتیازی داده‌اند. فقط امتیازهای تأییدشده شمرده می‌شوند.', 'tecteb-marketplace-core')
        );
    }

    /** @param callable(string|int):string $fa */
    private function productReviewSection(VendorAreaView $view, int $vendorUserId, callable $fa): string
    {
        $reviews = $this->container->get(ManageReviews::class)
            ->reviewsForVendor($view->userId, $vendorUserId, null, 30);
        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('نظر روی محصول‌های شما', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">'
            . esc_html__('این نظرها در خود ووکامرس ثبت می‌شوند و همان‌جا هم روی صفحهٔ محصول دیده می‌شوند؛ اینجا فقط پاسخ می‌دهید.', 'tecteb-marketplace-core')
            . '</p>';
        if ($reviews === []) {
            return $html . '<p>' . esc_html__('نظری روی محصول‌های این فروشگاه ثبت نشده است.', 'tecteb-marketplace-core')
                . '</p></section>';
        }
        $html .= '<ul class="tv-reviews" role="list">';
        foreach ($reviews as $review) {
            $html .= $this->reviewItem($view, $review, $fa);
        }
        return $html . '</ul></section>';
    }

    /** @param callable(string|int):string $fa */
    private function reviewItem(VendorAreaView $view, ProductReview $review, callable $fa): string
    {
        $html = '<li class="tv-review">'
            . '<p class="tv-review__meta">'
            . '<strong>' . esc_html($review->authorName !== '' ? $review->authorName : __('خریدار', 'tecteb-marketplace-core')) . '</strong> '
            . '<span class="tv-review__stars">' . esc_html($fa((string) $review->stars) . ' ★') . '</span> '
            . VendorUi::chip($review->approved ? 'success' : 'warning', $review->approved
                ? __('نمایش‌داده‌شده', 'tecteb-marketplace-core')
                : __('در انتظار بررسی مدیر', 'tecteb-marketplace-core'));
        if ($review->verifiedBuyer) {
            $html .= ' ' . VendorUi::chip('info', __('خرید تأییدشده', 'tecteb-marketplace-core'));
        }
        $html .= '</p><p class="tv-review__body">' . esc_html($review->body) . '</p>';
        if ($review->hasReply()) {
            $html .= '<p class="tv-review__reply"><strong>'
                . esc_html__('پاسخ شما:', 'tecteb-marketplace-core') . '</strong> '
                . esc_html($review->reply) . '</p>';
            return $html . '</li>';
        }
        $html .= '<form method="post" class="tv-reply">' . $view->nonceField
            . '<input type="hidden" name="tmc_vendor_action" value="reply_review">'
            . '<input type="hidden" name="review_id" value="' . esc_attr((string) $review->id) . '">'
            . VendorUi::textarea('reply_body', __('پاسخ فروشگاه', 'tecteb-marketplace-core'), '', true,
                __('یک پاسخ برای هر نظر. پس از ثبت ویرایش نمی‌شود.', 'tecteb-marketplace-core'))
            . VendorUi::submit(__('ثبت پاسخ', 'tecteb-marketplace-core'), 'secondary')
            . '</form>';
        return $html . '</li>';
    }

    /** @param callable(string|int):string $fa */
    private function vendorRatingSection(VendorAreaView $view, int $vendorUserId, callable $fa): string
    {
        $ratings = $this->container->get(ManageReviews::class)
            ->ratingsForVendor($view->userId, $vendorUserId, null, 30);
        $html = '<section class="tv-card"><h2 class="tv-card__title">'
            . esc_html__('امتیاز به خودِ فروشگاه', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">' . esc_html(ReviewMessages::pendingVisibilityNote()) . '</p>';
        if ($ratings === []) {
            return $html . '<p>' . esc_html__('هنوز کسی به این فروشگاه امتیاز نداده است.', 'tecteb-marketplace-core')
                . '</p></section>';
        }
        $html .= '<ul class="tv-reviews" role="list">';
        foreach ($ratings as $rating) {
            $html .= '<li class="tv-review">'
                . '<p class="tv-review__meta">'
                . '<span class="tv-review__stars">' . esc_html($fa((string) $rating->stars) . ' ★') . '</span> '
                . VendorUi::chip(ReviewMessages::statusTone($rating->status), ReviewMessages::statusLabel($rating->status))
                . ' <time datetime="' . esc_attr($rating->createdAt) . '">' . esc_html($fa($rating->createdAt)) . '</time>'
                . '</p>'
                . '<p class="tv-review__body">' . esc_html($rating->body) . '</p>';
            if ($rating->moderationReason !== '') {
                // The manager's reason, shown to the shop it is about. A
                // decision a shop cannot see is a decision it cannot learn from.
                $html .= '<p class="tv-hint">' . esc_html(sprintf(
                    /* translators: %s: the manager's stated reason */
                    __('دلیل مدیر: %s', 'tecteb-marketplace-core'),
                    $rating->moderationReason
                )) . '</p>';
            }
            if ($rating->hasReply()) {
                $html .= '<p class="tv-review__reply"><strong>'
                    . esc_html__('پاسخ شما:', 'tecteb-marketplace-core') . '</strong> '
                    . esc_html($rating->reply) . '</p>';
            } else {
                $html .= '<form method="post" class="tv-reply">' . $view->nonceField
                    . '<input type="hidden" name="tmc_vendor_action" value="reply_rating">'
                    . '<input type="hidden" name="rating_id" value="' . esc_attr((string) $rating->id) . '">'
                    . VendorUi::textarea('reply_body', __('پاسخ فروشگاه', 'tecteb-marketplace-core'), '', true, '')
                    . VendorUi::submit(__('ثبت پاسخ', 'tecteb-marketplace-core'), 'secondary')
                    . '</form>';
            }
            $html .= '</li>';
        }
        return $html . '</ul></section>';
    }

    public function handle(string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }
        $vendorUserId = $this->container->get(StaffAccess::class)->storeFor($userId);
        if ($vendorUserId === null) {
            return new VendorAreaOutcome('forbidden', $this->reviewsUrl());
        }
        $reviews = $this->container->get(ManageReviews::class);
        $body = $request->postTextarea('reply_body');
        $result = $action === 'reply_review'
            ? $reviews->replyToReview($userId, $vendorUserId, $request->postInt('review_id'), $body)
            : $reviews->replyToRating($userId, $vendorUserId, $request->postInt('rating_id'), $body);
        return new VendorAreaOutcome($result->code, $this->reviewsUrl(), $result->context);
    }

    public function reviewsUrl(): string
    {
        return (string) get_option('permalink_structure', '') !== ''
            ? home_url('/vendor/' . self::SLUG . '/')
            : home_url('/?tmc_vendor=' . self::SLUG);
    }
}
