<?php

use App\Audit\Findings\Severity;
use App\Audit\Remediation\FindingRemediationService;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\QualityGates\GateScans;
use Tests\Support\Remediation\RemEvidence;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function cliFinding(): Finding
{
    $project = GateScans::project();
    GateScans::scan($project, null, ['semgrep' => [GateScans::candidate('a', Severity::High, 'semgrep', RemEvidence::SQL_RULE, 42)]]);

    return Finding::query()->firstOrFail();
}

/** @return array{0: int, 1: string} */
function cliRun(array $arguments): array
{
    $code = Artisan::call('laradogs:finding:remediation', $arguments);

    return [$code, Artisan::output()];
}

it('prints a readable, guidance-only plan for a finding', function () {
    $finding = cliFinding();
    [$code, $out] = cliRun(['finding' => $finding->public_id]);

    expect($code)->toBe(0)
        ->and($out)->toContain("Remediation — {$finding->public_id}")->toContain('Recommended action')->toContain('Steps')->toContain('Validation')
        ->toContain('guidance_only')->toContain('never edits your code')->toContain('app/a.php:42')->toContain('untrusted source data')
        ->toContain('binding');
});

it('emits pure JSON on stdout under --json, identical in shape to MCP', function () {
    $finding = cliFinding();
    [$code, $out] = cliRun(['finding' => $finding->public_id, '--json' => true]);
    $json = json_decode($out, true, flags: JSON_THROW_ON_ERROR); // throws on ANY non-JSON byte

    expect($code)->toBe(0)
        ->and($json['schema_version'])->toBe(1)
        ->and($json['remediation']['finding_id'])->toBe($finding->public_id)
        ->and($json['remediation']['automation_level'])->toBe('guidance_only')
        ->and(array_keys($json['remediation']))->toBe(array_keys(app(FindingRemediationService::class)->forFinding($finding)->toArray()));
});

it('reports a missing or malformed finding id without a stack trace', function () {
    foreach (['01hzzzzzzzzzzzzzzzzzzzzzzz', 'nope', '../../etc/passwd'] as $bad) {
        [$code, $out] = cliRun(['finding' => $bad]);

        expect($code)->toBe(1)->and($out)->toContain('No finding with that public ID.')->not->toContain('Exception')->not->toContain('SQLSTATE')->not->toContain('#0');
    }
});

it('leaks no host path, secret or user identity in either output mode', function () {
    $finding = cliFinding();
    $root = $finding->project->path;
    $finding->forceFill(['description' => "Leaked in {$root}/app/a.php and /Users/dev/x.php", 'title' => 'Token ghp_FAKE0123456789abcdefghij0123'])->save();
    FindingOccurrence::query()->update(['code_snippet' => "k=sk-FAKEfakeFAKEfakeFAKEfake0123 {$root}/x\nBearer abcdefghijklmnop1234567890"]);

    foreach ([[], ['--json' => true]] as $extra) {
        [, $out] = cliRun(['finding' => $finding->public_id, ...$extra]);

        expect($out)->not->toContain($root)->not->toContain('/Users/dev')->not->toContain('ghp_FAKE')->not->toContain('sk-FAKE')->not->toContain('abcdefghijklmnop1234567890')->not->toContain('@');
    }
});

it('runs read-only: nothing is written and nothing is queued', function () {
    $finding = cliFinding();
    $before = [Finding::query()->count(), $finding->refresh()->updated_at->toIso8601String()];

    cliRun(['finding' => $finding->public_id, '--json' => true]);

    expect([Finding::query()->count(), $finding->refresh()->updated_at->toIso8601String()])->toBe($before);
});

it('keeps stdout pure JSON in a REAL process too (no banner, no ANSI, diagnostics only on stderr)', function () {
    $db = sys_get_temp_dir().'/laradogs-rem-cli-'.bin2hex(random_bytes(6)).'.sqlite';
    touch($db);
    $env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '', 'DB_URL' => '', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'single', 'LOG_STACK' => 'single', 'NO_COLOR' => '1'];
    $base = dirname(__DIR__, 3);

    try {
        (new Process(['php', 'artisan', 'migrate', '--force', '--no-interaction'], $base, $env))->mustRun();
        $script = '$p = App\Models\Audit\Project::query()->create(["name" => "Cli Demo", "path" => "/tmp/laradogs-cli-demo"]); $s = App\Models\Audit\Scan::query()->create(["project_id" => $p->id, "status" => "completed", "started_at" => now(), "finished_at" => now(), "project_profile" => ["project" => ["type" => "laravel"]]]); $f = App\Models\Audit\Finding::query()->create(["project_id" => $p->id, "fingerprint" => hash("sha256", "cli"), "fingerprint_version" => "v1", "rule_id" => "laradogs.security.sql.tainted-raw-query", "analyzer_id" => "semgrep", "category" => "security", "severity" => "high", "confidence" => "medium", "title" => "Demo", "status" => "open", "first_seen_scan_id" => $s->id, "first_seen_at" => now(), "last_seen_scan_id" => $s->id, "last_seen_at" => now()]); echo "ID=".$f->public_id;';
        $seed = (new Process(['php', 'artisan', 'tinker', '--execute='.$script], $base, $env))->mustRun()->getOutput();
        preg_match('/ID=([0-9a-z]{26})/', $seed, $m);

        $run = new Process(['php', 'artisan', 'laradogs:finding:remediation', $m[1], '--json'], $base, $env);
        $run->setTimeout(60);
        $run->run();
        $json = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($run->getExitCode())->toBe(0)->and($json['remediation']['finding_id'])->toBe($m[1])
            ->and($run->getOutput())->not->toContain("\x1b[")
            ->and($json['remediation']['source']['state'])->toBe('unknown');
    } finally {
        @unlink($db);
    }
});
