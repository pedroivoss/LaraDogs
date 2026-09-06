<?php

namespace App\Audit\Findings\Ingestion;

use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Engine\Execution\AnalyzerExecution;
use App\Audit\Engine\Execution\AuditRunResult;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\ScanStatus;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ties Phase 1 (Project Discovery) and Phase 2 (Audit Engine) output to
 * Phase 3 persistence: opens a Scan, persists one
 * {@see ScanAnalyzerExecution} per {@see AnalyzerExecution},
 * ingests every observed {@see FindingCandidate} via {@see FindingIngestor},
 * then reconciles auto-resolutions via {@see FindingReconciler}.
 *
 * No real analyzer produces `FindingCandidate`s yet (Phase 4+) — callers
 * (tests, for now) supply them alongside a real {@see AuditRunResult} from
 * the Phase 2 engine.
 */
final class ScanRecorder
{
    public function __construct(
        private readonly FindingIngestor $ingestor,
        private readonly FindingReconciler $reconciler,
    ) {}

    public function startScan(Project $project, ProjectProfile $profile): Scan
    {
        return Scan::query()->create([
            'project_id' => $project->id,
            'status' => ScanStatus::Running,
            'started_at' => now(),
            'laradogs_version' => config('laradogs.version'),
            'project_profile' => $profile,
            'environment' => [
                'php_version' => PHP_VERSION,
                'os' => PHP_OS_FAMILY,
            ],
        ]);
    }

    /**
     * @param  array<string, list<FindingCandidate>>  $candidatesByAnalyzer  Keyed by analyzer id.
     */
    public function completeScan(Scan $scan, AuditRunResult $runResult, array $candidatesByAnalyzer = []): Scan
    {
        try {
            DB::transaction(function () use ($scan, $runResult, $candidatesByAnalyzer): void {
                foreach ($runResult->executions as $execution) {
                    ScanAnalyzerExecution::query()->create([
                        'scan_id' => $scan->id,
                        'analyzer_id' => (string) $execution->id,
                        'analyzer_name' => $execution->name,
                        'category' => $execution->category,
                        'status' => $execution->status,
                        'summary' => $execution->result?->summary,
                        'diagnostics' => $execution->result?->diagnostics,
                        'duration_ms' => $execution->durationMs,
                        'note' => $execution->note,
                    ]);
                }

                foreach ($candidatesByAnalyzer as $candidates) {
                    foreach ($candidates as $candidate) {
                        $this->ingestor->ingest($scan->project, $scan, $candidate);
                    }
                }
            });
        } catch (Throwable $exception) {
            $scan->status = ScanStatus::Failed;
            $scan->finished_at = now();
            $scan->save();

            throw $exception;
        }

        $resolvedCount = $this->reconciler->reconcile($scan->project, $scan);

        $scan->status = ScanStatus::Completed;
        $scan->finished_at = now();
        $scan->duration_ms = $runResult->durationMs;
        $scan->findings_summary = $this->summarize($scan, $resolvedCount);
        $scan->save();

        return $scan;
    }

    /**
     * @return array<string,mixed>
     */
    private function summarize(Scan $scan, int $autoResolvedCount): array
    {
        $findingIds = FindingOccurrence::query()->where('scan_id', $scan->id)->pluck('finding_id');

        $bySeverity = Finding::query()
            ->whereIn('id', $findingIds)
            ->pluck('severity')
            ->countBy(fn ($severity) => $severity->value);

        return [
            'observed' => $findingIds->count(),
            'auto_resolved' => $autoResolvedCount,
            'by_severity' => $bySeverity,
        ];
    }
}
