<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;

/**
 * WHETHER THE TEMPORARY MIGRATION TOOLS STILL BELONG IN THE EVERYDAY MENU.
 *
 * §5: «این موارد پس از تأیید موفقیت مهاجرت، از منوی معمولی پنهان شوند» and
 * «از حذف فوری کد، دیتابیس، اسناد یا قابلیت بازگشت اجتناب کن». §3-10 says the
 * same about the Min Max exit screen and adds «قابلیت بازیابی و شواهد لازم
 * برای بازگشت محفوظ بمانند».
 *
 * ### Why a recorded decision rather than a derived fact
 *
 * `AdminNavigation`'s own docblock rejected a runtime flag, and its argument
 * was good: «A hidden page whose address still works is a page a manager
 * cannot find and cannot be told about, and «ویرایش دستی یک ردیف option» as
 * the way back is the shape `alpha.80` calls worse than having no gate at
 * all.» Both halves of that objection are answered here rather than ignored:
 *
 *  - **The way back is a button**, on a permanent page (سلامت سیستم), next to
 *    a sentence saying the tools are hidden and why. Nobody edits an option
 *    row, and nobody has to remember a URL.
 *  - **The decision is auditable and revocable**, which is the shape the
 *    repository already uses for the records confirmation
 *    (`M0037ShopRecordConfirmation`): «تأیید صریح مدیر … همراه با تاریخچه
 *    ممیزی و امکان ابطال».
 *
 * A derived condition was tried first and rejected with a measurement:
 * `MigrationPlanChecklist` is per SHOP and `MigrationPlanSnapshot::of()` is
 * many reads, and the menu is rebuilt on every single wp-admin request. Asking
 * «is every imported shop finished?» there would put that work on every page
 * load of the panel — the shape `alpha.96` §4 calls paying for forty fields to
 * read one. So the expensive question is asked **on the click**, once, by
 * `ConcludeMigration`, and what the menu reads is one option.
 *
 * ⛔ **And the default is VISIBLE, including on every read that fails.** A
 * tool a manager cannot see is worse than a menu with one extra section in
 * it, so «I could not tell» resolves to showing them.
 */
final class MigrationToolsVisibility
{
    /**
     * Set only when a manager has confirmed the migration finished.
     *
     * Absent is the normal state and means «show them». The value is the
     * timestamp of the confirmation so the screen can say when, which is also
     * why it is not a bare boolean.
     */
    public const OPTION = 'tmc_migration_concluded_at';

    public function __construct(private readonly OptionStoreInterface $options)
    {
    }

    /** Whether the temporary section should appear in the everyday menu. */
    public function toolsAreVisible(): bool
    {
        return $this->concludedAt() === '';
    }

    /**
     * When the migration was confirmed finished, or '' while it has not been.
     *
     * Anything stored that is not a non-empty string reads as '' — not as a
     * conclusion. A truthy-but-unusable value (a `1` left by an older build,
     * an array) must not hide the tools on the strength of being truthy.
     */
    public function concludedAt(): string
    {
        $stored = $this->options->get(self::OPTION, '');
        if (!is_string($stored)) {
            return '';
        }
        return trim($stored);
    }

    /** @internal written only by `ConcludeMigration`, which checks first. */
    public function recordConclusion(string $at): bool
    {
        return $this->options->set(self::OPTION, $at);
    }

    /** @internal the way back, driven by the button on the health page. */
    public function forgetConclusion(): bool
    {
        return $this->options->delete(self::OPTION);
    }
}
