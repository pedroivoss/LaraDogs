<?php

namespace App\Audit\QualityGates\Query;

use App\Models\Audit\Project;
use App\Models\Audit\ProjectQualityGate;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\Scan;

/**
 * Read side of Quality Gates for the Dashboard/CLI — bounded queries only
 * (one policy row, one result with its handful of rule rows), never a
 * per-project loop.
 */
final class ProjectQualityGateQuery
{
    public function policyFor(Project $project): ?ProjectQualityGate
    {
        return ProjectQualityGate::query()->where('project_id', $project->id)->first();
    }

    /**
     * The scan's immutable result with its rule rows and baseline, or null
     * when the scan was not evaluated (gate disabled at the time, or a
     * scan from before Quality Gates existed).
     */
    public function resultForScan(Scan $scan): ?QualityGateResult
    {
        return QualityGateResult::query()
            ->where('scan_id', $scan->id)
            ->with(['ruleResults', 'baselineScan:id,public_id'])
            ->first();
    }
}
