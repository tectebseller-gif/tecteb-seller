#!/usr/bin/env python3
"""The mutations `alpha88-falsification.sh` applies, one at a time.

Each one puts back a defect this round closed, or removes a guard this round
added. The data lives here rather than in the shell script because every
snippet is full of `$variable` and single quotes, and a heredoc without a
quoted delimiter eats them silently (`alpha.77`).

A mutation names `suite:filter:count` — the suite that MUST go red and, for
the database suite, the one class that owns the claim, because a full run of
nine hundred tests takes minutes and measures the same thing. «The mutation
was applied and nothing failed» is itself a failure rather than a reassuring
silence.
"""
import io
import sys

PAGE = 'src/Modules/Migration/Presentation/Admin/HandoverPage.php'
MAPPING = 'src/Modules/Migration/Application/CategoryMapping.php'

MUTATIONS = [
    # ---- §1 the shop on each row ------------------------------------------
    dict(
        name='drop the vendor from the held rows, as alpha.87 did',
        file=MAPPING,
        expect='database:CategoryMappingFlowTest:1',
        # ⛔ The closing bracket is part of the snippet on purpose: the `apply`
        # array two screens down carries the SAME two lines in the same order,
        # so without it this matched twice and the tool refused to apply —
        # which is the correct refusal and was counted as a failure
        # (`alpha.77`). The first run of this script found it.
        old="""                'vendor_user_id' => (int) $row['vendor_user_id'],
                'source_key' => $sourceKey,
            ];""",
        new="""                'source_key' => $sourceKey,
            ];""",
    ),
    # ---- §2 the rows themselves -------------------------------------------
    dict(
        name='print the reason and the count only, as alpha.87 did',
        file=PAGE,
        expect='database:UncategorisedProductListTest:3',
        old="""                        . '<td>' . esc_html(ProductMessages::displayTitle(
                            (string) ($row['title'] ?? ''),
                            $productId
                        )) . '</td>'""",
        new="""                        . '<td></td>'""",
    ),
    dict(
        name='stop printing the WooCommerce id',
        file=PAGE,
        # One, not two: the id is asserted inside the held table by the naming
        # test and nowhere else — the two-shop test compares shops, not ids.
        # The number is what was MEASURED rather than what I guessed.
        expect='database:UncategorisedProductListTest:1',
        old="""                        . '<td>' . ($wcId > 0
                            ? esc_html('#' . $fa((string) $wcId))
                            : esc_html__('ندارد', 'tecteb-marketplace-core')) . '</td>'""",
        new="""                        . '<td></td>'""",
    ),
    dict(
        name='stop naming the shop on the row',
        file=PAGE,
        expect='database:UncategorisedProductListTest:2',
        old="""                        . '<td>' . esc_html($this->shopName((int) ($row['vendor_user_id'] ?? 0))) . '</td>'""",
        new="""                        . '<td></td>'""",
    ),
    # ---- §6 the cap and the honest sentence --------------------------------
    dict(
        name='claim the rows are all of them whatever the scan did',
        file=PAGE,
        expect='architecture:WaitingProductsAreNamedTest:1',
        old="""            echo '<p class="tmc-field__desc">' . esc_html((bool) $plan['finished']
                ? __('این ردیف‌ها همان‌هایی‌اند که شمارندهٔ دروازه می‌شمارد.', 'tecteb-marketplace-core')""",
        new="""            echo '<p class="tmc-field__desc">' . esc_html(true
                ? __('این ردیف‌ها همان‌هایی‌اند که شمارندهٔ دروازه می‌شمارد.', 'tecteb-marketplace-core')""",
    ),
    dict(
        name='call a page that hit its cap a finished scan',
        file=MAPPING,
        expect='database:CategoryMappingFlowTest:1',
        old="""            'finished' => $total >= 0 && count($waiting) >= $total,""",
        new="""            'finished' => $total >= 0,""",
    ),
    # ---- §3 the two capabilities ------------------------------------------
    dict(
        name='open the review screen with THIS page\'s capability',
        file=PAGE,
        expect='architecture:WaitingProductsAreNamedTest:1',
        old="""            $mayReview = current_user_can(ProductReviewPage::CAPABILITY);""",
        new="""            $mayReview = current_user_can(self::CAPABILITY);""",
    ),
    dict(
        name='print the review address with no capability check at all',
        file=PAGE,
        expect='database:UncategorisedProductListTest:1',
        old="""        if (!$mayReview) {
            return '<span class="tmc-field__desc">'
                . esc_html__('مجوز بررسی محصولات را ندارید', 'tecteb-marketplace-core') . '</span>';
        }""",
        new="""""",
    ),
    # ---- §4 never a dead link ---------------------------------------------
    dict(
        name='link the editor even when the reader gave nothing',
        file=PAGE,
        expect='database:UncategorisedProductListTest:2',
        old="""        $url = $storefront->editorUrl($wcProductId);
        if ($url === '') {
            return '<span class="tmc-field__desc">'
                . esc_html__('پستش پیدا نشد یا مجوز ویرایشش را ندارید', 'tecteb-marketplace-core') . '</span>';
        }""",
        new="""        $url = $storefront->editorUrl($wcProductId);""",
    ),
    dict(
        name='offer an editor link for a row with no WooCommerce product',
        file=PAGE,
        expect='database:UncategorisedProductListTest:1',
        old="""        if ($wcProductId <= 0) {
            return '<span class="tmc-field__desc">'
                . esc_html__('محصول ووکامرسی ندارد', 'tecteb-marketplace-core') . '</span>';
        }""",
        new="""""",
    ),
    dict(
        name='stop saying WooCommerce is absent and let the null through',
        file=PAGE,
        expect='database:UncategorisedProductListTest:1',
        old="""        if ($storefront === null) {
            return '<span class="tmc-field__desc">'
                . esc_html__('ووکامرس در دسترس نیست', 'tecteb-marketplace-core') . '</span>';
        }""",
        new="""""",
    ),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha88-mutations.py count|name <n>|file <n>|expect <n>|apply <n>', file=sys.stderr)
        return 2
    verb = sys.argv[1]
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    index = int(sys.argv[2]) - 1
    if index < 0 or index >= len(MUTATIONS):
        print('no such mutation', file=sys.stderr)
        return 2
    mutation = MUTATIONS[index]
    if verb == 'name':
        print(mutation['name'])
        return 0
    if verb == 'file':
        print(mutation['file'])
        return 0
    if verb == 'expect':
        print(mutation['expect'])
        return 0
    if verb == 'apply':
        source = io.open(mutation['file'], encoding='utf-8').read()
        hits = source.count(mutation['old'])
        if hits != 1:
            # ⛔ A FAILURE, not an unbreakable guard. A mutation that does not
            # apply measured nothing, and «nothing was measured» looks exactly
            # like «this cannot be broken» (`alpha.77`).
            print('mutation did not apply: %d occurrences' % hits, file=sys.stderr)
            return 1
        io.open(mutation['file'], 'w', encoding='utf-8').write(
            source.replace(mutation['old'], mutation['new'])
        )
        return 0
    print('unknown verb', file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main())
