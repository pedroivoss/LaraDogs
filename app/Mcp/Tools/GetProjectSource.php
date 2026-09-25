<?php

namespace App\Mcp\Tools;

use App\Audit\Projects\Query\ProjectSummaryQuery;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Http\Support\SourceOverviewPayload;
use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpSanitizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetProjectSource extends LaraDogsTool
{
    protected string $name = 'laradogs.get_project_source';

    protected string $title = 'Get project source state';

    protected string $description = 'Returns the CURRENT local Git state of a project (revision, branch, dirty, sanitized origin) next to the LAST AUDITED source snapshot, and whether they differ. The current state is a bounded, read-only local inspection (no fetch, no network) and is NOT an audit result. No credentials, no host paths. Requires a valid MCP token (read scope).';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->string()->description('The project public id (26 characters).')->required()];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $access->forRead();
        $args = $this->validated($arguments, ['project_id' => $this->idRule()]);
        $project = $this->project($args['project_id']);

        $lastCompleted = app(ProjectSummaryQuery::class)->forProject($project)->lastCompletedScan;
        $overview = SourceOverviewPayload::for($project, $lastCompleted, app(GitRepositoryInspector::class));
        $sanitizer = new McpSanitizer;

        // Defense in depth: even bounded Git metadata (subjects, branch names) is target-derived text.
        array_walk_recursive($overview, function (&$value) use ($sanitizer): void {
            if (is_string($value)) {
                $value = $sanitizer->text($value, McpSanitizer::MAX_TITLE);
            }
        });

        return ['project_id' => $project->public_id, ...$overview];
    }
}
