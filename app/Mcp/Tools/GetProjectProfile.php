<?php

namespace App\Mcp\Tools;

use App\Audit\Projects\Query\ProjectSummaryQuery;
use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpError;
use App\Mcp\Support\McpErrorCode;
use App\Mcp\Support\McpPayloads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetProjectProfile extends LaraDogsTool
{
    protected string $name = 'laradogs.get_project_profile';

    protected string $title = 'Get project technology profile';

    protected string $description = 'Returns the normalized technology profile (Laravel/PHP versions, Blade, Livewire, Inertia, React/Vue, Node, database and CI hints) as recorded by the last audit, or by a specific scan. Historical snapshot only — it is never re-discovered. Host paths are removed. Requires a valid MCP token (read scope).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->description('The project public id (26 characters).')->required(),
            'scan_id' => $schema->string()->description('Optional scan public id: return that scan\'s profile snapshot instead of the latest.'),
        ];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $access->forRead();
        $args = $this->validated($arguments, ['project_id' => $this->idRule(), 'scan_id' => $this->idRule(false)]);
        $project = $this->project($args['project_id']);
        $payloads = new McpPayloads;

        if (isset($args['scan_id'])) {
            $scan = $this->scan($args['scan_id']);

            if ($scan->project_id !== $project->id) {
                throw new McpError(McpErrorCode::ScanNotFound, 'No scan with that id.');
            }

            $profile = $scan->project_profile;
            $source = ['type' => 'scan_snapshot', 'scan_id' => $scan->public_id];
        } else {
            $profile = app(ProjectSummaryQuery::class)->forProject($project)->profile;
            $source = ['type' => 'last_audited'];
        }

        return [
            'project_id' => $project->public_id,
            'profile_source' => $source,
            'profile' => $profile === null || $profile === [] ? null : $payloads->profile($profile, $project->path),
        ];
    }
}
