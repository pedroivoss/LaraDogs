<?php

namespace App\Audit\Findings;

/**
 * Phase 7.1.4 adds `Queued`: a Scan now exists (with a stable public ID)
 * from the moment an audit is requested — manually, by schedule, or by
 * CLI — not only once a worker actually starts executing analyzers. This
 * is what lets the Dashboard show "Queued" immediately after "Run Audit"
 * without waiting on a worker, and lets a queued-but-not-yet-picked-up
 * job be distinguished from one a crashed worker abandoned mid-execution
 * (see App\Audit\Projects\StaleScanReclaimer, which now uses separate
 * thresholds for each).
 */
enum ScanStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
