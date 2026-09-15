<?php

namespace App\Http\Controllers\Projects;

use App\Audit\Projects\AuditSchedule;
use App\Audit\Projects\ProjectAuditScheduler;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateAuditScheduleRequest;
use App\Models\Audit\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Mutates a Project's optional audit schedule (Phase 7.1.4) — gated by
 * the `staff` route middleware (Owner/Admin only, see
 * `routes/web.php`), same reach as project registration/manual audit.
 * Recomputes `next_audit_at` immediately on every change via
 * {@see ProjectAuditScheduler} so the Dashboard's "Next run" always
 * reflects the just-saved schedule, never a stale value from before the
 * edit.
 */
final class ProjectAuditScheduleController extends Controller
{
    public function update(UpdateAuditScheduleRequest $request, Project $project, ProjectAuditScheduler $scheduler): RedirectResponse
    {
        $validated = $request->validated();
        $schedule = AuditSchedule::from($validated['audit_schedule']);

        $project->audit_schedule = $schedule;
        $project->audit_schedule_day_of_week = $validated['audit_schedule_day_of_week'] ?? null;
        $project->audit_schedule_day_of_month = $validated['audit_schedule_day_of_month'] ?? null;
        $next = $scheduler->nextRunAfter(
            $schedule,
            $project->audit_schedule_day_of_week,
            $project->audit_schedule_day_of_month,
            Carbon::now()->toImmutable(),
        );
        $project->next_audit_at = $next === null ? null : Carbon::instance($next);
        $project->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Audit schedule updated.')]);

        return back();
    }
}
