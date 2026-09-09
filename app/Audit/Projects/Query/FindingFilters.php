<?php

namespace App\Audit\Projects\Query;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Severity;

/**
 * Backend/query-layer filter boundary for {@see CurrentFindingsQuery} — no
 * UI exists yet (deliberately out of scope this phase), but a future
 * Dashboard/MCP adapter can build one of these from request input without
 * any change to the query itself.
 */
final readonly class FindingFilters
{
    /**
     * @param  list<FindingStatus>|null  $status
     * @param  list<Severity>|null  $severity
     * @param  list<AnalyzerCategory>|null  $category
     */
    public function __construct(
        public ?array $status = null,
        public ?array $severity = null,
        public ?array $category = null,
        public ?string $analyzerId = null,
        public ?string $ruleId = null,
    ) {}
}
