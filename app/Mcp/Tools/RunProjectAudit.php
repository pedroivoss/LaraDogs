<?php

namespace App\Mcp\Tools;

use App\Audit\Findings\ScanOrigin;
use App\Audit\Projects\RunProjectAudit as ProjectAuditRunner;
use App\Audit\Projects\RunProjectAuditOutcome;
use App\Jobs\RunProjectAuditJob;
use App\Mcp\Auth\McpAccess;
use App\Mcp\Support\McpError;
use App\Mcp\Support\McpErrorCode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
final class RunProjectAudit extends LaraDogsTool
{
    protected string $name = 'laradogs.run_project_audit';

    protected string $title = 'Run project audit';

    protected string $description = 'Queues a new audit of a registered project and returns immediately with the scan id and status "queued" (the audit runs on the LaraDogs worker, never inside this request). Only one audit per project can be active. Requires an MCP token with the audit scope belonging to a user who is currently an Owner or Admin.';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->string()->description('The project public id (26 characters).')->required()];
    }

    protected function run(array $arguments, McpAccess $access): array
    {
        $principal = $access->forAudit();
        $args = $this->validated($arguments, ['project_id' => $this->idRule()]);
        $project = $this->project($args['project_id']);

        // The SAME reservation path as the Dashboard/CLI: the portable
        // one-active-scan mutex, an immediate `Queued` scan, no analyzer here.
        $result = app(ProjectAuditRunner::class)->enqueue($project, ScanOrigin::Mcp, $principal->user);

        if ($result->outcome === RunProjectAuditOutcome::AlreadyRunning) {
            throw new McpError(
                McpErrorCode::AuditAlreadyRunning,
                'An audit for this project is already queued or running.',
                $result->conflictingScan === null ? [] : ['scan_id' => $result->conflictingScan->public_id],
            );
        }

        if ($result->outcome !== RunProjectAuditOutcome::Queued || $result->scan === null) {
            throw new McpError(McpErrorCode::TemporarilyUnavailable, 'The audit could not be queued right now.');
        }

        RunProjectAuditJob::dispatch($result->scan->id)->afterCommit();

        return [
            'project_id' => $project->public_id,
            'scan_id' => $result->scan->public_id,
            'status' => $result->scan->status->value,
        ];
    }
}
