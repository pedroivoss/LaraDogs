<?php

/**
 * Static proof that the Audit Engine core does not shell out anywhere.
 * Process execution is a documented, not-yet-implemented future boundary
 * (see App\Audit\Engine\Process\ProcessRunner and
 * docs/auditing/audit-engine.md) — this test guards against that boundary
 * being silently bypassed with an inline shell_exec/exec/system call.
 */
it('contains no direct shell/process-execution calls anywhere in the engine core', function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 4).'/app/Audit/Engine', FilesystemIterator::SKIP_DOTS),
    );

    $forbidden = ['shell_exec(', 'exec(', 'system(', 'passthru(', 'proc_open(', 'popen('];
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Strip comments/docblocks first — this file's own doc comments
        // mention shell_exec()/exec() by name as things NOT to do, which
        // would otherwise trip this check on prose, not code.
        $contents = php_strip_whitespace($file->getPathname());

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getPathname().' contains '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});
