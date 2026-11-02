<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the staged hand-over cannot move a product the
 * per-product button would refuse, it writes nothing while previewing, it is
 * reachable from exactly one explicit admin action, and it stops at the first
 * failure.
 *
 * ⛔ **Why these are source assertions.** A behaviour test shows that one
 * batch moved the rows it was given. It cannot show that the next line added
 * to the apply service will not pass an acknowledgement array, or that the
 * next hook will not call it — those lines would be reachable and every
 * behaviour test would stay green (`alpha.77`).
 *
 * The owner's §4 is the whole reason: «بدون انتقال خودکار، با پیش‌نمایش،
 * تأیید صریح مدیر برای هر دسته عملیات، ثبت ممیزی و امکان توقف در اولین خطا.»
 *
 * ⛔ Comments are stripped first: both files name what they forbid out loud,
 * and a rule checked against raw source is satisfied by the sentence
 * describing it (`alpha.22`).
 */
final class StagedOwnershipTransferIsSafeTest extends TestCase
{
    private const PLAN = 'src/Modules/Migration/Application/OwnershipTransferPlan.php';

    private const APPLY = 'src/Modules/Migration/Application/ApplyOwnershipTransfer.php';

    private const PAGE = 'src/Modules/Migration/Presentation/Admin/MigrationPage.php';

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

    private function methodBody(string $code, string $method): string
    {
        $start = strpos($code, 'function ' . $method . '(');
        self::assertIsInt($start, 'no such method: ' . $method);
        $open = strpos($code, '{', (int) $start);
        self::assertIsInt($open, $method . ' has no body');
        $depth = 0;
        $length = strlen($code);
        for ($i = (int) $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . ' is unbalanced');
    }

    /** The preview writes nothing, of any kind. */
    public function testThePlanHasNoWriter(): void
    {
        $code = $this->code(self::PLAN);
        foreach ([
            'setLinkOwnership',
            'claimStorefrontPost',
            'releaseStorefrontPost',
            '->take(',
            '->giveBack(',
            'refreshFrom',
            'update_post_meta',
            'wp_update_post',
            'INSERT',
            'UPDATE ',
            'DELETE',
            'audit',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'the ownership preview may not name ' . $forbidden
            );
        }
    }

    /**
     * ⛔ THE SAFETY PROOF: the batch waives nothing.
     *
     * `TransferOwnership::take()` lets a preservation risk through only when
     * the acknowledgement array names it, and a storefront drift only when
     * `seenShop` carries that value's fingerprint. The batch passes `[]` and
     * `[]`, so the EXISTING guard refuses such a row and the batch stops —
     * which holds even if the planner's bucketing is wrong.
     */
    public function testTheBatchWaivesNoRiskAndNoDrift(): void
    {
        $apply = $this->methodBody($this->code(self::APPLY), 'apply');
        self::assertStringContainsString(
            '$this->transfer->take($productId, [], [])',
            $apply,
            'the batch must pass empty acknowledgement and empty seenShop'
        );
        foreach (['acknowledged', 'seen_shop', 'seenShop', 'fingerprint'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $apply,
                'the batch may not carry a waiver: ' . $forbidden
            );
        }
    }

    /**
     * The plan is read AGAIN at apply time, and a moved plan refuses the whole
     * batch.
     *
     * ⛔ Both halves. Pinning the call alone would pass with the comparison
     * removed, and pinning the comparison alone would pass with the plan
     * taken from the form (`alpha.79`/`alpha.81`).
     */
    public function testTheApplyRereadsThePlanAndRefusesAMovedOne(): void
    {
        $apply = $this->methodBody($this->code(self::APPLY), 'apply');
        self::assertStringContainsString('$this->plan->plan($vendorUserId, $afterId)', $apply);
        self::assertStringContainsString('hash_equals(OwnershipTransferPlan::digestOf($ready)', $apply);
        self::assertStringContainsString("'plan_moved'", $apply);
        // And an id the page did not offer refuses the batch WHOLE rather
        // than being filtered out, because filtering would carry out part of
        // something nobody asked for.
        self::assertStringContainsString("'selection_not_offered'", $apply);
        self::assertStringContainsString('$requested !== $offered', $apply);
    }

    /** It stops at the first failure, and the stop is a `return`. */
    public function testItStopsAtTheFirstFailure(): void
    {
        $apply = $this->methodBody($this->code(self::APPLY), 'apply');
        $loop = strpos($apply, 'foreach ($requested as $productId)');
        self::assertIsInt($loop);
        $body = substr($apply, (int) $loop);
        self::assertStringContainsString('if (!$result->ok)', $body);
        $refusal = strpos($body, 'if (!$result->ok)');
        $returns = strpos($body, "return \$this->answer(false, 'stopped'", (int) $refusal);
        $appends = strpos($body, '$moved[] = $productId;');
        self::assertIsInt($returns, 'the failure path must return');
        self::assertIsInt($appends);
        self::assertLessThan((int) $appends, (int) $returns, 'and it must return BEFORE counting it as moved');
        foreach (['continue', 'retry'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $body,
                'the batch may not skip or retry a failed unit: ' . $forbidden
            );
        }
    }

    /**
     * The batch row is written on BOTH paths.
     *
     * A batch that moved eleven products and stopped on the twelfth has
     * changed the catalogue; a trail that recorded only completed batches
     * would have nothing to say about it.
     */
    public function testTheBatchIsAuditedWhetherItFinishedOrStopped(): void
    {
        $apply = $this->methodBody($this->code(self::APPLY), 'apply');
        self::assertSame(
            2,
            substr_count($apply, '$this->record('),
            'both the stopped path and the finished path must record'
        );
        $record = $this->methodBody($this->code(self::APPLY), 'record');
        self::assertStringContainsString('DOKAN_OWNERSHIP_BATCH', $record);
        self::assertStringContainsString("'stopped_at' => \$stoppedAt", $record);
    }

    /**
     * Exactly one admin action reaches it, and it is a POST behind the page's
     * own nonce.
     *
     * «بدون انتقال خودکار» is this test: no cron, no queue job, no hook.
     */
    public function testOnlyOneExplicitAdminActionReachesTheApply(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $callers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(dirname(__DIR__, 2) . '/', '', (string) $file->getPathname());
            if (strpos($this->code($relative), 'ApplyOwnershipTransfer') !== false) {
                $callers[] = $relative;
            }
        }
        sort($callers);
        self::assertSame([
            self::APPLY,
            'src/Modules/Migration/MigrationModule.php',
            self::PAGE,
        ], $callers);

        $page = $this->code(self::PAGE);
        $handler = $this->methodBody($page, 'handleAction');
        self::assertStringContainsString("\$action === 'apply_ownership_batch'", $handler);
        // The page's own guards: POST only, and the nonce.
        self::assertStringContainsString('$request->isPost()', $handler);
        self::assertStringContainsString("nonceOk('tmc_migration_nonce', self::NONCE)", $handler);
        // And nothing schedules it.
        foreach (['wp_schedule', 'as_schedule', 'JobRunner', 'add_action'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $this->code(self::APPLY),
                'nothing may run the batch unattended: ' . $forbidden
            );
        }
    }
}
