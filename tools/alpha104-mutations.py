#!/usr/bin/env python3
"""The mutations for alpha.104 — each one a fix of this round put back wrong.

Run through `tools/alpha104-falsification.sh`, which restores every file byte
for byte afterwards and compares hashes. A mutation that does NOT apply is a
failure, not an unbreakable guard (`alpha.77`): «nothing was measured» looks
byte for byte like «this cannot be broken».

Every `old` must occur EXACTLY ONCE in its file (`alpha.88`), and each name
says what it BREAKS rather than what it edits (`alpha.82`).
"""
import hashlib
import pathlib
import sys

COVERAGE = "src/Modules/Finance/Application/CommissionCoverage.php"
REVIEW_PAGE = "src/Modules/Product/Presentation/Admin/ProductReviewPage.php"
SANITIZE_TEST = "tests/WordPressContract/SanitizeTitleTest.php"

MUTATIONS = [
    # ── §1 the P0: which products would earn nothing after the exit ─────────
    {
        "name": "an explicit «no commission» is counted as nobody deciding",
        "file": COVERAGE,
        "expect": "database",
        "old": "                $counts[$rule->isNone() ? self::DECIDED_NONE : self::EARNS]++;",
        "new": "                if ($rule->isNone()) {\n"
               "                    $counts[self::NO_RULE]++;\n"
               "                    $uncoveredTotal++;\n"
               "                    if (count($uncovered) < self::NAMES) {\n"
               "                        $uncovered[] = $product->id;\n"
               "                    }\n"
               "                    continue;\n"
               "                }\n"
               "                $counts[self::EARNS]++;",
    },
    {
        "name": "a row this marketplace does not sell is counted as well",
        "file": COVERAGE,
        "expect": "database",
        "old": "                if ($product->linkOwnership !== LinkOwnership::MARKETPLACE_VALUE) {\n"
               "                    continue;\n"
               "                }\n",
        "new": "",
    },
    {
        "name": "the scan never reads past the first page",
        "file": COVERAGE,
        "expect": "database",
        "old": "        for ($page = 0; $page < self::MAX_PAGES; $page++) {",
        "new": "        for ($page = 0; $page < 1; $page++) {",
    },
    {
        "name": "the card walks the catalogue on every visit to the page",
        "file": REVIEW_PAGE,
        "expect": "database",
        "old": "        $asked = $request->isPost()\n"
               "            && $request->postKey('commission_action') === 'scan'\n"
               "            && $request->nonceOk('tmc_review_nonce', self::NONCE);",
        "new": "        $asked = true;",
    },
    # ── §2 the interpreter: the skip must stay as narrow as the dependency ──
    #
    # The round's own fix opened a hole of its own shape: a predicate that
    # skipped MORE than the missing extension would stop measuring the
    # fifteen pinned pairs and report «OK» while measuring nothing. So the
    # predicate has an assertion of its own, and this is the mutation of it.
    {
        "name": "the ext-intl skip widens until nothing is measured",
        "file": SANITIZE_TEST,
        "expect": "contract",
        "old": "        return !class_exists(\\Normalizer::class)\n"
               "            && preg_match('/[\\x{00C0}-\\x{024F}\\x{1E00}-\\x{1EFF}]/u', $title) === 1;",
        "new": "        return true;",
    },
]


def main() -> int:
    root = pathlib.Path(__file__).resolve().parent.parent
    if len(sys.argv) < 2:
        print("usage: alpha104-mutations.py count|name <n>|file <n>|expect <n>|apply <n>")
        return 2
    verb = sys.argv[1]
    if verb == "count":
        print(len(MUTATIONS))
        return 0
    index = int(sys.argv[2]) - 1
    if index < 0 or index >= len(MUTATIONS):
        print("no such mutation", file=sys.stderr)
        return 2
    m = MUTATIONS[index]
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
        print("unknown verb", file=sys.stderr)
        return 2

    path = root / m["file"]
    text = path.read_text(encoding="utf-8")
    hits = text.count(m["old"])
    if hits != 1:
        # NOT applying is a failure, not a guard that cannot be broken.
        print(f"REFUSED: anchor occurs {hits} times in {m['file']}", file=sys.stderr)
        return 1
    before = hashlib.sha256(text.encode()).hexdigest()
    path.write_text(text.replace(m["old"], m["new"], 1), encoding="utf-8")
    after = hashlib.sha256(path.read_text(encoding="utf-8").encode()).hexdigest()
    if before == after:
        print("REFUSED: the file did not change", file=sys.stderr)
        return 1
    print(f"applied: {m['name']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
