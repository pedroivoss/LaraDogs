<?php

namespace Tests\Support\Git;

use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Projects\RegisterProject;
use App\Models\Audit\Project;
use Symfony\Component\Process\Process;
use Tests\Support\Engine\Analyzers\ScriptedAnalyzer;

/**
 * A registered LaraDogs Project whose directory is a real Git repository
 * (a copy of the discovery fixture, committed on `main`).
 */
final class GitProject
{
    private function __construct(
        public readonly GitFixture $repo,
        public readonly Project $project,
        public readonly ScriptedAnalyzer $analyzer,
    ) {}

    public static function create(bool $git = true, string $fixture = 'laravel-blade', bool $commit = true): self
    {
        $repo = GitFixture::directory();
        (new Process(['cp', '-R', dirname(__DIR__, 2).'/Fixtures/discovery/'.$fixture.'/.', $repo->path]))->mustRun();

        if ($git) {
            $repo->git('init', '-q', '-b', 'main');

            if ($commit) {
                $repo->git('add', '-A');
                $repo->git('commit', '-q', '-m', 'Fixture project');
            }
        }

        $analyzer = new ScriptedAnalyzer('semgrep', ['R1', 'R2']);
        $registry = new AnalyzerRegistry;
        $registry->register($analyzer);
        app()->instance(AnalyzerRegistry::class, $registry);

        return new self($repo, (new RegisterProject)->register($repo->path)->project, $analyzer);
    }
}
