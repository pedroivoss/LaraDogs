<?php

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\ActorType;
use App\Audit\Findings\Confidence;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Findings\Severity;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Mcp\Tools\GetAuditStatus;
use App\Mcp\Tools\GetFinding;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\GetProjectProfile;
use App\Mcp\Tools\GetProjectSource;
use App\Mcp\Tools\GetQualityGate;
use App\Mcp\Tools\GetScan;
use App\Mcp\Tools\GetScanQualityGate;
use App\Mcp\Tools\ListFindings;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListScans;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Git\GitFixture;
use Tests\Support\Git\GitProject;
use Tests\Support\Mcp\McpWorld;
use Tests\Support\QualityGates\GateScans;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => McpWorld::token(McpWorld::user(Role::User)));

function mcpCandidate(string $key, array $over = []): FindingCandidate
{
    return new FindingCandidate(...[
        'ruleId' => 'R1', 'analyzerId' => 'semgrep', 'category' => AnalyzerCategory::Security, 'severity' => Severity::High,
        'confidence' => Confidence::High, 'title' => "Finding {$key}", 'description' => "Description of {$key}",
        'recommendation' => 'Fix it', 'filePath' => "app/{$key}.php", 'lineStart' => 10, 'lineEnd' => 12,
        'codeSnippet' => "risky({$key});", ...$over,
    ]);
}

/** @return array{0: Project, 1: Scan} */
function mcpSeeded(array $candidates): array
{
    $project = GateScans::project();
    $scan = GateScans::scan($project, null, ['semgrep' => $candidates]);

    return [$project, $scan];
}

function mcpQueries(Closure $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

// ---------------- projects ----------------

it('lists projects bounded and paginated, rejecting absurd sizes', function () {
    foreach (range(1, 30) as $i) {
        McpWorld::project(sprintf('P%02d', $i));
    }

    $page1 = McpWorld::call(ListProjects::class);
    $page2 = McpWorld::call(ListProjects::class, ['page' => 2]);

    expect($page1['projects'])->toHaveCount(25)
        ->and($page1['pagination'])->toMatchArray(['page' => 1, 'limit' => 25, 'total' => 30, 'has_more' => true])
        ->and($page2['projects'])->toHaveCount(5)->and($page2['pagination']['has_more'])->toBeFalse()
        ->and(McpWorld::call(ListProjects::class, ['limit' => 100])['projects'])->toHaveCount(30);

    foreach ([['limit' => 101], ['limit' => 0], ['limit' => '25'], ['page' => 0], ['page' => 1_000_000], ['limit' => -5]] as $bad) {
        expect(McpWorld::errorCode(McpWorld::call(ListProjects::class, $bad)))->toBe('invalid_arguments');
    }
});

it('lists projects without N+1 queries or Git subprocesses', function () {
    foreach (range(1, 5) as $i) {
        McpWorld::project("A{$i}");
    }
    McpWorld::call(ListProjects::class); // warm-up: the once-a-minute last_used_at write
    $few = mcpQueries(fn () => McpWorld::call(ListProjects::class));
    foreach (range(1, 20) as $i) {
        McpWorld::project("B{$i}");
    }
    $many = mcpQueries(fn () => McpWorld::call(ListProjects::class));

    expect($many)->toBe($few);
});

it('gets a project without host paths or any user identity', function () {
    $owner = McpWorld::user(Role::Owner);
    [$project, $scan] = mcpSeeded([mcpCandidate('a')]);
    $scan->forceFill(['initiated_by_user_id' => $owner->id, 'origin' => ScanOrigin::Manual])->save();

    $result = McpWorld::call(GetProject::class, ['project_id' => $project->public_id]);
    $json = json_encode($result);

    expect($result['project']['id'])->toBe($project->public_id)
        ->and($result['findings']['open'])->toBe(1)
        ->and($result['project']['last_scan']['id'])->toBe($scan->public_id)
        ->and($json)->not->toContain($project->path)->not->toContain('/Users')->not->toContain($owner->email)->not->toContain($owner->name)
        ->and(McpWorld::errorCode(McpWorld::call(GetProject::class, ['project_id' => str_repeat('z', 26)])))->toBe('project_not_found');
});

it('returns the last audited technology profile without the host path, and a scan snapshot on request', function () {
    [$project, $scan] = mcpSeeded([]);

    $latest = McpWorld::call(GetProjectProfile::class, ['project_id' => $project->public_id]);
    $byScan = McpWorld::call(GetProjectProfile::class, ['project_id' => $project->public_id, 'scan_id' => $scan->public_id]);

    expect($latest['profile_source']['type'])->toBe('last_audited')
        ->and($latest['profile']['project']['type'])->toBe('laravel')
        ->and($latest['profile']['backend']['laravel']['installed_version'])->not->toBeNull()
        ->and(json_encode($latest))->not->toContain($project->path)
        ->and($byScan['profile_source'])->toBe(['type' => 'scan_snapshot', 'scan_id' => $scan->public_id]);

    $other = McpWorld::project('Other');
    expect(McpWorld::errorCode(McpWorld::call(GetProjectProfile::class, ['project_id' => $other->public_id, 'scan_id' => $scan->public_id])))->toBe('scan_not_found');
});

it('distinguishes the CURRENT source from the LAST AUDITED one, with no credentials or host path', function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('git missing');
    }
    $p = GitProject::create();
    $p->repo->git('remote', 'add', 'origin', 'https://deploy:ghp_FAKE0123456789abcdefghij@example.com/org/app.git');
    app(RunProjectAudit::class)->run($p->project);
    $audited = $p->repo->sha();

    $same = McpWorld::call(GetProjectSource::class, ['project_id' => $p->project->public_id]);
    $p->repo->write('later.txt', 'x');
    $newSha = $p->repo->commitAll('later');
    $changed = McpWorld::call(GetProjectSource::class, ['project_id' => $p->project->public_id]);

    expect($same['current']['commit'])->toBe($audited)->and($same['last_audited']['source']['commit'])->toBe($audited)
        ->and($same['changed_since_last_audit'])->toBeFalse()
        ->and($changed['current']['commit'])->toBe($newSha)->and($changed['last_audited']['source']['commit'])->toBe($audited)
        ->and($changed['changed_since_last_audit'])->toBeTrue()
        ->and($same['current']['remote'])->toBe('https://example.com/org/app.git')
        ->and(json_encode([$same, $changed]))->not->toContain('ghp_FAKE')->not->toContain($p->repo->path)->not->toContain('author-secret');
});

