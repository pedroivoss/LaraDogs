<?php

namespace App\Audit\QualityGates;

use App\Audit\Findings\Events\ScanFinished;
use Throwable;

/**
 * Bridges {@see ScanFinished} to {@see EvaluateScanQualityGate}. A gate
 * failure must NEVER undo or break a scan that already finished: any
 * error is reported and swallowed here, leaving the scan without a gate
 * result — which every reader (Dashboard, CLI) treats as "not evaluated",
 * never as a pass.
 */
final class EvaluateQualityGateWhenScanFinishes
{
    public function __construct(private readonly EvaluateScanQualityGate $evaluate) {}

    public function handle(ScanFinished $event): void
    {
        try {
            ($this->evaluate)($event->scan);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
