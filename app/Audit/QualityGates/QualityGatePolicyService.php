<?php

namespace App\Audit\QualityGates;

use App\Audit\QualityGates\Policy\InvalidQualityGatePolicy;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Models\Audit\Project;
use App\Models\Audit\ProjectQualityGate;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a project's Quality Gate policy.
 *
 * - Default is disabled: a project with no row has no gate, and this
 *   service never enables one on its own (saving "disabled" for a project
 *   with no policy creates nothing).
 * - Every EFFECTIVE change (enabled flag or rules) increments `revision`
 *   monotonically; a save that changes nothing does not. The row is
 *   locked (`lockForUpdate()`, a no-op on SQLite) inside a short
 *   transaction so concurrent saves cannot reuse a revision number — more
 *   than enough for this single-instance model.
 * - Disabling keeps the stored rules (so re-enabling restores them) and
 *   never deletes or alters historical results; changing the policy only
 *   affects FUTURE evaluations — old results keep their own revision and
 *   snapshot.
 */
final class QualityGatePolicyService
{
    /**
     * @param  QualityGatePolicy|null  $policy  null keeps the currently stored rules
     *
     * @throws InvalidQualityGatePolicy when enabling without any rule
     */
    public function update(Project $project, bool $enabled, ?QualityGatePolicy $policy): ?ProjectQualityGate
    {
        return DB::transaction(function () use ($project, $enabled, $policy): ?ProjectQualityGate {
            $gate = ProjectQualityGate::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->first();

            $newPolicy = $policy?->toArray() ?? $this->canonical($gate?->policy);

            if ($enabled && ($newPolicy === null || $newPolicy['rules'] === [])) {
                throw new InvalidQualityGatePolicy('Enable the gate together with at least one rule.');
            }

            if ($gate === null && ! $enabled && ($policy === null || $policy->isEmpty())) {
                return null;
            }

            // Compared in canonical form: a database's native JSON type may
            // reorder object keys (MySQL does), so the stored document must
            // never be compared to the new one by raw array identity.
            $changed = $gate === null
                || $gate->enabled !== $enabled
                || $this->canonical($gate->policy) !== $newPolicy;

            if (! $changed) {
                return $gate;
            }

            $gate ??= new ProjectQualityGate(['project_id' => $project->id, 'revision' => 0]);
            $gate->enabled = $enabled;
            $gate->policy = $newPolicy;
            $gate->revision = $gate->revision + 1;
            $gate->save();

            return $gate;
        });
    }

    /**
     * @param  array<string,mixed>|null  $document
     * @return array{schema: int, rules: list<array<string,mixed>>}|null null when absent or unreadable
     */
    private function canonical(?array $document): ?array
    {
        if ($document === null) {
            return null;
        }

        try {
            return QualityGatePolicy::fromArray($document)->toArray();
        } catch (InvalidQualityGatePolicy) {
            return null;
        }
    }
}
