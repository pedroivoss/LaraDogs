<?php

namespace App\Audit\QualityGates\Evaluation;

use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Execution\ExecutionStatus;

/**
 * What one analyzer's persisted execution said in one scan — the only
 * two facts a gate needs from it (status and declared coverage).
 */
final readonly class ExecutionEvidence
{
    public function __construct(
        public ExecutionStatus $status,
        public AnalyzerCoverage $coverage,
    ) {}
}
