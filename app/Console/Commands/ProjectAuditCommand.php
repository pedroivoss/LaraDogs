<?php

namespace App\Console\Commands;

use App\Audit\Projects\RunProjectAudit;
use App\Audit\Projects\RunProjectAuditOutcome;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Console\Command;

/**
 * Thin CLI adapter over {@see RunProjectAudit} — persists a Scan against
 * an already-registered Project. Distinct from `laradogs:audit` (ad-hoc,
 * stateless, never persists — see that command's own docblock): this
 * command is the persisted-project workflow's execution step.
 */
final class ProjectAuditCommand extends Command
{
    protected $signature = 'laradogs:project:audit
        {project : A project\'s public ID (see `laradogs:project:list`)}
        {--json : Output the result as JSON}';

    protected $description = 'Run a persisted audit for a registered project, recording a new Scan';

    public function handle(RunProjectAudit $runner): int
    {
        $publicId = (string) $this->argument('project');

        $project = Project::query()->where('public_id', $publicId)->first();

        if ($project === null) {
            return $this->failWith("No project found with ID: {$publicId}. See `laradogs:project:list`.");
        }

        $result = $runner->run($project);

        return match ($result->outcome) {
            RunProjectAuditOutcome::PathUnavailable => $this->failWith(
                $result->discoveryFailure === null
                    ? "Project path is no longer available: {$project->path}"
                    : $result->discoveryFailure->status->describe($result->discoveryFailure->path),
            ),
            RunProjectAuditOutcome::AlreadyRunning => $this->failWith(
                $result->conflictingScan === null
                    ? 'Another audit for this project is already running.'
                    : "Another audit for this project is already running (scan {$result->conflictingScan->public_id}, started {$result->conflictingScan->started_at->diffForHumans()}).",
            ),
            RunProjectAuditOutcome::Completed => $result->scan === null
                ? $this->failWith('Unexpected: audit completed but no scan was returned.')
                : $this->renderCompleted($project, $result->scan),
        };
    }

    private function failWith(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['succeeded' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    private function renderCompleted(Project $project, Scan $scan): int
    {
        $executions = ScanAnalyzerExecution::query()->where('scan_id', $scan->id)->get();
        $occurrences = FindingOccurrence::query()->where('scan_id', $scan->id)->with('finding')->get();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                [
                    'succeeded' => true,
                    'scan' => $this->scanToArray($scan),
                    'analyzer_executions' => $executions->map($this->executionToArray(...))->all(),
                    'findings' => $occurrences->map($this->occurrenceToArray(...))->all(),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->components->info("Scan {$scan->public_id} for {$project->name}: {$scan->status->value}");
        $this->line("Duration: {$scan->duration_ms}ms");
        $this->newLine();

        foreach ($executions as $execution) {
            $this->renderExecution($execution);
        }

        if ($occurrences->isNotEmpty()) {
            $this->components->info(sprintf('Findings (%d)', $occurrences->count()));
            $this->newLine();

            foreach ($occurrences as $occurrence) {
                $this->renderOccurrence($occurrence);
            }
        }

        return self::SUCCESS;
    }

    private function renderExecution(ScanAnalyzerExecution $execution): void
    {
        $color = match ($execution->status->value) {
            'passed' => 'green',
            'failed', 'timed_out' => 'red',
            'not_applicable', 'unavailable', 'skipped' => 'gray',
            default => 'default',
        };

        $this->line("<fg={$color}>[{$execution->status->value}]</> {$execution->analyzer_name} ({$execution->analyzer_id})");

        if ($execution->summary !== null) {
            $this->line("  {$execution->summary}");
        }

        $this->newLine();
    }

    private function renderOccurrence(FindingOccurrence $occurrence): void
    {
        $finding = $occurrence->finding;

        $color = match ($finding->severity->value) {
            'critical', 'high' => 'red',
            'medium' => 'yellow',
            'low', 'info' => 'gray',
            default => 'default',
        };

        $location = $occurrence->file_path !== null
            ? sprintf('%s:%s', $occurrence->file_path, $occurrence->line_start ?? '?')
            : '(no location)';

        $this->line(sprintf(
            '<fg=%s>[%s]</> %s <fg=gray>(%s, confidence: %s)</>',
            $color,
            strtoupper($finding->severity->value),
            $finding->rule_id,
            $finding->category->value,
            $finding->confidence->value,
        ));
        $this->line("  {$location}");
        $this->line("  {$finding->title}");
        $this->newLine();
    }

    /**
     * @return array<string,mixed>
     */
    private function scanToArray(Scan $scan): array
    {
        return [
            'id' => $scan->public_id,
            'status' => $scan->status->value,
            'started_at' => $scan->started_at->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'duration_ms' => $scan->duration_ms,
            'findings_summary' => $scan->findings_summary,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function executionToArray(ScanAnalyzerExecution $execution): array
    {
        return [
            'analyzer_id' => $execution->analyzer_id,
            'analyzer_name' => $execution->analyzer_name,
            'status' => $execution->status->value,
            'summary' => $execution->summary,
            'duration_ms' => $execution->duration_ms,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function occurrenceToArray(FindingOccurrence $occurrence): array
    {
        $finding = $occurrence->finding;

        return [
            'finding_id' => $finding->public_id,
            'rule_id' => $finding->rule_id,
            'analyzer_id' => $finding->analyzer_id,
            'category' => $finding->category->value,
            'severity' => $finding->severity->value,
            'confidence' => $finding->confidence->value,
            'status' => $finding->status->value,
            'title' => $finding->title,
            'file' => $occurrence->file_path,
            'line_start' => $occurrence->line_start,
            'line_end' => $occurrence->line_end,
        ];
    }
}
