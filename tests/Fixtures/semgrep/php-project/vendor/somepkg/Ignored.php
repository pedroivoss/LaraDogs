<?php

// A third-party vendor file — must never be scanned by SemgrepTargetCollector
// (vendor/ is always excluded, regardless of what any .gitignore says).
// Contains an eval() call that must NEVER be reported as a finding: if this
// ever shows up in a test's candidates, the vendor exclusion has regressed.
eval('1;');
