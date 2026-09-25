<?php

namespace App\Mcp\Tools;

use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpPayloads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetScan extends LaraDogsTool
{
    protected string $name = 'laradogs.get_scan';

    protected string $title = 'Get scan';

    protected string $description = 'Returns one immutable scan: status, origin, timing, source revision/provenance and integrity, per-analyzer executions and its Quality Gate result. Finding evidence is not included (use the findings tools). Requires a valid MCP token (read scope).';

    public function schema(JsonSchema $schema): array
    {
        return ['scan_id' => $schema->string()->description('The scan public id (26 characters).')->required()];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $access->forRead();
        $args = $this->validated($arguments, ['scan_id' => $this->idRule()]);
        $scan = $this->scan($args['scan_id']);
        $scan->load('analyzerExecutions', 'qualityGateResult');
        $payloads = new McpPayloads;

        return [
            'scan' => [
                ...$payloads->scanSummary($scan),
                'project_id' => $scan->project->public_id,
                'queued_at' => $scan->started_at->toIso8601String(),
                'running_at' => $scan->running_at?->toIso8601String(),
                'laradogs_version' => $scan->laradogs_version,
                'analyzer_executions' => $scan->analyzerExecutions->map(fn ($e) => $payloads->executionSummary($e))->all(),
            ],
        ];
    }
}
