<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\Engine\Analyzers\AlwaysFailAnalyzer;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;

/**
 * Extends Phase 1's no-code-execution guarantee (tests/Unit/Audit/Discovery/NoCodeExecutionTest.php)
 * to the Phase 2 pipeline: Project Discovery -> ProjectProfile -> AuditContext
 * -> AuditEngine (plan + run with fake analyzers). Proves that building a
 * plan and executing it never triggers the target's composer.json/
 * package.json scripts — the new Audit Engine layer adds no implicit
 * execution of its own.
 */
it('never executes composer.json/package.json scripts in the analyzed project when planning and running an audit', function () {
    $filesystem = new Filesystem;

    $source = dirname(__DIR__, 3).'/Fixtures/discovery/no-code-execution';
    $target = sys_get_temp_dir().'/laradogs-engine-no-exec-'.uniqid();

    $filesystem->copyDirectory($source, $target);

    try {
        $discovery = (new ProjectDiscovery)->discover($target);
        expect($discovery->isSuccessful())->toBeTrue();

        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer);
        $registry->register(new AlwaysFailAnalyzer);

        $engine = new AuditEngine($registry);
        $context = new AuditContext(runId: 'no-exec-test', projectPath: $target, profile: $discovery->profile);

        $plan = $engine->plan($context);
        expect($filesystem->exists($target.'/SHOULD_NEVER_EXIST'))->toBeFalse();

        $result = $engine->execute($plan, $context);

        expect($result->executions)->toHaveCount(2);
        expect($filesystem->exists($target.'/SHOULD_NEVER_EXIST'))->toBeFalse();
        expect($filesystem->exists($source.'/SHOULD_NEVER_EXIST'))->toBeFalse();
    } finally {
        $filesystem->deleteDirectory($target);
    }
});
