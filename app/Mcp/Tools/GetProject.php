<?php

namespace App\Mcp\Tools;

use App\Audit\Projects\Query\ProjectSummaryQuery;
use App\Audit\QualityGates\Query\ProjectQualityGateQuery;
use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpPayloads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetProject extends LaraDogsTool
{
    protected string $name = 'laradogs.get_project';

    protected string $title = 'Get project';

    protected string $description = 'Returns a bounded summary of one LaraDogs project: identity, open finding counts, last audit, Quality Gate state and the last audited source revision. Uses only persisted data (no Git or analyzer run). Requires a valid MCP token (read scope).';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->string()->description('The project public id (26 characters).')->required()];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $access->forRead();
        $args = $this->validated($arguments, ['project_id' => $this->idRule()]);
        $project = $this->project($args['project_id']);

        $summary = app(ProjectSummaryQuery::class)->forProject($project);
        $policy = app(ProjectQualityGateQuery::class)->policyFor($project);
        $active = $project->activeScan();
        $payloads = new McpPayloads;
        $last = $summary->lastScan;
        $gate = $last === null ? null : $last->qualityGateResult;

        return [
            'project' => $payloads->projectSummary(
                $project,
                $payloads->frameworkSummary($summary->profile),
                $summary->openFindings,
                $last === null ? null : $payloads->scanSummary($last),
                [
                    'enabled' => $policy !== null && $policy->enabled,
                    'policy_revision' => $policy->revision ?? 0,
                    'last_outcome' => $gate?->outcome->value,
                ],
            ),
            'findings' => [
                'total' => $summary->totalFindings,
                'open' => $summary->openFindings,
                'open_by_severity' => $summary->openFindingsBySeverity,
                'open_by_category' => $summary->openFindingsByCategory,
            ],
            'active_scan' => $active === null ? null : ['id' => $active->public_id, 'status' => $active->status->value],
            'last_audited_source' => $summary->lastCompletedScan === null ? null : $payloads->source($summary->lastCompletedScan),
            'analyzer_statuses' => $summary->lastCompletedScanAnalyzerStatuses,
        ];
    }
}
