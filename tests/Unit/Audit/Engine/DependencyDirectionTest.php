<?php

/**
 * Static proof of the dependency direction ADR-0010 and Phase 4's design
 * both depend on: App\Audit\Engine must never depend on App\Audit\Findings
 * or on any concrete analyzer (App\Audit\Analyzers\*). Real analyzers are
 * free to depend on both (see ComposerAuditAnalyzer, which implements both
 * Engine's Analyzer and Findings\Ingestion's ProducesFindingCandidates) —
 * but that dependency must only ever point outward from the outer
 * Analyzers namespace, never back into Engine.
 */
it('never references App\Audit\Findings or App\Audit\Analyzers from inside App\Audit\Engine', function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 4).'/app/Audit/Engine', FilesystemIterator::SKIP_DOTS),
    );

    $forbidden = ['App\\Audit\\Findings', 'App\\Audit\\Analyzers'];
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = php_strip_whitespace($file->getPathname());

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $file->getPathname().' references '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});
