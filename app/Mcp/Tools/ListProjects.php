<?php

namespace App\Mcp\Tools;

use App\Audit\Projects\Query\ProjectListQuery;
use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpPage;
use App\Mcp\Support\McpPayloads;
use App\Models\Audit\ProjectQualityGate;
use App\Models\Audit\QualityGateResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListProjects extends LaraDogsTool
{
    protected string $name = 'laradogs.list_projects';

    protected string $title = 'List projects';

    protected string $description = 'Returns a bounded, paginated list of the projects registered in LaraDogs with a short summary of each. Requires a valid MCP token (read scope).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(McpPage::MAX_LIMIT)->description('Page size (default 25, maximum 100).'),
            'page' => $schema->integer()->min(1)->description('1-based page number.'),
        ];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $access->forRead();
        $this->validated($arguments, ['limit' => ['sometimes', 'integer'], 'page' => ['sometimes', 'integer']]);
        $page = McpPage::fromArguments($arguments);

        $paginator = app(ProjectListQuery::class)->paginate($page->limit, $page->page);
        $projects = $paginator->getCollection();

        // Bounded aggregates, never per-row queries: one for the policies, one for the latest results.
        $policies = ProjectQualityGate::query()->whereIn('project_id', $projects->pluck('id'))->get()->keyBy('project_id');
        $results = QualityGateResult::query()->whereIn('scan_id', $projects->pluck('latestScan.id')->filter())->get()->keyBy('scan_id');
        $payloads = new McpPayloads;

        return [
            'projects' => $projects->map(function ($project) use ($policies, $results, $payloads): array {
                $policy = $policies->get($project->id);
                $latest = $project->latestScan;

                return $payloads->projectSummary(
                    $project,
                    $payloads->frameworkSummary($latest?->project_profile),
                    (int) $project->open_findings_count,
                    $latest === null ? null : [
                        'id' => $latest->public_id,
                        'status' => $latest->status->value,
                        'origin' => $latest->origin->value,
                        'started_at' => $latest->started_at->toIso8601String(),
                        'finished_at' => $latest->finished_at?->toIso8601String(),
                        'source' => $payloads->source($latest),
                    ],
                    [
                        'enabled' => $policy !== null && $policy->enabled,
                        'policy_revision' => $policy->revision ?? 0,
                        'last_outcome' => $latest === null ? null : $results->get($latest->id)?->outcome->value,
                    ],
                );
            })->all(),
            'pagination' => $page->envelope($paginator->total(), $projects->count()),
        ];
    }
}
