<?php

namespace App\Audit\Engine\Execution;

/**
 * How confidently an analyzer's own execution covered the rules/findings
 * it's responsible for — see {@see AnalyzerCoverage}. Deliberately
 * independent of {@see ExecutionStatus}: `Passed` means "the analyzer ran
 * without error," never "the analyzer verified everything it has ever
 * produced a finding for."
 */
enum CoverageMode: string
{
    /** No usable coverage evidence — the safe default. Never authorizes auto-resolution. */
    case Unknown = 'unknown';

    /** The analyzer declares exactly which rule ids it verified this run. */
    case Explicit = 'explicit';

    /**
     * The analyzer declares its run covered its entire relevant domain.
     * Never inferred from a Passed status — only ever set by an explicit
     * analyzer declaration.
     */
    case Full = 'full';
}