// ---------------- scans ----------------

it('lists scans with pagination and typed filters', function () {
    $project = GateScans::project();
    foreach (range(1, 3) as $i) {
        GateScans::scan($project);
    }
    Scan::query()->create(['project_id' => $project->id, 'status' => ScanStatus::Failed, 'origin' => ScanOrigin::Mcp, 'started_at' => now(), 'project_profile' => []]);
    $id = $project->public_id;

    $page = McpWorld::call(ListScans::class, ['project_id' => $id, 'limit' => 2]);
    $failed = McpWorld::call(ListScans::class, ['project_id' => $id, 'status' => 'failed']);
    $mcp = McpWorld::call(ListScans::class, ['project_id' => $id, 'origin' => 'mcp']);

    expect($page['scans'])->toHaveCount(2)->and($page['pagination'])->toMatchArray(['total' => 4, 'has_more' => true])
        ->and($failed['scans'])->toHaveCount(1)->and($mcp['scans'][0]['origin'])->toBe('mcp')
        ->and(McpWorld::errorCode(McpWorld::call(ListScans::class, ['project_id' => $id, 'status' => 'bogus'])))->toBe('invalid_arguments')
        ->and(McpWorld::errorCode(McpWorld::call(ListScans::class, ['project_id' => $id, 'limit' => 5000])))->toBe('invalid_arguments')
        ->and(McpWorld::errorCode(McpWorld::call(ListScans::class, ['project_id' => str_repeat('z', 26)])))->toBe('project_not_found');
});

it('gets a scan with executions, source and gate — and works for a legacy scan without source', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $scan = GateScans::scan($project); // pre-Phase-9 path: no source snapshot

    $result = McpWorld::call(GetScan::class, ['scan_id' => $scan->public_id]);

    expect($result['scan']['id'])->toBe($scan->public_id)
        ->and($result['scan']['source'])->toBeNull()
        ->and($result['scan']['quality_gate']['outcome'])->toBe('passed')
        ->and($result['scan']['analyzer_executions'])->not->toBeEmpty()
        ->and(McpWorld::errorCode(McpWorld::call(GetScan::class, ['scan_id' => str_repeat('z', 26)])))->toBe('scan_not_found');
});

