<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Migration\Domain\MinMaxRule;

/**
 * Everything this plugin reads from «Min Max Control» — and, as with Dokan,
 * only ever reading.
 *
 * There is no write method, and the absence is the point: the standing rule is
 * that no existing plugin's data is edited or removed, and a migration that
 * COULD write Min Max's rows would be one bug away from changing the live
 * shop's purchase limits.
 *
 * The mode question — which meta prefix and which option key are in force — is
 * asked here rather than by each caller, because the plugin itself asks it
 * once, at load, in an order that matters (`wcmmq.php:49-61`) and getting the
 * order wrong reads a different set of rows than the shop is enforcing.
 */
interface MinMaxReaderInterface
{
    public const PAGE = 200;

    /** False when Min Max is not installed; every other method answers empty. */
    public function isAvailable(): bool;

    /**
     * Which of the three modes the plugin is in, and what that implies.
     *
     * @return array{mode:string, prefix:string, option:string, premium:bool}
     *         `mode` is `universal` / `single` / `plain`
     */
    public function mode(): array;

    /** The global default rule, or `null` when the option holds nothing. */
    public function globalRule(): ?MinMaxRule;

    /**
     * The configured term rules, keyed `taxonomy|term_id`.
     *
     * @return array<string, MinMaxRule>
     */
    public function termRules(): array;

    /**
     * One page of products that carry a rule of their own, strictly after
     * `$afterId`, ascending.
     *
     * Keyset rather than OFFSET for the reason the rest of this module uses
     * keyset: a walk over a set somebody else is changing skips rows silently.
     *
     * The CURSOR is returned separately from the rows, and that is not
     * decoration. A product whose four rows are all blank is read and then
     * dropped — the plugin itself drops it — so the largest id in `rows` can
     * be smaller than the largest id READ. A caller resuming from the rows
     * would re-read the same page for ever. `alpha.35`'s rule, one module
     * across: a batch has to say where it stopped, not only what it found.
     *
     * @return array{rows:list<array{product_id:int, parent_id:int, type:string, rule:MinMaxRule}>, cursor:int, done:bool}
     */
    public function productRulesAfter(int $afterId, int $limit = self::PAGE): array;

    /**
     * How many rows there are per level, so a preview can state a total.
     *
     * @return array{product:int, variation:int, term:int, global:int, default_quantity:int}
     */
    public function counts(): array;

    /**
     * The settings that are presentation only and have no limit in them.
     *
     * Named so the exit report can say «these are not being migrated and here
     * is why» instead of leaving them unmentioned, which reads as «nothing
     * else exists».
     *
     * @return array<string,string>
     */
    public function presentationSettings(): array;
}
