<?php

/**
 * Static proof that the Audit Engine core does not shell out anywhere.
 * Process execution is a documented, not-yet-implemented future boundary
 * (see App\Audit\Engine\Process\ProcessRunner and
 * docs/auditing/audit-engine.md) — this test guards against that boundary
 * being silently bypassed with an inline shell_exec/exec/system call.
 */
function scanForForbiddenProcessCalls(string $directory): array
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    $forbidden = ['shell_exec(', 'exec(', 'system(', 'passthru(', 'proc_open(', 'popen('];
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Strip comments/docblocks first — several of these files' own doc
        // comments mention shell_exec()/exec()/proc_open() by name (either
        // as things NOT to do, or — for SymfonyProcessRunner — to explain
        // that Symfony's OWN internal use of proc_open is acceptable),
        // which would otherwise trip this check on prose, not code.
        $contents = php_strip_whitespace($file->getPathname());

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getPathname().' contains '.$needle;
            }
        }
    }

    return $offenders;
}

it('contains no direct shell/process-execution calls anywhere in the engine core', function () {
    $offenders = scanForForbiddenProcessCalls(dirname(__DIR__, 4).'/app/Audit/Engine');

    expect($offenders)->toBe([]);
});

it('contains no direct shell/process-execution calls anywhere in real analyzers', function () {
    // ComposerAuditAnalyzer (and any future analyzer) must reach external
    // tools exclusively through ProcessRunner — never a scattered manual
    // shell call of its own.
    $offenders = scanForForbiddenProcessCalls(dirname(__DIR__, 4).'/app/Audit/Analyzers');

    expect($offenders)->toBe([]);
});
