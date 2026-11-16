#!/usr/bin/env python3
"""
This round's mutations: each one takes away exactly one thing the mandate
asked for, and names the suite that must go red.

⛔ The database suite is run with a `--filter` per mutation, not whole: a
twelve-minute suite per mutation is twelve minutes of wall clock that buys
nothing, and the filter names the test that is supposed to notice.

Run with `-I` so nothing in the working directory can be imported.
"""
import io
import sys

VIEW = 'src/Modules/Product/Presentation/ProductConflictView.php'
SUMMARY = 'src/Modules/Product/Domain/FieldSummary.php'
RULE = 'src/Modules/Product/Domain/ProductTypeChange.php'
MANAGE = 'src/Modules/Product/Application/ManageProducts.php'
REVIEW = 'src/Modules/Product/Application/ReviewProducts.php'
MESSAGES = 'src/Modules/Product/Presentation/ProductMessages.php'
PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php'
CSS = 'assets/vendor/tmc-vendor.css'

MUTATIONS = [
    # ---------------------------------------------------------------- §2
    # 1 — the defect itself, put back.
    dict(name='the long value is dumped into the cell again, as alpha.106 did',
         file=VIEW, expect='contract',
         old="""        if (!FieldSummary::needsFolding($value)) {
            return $mine ? '<strong>' . esc_html($value) . '</strong>' : esc_html($value);
        }""",
         new="""        return $mine ? '<strong>' . esc_html($value) . '</strong>' : esc_html($value);"""),
    # 2
    dict(name='the type row is dropped from the comparison',
         file=VIEW, expect='contract',
         old="""            'type' => [ProductMessages::type($stored->type), ProductMessages::type($typed->type)],\n""",
         new=''),
    # 3
    dict(name='a proposal is given the refusal\'s next-step sentence again',
         file=VIEW, expect='contract',
         old="""        $proposal = $purpose === self::PURPOSE_PROPOSAL;""",
         new="""        $proposal = false;"""),
    # 4
    dict(name='nothing is ever long enough to fold',
         file=SUMMARY, expect='unit + contract',
         old="""        return self::carriesMarkup($value) || mb_strlen($value) > self::FOLD_CHARS;""",
         new="""        return false;"""),
    # 5
    dict(name='the folded body keeps its markup instead of being readable text',
         file=SUMMARY, expect='unit',
         old="""        $out = strip_tags($out);""",
         new="""        $out = $out;"""),
    # 6
    dict(name='the preview is not cut, so a cell gets the whole thing',
         file=SUMMARY, expect='unit',
         old="""        if (mb_strlen($flat) <= self::PREVIEW_CHARS) {
            return $flat;
        }""",
         new="""        return $flat;"""),
    # 7 — the CSS contract (alpha.32's root cause).
    #
    # REWRITTEN AFTER THE FIRST RUN, AND THE FIRST VERSION IS WORTH KEEPING IN
    # WRITING. It renamed `.tv-fold__peek {`, which the sheet contained TWICE
    # (the rule, and the `--mine` compound one line later), so the driver
    # refused to apply it — a FAILURE, not an unbreakable guard. And the
    # obvious repair would have measured nothing: the architecture guard asks
    # whether the NAME appears anywhere in the sheet, so renaming one of two
    # occurrences leaves it present and the suite stays green. A mutation must
    # break the same claim, not part of it (`alpha.82`). The folded body's
    # class occurs once and carries the scroll cap — `max-height: 18rem;
    # overflow-y: auto` — so losing its rule is the same family of defect the
    # fold was built to remove: a cell that grows without bound.
    dict(name='the folded body loses its rule, so nothing caps its height',
         file=CSS, expect='architecture',
         old=""".tv-long__body {""",
         new=""".tv-long__body-renamed {"""),

    # ---------------------------------------------------------------- §3
    # 8
    dict(name='the type rule permits everything',
         file=RULE, expect='unit + database:ImportedParentFiguresTest',
         old="""        if (!$figuresAreOurs) {
            return self::NOT_OURS;
        }
        return $linkedToShop ? self::ALREADY_IN_SHOP : null;""",
         new="""        return null;"""),
    # 9
    dict(name='only the imported half of the rule survives',
         file=RULE, expect='unit + database:ImportedParentFiguresTest',
         old="""        return $linkedToShop ? self::ALREADY_IN_SHOP : null;""",
         new="""        return null;"""),
    # 10
    dict(name='ManageProducts stops asking the rule',
         file=MANAGE, expect='database:ImportedParentFiguresTest',
         old="""        $typeChange = $this->typeChangeRefusal($product, $details);
        if ($typeChange !== null) {
            return $typeChange;
        }""",
         new=''),
    # 11 — alpha.98: a guard that holds only because an earlier verb asks first.
    dict(name='the approval path stops asking the rule, leaving only one point',
         file=REVIEW, expect='database:ImportedParentFiguresTest',
         old="""        $typeChange = $this->typeChangeRefusal($product, $revision->payload, $revisionId);
        if ($typeChange !== null) {
            return $typeChange;
        }""",
         new=''),
    # 12 — the projector's own invariant.
    dict(name='the projector writes an existing product\'s type',
         file=PROJECTOR, expect='architecture',
         old="""        $wcProduct = $linked ?? $this->blank($product);""",
         new="""        $wcProduct = $linked ?? $this->blank($product);
        $wcProduct->set_type($product->details->type);"""),

    # ------------------------------------------------- the refusal sentences
    # 13
    dict(name='the type-change refusal loses its sentence and prints «Done.»',
         file=MESSAGES, expect='contract',
         old="""            'type_change_not_allowed' => __('نوع این محصول از این پنل تغییر نمی‌کند، چون قیمت و موجودی ترکیب‌هایش در ووکامرس نگهداری می‌شود. هیچ چیزی ذخیره نشد و ترکیب‌های فعلی دست‌نخورده‌اند.', 'tecteb-marketplace-core'),\n""",
         new=''),
    # 14
    dict(name='the parent-figures refusal loses its sentence and prints «Done.»',
         file=MESSAGES, expect='contract',
         old="""            'parent_figures_live_in_shop' => sprintf(""",
         new="""            'parent_figures_live_in_shop_renamed' => sprintf("""),

    # --------------------------------------------------------- §5 form paths
    # 15
    dict(name='a conflict is reported as a plain storage failure',
         file=MANAGE, expect='database:ProductFlowTest',
         old="""        if ($current !== '' && $current !== $expectedVersion) {""",
         new="""        if (false) {"""),
    # 16
    dict(name='a lost decision trail is counted as a clean review',
         file=REVIEW, expect='database:ProductFlowTest',
         old="""        if ($this->decisions !== null
            && !$this->decisions->record($productId, $product->vendorUserId, (int) $reviewer, $to->value, $note)) {""",
         new="""        if (false) {"""),
    # 17
    dict(name='a sale window that ends before it starts is accepted',
         file=MANAGE, expect='database:ProductFlowTest',
         old="""            if ($details->saleFrom !== null && $details->saleTo !== null && $details->saleFrom > $details->saleTo) {
                return OperationResult::failure('bad_sale_window');
            }""",
         new=''),
    # 18 — the one that would make a rejection change the product.
    dict(name='rejecting a proposal also writes the product',
         file=REVIEW, expect='database:ProductFlowTest',
         old="""        return OperationResult::success('revision_rejected', ['product_id' => $revision->productId]);""",
         new="""        $this->products->updateDetails($revision->productId, $product->details->with(['title' => 'رد شد']), $product->rowVersion);
        return OperationResult::success('revision_rejected', ['product_id' => $revision->productId]);"""),

    # 19 — the collision this round's own falsification loop found.
    #
    # §2 gave the new comparison fold the block root `tv-fold`, a name
    # `DashboardView` has owned since `alpha.33`, and `.tv-fold { margin: 0 }`
    # beat `.tv-card { margin-block-end: … }` on the one element that carries
    # both — so the dashboard's paperwork card silently lost the gap under it.
    # `StyledClassesHaveRulesTest` could not see it either: it asks whether a
    # class has A rule, not whether that rule is its own. This puts the shape
    # of the defect back by hand, on another class that shares an element with
    # the primitive.
    dict(name="a block beside .tv-card resets the card's bottom margin again",
         file=CSS, expect='architecture:CardRhythmIsNotResetTest',
         old=""".tv-shop { border-inline-start: 5px solid var(--tv-sky); }""",
         new=""".tv-shop { margin: 0; border-inline-start: 5px solid var(--tv-sky); }"""),
]


def main() -> int:
    if len(sys.argv) < 2:
        print('usage: alpha108-mutations.py count|name <n>|file <n>|expect <n>|apply <n>')
        return 2
    verb = sys.argv[1]
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    if n < 1 or n > len(MUTATIONS):
        print('no such mutation', file=sys.stderr)
        return 2
    m = MUTATIONS[n - 1]
    if verb in ('name', 'file', 'expect'):
        print(m[verb])
        return 0
    if verb == 'apply':
        src = io.open(m['file'], encoding='utf-8').read()
        # ⛔ EXACTLY ONCE, IN THE WHOLE FILE. Two matches and the tool refuses
        # rather than guessing which one — «nothing was measured» is
        # byte-for-byte like «this guard is unbreakable» (`alpha.88`).
        hits = src.count(m['old'])
        if hits != 1:
            print(f'snippet occurs {hits} times in {m["file"]}, refusing', file=sys.stderr)
            return 3
        io.open(m['file'], 'w', encoding='utf-8').write(src.replace(m['old'], m['new']))
        print('applied')
        return 0
    print('unknown verb', file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main())
