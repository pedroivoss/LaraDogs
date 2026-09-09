<?php

namespace App\Audit\Projects;

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Models\Audit\Project;
use Illuminate\Database\QueryException;

/**
 * Registers a local directory as a Project LaraDogs can repeatedly audit.
 *
 * Duplicate semantics (deliberate, documented choice — see
 * docs/auditing/projects.md): registering the SAME resolved path twice is
 * idempotent, not an error. The path is always realpath-resolved by
 * {@see ProjectDiscovery} before comparison/storage, so two different
 * symlinks (or a relative vs. absolute spelling) pointing at the same real
 * directory are correctly recognized as the same project rather than
 * silently creating a duplicate row. A `projects.path` unique index is the
 * actual correctness guarantee under concurrent registration (mirroring
 * the same "check-then-insert, unique index is the real guarantee, not
 * the check" pattern already used by {@see FindingIngestor}
 * for fingerprints) — the pre-check below only avoids the exception in the
 * overwhelmingly common non-concurrent case.
 *
 * This service never executes anything from the target directory — it
 * only calls {@see ProjectDiscovery}, which is itself execution-free (see
 * ADR-0012's realpath-containment philosophy, which this mirrors).
 */
final readonly class RegisterProject
{
    public function __construct(private ProjectDiscovery $discovery = new ProjectDiscovery) {}

    public function register(string $path, ?string $name = null): RegisterProjectResult
    {
        $discoveryResult = $this->discovery->discover($path);

        if ($discoveryResult->profile === null) {
            return RegisterProjectResult::pathInvalid($discoveryResult);
        }

        $canonicalPath = $discoveryResult->path;

        $existing = Project::query()->where('path', $canonicalPath)->first();

        if ($existing !== null) {
            return RegisterProjectResult::alreadyRegistered($existing);
        }

        try {
            $project = Project::query()->create([
                'name' => $name ?? basename($canonicalPath),
                'path' => $canonicalPath,
            ]);
        } catch (QueryException $exception) {
            // Lost a race with a concurrent registration of the same
            // resolved path between the check above and this insert — the
            // unique index rejected us, not a real failure. Return the
            // row the other request just created rather than surfacing a
            // spurious error for what is, from the caller's perspective,
            // still a successful (idempotent) registration.
            $winner = Project::query()->where('path', $canonicalPath)->first();

            if ($winner !== null) {
                return RegisterProjectResult::alreadyRegistered($winner);
            }

            throw $exception;
        }

        return RegisterProjectResult::created($project);
    }
}
