<?php

namespace App\Audit\Findings\Events;

use App\Models\Audit\Scan;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by `ScanRecorder` after a scan reached a TERMINAL state
 * (Completed or Failed) and its mutex was released — never for Queued or
 * Running. It lets policy layers (Quality Gates, Phase 8) react to a
 * finished scan without the audit pipeline knowing about them: the Engine
 * and the recorder stay unaware of organizational policy.
 */
final class ScanFinished
{
    use Dispatchable;

    public function __construct(public readonly Scan $scan) {}
}
