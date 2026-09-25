<?php

use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Jobs\RunProjectAuditJob;
use App\Mcp\Auth\McpScope;
use App\Mcp\Tools\GetAuditStatus;
use App\Mcp\Tools\RunProjectAudit;
use App\Models\Audit\ProjectActiveScan;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Mcp\McpWorld;
use Tests\Support\QualityGates\GateScans;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => Queue::fake());

function auditAs(Role $role, McpScope $scope): array
{
    $project = McpWorld::project();
    McpWorld::token(McpWorld::user($role), $scope);

    return [$project, McpWorld::call(RunProjectAudit::class, ['project_id' => $project->public_id])];
}

it('lets an Owner token with the audit scope enqueue an audit', function () {
    [$project, $result] = auditAs(Role::Owner, McpScope::Audit);

    expect(McpWorld::errorCode($result))->toBeNull()
        ->and($result['project_id'])->toBe($project->public_id)
        ->and($result['status'])->toBe('queued')
        ->and($result['scan_id'])->toMatch('/^[0-9a-z]{26}$/');
});

it('lets an Admin token with the audit scope enqueue an audit', function () {
    [, $result] = auditAs(Role::Admin, McpScope::Audit);

    expect($result['status'])->toBe('queued');
});

it('never lets a plain User enqueue, even with a read token', function () {
    [, $result] = auditAs(Role::User, McpScope::Read);

    expect(McpWorld::errorCode($result))->toBe('forbidden');
    Queue::assertNothingPushed();
    expect(Scan::query()->count())->toBe(0);
});

it('never lets a read-only token enqueue, even one belonging to an Owner', function () {
    [, $result] = auditAs(Role::Owner, McpScope::Read);

    expect(McpWorld::errorCode($result))->toBe('forbidden')->and($result['error']['message'])->toContain('audit');
    Queue::assertNothingPushed();
});

it('enforces the CURRENT role: an audit token of a demoted Admin can no longer audit', function () {
    $admin = McpWorld::user(Role::Admin);
    McpWorld::token($admin, McpScope::Audit);
    $project = McpWorld::project();
    expect(McpWorld::errorCode(McpWorld::call(RunProjectAudit::class, ['project_id' => $project->public_id])))->toBeNull();

    $admin->forceFill(['role' => Role::User])->save();
    ProjectActiveScan::query()->delete();
    Scan::query()->delete();

    expect(McpWorld::errorCode(McpWorld::call(RunProjectAudit::class, ['project_id' => $project->public_id])))->toBe('forbidden');
});

it('queues through the existing async path: a Queued scan, a job, provenance MCP, and NO analyzer runs in the request', function () {
    [$project, $result] = auditAs(Role::Admin, McpScope::Audit);
    $scan = Scan::query()->where('public_id', $result['scan_id'])->firstOrFail();

    expect($scan->status)->toBe(ScanStatus::Queued)
        ->and($scan->origin)->toBe(ScanOrigin::Mcp)
        ->and($scan->initiated_by_user_id)->not->toBeNull()
        ->and(ScanAnalyzerExecution::query()->count())->toBe(0);
    Queue::assertPushed(RunProjectAuditJob::class, fn ($job) => true);
    Queue::assertPushed(RunProjectAuditJob::class, 1);
});

it('reuses the one-active-scan mutex: a second call returns audit_already_running with the existing scan id', function () {
    [$project, $first] = auditAs(Role::Owner, McpScope::Audit);

    $second = McpWorld::call(RunProjectAudit::class, ['project_id' => $project->public_id]);

    expect(McpWorld::errorCode($second))->toBe('audit_already_running')
        ->and($second['error']['details']['scan_id'])->toBe($first['scan_id'])
        ->and(Scan::query()->count())->toBe(1);
    Queue::assertPushed(RunProjectAuditJob::class, 1);
});

it('reports a typed error for an unknown or malformed project id', function () {
    McpWorld::token(McpWorld::user(Role::Owner), McpScope::Audit);

    expect(McpWorld::errorCode(McpWorld::call(RunProjectAudit::class, ['project_id' => str_repeat('0', 26)])))->toBe('project_not_found')
        ->and(McpWorld::errorCode(McpWorld::call(RunProjectAudit::class, ['project_id' => '../../etc/passwd'])))->toBe('invalid_arguments')
        ->and(McpWorld::errorCode(McpWorld::call(RunProjectAudit::class, ['project_id' => '/Users/x/project'])))->toBe('invalid_arguments')
        ->and(McpWorld::errorCode(McpWorld::call(RunProjectAudit::class, [])))->toBe('invalid_arguments')
        ->and(McpWorld::errorCode(McpWorld::call(RunProjectAudit::class, ['project_id' => McpWorld::project()->public_id, 'path' => '/tmp'])))->toBe('invalid_arguments');
});

// ---------------- audit status ----------------

function statusOf(Scan $scan): array
{
    return McpWorld::call(GetAuditStatus::class, ['scan_id' => $scan->public_id]);
}

it('reports queued, running, completed and failed audits', function () {
    McpWorld::token(McpWorld::user(Role::User));
    $project = McpWorld::project();
    $mk = fn (ScanStatus $s) => Scan::query()->create(['project_id' => $project->id, 'status' => $s, 'started_at' => now(), 'project_profile' => []]);

    expect(statusOf($mk(ScanStatus::Queued))['status'])->toBe('queued')
        ->and(statusOf($mk(ScanStatus::Running))['status'])->toBe('running')
        ->and(statusOf($mk(ScanStatus::Completed))['status'])->toBe('completed')
        ->and(statusOf($mk(ScanStatus::Failed))['status'])->toBe('failed');
});

it('includes the source revision and the Quality Gate once an audit completed', function () {
    McpWorld::token(McpWorld::user(Role::User));
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $scan = GateScans::scan($project);

    $status = statusOf($scan);

    expect($status['status'])->toBe('completed')
        ->and($status['quality_gate']['outcome'])->toBe('passed')
        ->and($status)->toHaveKey('source')
        ->and($status['project_id'])->toBe($project->public_id);
});

it('has no gate before completion and a typed error for a missing scan', function () {
    McpWorld::token(McpWorld::user(Role::User));
    $project = McpWorld::project();
    $queued = Scan::query()->create(['project_id' => $project->id, 'status' => ScanStatus::Queued, 'started_at' => now(), 'project_profile' => []]);

    expect(statusOf($queued)['quality_gate'])->toBeNull()
        ->and(McpWorld::errorCode(McpWorld::call(GetAuditStatus::class, ['scan_id' => str_repeat('1', 26)])))->toBe('scan_not_found');
});
