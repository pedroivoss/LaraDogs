<?php

namespace App\Providers;

use App\Audit\Analyzers\Composer\ComposerAuditAnalyzer;
use App\Audit\Analyzers\Npm\NpmAuditAnalyzer;
use App\Audit\Analyzers\Semgrep\SemgrepAnalyzer;
use App\Audit\Engine\Process\ProcessRunner;
use App\Audit\Engine\Process\SymfonyProcessRunner;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\Events\ScanFinished;
use App\Audit\QualityGates\EvaluateQualityGateWhenScanFinishes;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Integrations\GitHub\GitHubApiClient;
use App\Integrations\GitHub\RecordGitHubCheckRun;
use App\Mcp\Auth\EnvironmentMcpTokenSource;
use App\Mcp\Auth\McpTokenSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProcessRunner::class, SymfonyProcessRunner::class);

        // Git metadata inspection uses its OWN runner: a much smaller
        // output cap than analyzers (a hostile repository must not create
        // an unbounded payload) — see config('laradogs.git').
        $this->app->singleton(GitRepositoryInspector::class, fn (): GitRepositoryInspector => new GitRepositoryInspector(
            runner: new SymfonyProcessRunner((int) config('laradogs.git.max_output_bytes')),
            binary: (string) config('laradogs.git.binary'),
            budgetSeconds: (int) config('laradogs.git.budget_seconds'),
            home: (string) config('laradogs.git.home'),
        ));

        // MCP (Phase 11): the caller's credential comes from the process
        // environment (stdio); a seam so authorization never depends on it.
        $this->app->bind(McpTokenSource::class, EnvironmentMcpTokenSource::class);

        // GitHub integration (Phase 10): consumes CI/Quality Gate results,
        // never defines them — see App\Integrations\GitHub's own docblocks.
        $this->app->singleton(GitHubApiClient::class, fn (): GitHubApiClient => new GitHubApiClient(
            http: $this->app->make(Factory::class),
            apiVersion: (string) config('laradogs.github.api_version'),
            userAgent: (string) config('laradogs.github.user_agent'),
            timeoutSeconds: (int) config('laradogs.ci.github_report_timeout_seconds'),
        ));

        $this->app->singleton(RecordGitHubCheckRun::class, fn (): RecordGitHubCheckRun => new RecordGitHubCheckRun(
            client: $this->app->make(GitHubApiClient::class),
            checkName: (string) config('laradogs.github.check_name'),
            summaryMaxLength: (int) config('laradogs.github.summary_max_length'),
            publicUrl: config('laradogs.public_url') === null ? null : rtrim((string) config('laradogs.public_url'), '/'),
        ));

        $this->app->singleton(AnalyzerRegistry::class, function (): AnalyzerRegistry {
            $registry = new AnalyzerRegistry;
            $registry->register($this->app->make(ComposerAuditAnalyzer::class));
            $registry->register($this->app->make(NpmAuditAnalyzer::class));
            $registry->register($this->app->make(SemgrepAnalyzer::class));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Quality Gates (Phase 8): evaluated when a scan reaches a terminal
        // state — one path for CLI, Dashboard/queued and scheduled audits.
        Event::listen(ScanFinished::class, [EvaluateQualityGateWhenScanFinishes::class, 'handle']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
