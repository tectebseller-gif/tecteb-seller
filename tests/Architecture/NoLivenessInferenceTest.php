<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * NOTHING THIS PLUGIN SAYS MAY CLAIM THAT A PROCESS IS ALIVE OR DEAD.
 *
 * ### The claim, and why it is invalid
 *
 * `alpha.75`'s queue page printed «کارگری که این را نوشت دیگر زنده نیست» when
 * a job's lease had expired. The owner struck it out, and was right: an
 * expired lease means the lease expired. The process may have been killed by
 * the host, or it may still be inside a synchronous step with its checkpoints
 * behind it, or waiting on a database lock. All three produce exactly that
 * row, and nothing in a database row can tell them apart.
 *
 * The same holds in the other direction, which is the half that is easy to
 * miss: a lease that has NOT expired does not mean a worker is running. It
 * means `claim()` will not hand the job to anybody else yet.
 *
 * ### What this test does about it
 *
 * It forbids the asserting forms outright, across everything that ships. It is
 * a string rule and that is deliberate: the defect was a sentence, the fix is
 * a sentence, and a sentence is the only thing that can carry the claim to a
 * person. Hedged forms are untouched and in use — «شاید زنده باشد» on the
 * hand-over gate, and «زنده‌بودنِ پردازشِ قبلی … مشخص نمی‌شود» on the queue
 * page — because saying what is not known is the opposite of this defect.
 *
 * ⛔ Comments are NOT stripped here, unlike most rules in this directory. The
 * `alpha.22` reason for stripping them is that prose describing a rule
 * satisfies a byte match of it; here the rule is about prose, and a docblock
 * that states a worker is dead is as wrong as a printed string — the next
 * person to touch the file reads it and believes it.
 */
final class NoLivenessInferenceTest extends TestCase
{
    /**
     * Claims about a process's life, in the asserting form.
     *
     * Each one is a sentence that cannot be true of a row: «is no longer
     * alive», «is dead», «the process was killed».
     *
     * ⛔ PERSIAN ONLY, and the first version of this list was not — it had the
     * English forms too, and failed on two docblocks that are both correct:
     * `JobRepositoryInterface` saying «it does not prove the worker is dead
     * and nothing here can», and `JobStatus` describing the hypothetical «a
     * job that stops because the process died». A negation and a hypothesis
     * are ordinary English and a blunt substring rule reads them as claims.
     *
     * Everything a person sees in this plugin is Persian, so the claim that
     * can reach somebody is a Persian one — and in Persian these phrases do
     * not appear inside the hedged forms this codebase uses
     * («شاید زنده باشد», «زنده‌بودنِ … مشخص نمی‌شود»), which is what makes the
     * rule decidable at all.
     */
    private const FORBIDDEN = [
        'زنده نیست',
        'مرده است',
        'پروسه مرده',
        'کشته شده است',
    ];

    /** @return list<string> */
    private function shippedFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        self::assertNotSame([], $files);
        return $files;
    }

    public function testNothingThatShipsClaimsAWorkerIsAliveOrDead(): void
    {
        $offenders = [];
        foreach ($this->shippedFiles() as $path) {
            $source = (string) file_get_contents($path);
            foreach (self::FORBIDDEN as $claim) {
                if (str_contains($source, $claim)) {
                    $offenders[] = substr($path, strlen(dirname(__DIR__, 2)) + 1) . ': ' . $claim;
                }
            }
        }
        self::assertSame([], $offenders, 'an expired lease proves nothing about a process');
    }

    /**
     * And the hedged form really is there, so this is not passing by silence.
     *
     * ⛔ Without this half, deleting the sentence altogether would also make
     * the test above green — and a page that says nothing about the lease is
     * not the fix. The fix is a page that says what the lease does and does
     * not prove.
     */
    public function testTheQueuePageSaysWhatTheLeaseDoesNotProve(): void
    {
        $page = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Modules/Operations/Presentation/Admin/QueuePage.php'
        );
        self::assertStringContainsString(
            'زنده‌بودنِ پردازشِ قبلی از این ردیف مشخص نمی‌شود',
            $page,
            'an expired lease has to be reported as what it is'
        );
        self::assertStringContainsString(
            'نه اینکه پردازشی در حال اجراست',
            $page,
            'and so does a lease that has not expired — the other half of the same mistake'
        );
        self::assertStringContainsString(
            'آخرین مرحلهٔ ثبت‌شده',
            $page,
            'the step is reported as the last one recorded, not as one in progress'
        );
    }
}
