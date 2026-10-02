<?php

namespace App\Audit\Remediation;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\Confidence;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Severity;
use App\Audit\Source\Git\GitSnapshot;

/**
 * Everything the {@see RemediationPlanner} may look at — loaded once, in a
 * bounded number of queries, by {@see FindingRemediationQuery}. A plain value
 * object: the planner needs no database, filesystem, process or network.
 *
 * All text fields are RAW persisted/target-derived data (untrusted); the
 * planner passes them through the shared sanitizer before they leave.
 */
final readonly class RemediationEvidence
{
    /**
     * @param  list<string>  $references
     * @param  array<string,mixed>  $metadata  persisted (already redacted) finding metadata
     * @param  array<string,mixed>|null  $profile  project profile snapshot of the last-seen scan
     */
    public function __construct(
        public string $findingId,
        public string $projectId,
        public string $projectRoot,
        public string $ruleId,
        public string $analyzerId,
        public AnalyzerCategory $category,
        public Severity $severity,
        public Confidence $confidence,
        public FindingStatus $status,
        public string $title,
        public ?string $description,
        public ?string $impact,
        public ?string $cwe,
        public ?string $cve,
        public array $references,
        public array $metadata,
        public ?string $filePath,
        public ?int $lineStart,
        public ?int $lineEnd,
        public ?string $snippet,
        public ?string $ruleVersion,
        public ?string $analyzerVersion,
        public ?array $profile,
        public ?GitSnapshot $observedSource,
        public ?GitSnapshot $currentSource,
        public ?GateFacts $gate,
    ) {}
}
