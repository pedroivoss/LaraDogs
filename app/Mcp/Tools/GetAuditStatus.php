<?php

namespace App\Mcp\Tools;

use App\Audit\QualityGates\Query\ProjectQualityGateQuery;
use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpPayloads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetAuditStatus extends LaraDogsTool
{
    protected string $name = 'laradogs.get_audit_status';

    protected string $title = 'Get audit status';

    protected string $description = 'Returns the status of one audit (queued, running, completed or failed) with timestamps, the audited source revision when known and the Quality Gate outcome once evaluated. Call it repeatedly to follow an audit; there is no streaming. Requires a valid MCP token (read scope).';

    public function schema(JsonSchema $schema): array
    {
        return ['scan_id' => $schema->string()->description('The scan public id returned by laradogs.run_project_audit (26 characters).')->required()];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $access->forRead();
        $args = $this->validated($arguments, ['scan_id' => $this->idRule()]);
        $scan = $this->scan($args['scan_id']);
        $payloads = new McpPayloads;
        $gate = app(ProjectQualityGateQuery::class)->resultForScan($scan);

        return [
            'scan_id' => $scan->public_id,
            'project_id' => $scan->project->public_id,
            'status' => $scan->status->value,
            'origin' => $scan->origin->value,
            'queued_at' => $scan->started_at->toIso8601String(),
            'running_at' => $scan->running_at?->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'duration_ms' => $scan->duration_ms,
            'source' => $payloads->source($scan),
            'quality_gate' => $gate === null ? null : $payloads->gateSummary($gate),
        ];
    }
}
