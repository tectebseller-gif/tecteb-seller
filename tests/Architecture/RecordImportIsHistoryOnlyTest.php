<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Importing a shop's records writes HISTORY, and nothing else.
 *
 * The owner's words: «ورود سابقه فقط ثبت تاریخچه است؛ نباید بدهی قابل‌پرداخت،
 * تسویه، دسترسی کارکنان، تغییر مالکیت محصول یا فعال‌شدن فروش ایجاد کند» and
 * «داده‌ها و جداول دکان، سفارش‌های ووکامرس، قیمت و وضعیت محصولات دست‌نخورده
 * بمانند».
 *
 * ⛔ **Why this is an architecture test and not a behaviour test.** A
 * behaviour test can show that a particular import did not create a debt. It
 * cannot show that no import ever will: the next method added to the loop
 * would be reachable, and every behaviour test would stay green because none
 * of them exercises the line that was added. The property wanted here is
 * «there is no way from here to there», which is a property of the source.
 *
 * It is scanned per METHOD BODY, not per file. `ImportFromDokan` legitimately
 * holds the ledger, the product repository and the vendor repository — the
 * product import needs all three — so a file-wide rule would either be
 * vacuous or forbid the class its own job. The rule is about the four methods
 * the record import can reach.
 *
 * ⛔ Comments are stripped first. A rule asserted against raw source is
 * satisfied by the sentence describing it (`alpha.22`), and the methods below
 * have docblocks that name the very things being forbidden.
 */
final class RecordImportIsHistoryOnlyTest extends TestCase
{
    private const IMPORT = 'src/Modules/Migration/Application/ImportFromDokan.php';

    private const JOB = 'src/Modules/Migration/Application/DokanRecordImportJob.php';

    private const PREVIEW = 'src/Modules/Migration/Application/ShopRecordPreview.php';

    /** The four methods the record import is made of. */
    private const METHODS = [
        'importStaffRecordPage',
        'importBalanceRecordPage',
        'importWithdrawalRecordPage',
        'recordPage',
    ];

    /**
     * Dependencies of `ImportFromDokan` that the record path may NOT reach.
     *
     * Each one is a way to make something operational, and each is named
     * rather than matched by a pattern: an exemption that can be earned by
     * accident is not an exemption, and so is a prohibition.
     */
    private const OUT_OF_REACH = [
        '$this->ledger' => 'a ledger line is money this marketplace owes',
        '$this->products' => 'a product row is ownership and a price',
        '$this->vendors' => 'a vendor profile is whether a shop may sell',
        '$this->history' => 'order history is a different import with its own rules',
        '$this->images' => 'a media copy belongs to the product import',
        '$this->options' => 'the run manifest belongs to the import that creates rows',
    ];

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

