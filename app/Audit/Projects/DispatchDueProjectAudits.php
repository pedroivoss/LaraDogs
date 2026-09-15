<?php

namespace App\Audit\Projects;

use App\Audit\Findings\ScanOrigin;
use App\Jobs\RunProjectAuditJob;
use App\Models\Audit\Project;
use Illuminate\Support\Carbon;

/**
 * The Laravel Scheduler's own tick calls this (via
 * `laradogs:project:dispatch-due-audits`, scheduled every minute — see
 * `routes/console.php`) — it never runs analyzers itself (section 32's
 * own explicit instruction). The due-project lookup is a single indexed
 * range scan against `projects.next_audit_at` (persisted, precomputed by
 * {@see ProjectAuditScheduler} — see that column's own migration
 * docblock), never a fresh discovery/profile recomputation per project.
 *
 * Duplicate-dispatch safety is inherited entirely from
 * {@see RunProjectAudit::enqueue()}'s own portable mutex (Phase 7.1.4) —
 * this class does nothing extra to prevent double-enqueueing a project
 * that's already active; `enqueue()` already refuses. This is also what
 * makes "the scheduler ticks twice while a project is still due"
 * (Compose's `scheduler` service restarting mid-tick, or an operator
 * running the command manually at the same moment) safe by construction.
 *
 * A failed/skipped dispatch (project already active, or `enqueue()`
 * otherwise declines) still advances `next_audit_at` to the next
 * regularly-scheduled occurrence — a failure must never disable future
 * scheduling, and must never create a retry storm (see this class's own
 * `dispatch()` docblock below for the missed-schedule "one catch-up run"
 * semantics this also produces for free).
 */
final readonly class DispatchDueProjectAudits
{
    public function __construct(
        private RunProjectAudit $runner,
        private ProjectAuditScheduler $scheduler,
    ) {}

    /**
     * @return int number of audits actually enqueued this tick
     */
    public function dispatch(): int
    {
        $now = Carbon::now();

        $dueProjects = Project::query()
            ->where('audit_schedule', '!=', AuditSchedule::Disabled->value)
            ->whereNotNull('next_audit_at')
            ->where('next_audit_at', '<=', $now)
            ->get();

        $dispatched = 0;

        foreach ($dueProjects as $project) {
            $result = $this->runner->enqueue($project, ScanOrigin::Scheduled);

            // Recompute from NOW, not from the missed `next_audit_at` —
            // if LaraDogs was down for several periods, this produces
            // exactly ONE catch-up dispatch (already happened above) and
            // resumes the regular cadence from here, never a backlog of
            // every missed historical occurrence.
            $next = $this->scheduler->nextRunAfter(
                $project->audit_schedule,
                $project->audit_schedule_day_of_week,
                $project->audit_schedule_day_of_month,
                $now->toImmutable(),
            );
            $project->next_audit_at = $next === null ? null : Carbon::instance($next);

            if ($result->outcome === RunProjectAuditOutcome::Queued) {
                $project->last_scheduled_audit_at = $now;
                RunProjectAuditJob::dispatch($result->scan->id)->afterCommit();
                $dispatched++;
            }

            $project->save();
        }

        return $dispatched;
    }
}
