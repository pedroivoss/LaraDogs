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
 */
final readonly class ProjectSummary
{
    /**
     * @param  array<string,int>  $openFindingsBySeverity  keyed by {@see Severity} value
     * @param  array<string,int>  $openFindingsByCategory  keyed by {@see AnalyzerCategory} value
     * @param  array<string,string>  $lastScanAnalyzerStatuses  analyzer id => {@see ExecutionStatus} value
     */
    public function __construct(
        public int $totalFindings,
        public int $openFindings,
        public array $openFindingsBySeverity,
        public array $openFindingsByCategory,
        public array $lastScanAnalyzerStatuses,
        public ?Scan $lastScan,
    ) {}
}
