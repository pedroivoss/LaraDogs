<?php

namespace App\Audit\Findings\Ingestion;

use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Execution\AnalyzerResult;
use App\Audit\Findings\FindingCandidate;

/**
 * Implemented by concrete analyzers (`App\Audit\Analyzers\*`) that both run
 * against the Engine's `Analyzer` contract AND know how to normalize their
 * own {@see AnalyzerResult} into {@see FindingCandidate}s.
 *
 * This interface — not `Analyzer` itself — is what lets a real analyzer
 * depend on the Findings domain: `App\Audit\Engine` must never depend on
 * `App\Audit\Findings` (see ADR-0010), so `Analyzer` stays Findings-free.
 * A concrete analyzer class is free to implement both `Analyzer` and this
 * interface at once, living in an outer namespace that may legitimately
 * depend on both.
 *
 * `candidates()` must be a pure function of its two arguments — no hidden
 * state, no re-running the underlying tool, no I/O. It reads `$result`
 * (typically `$result->rawMetadata`, populated by that analyzer's own
 * `run()`) and maps it to zero or more candidates. Called only after
 * `run()` produced a `Passed` result — an analyzer that failed or timed out
 * has nothing reliable to normalize.
 */
interface ProducesFindingCandidates
{
    /**
     * @return list<FindingCandidate>
     */
    public function candidates(AuditContext $context, AnalyzerResult $result): array;
}
