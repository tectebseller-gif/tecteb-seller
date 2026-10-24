#!/usr/bin/env python3
"""The mutations `alpha.79`'s four fixes are falsified with.

⛔ The mutation lives in DATA, never in a shell heredoc: every PHP fragment
below is full of `$variable` and single quotes, and an unquoted heredoc empties
them so the mutation applies silently to nothing (`alpha.77`).

Each entry says which file, the exact text to replace, what to put there, and
which test filter must FAIL once it is applied. `apply` refuses when the needle
is absent or not unique — «nothing was measured» is byte-for-byte identical to
«this guard cannot be broken», so it has to be a failure.
"""
import io
import sys

SRC = 'src/Modules/Migration/Application/'

MUTATIONS = [
    {
        'name': 'shop criterion counts a record again',
        'file': SRC + 'MigrationCompleteness.php',
        'old': """    public function shopVendorUserIds(): array
    {
        $ids = [];""",
        'new': """    public function shopVendorUserIds(): array
    {
        $ids = array_map('intval', $this->records->vendorsWithRecords());""",
        'expect': 'ShopRecordScopeTest|BeingAShopIsNotOwningRecordsTest',
    },
    {
        'name': 'the record walk stops asking the shown scope',
        'file': SRC + 'ImportFromDokan.php',
        'old': """            if ($this->recordScope !== null) {
                $owner = $ownerOf($unit);
                if (!$this->recordScope->admits($owner)) {""",
        'new': """            if (false) {
                $owner = $ownerOf($unit);
                if (!$this->recordScope->admits($owner)) {""",
        'expect': 'ShopRecordScopeTest|RecordImportIsHistoryOnlyTest',
    },
    {
        'name': 'an unreadable scope admits everybody',
        'file': SRC + 'ShopRecordScope.php',
        'old': """        if (!$this->readable()) {
            return false;
        }
        return in_array($vendorUserId, $this->ids(), true);""",
        'new': """        return in_array($vendorUserId, $this->ids(), true);""",
        'expect': 'ShopRecordScopeTest',
    },
    {
        'name': 'the record gate falls back to a count',
        'file': SRC + 'MigrationCompleteness.php',
        'old': """            'records_imported' => match ($records['state']) {
                self::RECORDS_SETTLED, self::RECORDS_NO_SOURCE => true,
                default => false,
            },""",
        'new': """            'records_imported' => match ($records['state']) {
                self::RECORDS_SETTLED, self::RECORDS_NO_SOURCE => true,
                self::RECORDS_OUTSTANDING, self::RECORDS_UNREADABLE => false,
                default => ($summary['balance_rows'] + $summary['withdrawals'] + $summary['staff']) > 0
                    || $tally['marketplace'] + $tally['observed'] > 0,
            },""",
        'expect': 'ShopRecordScopeTest|BeingAShopIsNotOwningRecordsTest',
    },
]


def main() -> int:
    verb = sys.argv[1] if len(sys.argv) > 1 else ''
    if verb == 'count':
        print(len(MUTATIONS))
        return 0
    n = int(sys.argv[2])
    m = MUTATIONS[n]
    if verb == 'name':
        print(m['name'])
        return 0
    if verb == 'file':
        print(m['file'])
        return 0
    if verb == 'expect':
        print(m['expect'])
        return 0
    if verb == 'apply':
        s = io.open(m['file'], encoding='utf-8').read()
        if s.count(m['old']) != 1:
            print('NEEDLE NOT UNIQUE (%d) in %s' % (s.count(m['old']), m['file']))
            return 2
        io.open(m['file'], 'w', encoding='utf-8').write(s.replace(m['old'], m['new'], 1))
        return 0
    print('usage: count | name <n> | file <n> | expect <n> | apply <n>')
    return 2


if __name__ == '__main__':
    raise SystemExit(main())
