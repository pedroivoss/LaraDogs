<?php

namespace App\Providers;

use App\Audit\Analyzers\Composer\ComposerAuditAnalyzer;
use App\Audit\Analyzers\Npm\NpmAuditAnalyzer;
use App\Audit\Analyzers\Semgrep\SemgrepAnalyzer;
use App\Audit\Engine\Process\ProcessRunner;
use App\Audit\Engine\Process\SymfonyProcessRunner;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
