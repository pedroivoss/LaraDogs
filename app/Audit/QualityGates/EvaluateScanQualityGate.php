<?php

namespace App\Audit\QualityGates;

use App\Audit\Findings\ScanStatus;
use App\Audit\QualityGates\Evaluation\GateEvidenceLoader;
use App\Audit\QualityGates\Evaluation\QualityGateEvaluator;
use App\Models\Audit\ProjectQualityGate;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\Scan;

/**
 * Evaluates a TERMINAL scan against its project's ENABLED Quality Gate
 * policy and persists the immutable result. The single evaluation path —
 * CLI, Dashboard/queued and scheduled audits all reach it through the same
 * `ScanFinished` event, so there is no per-trigger gate logic.
 *
 * No-ops (returns null) when: the scan is not terminal (a Queued/Running
 * scan is never gated), the project has no policy or it is disabled (a
 * disabled gate produces NO result — never a fake "Passed"). Idempotent:
 * an already-evaluated scan keeps its first result, so replays cannot
 * duplicate or change it.
 *
 * A `Failed` scan is evaluated too (its executions are absent/incomplete),
 * which yields Indeterminate for anything that rests on evidence it does
 * not have — never a false Passed.
 *
 * Read-only w.r.t. the audit domain: never touches findings, lifecycle or
 * analyzers, and never runs inside the scan's persistence transaction.
 */
final class EvaluateScanQualityGate
{
    public function __construct(
        private readonly GateEvidenceLoader $loader,
        private readonly QualityGateEvaluator $evaluator,
        private readonly QualityGateResultRecorder $recorder,
    ) {}

    public function __invoke(Scan $scan): ?QualityGateResult
    {
        if (! in_array($scan->status, [ScanStatus::Completed, ScanStatus::Failed], true)) {
            return null;
        }

        $gate = ProjectQualityGate::query()->where('project_id', $scan->project_id)->first();

        if ($gate === null || ! $gate->enabled) {
            return null;
        }

        $existing = QualityGateResult::query()->where('scan_id', $scan->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $policy = $gate->policy();

        if ($policy === null) {
            return null;
        }

        $evaluation = $this->evaluator->evaluate($policy, $this->loader->load($scan, $policy));

        return $this->recorder->record($scan, $gate, $policy, $evaluation);
    }
}
