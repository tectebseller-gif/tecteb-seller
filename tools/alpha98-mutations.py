#!/usr/bin/env python3
"""The mutations for alpha.98, each one a fix of this round put back wrong.

Run through `tools/alpha98-falsification.sh`, which restores every file byte
for byte afterwards and compares hashes. A mutation that does NOT apply is a
failure, not an unbreakable guard (`alpha.77`): «nothing was measured» looks
byte for byte like «this cannot be broken».

Every `old` here must occur EXACTLY ONCE in its file. A snippet that matches
twice is refused by the harness below rather than applied to the first hit
(`alpha.88`).

⛔ And each one's name says what it BREAKS, not what it edits — `alpha.82`
measured that a mutation whose name does not match its work is worse than no
mutation, because nobody can check it.
"""
import hashlib
import pathlib
import sys

GATE = "src/Modules/Order/Application/OrderOperationsGate.php"
TRIAL = "src/Modules/Order/Application/TrialUnlock.php"
PROBE = "src/Modules/Order/Infrastructure/WordPress/WpDokanPresence.php"
BOOTSTRAP = "src/Infrastructure/WordPress/Bootstrap.php"
RESOLVER = "src/Core/Environment/EnvironmentResolver.php"
BUILD = "tools/build.sh"

MUTATIONS = [
    {
        "name": "the order gate stops asking whether Dokan is running",
        "file": GATE,
        "expect": "unit",
        "old": """        if ($this->dokan?->isRunning() === true) {
            return [
                'ready' => false,
                'reason' => self::DOKAN_RUNNING,""",
        "new": """        if (false) {
            return [
                'ready' => false,
                'reason' => self::DOKAN_RUNNING,""",
    },
    {
        "name": "a trial can waive Dokan, because the trial is asked first",
        "file": GATE,
        "expect": "architecture",
        "old": """        if ($this->dokan?->isRunning() === true) {
            return [
                'ready' => false,
                'reason' => self::DOKAN_RUNNING,
                'detail' => 'dokan is running (' . $this->dokan->signal()
                    . '); it attributes the same products by post_author and would record the sale too',
            ];
        }
        // The decisions come next""",
        "new": """        // The decisions come next""",
    },
    {
        "name": "blockers() stops naming Dokan",
        "file": GATE,
        "expect": "unit",
        "old": """        if ($this->dokan?->isRunning() === true) {
            // Named on its own line rather than joined with the decisions:""",
        "new": """        if (false) {
            // Named on its own line rather than joined with the decisions:""",
    },
    {
        "name": "the Dokan branch records the reason and falls through",
        "file": GATE,
        "expect": "architecture",
        "old": """            return [
                'ready' => false,
                'reason' => self::DOKAN_RUNNING,
                'detail' => 'dokan is running (' . $this->dokan->signal()
                    . '); it attributes the same products by post_author and would record the sale too',
            ];""",
        "new": """            $noted = self::DOKAN_RUNNING;
            unset($noted);""",
    },
    {
        "name": "the container stops passing the probe into the gate",
        "file": BOOTSTRAP,
        "expect": "architecture",
        "old": """            $c->get(DokanPresenceInterface::class)
        ));""",
        "new": """        ));""",
    },
    {
        "name": "the probe forgets the active-plugin list, so a broken Dokan passes",
        "file": PROBE,
        "expect": "architecture",
        "old": """        $active = $this->activePlugins();
        foreach (self::PLUGIN_FILES as $file) {""",
        "new": """        $active = [];
        foreach (self::PLUGIN_FILES as $file) {""",
    },
    {
        "name": "the probe asks about Dokan's tables, which the exit keeps",
        "file": PROBE,
        "expect": "architecture",
        "old": """        if (function_exists('dokan')) {
            return 'function_dokan';
        }""",
        "new": """        if (function_exists('dokan')) {
            return 'function_dokan';
        }
        if ($this->tableExists('dokan_orders')) {
            return 'table_dokan_orders';
        }""",
    },
    {
        "name": "a trial grant is honoured on any site again",
        "file": TRIAL,
        "expect": "unit",
        "old": """        return $this->isRequested() && $this->isPermitted() && $this->isForThisSite();""",
        "new": """        return $this->isRequested() && $this->isPermitted();""",
    },
    {
        "name": "the grant is stored without the site it was granted on",
        "file": TRIAL,
        "expect": "unit",
        "old": """        return $this->options->set(self::OPTION, [self::GRANTED => true, self::HOST => $here]);""",
        "new": """        return $this->options->set(self::OPTION, true);""",
    },
    {
        "name": "a grant with no host recorded is trusted everywhere",
        "file": TRIAL,
        "expect": "unit",
        "old": """        return $granted !== '' && $here !== null && hash_equals($granted, $here);""",
        "new": """        return $granted === '' || ($here !== null && hash_equals($granted, $here));""",
    },
    {
        "name": "the screen is not told why a grant was refused",
        "file": TRIAL,
        "expect": "unit",
        "old": """        if (!$this->isForThisSite()) {""",
        "new": """        if (false) {""",
    },
    {
        "name": "the resolver cannot say which site this is",
        "file": RESOLVER,
        "expect": "unit",
        "old": """        $host = $this->probe->hostname();
        return is_string($host) && $host !== '' ? strtolower($host) : null;""",
        "new": """        return null;""",
    },
    {
        "name": "the two read-only tools stop shipping inside the package",
        "file": BUILD,
        "expect": "packaging",
        "old": """for ro in shop-dependency-census.php plugin-exit-inventory.php; do
  cp "tools/${ro}" "${PAYLOAD}/admin-tools/${ro}"
done""",
        "new": """for ro in ; do
  cp "tools/${ro}" "${PAYLOAD}/admin-tools/${ro}"
done""",
    },
]


def body(index: int) -> dict:
    return MUTATIONS[index - 1]


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: alpha98-mutations.py count|name <n>|file <n>|expect <n>|apply <n>")
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
