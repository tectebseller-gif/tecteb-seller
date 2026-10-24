#!/usr/bin/env python3
"""The mutations `alpha77-falsification.sh` puts back, one per guard.

⛔ In Python and not inline in the shell script, and that is not a style
choice: every one of these snippets is PHP, so it is full of `$variable` and
of single quotes. An unquoted shell heredoc would expand `$progress` to
nothing and the mutation would silently fail to apply — a falsification run
that measures nothing and reports success. Here the text is data.

Usage:
    python3 tools/alpha77-mutations.py count
    python3 tools/alpha77-mutations.py name <n>
    python3 tools/alpha77-mutations.py apply <n>
"""
import io
import sys

MUTATIONS = [
    {
        "name": "a failed source read carries a count of nought",
        "file": "src/Modules/Migration/Domain/RecordSource.php",
        "old": "return new self(self::UNREADABLE, null);",
        "new": "return new self(self::UNREADABLE, 0);",
    },
    {
        "name": "the row count is read before unreadability is ruled out",
        "file": "src/Modules/Migration/Application/ShopRecordPreview.php",
        "old": "if ($source->state === RecordSource::UNREADABLE) {",
        "new": "if (false) {",
    },
    {
        "name": "the kept cursor positions are captured by value",
        "file": "src/Modules/Migration/Application/DokanRecordImportJob.php",
        "old": "$carry = static function (array $cursor) use (&$kept): array {",
        "new": "$carry = static function (array $cursor) use ($kept): array {",
    },
    {
        "name": "the record write runs outside the lease gate",
        "file": "src/Modules/Migration/Application/ImportFromDokan.php",
        "old": """                $hold = $progress->writing(function () use ($write, $unit, &$tally): bool {
                    $tally = $write($unit);
                    return $tally['failed'] === 0;
                });""",
        "new": "                $tally = $write($unit);",
    },
    {
        "name": "the record step name is taken off the allowlist",
        "file": "src/Core/Jobs/StepTiming.php",
        "old": "        'record_write',\n    ];",
        "new": "    ];",
    },
    {
        "name": "the handover page asks only which shops have records",
        "file": "src/Modules/Migration/Presentation/Admin/HandoverPage.php",
        # ⛔ With the following line, because `vendorUserIds()` is called
        # TWICE in that file — once to draw the page and once in the import
        # action — and a mutation that matches twice does not apply at all.
        "old": """$shops = $this->container->get(MigrationCompleteness::class)->vendorUserIds();
        if ($shops === []) {""",
        "new": """$shops = $this->container->get(ShopRecordRepositoryInterface::class)->vendorsWithRecords();
        if ($shops === []) {""",
    },
    {
        "name": "the enqueued total leaves the order phase out again",
        "file": "src/Modules/Migration/Presentation/Admin/MigrationPage.php",
        "old": """                    'total' => (int) $summary['vendors']
                        + (int) $summary['products']
                        + (int) ($summary['orders'] ?? 0),""",
        "new": "                    'total' => (int) $summary['vendors'] + (int) $summary['products'],",
    },
    {
        "name": "the queue page clamps instead of saying the ceiling was short",
        "file": "src/Modules/Operations/Presentation/Admin/QueuePage.php",
        "old": "} elseif ($job->total > 0 && $accounted > $job->total) {",
        "new": "} elseif (false) {",
    },
    {
        "name": "the active phase reads one shared position",
        "file": "src/Modules/Migration/Application/DokanRecordImportJob.php",
        "old": "$after = $kept[self::POSITION[$phase]];",
        "new": "$after = (int) ($job->cursor['after'] ?? 0);",
    },
    {
        "name": "the preview is given a way to write",
        "file": "src/Modules/Migration/Application/ShopRecordPreview.php",
        "old": "    public function forVendor(int $vendorUserId): array\n    {",
        "new": """    public function importStaff(string $runId, array $member): string
    {
        return (string) $this->records?->recordStaff($runId, $member);
    }

    public function forVendor(int $vendorUserId): array
    {""",
    },
]


def main() -> int:
    verb = sys.argv[1]
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    index = int(sys.argv[2]) - 1
    if index < 0 or index >= len(MUTATIONS):
        sys.stderr.write("no such mutation\n")
        return 2
    mutation = MUTATIONS[index]
    if verb == "name":
        print(mutation["name"])
        return 0
    if verb == "file":
        print(mutation["file"])
        return 0
    if verb != "apply":
        sys.stderr.write("unknown verb\n")
        return 2
    path = mutation["file"]
    source = io.open(path, encoding="utf-8").read()
    hits = source.count(mutation["old"])
    if hits != 1:
        # Loud, because a mutation that does not apply looks exactly like a
        # guard that cannot be broken.
        sys.stderr.write(
            "MUTATION %d DID NOT APPLY: %d matches in %s\n" % (index + 1, hits, path)
        )
        return 3
    io.open(path, "w", encoding="utf-8").write(
        source.replace(mutation["old"], mutation["new"], 1)
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
