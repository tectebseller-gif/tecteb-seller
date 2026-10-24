<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

/**
 * The admission question the record import asks before it writes anything:
 * «is this owner one the page has already shown?»
 *
 * ⛔ **Why the write path needs this at all.** `alpha.78` built one shared
 * list — `ShopRecordScope` — and then used it in two places only: the preview
 * table and the button that queues the import. The walk itself still read
 * Dokan's own keysets (`vendorsAfter()`, `balanceRowsAfter()`,
 * `withdrawalsAfter()`), which hold every shop at once and know nothing about
 * what any page displayed. So the two halves agreed because the union was
 * drawn from the same tables the walk reads — a correspondence that happened
 * to hold rather than one anything enforced. The owner named it: «دامنهٔ مشترک
 * را در مسیر واقعی اجرا اعمال کن… دامنهٔ ناقص یا ناخوانا نباید اجازهٔ ورودِ
 * صاحبان نمایش‌داده‌نشده بدهد.»
 *
 * A narrow interface rather than the class, for two reasons. The import needs
 * two answers and not a scope's whole table, so this is what it may ask; and
 * `ShopRecordScope` reaches a vendor repository and a completeness service,
 * neither of which has any business being inside the record walk's reach.
 *
 * Both methods are reads. There is no write method here and there is none on
 * the implementation either, which is how «پیش‌نمایش فقط‌خواندنی» survives
 * being handed to the thing that does the writing.
 */
interface RecordScopeInterface
{
    /**
     * Whether this id is one the scope names — and therefore one whose
     * records may be written.
     *
     * An id outside the scope is an id whose records would arrive unannounced,
     * which is the whole defect. It is skipped and named rather than written.
     */
    public function admits(int $vendorUserId): bool;

    /**
     * Whether the scope could be enumerated completely.
     *
     * `false` means the list may be short, and a short list cannot be used to
     * admit anybody: `getResults()` answers `[]` both for «no owners» and for
     * «the statement failed» (`alpha.8`), so an enumeration that silently came
     * back short would admit exactly the owners it failed to mention. When
     * this is false, `admits()` answers false for every id.
     */
    public function readable(): bool;
}
