#!/usr/bin/env python3
"""The mutations for alpha.99, each one a fix of this round put back wrong.

Run through `tools/alpha99-falsification.sh`, which restores every file byte
for byte afterwards and compares hashes. A mutation that does NOT apply is a
failure, not an unbreakable guard (`alpha.77`): «nothing was measured» looks
byte for byte like «this cannot be broken».

Every `old` here must occur EXACTLY ONCE in its file. A snippet that matches
twice is refused by the harness rather than applied to the first hit
(`alpha.88`).

⛔ And each one's name says what it BREAKS, not what it edits — `alpha.82`
measured that a mutation whose name does not match its work is worse than no
mutation, because nobody can check it.
"""
import hashlib
import pathlib
import sys

SYNC = "src/Modules/Order/Application/SyncOrderLifecycle.php"
LIFECYCLE = "src/Modules/Order/Domain/WcOrderLifecycle.php"
DISPOSITION = "src/Modules/Order/Domain/WcOrderDisposition.php"
HOOKS = "src/Modules/Order/Infrastructure/WordPress/OrderHooks.php"
RETURNS = "src/Modules/Order/Application/ManageReturns.php"
AUDIT_PAGE = "src/Modules/Operations/Presentation/Admin/AuditPage.php"
AUDIT_REPO = "src/Infrastructure/WordPress/WpAuditRepository.php"
SITE_TIME = "src/Core/Support/SiteTime.php"
CATALOG = "src/Core/Audit/AuditEventCatalog.php"
REPORT = "src/Modules/Order/Application/OrderLedgerReport.php"
FILTER = "src/Modules/Order/Domain/OrderLedgerFilter.php"
ITEM_REPO = "src/Modules/Order/Infrastructure/DbOrderItemRepository.php"
MIGRATION = "src/Modules/Order/Infrastructure/Migrations/M0039WcOrderStatusOnRows.php"

