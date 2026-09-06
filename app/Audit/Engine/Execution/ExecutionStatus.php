<?php

namespace App\Audit\Engine\Execution;

use App\Audit\Engine\Plan\AuditPlanItem;

/**
 * The full lifecycle vocabulary shared by an {@see AuditPlanItem}
 * (pre-execution: only Planned/NotApplicable/Unavailable are possible) and
 * an {@see AnalyzerExecution} (post-execution: any case is possible).
 *
 * `Running` and `Cancelled` were considered (they're natural companions to
 * this set) and deliberately left out: Phase 2's engine runs analyzers
 * synchronously and in-process with no cancellation mechanism, so no code
 * path can ever produce either state — adding them now would be designing
 * for a problem (async/queued execution, mid-run cancellation) this phase
 * doesn't have yet. Add them, with real callers, when one of those
 * actually exists.
 */
enum ExecutionStatus: string
{
    /** In the plan, will be executed. */
    case Planned = 'planned';

    /** The analyzer's own `run()` completed and reported success. */
    case Passed = 'passed';

    /** The analyzer's own `run()` completed and reported failure. */
    case Failed = 'failed';

    /** The analyzer (or, in the future, the process running it) reported a timeout. */
    case TimedOut = 'timed_out';

    /** Applicable and available, but not run — e.g. fail-fast after an earlier failure. */
    case Skipped = 'skipped';

    /** The project's detected stack doesn't call for this analyzer. */
    case NotApplicable = 'not_applicable';

    /** Applicable, but this host can't currently run it. */
    case Unavailable = 'unavailable';
}
