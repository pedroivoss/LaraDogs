<?php

use App\Audit\QualityGates\Query\ProjectQualityGateQuery;
use App\Audit\Remediation\FindingRemediationQuery;
use App\Audit\Remediation\FindingRemediationService;
use App\Audit\Remediation\RemediationPlanner;
use App\Audit\Source\Git\GitRepositoryInspector;

/*
 * Architectural guard (Phase 12): remediation is GUIDANCE ONLY. Nothing in the
 * remediation namespace, its MCP tool or its CLI command may write to a target
 * (or anywhere), execute a process, run Git write operations, use the network
 * or reach a model. Checked on the PARSED token stream (comments and strings
 * are ignored), not with a fragile grep.
 */

const REMEDIATION_ROOT = __DIR__.'/../../../../';

/** @return list<string> */
function remediationFiles(): array
{
    return [
        ...glob(REMEDIATION_ROOT.'app/Audit/Remediation/*.php'),
        REMEDIATION_ROOT.'app/Mcp/Tools/GetFindingRemediation.php',
        REMEDIATION_ROOT.'app/Console/Commands/FindingRemediationCommand.php',
    ];
}

/** @return list<string> identifiers (functions, classes, namespaced names) used as code */
function remediationIdentifiers(string $file): array
{
    $names = [];

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            $names[] = ltrim($token[1], '\\');
        }
    }

    return array_values(array_unique($names));
}

it('finds the remediation code to guard (the guard cannot pass vacuously)', function () {
    expect(remediationFiles())->toContain(REMEDIATION_ROOT.'app/Audit/Remediation/RemediationPlanner.php')
        ->and(count(remediationFiles()))->toBeGreaterThan(10);
});

it('contains no file-writing, process-executing, network or model primitive', function () {
    $forbidden = [
        // filesystem writes
        'file_put_contents', 'fopen', 'fwrite', 'fputs', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'chown', 'symlink', 'link', 'tempnam', 'tmpfile', 'move_uploaded_file', 'Storage', 'File', 'Filesystem',
        // process / shell execution
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec', 'Process', 'ProcessRunner', 'SymfonyProcessRunner', 'ProcessCommand', 'Artisan',
        // network / models
        'Http', 'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'file_get_contents', 'Client', 'OpenAI', 'Anthropic', 'Gemini', 'Guzzle', 'GuzzleHttp',
        // persistence writes
        'forceFill', 'save', 'update', 'delete', 'insert', 'upsert', 'truncate', 'statement', 'unprepared',
    ];

    foreach (remediationFiles() as $file) {
        $used = remediationIdentifiers($file);

        // (Not `not->toContain($a, $b)`: Pest passes that when ANY needle is absent.)
        expect(array_values(array_intersect($used, $forbidden)))->toBe([], basename($file).' uses a forbidden primitive');

        foreach ($used as $name) {
            expect($name)->not->toStartWith('Symfony\\Component\\Process')->not->toStartWith('Illuminate\\Support\\Facades\\Http')->not->toStartWith('Illuminate\\Support\\Facades\\Storage')->not->toStartWith('Illuminate\\Support\\Facades\\File');
        }
    }
});

it('keeps the planner strictly pure: no database, models, Git, container or config access', function () {
    $used = remediationIdentifiers(REMEDIATION_ROOT.'app/Audit/Remediation/RemediationPlanner.php');

    expect(array_values(array_intersect($used, ['DB', 'Model', 'Finding', 'Scan', 'Project', 'GitRepositoryInspector', 'app', 'config', 'resolve', 'Log', 'Cache', 'Http'])))->toBe([]);

    expect(array_filter($used, fn (string $n) => str_starts_with($n, 'App\\Models') || str_starts_with($n, 'Illuminate\\')))->toBe([]);
});

it('gives the loader only read-only collaborators: the Phase 9 inspector and the gate query', function () {
    $params = array_map(fn (ReflectionParameter $p) => (string) $p->getType(), (new ReflectionClass(FindingRemediationQuery::class))->getConstructor()->getParameters());

    expect($params)->toBe([GitRepositoryInspector::class, ProjectQualityGateQuery::class])
        ->and(array_map(fn (ReflectionParameter $p) => (string) $p->getType(), (new ReflectionClass(FindingRemediationService::class))->getConstructor()->getParameters()))
        ->toBe([FindingRemediationQuery::class, RemediationPlanner::class])
        ->and((new ReflectionClass(RemediationPlanner::class))->getConstructor()->getParameters())->toHaveCount(1);
});

it('exposes no apply/patch/edit surface in the remediation namespace or its adapters', function () {
    foreach (remediationFiles() as $file) {
        foreach (remediationIdentifiers($file) as $name) {
            expect(strtolower($name))->not->toMatch('/^(apply|patch|autofix|codemod|createpullrequest)|^(commit|checkout|push|edit)$/');
        }
    }

    foreach (get_class_methods(FindingRemediationService::class) as $method) {
        expect(strtolower($method))->not->toMatch('/(apply|patch|write|edit|fix$|commit)/');
    }
});
