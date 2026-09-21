<?php

namespace App\Http\Controllers\Projects;

use App\Audit\QualityGates\Policy\InvalidQualityGatePolicy;
use App\Audit\QualityGates\QualityGatePolicyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateQualityGateRequest;
use App\Models\Audit\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Mutates a Project's Quality Gate POLICY (Phase 8) — gated by the `staff`
 * route middleware (Owner/Admin only; a User gets a 404), same reach as
 * project registration and the audit schedule. A thin adapter: the
 * revisioning/validation rules live in {@see QualityGatePolicyService}.
 * Changing a policy only affects FUTURE evaluations — it never re-
 * evaluates or rewrites historical results.
 */
final class ProjectQualityGateController extends Controller
{
    public function update(UpdateQualityGateRequest $request, Project $project, QualityGatePolicyService $service): RedirectResponse
    {
        try {
            $service->update($project, $request->enabled(), $request->toPolicy());
        } catch (InvalidQualityGatePolicy $exception) {
            throw ValidationException::withMessages(['enabled' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quality Gate updated.')]);

        return back();
    }
}
