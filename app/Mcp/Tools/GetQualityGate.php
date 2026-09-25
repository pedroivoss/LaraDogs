<?php

namespace App\Mcp\Tools;

use App\Audit\QualityGates\Policy\InvalidQualityGatePolicy;
use App\Audit\QualityGates\Query\ProjectQualityGateQuery;
use App\Mcp\Auth\McpAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetQualityGate extends LaraDogsTool
{
    protected string $name = 'laradogs.get_quality_gate';

    protected string $title = 'Get Quality Gate policy';

    protected string $description = 'Returns a project\'s CURRENT Quality Gate policy (enabled flag, revision and rules). Read-only: policy changes are not available through MCP. Historical results are read with laradogs.get_scan_quality_gate. Requires a valid MCP token (read scope).';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->string()->description('The project public id (26 characters).')->required()];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $access->forRead();
        $args = $this->validated($arguments, ['project_id' => $this->idRule()]);
        $project = $this->project($args['project_id']);
        $gate = app(ProjectQualityGateQuery::class)->policyFor($project);

        $policy = null;
        $valid = true;

        try {
            $policy = $gate?->policy()?->toArray();
        } catch (InvalidQualityGatePolicy) {
            $valid = false;
        }

        return [
            'project_id' => $project->public_id,
            // No row means the gate is disabled — stated honestly, never a fabricated policy.
            'enabled' => $gate !== null && $gate->enabled,
            'revision' => $gate->revision ?? 0,
            'policy' => $policy,
            'policy_valid' => $valid,
            'updated_at' => $gate?->updated_at?->toIso8601String(),
        ];
    }
}
