<?php

namespace App\Mcp\Tools;

use App\Audit\QualityGates\Query\ProjectQualityGateQuery;
use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpPayloads;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetScanQualityGate extends LaraDogsTool
{
    protected string $name = 'laradogs.get_scan_quality_gate';

    protected string $title = 'Get scan Quality Gate result';

    protected string $description = 'Returns the IMMUTABLE Quality Gate result persisted when a scan finished (outcome, policy revision and snapshot, per-rule results with bounded evidence ids). It is never re-evaluated against the current policy. Requires a valid MCP token (read scope).';

    public function schema(JsonSchema $schema): array
    {
        return ['scan_id' => $schema->string()->description('The scan public id (26 characters).')->required()];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $access->forRead();
        $args = $this->validated($arguments, ['scan_id' => $this->idRule()]);
        $scan = $this->scan($args['scan_id']);
        $result = app(ProjectQualityGateQuery::class)->resultForScan($scan);

        return [
            'scan_id' => $scan->public_id,
            'scan_status' => $scan->status->value,
            'evaluated' => $result !== null,
            'gate' => $result === null ? null : (new McpPayloads)->gateDetail($result),
        ];
    }
}
