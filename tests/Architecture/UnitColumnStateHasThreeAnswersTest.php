<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0033MoneyUnitOnRows;

/**
 * «ABSENT», «PRESENT» AND «READ FAILED» STAY THREE ANSWERS.
 *
 * ⛔ **The defect this pins.** `alpha.67` asked the question with
 * `carriedBy(): bool` and documented a failed read answering `false` as «the
 * conservative direction for both uses». It is not: on an upgraded database
 * the columns are PRESENT and carry exponent 4, so a reader told `false`
 * substitutes `NULL` for them and reads ۸۸٫۳۷۵ تومان as ۸۸۳٬۷۵۰ — measured,
 * `exact_row_factor_applied=10000`.
 *
 * A `bool` cannot hold three answers, so the guard against that defect
 * coming back is a guard on the SHAPE: the two-state method must not exist,
 * every consumer must compare against `PRESENT` by name rather than treating
 * the answer as truthy, and no consumer may map `UNKNOWN` onto `ABSENT`.
 *
 * This is an architecture test rather than a comment because «the answer has
 * three states» is a sentence, and a sentence does not fail
 * (`alpha.22`).
 */
final class UnitColumnStateHasThreeAnswersTest extends TestCase
{
    /** Every file that asks the question. */
    private const CONSUMERS = [
        'src/Modules/Order/Infrastructure/DbOrderItemRepository.php',
        'src/Modules/Order/Infrastructure/DbShipmentRepository.php',
        'src/Modules/Finance/Infrastructure/DbWithdrawalRepository.php',
        'src/Modules/Finance/Application/MoneyScaleRollbackGate.php',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testTheTwoStateHelperIsGone(): void
    {
        self::assertFalse(
            method_exists(M0033MoneyUnitOnRows::class, 'carriedBy'),
            'a boolean answer cannot say «I could not find out»; `carriedBy()` must not come back'
        );
        self::assertTrue(method_exists(M0033MoneyUnitOnRows::class, 'unitColumnState'));
    }

    public function testTheThreeStatesAreNamedAndDistinct(): void
    {
        $states = [
            M0033MoneyUnitOnRows::PRESENT,
            M0033MoneyUnitOnRows::ABSENT,
            M0033MoneyUnitOnRows::UNKNOWN,
        ];
        self::assertSame($states, array_values(array_unique($states)), 'three answers, not two spellings of one');
        foreach ($states as $state) {
            self::assertNotSame('', $state);
        }
    }

    /**
     * EVERY USE OF THE ANSWER NAMES WHICH STATE IT MEANS.
     *
     * The answer is one of three strings, so a consumer that uses it without
     * naming a state is deciding by truthiness — and every non-empty string
     * is truthy, which would make `UNKNOWN` read as «yes, the columns are
     * there». So each call site must mention one of the three constants in
     * the same statement. The only exception is the per-instance memo, which
     * stores the raw answer and is named here with its reason.
     */
    public function testEveryUseOfTheAnswerNamesAState(): void
    {
        $checked = 0;
        foreach (self::CONSUMERS as $relative) {
            $file = self::root() . '/' . $relative;
            self::assertFileExists($file);
            $source = self::withoutCommentsOrStrings($file);
            foreach (explode(';', $source) as $statement) {
                if (!str_contains($statement, 'unitColumnsOn(')
                    && !str_contains($statement, 'unitColumnState(')) {
                    continue;
                }
                // The memo: `$this->unitColumns[$table] = …unitColumnState(…)`
                // keeps the answer AS the answer, which is the one place that
                // must not narrow it.
                if (str_contains($statement, '$this->unitColumns[$table] =')) {
                    continue;
                }
                // And a plain `$state = …` that only holds the answer so the
                // two sentences below it can each name a state. The name is
                // the exemption: anything else assigned from this call has
                // to say which state it means in the same breath.
                if (str_contains($statement, '$state = $this->unitColumnsOn(')) {
                    continue;
                }
                $checked++;
                $namesAState = str_contains($statement, 'PRESENT')
                    || str_contains($statement, 'ABSENT')
                    || str_contains($statement, 'UNKNOWN');
                self::assertTrue(
                    $namesAState,
                    $relative . ' uses the column state without naming which one: '
                        . trim(preg_replace('/\s+/', ' ', $statement) ?? '')
                );
            }
        }
        self::assertGreaterThan(5, $checked, 'sanity: the call sites were actually found');
    }

    public function testNoConsumerMapsUnknownOntoAbsent(): void
    {
        foreach (self::CONSUMERS as $relative) {
            $source = self::withoutCommentsOrStrings(self::root() . '/' . $relative);
            // `=== U::ABSENT` is legitimate — `MoneyScaleRollbackGate` uses it
            // to say «no row can carry a finer unit». What is not legitimate
            // is `!== PRESENT` standing in for «absent», because that folds
            // UNKNOWN in with it.
            self::assertStringNotContainsString(
                '!== U::PRESENT ? ',
                $source,
                $relative . ' collapses UNKNOWN into ABSENT'
            );
            self::assertStringNotContainsString(
                '!== M0033::PRESENT ? ',
                $source,
                $relative . ' collapses UNKNOWN into ABSENT'
            );
        }
    }

    /**
     * Comments and string literals emptied, because a count that works on
     * text must empty them first (`alpha.22`): a docblock that EXPLAINS the
     * collapse would otherwise fail the test about not doing it.
     */
    private static function withoutCommentsOrStrings(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
