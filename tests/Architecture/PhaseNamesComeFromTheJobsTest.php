<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Application\DokanImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\DokanRecordImportJob;

/**
 * Every phase a job can write into its cursor has a name on the queue page,
 * and the page names no phase no job can write.
 *
 * ⛔ **What was broken.** `OperationsMessages::importPhase()` knew the product
 * import's three phases and nothing else, so the record import — whose cursor
 * names its phase perfectly well — printed «مرحلهٔ نامعلوم» on every batch
 * while it ran. A reader was invited to think the job had lost its place at
 * the exact moment the page's one job was to say where it was.
 *
 * Both directions, because one alone is half the mistake (`alpha.76`): a
 * phase with no name prints «نامعلوم», and a name no job writes is a label
 * the page can print and the import can never produce.
 *
 * ⛔ Asserted against the SOURCE rather than by calling the method, because
 * the architecture suite defines no WordPress symbols and `importPhase()`
 * goes through `__()`. Comments are stripped first: a rule asserted against
 * raw source is satisfied by the sentence describing it (`alpha.22`), and
 * that file's own prose names these phases.
 */
final class PhaseNamesComeFromTheJobsTest extends TestCase
{
    private const MESSAGES = 'src/Modules/Operations/Presentation/OperationsMessages.php';

    /**
     * The two handlers that write a phase into a cursor.
     *
     * Named classes rather than a directory scan, so a third handler added
     * later is a deliberate line here and not something a pattern swallowed.
     */
    private const JOBS = [DokanImportJob::class, DokanRecordImportJob::class];

    private function code(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** @return list<string> */
    private function declaredPhases(): array
    {
        $out = [];
        foreach (self::JOBS as $class) {
            foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
                if (str_starts_with($name, 'PHASE_')) {
                    $out[] = (string) $value;
                }
            }
        }
        self::assertNotSame([], $out, 'the jobs must declare their phases as constants');
        return $out;
    }

    /**
     * The phase keys the page's `match` handles — the arms of `importPhase()`
     * alone, not every literal in the file.
     *
     * @return list<string>
     */
    private function namedPhases(): array
    {
        $code = $this->code(self::MESSAGES);
        $start = strpos($code, 'function importPhase(');
        self::assertIsInt($start, 'importPhase() must exist');
        // Brace matching rather than «up to the next `function`»: the second
        // form cut a method in half once already (`alpha.77`).
        $open = strpos($code, '{', (int) $start);
        self::assertIsInt($open);
        $depth = 0;
        $end = (int) $open;
        for ($i = (int) $open, $n = strlen($code); $i < $n; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $body = substr($code, (int) $open, $end - (int) $open + 1);
        preg_match_all("~'([^']*)'\s*=>~", $body, $matches);
        return array_values(array_map('strval', $matches[1]));
    }

    public function testEveryPhaseAJobWritesHasAName(): void
    {
        $named = $this->namedPhases();
        foreach ($this->declaredPhases() as $phase) {
            self::assertContains(
                $phase,
                $named,
                'the queue page would print «نامعلوم» for the phase «' . $phase . '»'
            );
        }
    }

    public function testThePageNamesNoPhaseNoJobCanWrite(): void
    {
        $declared = $this->declaredPhases();
        foreach ($this->namedPhases() as $phase) {
            self::assertContains(
                $phase,
                $declared,
                'the page names a phase «' . $phase . '» that no job writes'
            );
        }
    }

    /**
     * The record phases carry their suffix, and that is the point of them.
     *
     * `balance_records` rather than `balance` so a record cursor can never be
     * read as the product import's phase of the same word — worth having only
     * if the constants and the page agree on the suffixed form, which the two
     * tests above then enforce in both directions.
     */
    public function testTheRecordPhasesAreNotBareWords(): void
    {
        foreach ([
            DokanRecordImportJob::PHASE_STAFF,
            DokanRecordImportJob::PHASE_BALANCE,
            DokanRecordImportJob::PHASE_WITHDRAWALS,
        ] as $phase) {
            self::assertStringEndsWith('_records', $phase, 'a record phase must be distinguishable');
            self::assertNotContains(
                $phase,
                [DokanImportJob::PHASE_VENDORS, DokanImportJob::PHASE_PRODUCTS, DokanImportJob::PHASE_ORDERS],
                'a record phase must not collide with a product-import phase'
            );
        }
    }
}