// ---------------- findings ----------------

it('lists findings with pagination and typed filters', function () {
    [$project] = mcpSeeded([
        mcpCandidate('a'), mcpCandidate('b', ['severity' => Severity::Low]),
        mcpCandidate('c', ['category' => AnalyzerCategory::Bug, 'ruleId' => 'R2']), mcpCandidate('d'),
    ]);
    $id = $project->public_id;

    $all = McpWorld::call(ListFindings::class, ['project_id' => $id, 'limit' => 2]);
    $high = McpWorld::call(ListFindings::class, ['project_id' => $id, 'severity' => ['high']]);
    $bug = McpWorld::call(ListFindings::class, ['project_id' => $id, 'category' => ['bug']]);
    $rule = McpWorld::call(ListFindings::class, ['project_id' => $id, 'rule_id' => 'R2']);
    $none = McpWorld::call(ListFindings::class, ['project_id' => $id, 'status' => ['resolved']]);

    expect($all['findings'])->toHaveCount(2)->and($all['pagination'])->toMatchArray(['total' => 4, 'has_more' => true])
        ->and($high['pagination']['total'])->toBe(3)->and($bug['pagination']['total'])->toBe(1)
        ->and($rule['findings'][0]['rule_id'])->toBe('R2')->and($none['findings'])->toBe([])
        ->and($all['content_trust'])->toBe('untrusted_source_data');

    foreach ([['severity' => ['bogus']], ['severity' => 'high'], ['status' => ['open', 'nope']], ['query' => 'severity=high'], ['analyzer' => 'a b; drop'], ['rule_id' => "R1'; --"]] as $bad) {
        expect(McpWorld::errorCode(McpWorld::call(ListFindings::class, ['project_id' => $id, ...$bad])))->toBe('invalid_arguments');
    }
});

it('lists findings without N+1 queries and returns only project-relative locations', function () {
    $mk = fn (int $n) => array_map(fn ($i) => mcpCandidate("f{$n}x{$i}"), range(1, $n));
    [$small] = mcpSeeded($mk(2));
    McpWorld::call(ListFindings::class, ['project_id' => $small->public_id]); // warm-up
    $few = mcpQueries(fn () => McpWorld::call(ListFindings::class, ['project_id' => $small->public_id]));
    DB::table('findings')->delete();
    [$project] = [$small];
    GateScans::scan($project, null, ['semgrep' => $mk(20)]);
    $many = mcpQueries(fn () => McpWorld::call(ListFindings::class, ['project_id' => $project->public_id]));

    expect($many)->toBe($few);
});

it('returns finding paths only as project-relative — never absolute, never a traversal', function () {
    [$project] = mcpSeeded([
        mcpCandidate('rel'),
        mcpCandidate('abs', ['filePath' => GateScans::project()->path.'/app/Inside.php']),
        mcpCandidate('host', ['filePath' => '/etc/passwd']),
        mcpCandidate('trav', ['filePath' => '../../secrets/.env']),
        mcpCandidate('win', ['filePath' => 'C:\\Users\\dev\\app.php']),
    ]);

    $paths = collect(McpWorld::call(ListFindings::class, ['project_id' => $project->public_id])['findings'])->keyBy('title')->map(fn ($f) => $f['location']['path']);

    expect($paths['Finding rel'])->toBe('app/rel.php')
        ->and($paths['Finding abs'])->toBe('app/Inside.php')
        ->and($paths['Finding host'])->toBeNull()->and($paths['Finding trav'])->toBeNull()->and($paths['Finding win'])->toBeNull();
});

