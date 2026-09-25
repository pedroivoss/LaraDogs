<?php

namespace App\Http\Support;

use App\Audit\Source\Git\GitRepositoryInspector;
use App\Audit\Source\Git\GitSnapshot;
use App\Audit\Source\Git\SourceConsistency;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;

/**
 * CURRENT source (a request-time, read-only, bounded Git inspection) next
 * to the LAST AUDITED source (the immutable snapshot persisted on the last
 * completed scan) — never one standing in for the other (Phase 9). Shared by
 * the Dashboard's Project Detail and the MCP `get_project_source` tool so
 * both describe source state identically.
 */
final class SourceOverviewPayload
{
    /**
     * @return array<string,mixed>
     */
    public static function for(Project $project, ?Scan $lastCompleted, GitRepositoryInspector $git): array
    {
        $current = $git->inspect($project->path);
        $audited = $lastCompleted === null ? null : GitSnapshot::fromScan($lastCompleted);
        $comparison = $audited === null ? null : SourceConsistency::sameSource($audited, $current);

        return [
            'current' => SourcePayload::snapshot($current),
            'last_audited' => $lastCompleted === null ? null : [
                'scan_id' => $lastCompleted->public_id,
                'source' => SourcePayload::forScan($lastCompleted),
            ],
            // null = not comparable (no Git repository, legacy scan, ...).
            'changed_since_last_audit' => $comparison === null ? null : ! $comparison,
        ];
    }
}