    /**
     * One method's body, by MATCHING BRACES from its signature.
     *
     * ⛔ Not «up to the next `function` keyword», which is how the first
     * version of this file was written and which cut
     * `importStaffRecordPage()` in half: its body passes a closure declared
     * with `function`, so the scan stopped before the write it was looking
     * for and the test reported a method that does the right thing as one
     * that does not. A body is delimited by its braces, not by a word that
     * can legally appear inside it.
     */
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
                continue;
            }
            if ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . ': unbalanced braces');
    }

    public function testTheRecordImportCannotReachAnythingOperational(): void
    {
        $code = $this->code(self::IMPORT);
        foreach (self::METHODS as $method) {
            $body = $this->methodBody($code, $method);
            foreach (self::OUT_OF_REACH as $needle => $why) {
                self::assertStringNotContainsString(
                    $needle,
                    $body,
                    $method . ' may not touch ' . $needle . ': ' . $why
                );
            }
        }
    }

    /**
     * The only writes are the three record writes, each in its own method.
     *
     * Both directions. A method that wrote a second kind would be a second
     * cursor's rows landing under this cursor's position, which is the
     * coupling the three kinds were separated to remove.
     */
    public function testEachKindWritesOnlyItsOwnRecord(): void
    {
        $code = $this->code(self::IMPORT);
        $writes = [
            'importStaffRecordPage' => 'recordStaff(',
            'importBalanceRecordPage' => 'recordBalance(',
            'importWithdrawalRecordPage' => 'recordWithdrawal(',
        ];
        foreach ($writes as $method => $own) {
            $body = $this->methodBody($code, $method);
            self::assertStringContainsString($own, $body, $method . ' must write its own kind');
            foreach ($writes as $other => $call) {
                if ($call === $own) {
                    continue;
                }
                self::assertStringNotContainsString(
                    $call,
                    $body,
                    $method . ' must not write ' . $other . "'s records"
                );
            }
        }
        // And the shared loop writes nothing itself: it is handed a closure.
        $shared = $this->methodBody($code, 'recordPage');
        foreach ($writes as $call) {
            self::assertStringNotContainsString(
                $call,
                $shared,
                'recordPage must not write records itself; it runs the closure it was given'
            );
        }
    }

    /**
     * Every record write asks the shown scope first — and asks it in the one
     * loop all three kinds go through.
     *
     * ⛔ **Both halves, because either alone is green on the defect.**
     * `alpha.78` shipped a shared `ShopRecordScope` and wired it to the
     * preview table and the button only; the walk still read Dokan's own
     * keysets, which hold every shop at once. Every behaviour test passed,
     * because the scope is a UNION of the very tables the walk reads, so the
     * two agreed by construction rather than by enforcement — and the owner
     * named it: «دامنهٔ مشترک را در مسیر واقعی اجرا اعمال کن». A test that
     * only forbade something would stay green with the question never asked,
     * so the asking is required here, in the source, where it is visible.
     *
     * It is asserted on `recordPage` because that is the single loop; a kind
     * that grew its own copy of the loop would be a kind whose admission
     * nobody checks, and `testEachKindWritesOnlyItsOwnRecord` already pins
     * the three to this one.
     */
    public function testEveryRecordWriteAsksTheShownScopeFirst(): void
    {
        $body = $this->methodBody($this->code(self::IMPORT), 'recordPage');
        self::assertStringContainsString(
            '$this->recordScope->admits(',
            $body,
            'the shared record loop must ask whether this owner has been shown'
        );
        // ⛔ And the ONE reason it may be skipped is that no scope was
        // supplied. Containment on the call alone is satisfied by a call
        // standing behind a condition that is never true — measured: the
        // mutation `if (false)` kept this assertion green and only the
        // behaviour test caught it. The condition is pinned too.
        self::assertStringContainsString(
            'if ($this->recordScope !== null) {',
            $body,
            'the only excuse for not asking is an install that supplied no scope'
        );
        // Before the claim and before the write, and «before» is read from
        // the byte offsets rather than from a sentence about the order.
        $asked = strpos($body, '$this->recordScope->admits(');
        $claimed = strpos($body, '$progress->claim(');
        $wrote = strpos($body, '$progress->writing(');
        self::assertIsInt($asked);
        self::assertIsInt($claimed);
        self::assertIsInt($wrote);
        self::assertLessThan((int) $claimed, (int) $asked, 'admission is asked before the unit is claimed');
        self::assertLessThan((int) $wrote, (int) $asked, 'admission is asked before anything is written');
    }

    /**
     * What the import may ask the scope for: two booleans, and nothing it
     * could write with.
     *
     * The record walk is handed `RecordScopeInterface` and not
     * `ShopRecordScope`, because the scope reaches a completeness service and
     * a vendor repository and neither has any business inside this loop. The
     * guarantee is that absence, so it is measured from the interface's own
     * bytes — the shape `DokanReaderInterface` has carried since `alpha.9`.
     */
    public function testTheScopeHandedToTheImportCanOnlyBeAsked(): void
    {
        $code = $this->code('src/Modules/Migration/Application/RecordScopeInterface.php');
        preg_match_all('/function\s+([A-Za-z0-9_]+)\s*\(/', $code, $found);
        self::assertSame(
            ['admits', 'readable'],
            $found[1],
            'the record import may ask two questions of the scope and nothing else'
        );
        foreach (['ShopRecordScope.php', 'RecordScopeInterface.php'] as $file) {
            $source = $this->code('src/Modules/Migration/Application/' . $file);
            foreach (['insert', 'update', 'delete', 'save', 'upsert', 'write'] as $verb) {
                self::assertStringNotContainsStringIgnoringCase(
                    'function ' . $verb,
                    $source,
                    $file . ' is read-only: «فقط‌خواندنی» is the absence of the method, not a promise'
                );
            }
        }
    }

    /**
     * The record write sits INSIDE the lease gate, not beside it.
     *
     * ⛔ **This test exists because falsification found its absence.** Taking
     * `$progress->writing()` away and calling the writer directly left every
     * other test in this round green — including the two-worker one, because
     * `claim()` refuses a worker whose lease has ALREADY gone and the window
     * `writing()` closes is the one that opens AFTER the claim. No behaviour
     * test in the suite can reach that window without an injection point, so
     * the property is asserted where it is visible: in the source.
     *
     * Containment on the text of the call, not by position — a `strpos`
     * ordering test accepts an empty block with the write after it
     * (`alpha.74`).
     */
    public function testTheRecordWriteSitsInsideTheLeaseGate(): void
    {
        $body = $this->methodBody($this->code(self::IMPORT), 'recordPage');
        $open = strpos($body, '$progress->writing(');
        self::assertIsInt($open, 'the record write must run under the lease gate');

        $held = $this->parenthesised($body, (int) $open + strlen('$progress->writing'));
        self::assertStringContainsString(
            '$tally = $write($unit);',
            $held,
            'the write must be INSIDE the gate, not merely after it'
        );
        self::assertStringContainsString(
            "return \$tally['failed'] === 0;",
            $held,
            'and the gate must be told whether the work failed, so the unit rolls back whole'
        );
        // Exactly two call sites: the gated one, and the unleased path the
        // tools take. A third would be a write nobody guards.
        self::assertSame(
            2,
            substr_count($body, '$write($unit)'),
            'one gated write and one unleased write, and nothing else'
        );
    }

    /** The text between a `(` at `$from` and its matching `)`. */
    private function parenthesised(string $code, int $from): string
    {
        $open = strpos($code, '(', $from);
        self::assertIsInt($open);
        $depth = 0;
        $length = strlen($code);
        for ($i = (int) $open; $i < $length; $i++) {
            if ($code[$i] === '(') {
                $depth++;
                continue;
            }
            if ($code[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail('unbalanced parentheses');
    }

    /**
     * The three kinds keep three positions, and the handler is what carries
     * them.
     *
     * ⛔ `CursorProgress` writes one `phase` and one `after`, which is right
     * for a single walk and would silently drop two thirds of this one's
     * place. So the merge is asserted where it lives — in the handler's own
     * data — rather than assumed.
     */
    public function testTheRecordCursorCarriesAllThreePositions(): void
    {
        $job = $this->code(self::JOB);
        foreach (['after_staff', 'after_balance', 'after_withdraw'] as $key) {
            self::assertStringContainsString(
                "'" . $key . "'",
                $job,
                'the record cursor must keep a position of its own for each kind: ' . $key
            );
        }
        // Written through ONE merge, so a cursor this handler emits cannot
        // leave a kind behind by being built somewhere else.
        self::assertStringContainsString(
            'array_merge($cursor, $kept)',
            $job,
            'every cursor the handler writes must carry the kept positions'
        );
    }

    /** The record handler names nothing operational, anywhere in its file. */
    public function testTheRecordHandlerNamesNothingOperational(): void
    {
        $job = $this->code(self::JOB);
        $forbidden = [
            'LedgerRepositoryInterface',
            'WithdrawalRepositoryInterface',
            'StaffRepositoryInterface',
            'GrantImportedStaff',
            'TransferOwnership',
            'LinkOwnership',
            'ProductStatus',
            'SettlementGate',
            'ProductRepositoryInterface',
            'VendorRepositoryInterface',
        ];
        foreach ($forbidden as $name) {
            self::assertStringNotContainsString(
                $name,
                $job,
                'the record handler may not name ' . $name
            );
        }
        // One dependency, and it is the import service.
        self::assertStringContainsString(
            'public function __construct(private readonly ImportFromDokan $import)',
            $job,
            'the handler takes the import service and nothing else'
        );
    }

    /**
     * The preview reads. It has no write method of any kind.
     *
     * «اولین گام پس از نصب فقط دیدن پیش‌نمایش سوابق باشد» is enforced by the
     * absence of a capability, not by a rule the page has to keep — the shape
     * `alpha.9`, `alpha.12` and `alpha.55` all use, and the one
     * `DisposableOnlyToolsTest` holds the inventory tool to.
     */
    public function testThePreviewHasNoWayToWriteAnything(): void
    {
        $preview = $this->code(self::PREVIEW);
        foreach (
            [
                'recordStaff(',
                'recordBalance(',
                'recordWithdrawal(',
                'deleteRun(',
                'wpdb',
                'update_post_meta',
                'update_option',
                '$this->db',
            ] as $needle
        ) {
            self::assertStringNotContainsString(
                $needle,
                $preview,
                'the preview must not be able to write: ' . $needle
            );
        }
    }

    /**
     * Nothing of Dokan's can be written, because there is no method for it.
     *
     * Asserted on the INTERFACE, which is what every caller is bound to —
     * including the record import. A write method here would be reachable
     * from the loop above without any of the rules in this file noticing.
     */
    public function testDokansDataHasNoWriteMethodToReach(): void
    {
        $reader = $this->code('src/Modules/Migration/Application/DokanReaderInterface.php');
        preg_match_all('~function\s+([A-Za-z0-9_]+)\s*\(~', $reader, $matches);
        self::assertNotSame([], $matches[1], 'the reader interface must declare methods');
        foreach ($matches[1] as $method) {
            // ⛔ `record` is deliberately NOT on this list, and the reason is
            // a name in the interface: `recordSourceFor()` READS how many
            // records a source has. A verb list wide enough to catch it
            // would forbid the reader from talking about records at all,
            // which is the subject it exists for — a guard wider than the
            // defect is worse than the defect (`alpha.60`).
            foreach (['insert', 'update', 'delete', 'write', 'save', 'upsert', 'truncate', 'drop'] as $verb) {
                self::assertStringNotContainsStringIgnoringCase(
                    $verb,
                    $method,
                    'DokanReaderInterface::' . $method . '() reads like a write; Dokan is read only'
                );
            }
        }
    }
}