it('gets a finding with bounded, redacted evidence and a bounded history without any user identity', function () {
    $actor = McpWorld::user(Role::Admin);
    [$project] = mcpSeeded([mcpCandidate('a', ['codeSnippet' => str_repeat('A', 5000), 'references' => array_map(fn ($i) => "https://ref/{$i}", range(1, 30))])]);
    $finding = Finding::query()->firstOrFail();
    foreach (range(1, 8) as $i) {
        app(FindingLifecycleService::class)->transition($finding->refresh(), FindingStatus::Confirmed, ActorType::User, actorIdentifier: $actor->email);
        app(FindingLifecycleService::class)->transition($finding->refresh(), FindingStatus::Open, ActorType::User, actorIdentifier: $actor->email);
    }

    $result = McpWorld::call(GetFinding::class, ['finding_id' => $finding->public_id])['finding'];

    expect($result['id'])->toBe($finding->public_id)
        ->and($result['location']['path'])->toBe('app/a.php')
        ->and(mb_strlen($result['occurrences'][0]['snippet']))->toBeLessThanOrEqual(1500)
        ->and($result['references'])->toHaveCount(10)
        ->and($result['status_history'])->toHaveCount(10)
        ->and($result['content_trust'])->toBe('untrusted_source_data')
        ->and(json_encode($result))->not->toContain($actor->email)->not->toContain($actor->name)
        ->and(McpWorld::errorCode(McpWorld::call(GetFinding::class, ['finding_id' => str_repeat('z', 26)])))->toBe('finding_not_found');
});

// ---------------- Quality Gate ----------------

it('reads the current policy honestly, including a disabled gate', function () {
    $project = GateScans::project();

    $disabled = McpWorld::call(GetQualityGate::class, ['project_id' => $project->public_id]);
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $enabled = McpWorld::call(GetQualityGate::class, ['project_id' => $project->public_id]);

    expect($disabled)->toMatchArray(['enabled' => false, 'revision' => 0, 'policy' => null])
        ->and($enabled['enabled'])->toBeTrue()->and($enabled['revision'])->toBe(1)->and($enabled['policy']['rules'])->not->toBeEmpty();
});

it('returns the IMMUTABLE historical gate result and never re-evaluates it against the current policy', function () {
    $project = GateScans::project();
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
    $scan = GateScans::scan($project, null, ['semgrep' => [mcpCandidate('bad')]]);

    $before = McpWorld::call(GetScanQualityGate::class, ['scan_id' => $scan->public_id]);
    GateScans::enable($project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 99])])); // a laxer policy, revision 2
    $after = McpWorld::call(GetScanQualityGate::class, ['scan_id' => $scan->public_id]);

    expect($before['gate']['outcome'])->toBe('failed')
        ->and($after['gate'])->toBe($before['gate'])
        ->and($after['gate']['policy_revision'])->toBe(1)
        ->and(McpWorld::call(GetQualityGate::class, ['project_id' => $project->public_id])['revision'])->toBe(2);
});

it('states honestly when a scan was never evaluated', function () {
    $project = GateScans::project();
    $scan = GateScans::scan($project); // no policy

    $result = McpWorld::call(GetScanQualityGate::class, ['scan_id' => $scan->public_id]);

    expect($result['evaluated'])->toBeFalse()->and($result['gate'])->toBeNull();
});

it('says a scan came from MCP without ever exposing who initiated it (Owner privacy)', function () {
    $owner = McpWorld::user(Role::Owner);
    [$project, $scan] = mcpSeeded([mcpCandidate('a')]);
    $scan->forceFill(['initiated_by_user_id' => $owner->id, 'origin' => ScanOrigin::Mcp])->save();

    $results = [
        McpWorld::call(GetScan::class, ['scan_id' => $scan->public_id]),
        McpWorld::call(ListScans::class, ['project_id' => $project->public_id, 'origin' => 'mcp']),
        McpWorld::call(GetAuditStatus::class, ['scan_id' => $scan->public_id]),
        McpWorld::call(GetProject::class, ['project_id' => $project->public_id]),
    ];

    expect($results[1]['scans'][0]['origin'])->toBe('mcp')
        ->and($results[2]['origin'])->toBe('mcp');

    foreach ($results as $result) {
        $json = json_encode($result);
        expect($json)->not->toContain($owner->email)->not->toContain($owner->name)->not->toContain('initiated_by')
            ->not->toContain('password')->not->toContain('remember_token');
    }
});

it('returns only safe https references from get_finding (never javascript:, data:, file: or http:)', function () {
    [, $scan] = mcpSeeded([mcpCandidate('a', ['references' => ['javascript:alert(1)', 'data:text/html,x', 'file:///etc/passwd', 'http://insecure.example.com', 'https://cwe.mitre.org/data/definitions/89.html']])]);
    $result = McpWorld::call(GetFinding::class, ['finding_id' => Finding::query()->firstOrFail()->public_id])['finding'];

    expect($result['references'])->toBe(['https://cwe.mitre.org/data/definitions/89.html']);
});
