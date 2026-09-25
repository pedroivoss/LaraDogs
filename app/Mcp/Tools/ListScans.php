<?php

namespace App\Mcp\Tools;

use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\Query\ScanHistoryQuery;
use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpPage;
use App\Mcp\Support\McpPayloads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListScans extends LaraDogsTool
{
    protected string $name = 'laradogs.list_scans';

    protected string $title = 'List scans';

    protected string $description = 'Returns a bounded, paginated scan history of one project (newest first) with status, origin, timing, source revision and Quality Gate outcome per scan. Requires a valid MCP token (read scope).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->description('The project public id (26 characters).')->required(),
            'status' => $schema->string()->enum(array_map(fn (ScanStatus $s) => $s->value, ScanStatus::cases()))->description('Optional status filter.'),
            'origin' => $schema->string()->enum(array_map(fn (ScanOrigin $o) => $o->value, ScanOrigin::cases()))->description('Optional origin filter.'),
            'limit' => $schema->integer()->min(1)->max(McpPage::MAX_LIMIT)->description('Page size (default 25, maximum 100).'),
            'page' => $schema->integer()->min(1)->description('1-based page number.'),
        ];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $access->forRead();
        $args = $this->validated($arguments, [
            'project_id' => $this->idRule(),
            'status' => ['sometimes', 'string', Rule::enum(ScanStatus::class)],
            'origin' => ['sometimes', 'string', Rule::enum(ScanOrigin::class)],
            'limit' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer'],
        ]);
        $project = $this->project($args['project_id']);
        $page = McpPage::fromArguments($arguments);

        $paginator = app(ScanHistoryQuery::class)->paginateFor(
            $project,
            $page->limit,
            $page->page,
            isset($args['status']) ? ScanStatus::from($args['status']) : null,
            isset($args['origin']) ? ScanOrigin::from($args['origin']) : null,
        );
        $payloads = new McpPayloads;

        return [
            'project_id' => $project->public_id,
            'scans' => $paginator->getCollection()->map(fn ($scan) => $payloads->scanSummary($scan))->all(),
            'pagination' => $page->envelope($paginator->total(), $paginator->getCollection()->count()),
        ];
    }
}
