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
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\Ingestion\ProducesFindingCandidates;
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
 *
 * Phase 6: normalizes and prints each analyzer's `FindingCandidate`s
 * (rule id, severity, file:line, message) the same way
 * `App\Audit\Findings\Ingestion\ScanRunner` does — by calling
 * `ProducesFindingCandidates::candidates()` on the same registry — but
 * WITHOUT ever calling `ScanRecorder`, so nothing is persisted. Before
 * this, the CLI only ever printed an analyzer's own summary/diagnostics
 * (e.g. "2 finding(s) found"), never the findings themselves — not useful
 * enough for a real manual audit. This is deliberately NOT a new API: the
 * JSON shape below is built ad hoc in this command, not a stable contract,
 * and `FindingCandidate` itself gained no new interface.
 */
final class AuditCommand extends Command
{
    protected $signature = 'laradogs:audit
        {path : Path to the project to audit}
        {--json : Output the full AuditRunResult (with normalized findings) as JSON}
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
        $candidates = $this->collectCandidates($scopedRegistry, $context, $runResult);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                [
                    'run' => $runResult,
                    'findings' => array_map($this->candidateToArray(...), $candidates),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->renderHuman($runResult);
        $this->renderFindings($candidates, $discoveryResult->path);

        return self::SUCCESS;
    }

    /**
     * @return list<FindingCandidate>
     */
    private function collectCandidates(AnalyzerRegistry $registry, AuditContext $context, AuditRunResult $runResult): array
    {
        $candidates = [];

        foreach ($runResult->executions as $execution) {
            if ($execution->status !== ExecutionStatus::Passed || $execution->result === null) {
                continue;
            }

            $analyzer = $registry->get($execution->id);

            if ($analyzer instanceof ProducesFindingCandidates) {
                array_push($candidates, ...$analyzer->candidates($context, $execution->result));
            }
        }

        return $candidates;
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

    /**
     * @param  list<FindingCandidate>  $candidates
     */
    private function renderFindings(array $candidates, string $projectPath): void
    {
        if ($candidates === []) {
            return;
        }

        $this->components->info(sprintf('Findings (%d)', count($candidates)));
        $this->newLine();

        foreach ($candidates as $candidate) {
            $this->renderFinding($candidate);
        }
    }

    private function renderFinding(FindingCandidate $candidate): void
    {
        $color = match ($candidate->severity->value) {
            'critical', 'high' => 'red',
            'medium' => 'yellow',
            'low', 'info' => 'gray',
            default => 'default',
        };

        $location = $candidate->filePath !== null
            ? sprintf('%s:%s', $candidate->filePath, $candidate->lineStart ?? '?')
            : '(no location)';

        $this->line(sprintf(
            '<fg=%s>[%s]</> %s <fg=gray>(%s, confidence: %s)</>',
            $color,
            strtoupper($candidate->severity->value),
            $candidate->ruleId,
            $candidate->category->value,
            $candidate->confidence->value,
        ));
        $this->line("  {$location}");
        $this->line("  {$candidate->title}");
        $this->newLine();
    }

    /**
     * A deliberately ad hoc array shape for this command's own `--json`
     * output — not a stable API, and not a new interface on
     * `FindingCandidate` itself (see this class's own docblock).
     *
     * @return array<string,mixed>
     */
    private function candidateToArray(FindingCandidate $candidate): array
    {
        return [
            'rule_id' => $candidate->ruleId,
            'analyzer_id' => $candidate->analyzerId,
            'category' => $candidate->category->value,
            'severity' => $candidate->severity->value,
            'confidence' => $candidate->confidence->value,
            'title' => $candidate->title,
            'description' => $candidate->description,
            'recommendation' => $candidate->recommendation,
            'file' => $candidate->filePath,
            'line_start' => $candidate->lineStart,
            'line_end' => $candidate->lineEnd,
            'cwe' => $candidate->cwe,
            'references' => $candidate->references,
        ];
    }
}
