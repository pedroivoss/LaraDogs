<?php

namespace App\Console\Commands;

use App\Audit\Discovery\DiscoveryResult;
use App\Audit\Discovery\DiscoveryStatus;
use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Execution\AnalyzerExecution;
use App\Audit\Engine\Execution\AuditRunResult;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use Illuminate\Console\Command;

/**
 * A thin CLI adapter over the Audit Engine — contains no analyzer logic of
 * its own, only argument parsing and rendering. Deliberately prints the
 * engine's `AuditRunResult` and stops there: it does NOT create a
 * `Project`/`Scan` or otherwise persist anything (see
 * `App\Audit\Findings\Ingestion\ScanRunner` for the persisted-run path,
 * which is exercised by the automated test suite). An ad-hoc CLI
 * invocation has no well-defined, stable `Project` identity to attach
 * history to — inventing one here would be scope this command doesn't
 * need. See docs/auditing/analyzers/composer-audit.md's CLI section.
 */
final class AuditCommand extends Command
{
    protected $signature = 'laradogs:audit
        {path : Path to the project to audit}
        {--json : Output the full AuditRunResult as JSON}
        {--analyzer= : Only run the analyzer with this id (e.g. composer-audit)}';

    protected $description = 'Run a one-off audit against a directory and print the result (read-only, never persists a Scan)';

    public function handle(ProjectDiscovery $discovery, AnalyzerRegistry $registry): int
    {
        $path = (string) $this->argument('path');

        $discoveryResult = $discovery->discover($path);

        if ($discoveryResult->profile === null) {
            $this->error($this->describeDiscoveryFailure($discoveryResult));

            return self::FAILURE;
        }

        $analyzerFilter = $this->option('analyzer');
        $scopedRegistry = $analyzerFilter !== null
            ? $this->scopedRegistry($registry, (string) $analyzerFilter)
            : $registry;

        if ($scopedRegistry === null) {
            $this->error("Unknown analyzer id: {$analyzerFilter}");

            return self::FAILURE;
        }

        $context = new AuditContext(
            runId: (string) str()->uuid(),
            projectPath: $discoveryResult->path,
            profile: $discoveryResult->profile,
        );

        $runResult = (new AuditEngine($scopedRegistry))->run($context);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($runResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($runResult);

        return self::SUCCESS;
    }

    private function scopedRegistry(AnalyzerRegistry $registry, string $analyzerId): ?AnalyzerRegistry
    {
        $analyzer = $registry->get(new AnalyzerId($analyzerId));

        if ($analyzer === null) {
            return null;
        }

        $scoped = new AnalyzerRegistry;
        $scoped->register($analyzer);

        return $scoped;
    }

    private function describeDiscoveryFailure(DiscoveryResult $result): string
    {
        return match ($result->status) {
            DiscoveryStatus::PathNotFound => "Path not found: {$result->path}",
            DiscoveryStatus::PathNotDirectory => "Not a directory: {$result->path}",
            DiscoveryStatus::PathNotReadable => "Path is not readable: {$result->path}",
            DiscoveryStatus::Ok => 'Unexpected: reported as failure but status is ok.',
        };
    }

    private function renderHuman(AuditRunResult $result): void
    {
        $this->components->info("Audit run: {$result->runId}");
        $this->line("Duration: {$result->durationMs}ms");
        $this->newLine();

        foreach ($result->executions as $execution) {
            $this->renderExecution($execution);
        }
    }

    private function renderExecution(AnalyzerExecution $execution): void
    {
        $color = match ($execution->status->value) {
            'passed' => 'green',
            'failed', 'timed_out' => 'red',
            'not_applicable', 'unavailable', 'skipped' => 'gray',
            default => 'default',
        };

        $this->line("<fg={$color}>[{$execution->status->value}]</> {$execution->name} ({$execution->id})");

        if ($execution->note !== null) {
            $this->line("  {$execution->note}");
        }

        if ($execution->result !== null) {
            $this->line("  {$execution->result->summary}");

            foreach ($execution->result->diagnostics as $diagnostic) {
                $this->line("  - [{$diagnostic->level->value}] {$diagnostic->message}");
            }
        }

        $this->newLine();
    }
}
