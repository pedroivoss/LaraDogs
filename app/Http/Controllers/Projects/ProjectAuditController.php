<?php

namespace App\Http\Controllers\Projects;

use App\Audit\Findings\ScanOrigin;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\Projects\RunProjectAuditOutcome;
use App\Http\Controllers\Controller;
use App\Jobs\RunProjectAuditJob;
use App\Models\Audit\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Dashboard "Run Audit" (Phase 7.1.4) — gated by the `staff` route
 * middleware (Owner/Admin only, see `routes/web.php`), same as project
 * registration: an audit consumes server CPU/network, an administrative
 * concern in this V1 model, not a general-User capability. A thin
 * adapter, same architecture rule as every other Dashboard controller:
 * {@see RunProjectAudit::enqueue()} does the actual reservation/
 * concurrency work; this controller only translates that into an HTTP
 * response and dispatches the queue job — no audit-domain logic here,
 * and analyzers never run inside this request (the whole point of
 * queuing).
 */
final class ProjectAuditController extends Controller
{
    public function store(Request $request, Project $project, RunProjectAudit $runner): RedirectResponse
    {
        $result = $runner->enqueue($project, ScanOrigin::Manual, $request->user());

        if ($result->outcome === RunProjectAuditOutcome::Queued && $result->scan !== null) {
            RunProjectAuditJob::dispatch($result->scan->id)->afterCommit();

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Audit queued.')]);
        } else {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('An audit for this project is already in progress.')]);
        }

        return back();
    }
}
