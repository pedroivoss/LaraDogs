<?php

namespace App\Audit\Analyzers\Semgrep;

/**
 * One `results[]` entry from a real `semgrep --json` scan, already
 * normalized by {@see SemgrepParser} — `ruleId` here is always one of
 * {@see SemgrepRuleCatalog::ruleIds()}, never Semgrep's raw `check_id`
 * verbatim (see that parser's own docblock for why `check_id` is never
 * trusted as a clean, stable string on its own).
 *
 * `path` is Semgrep's own reported path for the match — typically
 * absolute, since {@see SemgrepAnalyzer} always invokes Semgrep with
 * absolute target file arguments (see {@see SemgrepTargetCollector}).
 * Project-relative normalization happens in
 * `SemgrepAnalyzer::candidates()`, not here — this class is a faithful,
 * un-opinionated transcription of one match, nothing more.
 */
final readonly class SemgrepFinding
{
    /**
     * @param  array<string,mixed>  $metadata  The rule's own YAML
     *                                         `metadata:` block, passed
     *                                         through by Semgrep verbatim
     *                                         (`extra.metadata`) — e.g.
     *                                         `cwe`/`references` for rules
     *                                         that declare them.
     */
    public function __construct(
        public string $ruleId,
        public string $path,
        public int $startLine,
        public int $startColumn,
        public int $endLine,
        public int $endColumn,
        public string $message,
        public string $rawSeverity,
        public array $metadata,
    ) {}
}