MUTATIONS = [
    # ── §3 the P0: the cancel path ──────────────────────────────────────────
    {
        "name": "a cancelled order no longer cancels the line (the staging defect, back)",
        "file": SYNC,
        "expect": "database",
        "old": "        return $this->voidAccrual($item);",
        "new": "        return $untouched('cancel_after_shipping');",
    },
    {
        "name": "the accrual is cancelled but never reversed",
        "file": SYNC,
        "expect": "architecture,database",
        "old": "        if (!$this->ledger->record($transaction)) {",
        "new": "        if (false && !$this->ledger->record($transaction)) {",
    },
    {
        "name": "a failed status write leaves the reversing document standing",
        "file": SYNC,
        "expect": "architecture",
        "old": """        if (!$this->items->updateStatus($item->id, OrderItemStatus::Cancelled, '', '', null)) {
            // The document would otherwise stand beside a line still counted
            // as earned — a reversal nobody's totals can see. Both halves go
            // back together.
            $this->tx->rollback();
            return $failed('void_status_unwritten');
        }""",
        "new": """        if (!$this->items->updateStatus($item->id, OrderItemStatus::Cancelled, '', '', null)) {
            return $failed('void_status_unwritten');
        }""",
    },
    {
        "name": "the reversal is written under the accrual's own key, so it never lands",
        "file": SYNC,
        "expect": "architecture,database",
        "old": "        return CaptureOrder::eventKey($orderId, $orderItemId) . ':void';",
        "new": "        return CaptureOrder::eventKey($orderId, $orderItemId);",
    },
    {
        "name": "a shipped line is cancelled behind the shop's back",
        "file": SYNC,
        "expect": "database",
        "old": """        if ($item->status->hasShipped()) {""",
        "new": """        if (false) {""",
    },
    {
        "name": "an order leaving `cancelled` is silently re-opened",
        "file": SYNC,
        "expect": "database",
        "old": """            return $disposition->voidsTheAccrual()
                ? $untouched()
                : $untouched('reopened_after_void');""",
        "new": """            return $untouched();""",
    },
    {
        "name": "a refunded order is treated as a cancellation",
        "file": LIFECYCLE,
        "expect": "unit,database",
        "old": "    public const REFUNDED = ['refunded'];",
        "new": "    public const REFUNDED = [];",
    },
    {
        "name": "a status this release does not know is read as paid",
        "file": DISPOSITION,
        "expect": "unit,database",
        "old": """    public function needsADecision(): bool
    {
        return $this === self::Refunded || $this === self::Unknown;
    }""",
        "new": """    public function needsADecision(): bool
    {
        return $this === self::Refunded;
    }""",
    },
    {
        "name": "the prefixed post status stops being the same status",
        "file": LIFECYCLE,
        "expect": "unit",
        "old": """        if (str_starts_with($slug, 'wc-')) {
            $slug = substr($slug, 3);
        }""",
        "new": """        if (false) {
            $slug = substr($slug, 3);
        }""",
    },
    {
        "name": "a trashed order keeps its obligation",
        "file": LIFECYCLE,
        "expect": "unit,database",
        "old": "    public const VOID = ['cancelled', 'trash'];",
        "new": "    public const VOID = ['cancelled'];",
    },
    {
        "name": "nothing listens to the order's status at all",
        "file": HOOKS,
        "expect": "contract",
        "old": """        add_action('woocommerce_order_status_changed', static function (mixed $orderId, mixed $from, mixed $to) use ($container): void {
            self::syncLifecycle($container, $orderId, is_string($to) ? $to : '');
        }, 20, 3);""",
        "new": """        // removed""",
    },
    {
        "name": "a cancelled line can have a return opened on it, reversing twice",
        "file": RETURNS,
        "expect": "database",
        "old": """        if ($item->status === OrderItemStatus::Cancelled) {
            return OperationResult::failure('item_cancelled', [
                'item_id' => $item->id,
                'order_id' => $item->orderId,
            ]);
        }""",
        "new": """        // removed""",
    },
    # ── §8 the audit page ───────────────────────────────────────────────────
    {
        "name": "the event filter goes back through `sanitize_key()` and loses the dot",
        "file": AUDIT_PAGE,
        "expect": "database",
        "old": "            'event' => self::eventFilter($request),",
        "new": "            'event' => $request->queryKey('event'),",
    },
    {
        "name": "any string reaches the event filter, allowlist or not",
        "file": AUDIT_PAGE,
        "expect": "database",
        "old": "        return isset(AuditEventCatalog::allowlist()[$raw]) ? $raw : '';",
        "new": "        return $raw;",
    },
    {
        "name": "the audit timestamp is printed as stored, in UTC",
        "file": AUDIT_PAGE,
        "expect": "database",
        "old": "            . esc_html($fa($this->time()->render($record->createdAtUtc))) . '</td>'",
        "new": "            . esc_html($fa($record->createdAtUtc->format('Y-m-d H:i'))) . '</td>'",
    },
    {
        "name": "the audit date filter treats a local date as a UTC midnight",
        "file": AUDIT_REPO,
        "expect": "database",
        "old": """        $from = $this->time()->dayStartUtc((string) ($filters['from'] ?? ''));
        if ($from !== null) {
            $clauses[] = 'created_at >= %s';
            $params[] = $from;
        }""",
        "new": """        if (($filters['from'] ?? '') !== '') {
            $clauses[] = 'created_at >= %s';
            $params[] = (string) $filters['from'] . ' 00:00:00';
        }""",
    },
    {
        # ⛔ MEASURED UNREACHABLE on the first run, and re-pointed rather than
        # dropped. Removing this guard changed no behaviour, because the
        # round-trip check below rejects everything it rejects — so the
        # property is asserted in the SOURCE and the mutation measures that
        # (`alpha.77`). Dropping the mutation instead would have left a
        # defence-in-depth guard that the next round deletes as dead code.
        "name": "a date nobody can place becomes a window anyway",
        "file": SITE_TIME,
        "expect": "architecture",
        "old": """        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) !== 1) {
            return null;
        }""",
        "new": """        if ($date === '') {
            return null;
        }""",
    },
    {
        "name": "a date that is not on the calendar is rolled over instead of refused",
        "file": SITE_TIME,
        "expect": "unit",
        "old": """        if ($local->format('Y-m-d') !== $date) {
            return null;
        }""",
        "new": """        if (false) {
            return null;
        }""",
    },
    {
        # ⛔ SURVIVED on the first run: the widened allowlist had NO test.
        # `AuditTest` now asserts the five keys the narrow list dropped —
        # `failed`, `refused_unit`, `repaired`, `incomplete`, `reasons` — the
        # ones a person needs when a capture went wrong.
        "name": "the capture's refusal reasons are dropped from the audit row again",
        "file": CATALOG,
        "expect": "unit",
        "old": """            self::ORDER_CAPTURED => [
                'order_id', 'vendors', 'items', 'recorded', 'skipped',
                'failed', 'refused_unit', 'repaired', 'incomplete', 'reasons',
            ],""",
        "new": """            self::ORDER_CAPTURED => ['order_id', 'vendors', 'items', 'recorded', 'skipped'],""",
    },
    # ── §7 the detailed report ──────────────────────────────────────────────
    {
        "name": "a vendor's scope is validated instead of overwritten, so a filter widens it",
        "file": REPORT,
        "expect": "database",
        "old": "        return $this->page($filter->forVendor($vendorUserId));",
        "new": "        return $this->page($filter);",
    },
    {
        "name": "the marketplace-wide report stops asking for the finance capability",
        "file": REPORT,
        "expect": "database",
        "old": "        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::VIEW_VENDOR_FINANCE)) {",
        "new": "        if (false) {",
    },
    {
        "name": "a cancelled line counts towards the valid total again",
        "file": REPORT,
        "expect": "unit",
        "old": """            if ((string) $row['item_status'] === OrderItemStatus::Cancelled->value) {
                continue;                       // sold once, owed by nobody
            }""",
        "new": """            if (false) {
                continue;
            }""",
    },
    {
        "name": "two currencies are added together",
        "file": REPORT,
        "expect": "unit",
        "old": """            } elseif ($currency !== $rowCurrency) {
                return [
                    'historic' => null, 'valid' => null, 'pending' => null,
                    'currency' => '', 'exponent' => null,
                    'refused' => 'mixed_currencies', 'lines' => count($rows),
                ];
            }""",
        "new": """            }""",
    },
    {
        "name": "the reversal lookup is asked once per row",
        "file": REPORT,
        "expect": "database",
        "old": """        $present = [];
        foreach ($this->ledger->eventsPresent($voidKeys) as $key) {
            $present[$key] = true;
        }""",
        "new": """        $present = [];
        foreach ($voidKeys as $key) {
            if ($this->ledger->hasEvent($key)) {
                $present[$key] = true;
            }
        }""",
    },
    {
        # ⛔ SURVIVED on the first run: the docblock cited `alpha.8` and the
        # guard had NO test. `testACountThatCouldNotBeTakenIsUnknownRatherThanNought`
        # now injects a failure on the COUNT by name, with two controls —
        # nought for an empty shop, a number for a healthy read.
        "name": "the report's page count is derived from the page's own length",
        "file": ITEM_REPO,
        "expect": "database",
        "old": """        $total = $counted === null ? null : (int) $counted;""",
        "new": """        $total = $counted === null ? 0 : (int) $counted;""",
    },
    {
        "name": "the sort comes from the request",
        "file": ITEM_REPO,
        "expect": "architecture",
        "old": """    private static function orderBy(string $sort): string
    {
        return match ($sort) {""",
        "new": """    private static function orderBy(string $sort): string
    {
        return $sort !== '' ? $sort : match ($sort) {""",
    },
    {
        "name": "a search term's wildcards are not escaped",
        "file": ITEM_REPO,
        "expect": "database",
        "old": "        return addcslashes($value, '_%\\\\');",
        "new": "        return $value;",
    },
    {
        "name": "the count and the page build two different WHERE clauses",
        "file": ITEM_REPO,
        "expect": "architecture",
        "old": """        $counted = $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` ' . $where,
            $params
        );""",
        "new": """        [$countWhere, $countParams] = $this->detailedWhere($filter, $mirror);
        $counted = $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` ' . $countWhere,
            $countParams
        );""",
    },
    {
        "name": "an id of nought becomes a filter that matches nothing",
        "file": FILTER,
        "expect": "unit",
        "old": "            return $raw === '' || !ctype_digit($raw) || (int) $raw <= 0 ? null : (int) $raw;",
        "new": "            return $raw === '' ? null : (int) $raw;",
    },
    {
        "name": "a page size from the request reaches the LIMIT",
        "file": FILTER,
        "expect": "unit",
        "old": "            in_array($perPage, self::PER_PAGE, true) ? $perPage : self::PER_PAGE[0],",
        "new": "            $perPage > 0 ? $perPage : self::PER_PAGE[0],",
    },
    {
        "name": "the status mirror column stops being nullable, so «unknown» is unsayable",
        "file": MIGRATION,
        "expect": "architecture,database",
        "old": "    public const DEFINITION = 'VARCHAR(32) NULL DEFAULT NULL';",
        "new": "    public const DEFINITION = \"VARCHAR(32) NOT NULL DEFAULT 'pending'\";",
    },
    {
        "name": "the migration stops adding the indexes the report filters on",
        "file": MIGRATION,
        "expect": "database",
        "old": """        foreach ([
            self::STATUS_INDEX => '`vendor_user_id`, `' . self::COLUMN . '`',
            self::CREATED_INDEX => '`vendor_user_id`, `created_at`',
        ] as $name => $columns) {""",
        "new": """        foreach ([] as $name => $columns) {""",
    },
    {
        "name": "a failed catalogue read is treated as «the column is absent»",
        "file": MIGRATION,
        "expect": "database",
        "old": """        if ($rows === []) {
            // An order-items table with no columns does not exist: this is a
            // read that failed, not a table that is empty.
            return null;
        }""",
        "new": """        if ($rows === []) {
            return false;
        }""",
    },
]


def body(n: int) -> dict:
    if n < 1 or n > len(MUTATIONS):
        raise SystemExit(f"no mutation {n}; there are {len(MUTATIONS)}")
    return MUTATIONS[n - 1]


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: alpha99-mutations.py count|name <n>|file <n>|expect <n>|apply <n>")
        return 2
    verb = sys.argv[1]
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    m = body(n)
    if verb == "name":
        print(m["name"])
        return 0
    if verb == "file":
        print(m["file"])
        return 0
    if verb == "expect":
        print(m["expect"])
        return 0
    if verb != "apply":
        print("unknown verb " + verb)
        return 2

    path = pathlib.Path(m["file"])
    text = path.read_text()
    found = text.count(m["old"])
    if found != 1:
        # NOT a silent skip. A mutation that does not apply measured nothing,
        # and that is indistinguishable from a guard that cannot be broken.
        print(f"REFUSED: snippet occurs {found} time(s) in {m['file']}", file=sys.stderr)
        return 3
    path.write_text(text.replace(m["old"], m["new"]))
    print("applied: " + m["name"])
    print("sha256 after: " + hashlib.sha256(path.read_bytes()).hexdigest())
    return 0


if __name__ == "__main__":
    sys.exit(main())
