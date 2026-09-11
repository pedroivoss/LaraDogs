<?php

namespace App\Http\Controllers\Projects;

use App\Audit\Projects\ProjectDirectoryDiscovery;
use App\Audit\Projects\RegisterProject;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard "Add Project" — a thin adapter, same architecture rule as
 * every other Dashboard controller: no duplicated registration logic here,
 * {@see RegisterProject} (already used by the `laradogs:project:add` CLI
 * command) remains the single place a Project row is created. The only
 * thing this controller adds is translating a user-picked directory NAME
 * into a real, containment-checked path via
 * {@see ProjectDirectoryDiscovery} — the browser never supplies a raw
 * filesystem path.
 */
final class ProjectRegistrationController extends Controller
{
    public function create(ProjectDirectoryDiscovery $discovery): Response
    {
        $result = $discovery->list();

        return Inertia::render('projects/add', [
            'root_available' => $result->rootAvailable,
            'directories' => array_map(
                fn ($candidate) => [
                    'name' => $candidate->name,
                    'already_registered' => $candidate->alreadyRegistered,
                    'looks_like_laravel' => $candidate->looksLikeLaravel,
                ],
                $result->candidates,
            ),
        ]);
    }

    public function store(Request $request, ProjectDirectoryDiscovery $discovery, RegisterProject $register): RedirectResponse
    {
        $validated = $request->validate([
            'directory' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $path = $discovery->resolve($validated['directory']);

        if ($path === null) {
            return back()->withErrors([
                'directory' => 'That project directory is not available under the configured project root.',
            ])->withInput();
        }

        $result = $register->register($path, $validated['name'] ?? null);

        if (! $result->succeeded() || $result->project === null) {
            return back()->withErrors([
                'directory' => 'This directory could not be registered as a project.',
            ])->withInput();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project registered.')]);

        return to_route('projects.show', $result->project->public_id);
    }
}
