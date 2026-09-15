<?php

namespace App\Audit\Findings\Ingestion;

use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Engine\Execution\AnalyzerExecution;
use App\Audit\Engine\Execution\AuditRunResult;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\RunProjectAudit;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\Project;
use App\Models\Audit\ProjectActiveScan;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ties Phase 1 (Project Discovery) and Phase 2 (Audit Engine) output to
 * Phase 3 persistence: opens a Scan, persists one
 * {@see ScanAnalyzerExecution} per {@see AnalyzerExecution},
 * ingests every observed {@see FindingCandidate} via {@see FindingIngestor},
 * then reconciles auto-resolutions via {@see FindingReconciler}.
 *
 * Phase 7.1.4 splits scan creation into two steps —
 * {@see enqueueScan()} (a Scan exists, `Queued`, before any analyzer
 * runs — see App\Audit\Findings\ScanStatus's own docblock) and
 * {@see beginRunning()} (the worker actually starts) — instead of the
 * single Phase 3 `startScan()`. {@see completeScan()}'s own persistence
 * behavior is unchanged.
 */
final class ScanRecorder
{
    public function __construct(
        private readonly FindingIngestor $ingestor,
        private readonly FindingReconciler $reconciler,
    ) {}

    /**
     * Reserves a `Queued` Scan for a Project, atomically with the
     * portable per-project mutex row (see
     * `create_project_active_scans_table`'s migration docblock) — both
     * inserts succeed together or neither does. `project_profile` is an
     * empty placeholder (the column is NOT NULL; discovery genuinely
     * hasn't happened yet) — {@see beginRunning()} overwrites it with the
     * real, freshly-discovered profile.
     *
     * @throws QueryException if another active scan
     *                        already holds this project's mutex row — callers should
     *                        catch this exactly where App\Audit\Projects\RegisterProject
     *                        already catches its own analogous race (see that class).
     */
    public function enqueueScan(Project $project, ScanOrigin $origin, ?User $actor): Scan
    {
        return DB::transaction(function () use ($project, $origin, $actor): Scan {
            $scan = Scan::query()->create([
                'project_id' => $project->id,
                'status' => ScanStatus::Queued,
                'origin' => $origin,
                'initiated_by_user_id' => $actor?->id,
                'started_at' => now(),
                'project_profile' => [],
            ]);

            ProjectActiveScan::query()->create([
                'project_id' => $project->id,
                'scan_id' => $scan->id,
            ]);

            return $scan;
        });
    }

    /**
     * Convenience combining {@see enqueueScan()} + {@see beginRunning()}
     * in one call, for callers that don't care about the interim
     * `Queued` micro-state — e.g. tests exercising the Discovery ->
     * Engine -> persistence pipeline directly, without going through
     * {@see RunProjectAudit}. Equivalent to the
     * pre-Phase-7.1.4 single-step `startScan()`.
     */
    public function startScan(Project $project, ProjectProfile $profile, ScanOrigin $origin = ScanOrigin::Cli): Scan
    {
        $scan = $this->enqueueScan($project, $origin, null);

        return $this->beginRunning($scan, $profile);
    }

    /**
     * Transitions a `Queued` scan to `Running`, recording the real,
     * freshly-discovered profile (never the registration-time one — see
     * App\Audit\Projects\RunProjectAudit's own docblock).
     */
    public function beginRunning(Scan $scan, ProjectProfile $profile): Scan
    {
        $scan->status = ScanStatus::Running;
        $scan->running_at = now();
        $scan->heartbeat_at = now();
        $scan->laradogs_version = config('laradogs.version');
        $scan->project_profile = $profile->jsonSerialize();
        $scan->environment = [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
        ];
        $scan->save();

        return $scan;
    }

    /**
     * Touches the heartbeat only — called between analyzer stages by
     * {@see ScanRunner} so a crashed worker is distinguishable from a
     * genuinely still-running long Semgrep pass (see
     * App\Audit\Projects\StaleScanReclaimer).
     */
    public function touchHeartbeat(Scan $scan): void
    {
        $scan->forceFill(['heartbeat_at' => now()])->save();
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
                        'coverage' => $execution->coverage(),
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
            $this->releaseActiveLock($scan);

            throw $exception;
        }

        $resolvedCount = $this->reconciler->reconcile($scan->project, $scan);

        $scan->status = ScanStatus::Completed;
        $scan->finished_at = now();
        $scan->duration_ms = $runResult->durationMs;
        $scan->findings_summary = $this->summarize($scan, $resolvedCount);
        $scan->save();
        $this->releaseActiveLock($scan);

        return $scan;
    }

    /**
     * Marks a scan Failed without ever attempting the persistence
     * pipeline above — used when discovery itself fails after a Queued
     * scan was already reserved (the path became unavailable between
     * dispatch and execution), or when a worker never gets far enough to
     * call {@see completeScan()} at all.
     */
    public function failScan(Scan $scan): Scan
    {
        $scan->status = ScanStatus::Failed;
        $scan->finished_at = now();
        $scan->save();
        $this->releaseActiveLock($scan);

        return $scan;
    }

    public function releaseActiveLock(Scan $scan): void
    {
        ProjectActiveScan::query()
            ->where('project_id', $scan->project_id)
            ->where('scan_id', $scan->id)
            ->delete();
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
