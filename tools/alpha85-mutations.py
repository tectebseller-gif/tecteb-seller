#!/usr/bin/env python3
"""The mutations `alpha85-falsification.sh` applies, one at a time.

Each one puts back a defect this round closed. The data lives here rather
than in the shell script because every snippet is full of `$variable` and
single quotes, and a heredoc without a quoted delimiter eats them silently
(`alpha.77`).

A mutation names `suite:filter:count` — the suite that MUST go red and, for
the database suite, the one class that owns the claim, because seven full runs
of 879 tests are hours and measure the same thing. «The mutation was applied
and nothing failed» is itself a failure rather than a reassuring silence.
"""
import io
import sys

PAGE = 'src/Modules/Migration/Presentation/Admin/HandoverPage.php'
PLAN = 'src/Modules/Product/Application/CategoryLinkPlan.php'
REPO = 'src/Modules/Product/Infrastructure/DbProductRepository.php'

MUTATIONS = [
    # ---- §A: the admin-UI rule -------------------------------------------
    dict(
        name='put a version number back into a label the page shows',
        file=PAGE,
        expect='architecture::1',
        old="__('چند دسته دارد؛ مجموعهٔ کاملش ثبت می‌شود', 'tecteb-marketplace-core')",
        new="__('چند دسته دارد (از alpha.82 ثبت می‌شود، نه نگه‌داشته)', 'tecteb-marketplace-core')",
    ),
    dict(
        name='unfold one paragraph back onto the page',
        file=PAGE,
        expect='architecture::1',
        old="""            . esc_html__('تا زدن دکمه هیچ‌چیز نوشته نمی‌شود، و هیچ دسته یا رابطهٔ ووکامرس ساخته، حذف یا تغییر نمی‌شود.', 'tecteb-marketplace-core')""",
        new="""            . esc_html__('هر محصول واردشده همین حالا در ووکامرس دسته دارد یا ندارد. این کارت همان ارتباط را بر اساس شناسهٔ دسته می‌خواند — نه شباهت نام و نه حدس — و نشان می‌دهد نوشتنش چه چیزی را عوض می‌کند. تا زدن دکمه هیچ‌چیز نوشته نمی‌شود، و هیچ دسته یا رابطهٔ ووکامرس ساخته، حذف یا تغییر نمی‌شود.', 'tecteb-marketplace-core')""",
    ),
    # ---- §B1: the cursor --------------------------------------------------
    dict(
        name='ask the plan for page one, for ever',
        file=PAGE,
        expect='architecture::1',
        old="""        $after = max(0, $request->queryInt(self::CATEGORY_AFTER));
        $plan = $this->container->get(CategoryLinkPlan::class)->plan(
            CategoryLinkPlan::ROW_LIMIT,
            $after
        );""",
        new="""        $after = 0;
        $plan = $this->container->get(CategoryLinkPlan::class)->plan();""",
    ),
    dict(
        # ⛔ The first spelling of this mutation stopped applying when the link
        # was wrapped in «only when the cursor has moved», and the loop counted
        # that as a FAILURE rather than as an unbreakable guard (`alpha.77`).
        # This one targets the block as it now stands.
        name='drop the «ادامه از اینجا» link',
        file=PAGE,
        expect='architecture::1',
        old="""            if ((int) $plan['cursor'] > $after) {
                echo ' <a href="' . esc_url($this->categoryLinkUrl((int) $plan['cursor'])) . '">'
                    . esc_html__('ادامه از اینجا', 'tecteb-marketplace-core') . '</a>';
            }
""",
        new="",
    ),
    dict(
        name='stop honouring the keyset position in the repository',
        file=REPO,
        expect='database:CategoryLinkFlowTest:1',
        old="""                  WHERE import_run_id <> %s AND id > %d
                  ORDER BY id ASC LIMIT ' . max(1, min(1000, $limit)),
                ['', max(0, $afterId)]""",
        new="""                  WHERE import_run_id <> %s AND id > %d
                  ORDER BY id ASC LIMIT ' . max(1, min(1000, $limit)),
                ['', 0]""",
    ),
    # ---- §B2: the form's own cap -----------------------------------------
    dict(
        name='let the form slice to 200 in silence again',
        file=PAGE,
        expect='architecture::1',
        old="""        if (count($apply) > ApplyCategoryLinks::BATCH_LIMIT) {
            echo '<p class="tmc-field__desc">' . esc_html(sprintf(
                /* translators: 1: rows on this form 2: rows ready on this page */
                __('این فرم %1$s ردیف از %2$s ردیف آمادهٔ این صفحه را می‌برد؛ بقیه پس از ثبت همین‌ها روی همین صفحه می‌مانند.', 'tecteb-marketplace-core'),
                $fa((string) ApplyCategoryLinks::BATCH_LIMIT),
                $fa((string) count($apply))
            )) . '</p>';
        }
""",
        new="",
    ),
    # ---- §B3: the whole-catalogue figure ---------------------------------
    dict(
        # ⛔ The first version of this mutation removed the
        # `hasCategoryTable() !== true` guard, and it would have SURVIVED: with
        # the table gone the statement fails and `getVar()` answers null, so
        # the method still returns `-1`. What that guard buys is a WordPress
        # database error NOT being printed onto the page being rendered
        # (`alpha.68`), which is a different claim and is stated in the method
        # rather than asserted here. The branch that decides the number is
        # this one (`alpha.82`: a mutation that measures nothing is worse than
        # no mutation).
        name='report a failed count as nought',
        file=REPO,
        expect='database:CategoryLinkFlowTest:1',
        old="""        return $value === null ? -1 : (int) $value;
    }

    public function importedWithCategoryKeys""",
        new="""        return (int) $value;
    }

    public function importedWithCategoryKeys""",
    ),
    dict(
        name='derive the whole-catalogue figure from the page',
        file=PLAN,
        expect='database:CategoryLinkFlowTest:1',
        old="            'unrecorded' => $this->products->countImportedWithoutCategorySet(),",
        new="            'unrecorded' => count($apply) + count($held),",
    ),
    dict(
        name='drop the «could not be read» branch from the screen',
        file=PAGE,
        expect='architecture::1',
        old="""        $unrecorded = (int) $plan['unrecorded'];
        echo '<p class="tmc-field__desc">' . ($unrecorded < 0""",
        new="""        $unrecorded = (int) $plan['unrecorded'];
        echo '<p class="tmc-field__desc">' . (false""",
    ),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha85-mutations.py count|name <n>|file <n>|expect <n>|apply <n>', file=sys.stderr)
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
