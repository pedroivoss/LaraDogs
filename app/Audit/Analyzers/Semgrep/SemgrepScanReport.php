<?php

namespace App\Audit\Analyzers\Semgrep;

/**
 * A normalized `semgrep --json` scan report — parsed by
 * {@see SemgrepParser}, consumed by {@see SemgrepAnalyzer} and
 * {@see SemgrepCoverageEvaluator}.
 *
 * `errors`/`skipped` are kept as plain, defensively-typed arrays (not
 * further value objects) — they exist purely for the coverage-safety
 * decision and diagnostics, never for Finding normalization, so a fuller
 * type would be unused ceremony (see docs/auditing/analyzers/semgrep.md).
 */
final readonly class SemgrepScanReport
{
    /**
     * @param  list<SemgrepFinding>  $findings
     * @param  list<array{code: int|null, level: string, type: string, message: string, path: string|null}>  $errors
     * @param  list<array{path: string, reason: string}>  $skipped
     * @param  list<string>  $scanned
     */
    public function __construct(
        public array $findings,
        public array $errors,
        public array $skipped,
        public array $scanned,
        public ?string $semgrepVersion,
    ) {}
}
