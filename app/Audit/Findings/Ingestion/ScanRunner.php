<?php

namespace App\Audit\Findings\Ingestion;

use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\FindingCandidate;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;

/**
 * The minimal orchestration seam between the Engine ({@see AuditEngine},
 * which must never depend on Findings — ADR-0010) and Findings
 * persistence: runs one real audit, then — for every executed analyzer
 * that also implements {@see ProducesFindingCandidates} — normalizes its
 * AnalyzerResult into FindingCandidates and hands everything to
 * {@see ScanRecorder}.
 *
 * Deliberately NOT part of `App\Audit\Engine`: it depends on both Engine
 * and Findings types, which would create the exact Engine -> Findings ->
 * Engine cycle ADR-0010 forbids if it lived in Engine's own namespace. It
 * lives in `Findings\Ingestion` instead, alongside the other pipeline
 * seams that already depend on Engine types (`ScanRecorder` itself already
 * takes an `AuditRunResult`).
 *
 * An `AnalyzerExecution` only carries an analyzer's id/name/category/
 * status/result, never the analyzer instance itself (by design — see
 * `AuditPlanItem`), so normalizing candidates requires looking the
 * original analyzer back up in the same {@see AnalyzerRegistry} the
 * engine was run against.
 */
final class ScanRunner
{
    public function __construct(
        private readonly AuditEngine $engine,
        private readonly AnalyzerRegistry $registry,
        private readonly ScanRecorder $recorder,
    ) {}

    public function run(Project $project, AuditContext $context): Scan
    {
        $scan = $this->recorder->startScan($project, $context->profile);

        $runResult = $this->engine->run($context);

        /** @var array<string, list<FindingCandidate>> $candidatesByAnalyzer */
        $candidatesByAnalyzer = [];

        foreach ($runResult->executions as $execution) {
            if ($execution->status !== ExecutionStatus::Passed || $execution->result === null) {
                continue;
            }

            $analyzer = $this->registry->get($execution->id);

            if ($analyzer instanceof ProducesFindingCandidates) {
                $candidatesByAnalyzer[(string) $execution->id] = $analyzer->candidates($context, $execution->result);
            }
        }

        return $this->recorder->completeScan($scan, $runResult, $candidatesByAnalyzer);
    }
}
