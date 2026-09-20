<?php

namespace App\Audit\Projects\Query;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Findings\Severity;
use App\Models\Audit\Scan;

/**
 * Efficient project/scan summary data for a future dashboard — see
 * {@see ProjectSummaryQuery}. Deliberately does NOT compute a health
 * score: no formula for one has been specified, and inventing one here
 * would be scope creep this phase explicitly avoids.
 *
 * "Historical context" (everything below) only ever comes from TERMINAL
 * scans (`Completed`/`Failed`) — never from an active `Queued`/`Running`
 * one, which has no analyzer executions yet and (while `Queued`) only an
 * empty placeholder profile. The active scan is a separate concept, see
 * `Project::activeScan()`.
 */
final readonly class ProjectSummary
{
    /**
     * @param  array<string,int>  $openFindingsBySeverity  keyed by {@see Severity} value
     * @param  array<string,int>  $openFindingsByCategory  keyed by {@see AnalyzerCategory} value
     * @param  array<string,string>  $lastCompletedScanAnalyzerStatuses  analyzer id => {@see ExecutionStatus} value,
     *                                                                   from {@see $lastCompletedScan} only
     * @param  Scan|null  $lastScan  the most recent TERMINAL scan (Completed or Failed) —
     *                               what "the last audit" means; a Failed one is
     *                               surfaced as such, never as a clean result
     * @param  Scan|null  $lastCompletedScan  the most recent Completed scan, with its
     *                                        analyzer executions loaded — the only source of
     *                                        analyzer status (executions are persisted only
     *                                        when a scan completes)
     * @param  array<string,mixed>|null  $profile  the most recent valid (non-empty) project-profile
     *                                             snapshot among terminal scans
     */
    public function __construct(
        public int $totalFindings,
        public int $openFindings,
        public array $openFindingsBySeverity,
        public array $openFindingsByCategory,
        public array $lastCompletedScanAnalyzerStatuses,
        public ?Scan $lastScan,
        public ?Scan $lastCompletedScan,
        public ?array $profile,
    ) {}
}
