<?php

namespace App\Audit\Findings\Lifecycle;

use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingStatusHistory;
use App\Models\Audit\Scan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY place a {@see Finding}'s status is allowed to change. Both
 * manual (human-triggered) and automatic (ingestion/reconciliation)
 * transitions route through here, so validation and history-writing can
 * never diverge between the two paths — see
 * docs/auditing/findings-lifecycle.md.
 */
final class FindingLifecycleService
{
    /**
     * @throws InvalidArgumentException if $newStatus requires a reason
     *                                  (see {@see FindingStatus::requiresReason()})
     *                                  and none was given.
     */
    public function transition(
        Finding $finding,
        FindingStatus $newStatus,
        ActorType $actorType,
        ?string $actorIdentifier = null,
        ?string $reason = null,
        ?Scan $scan = null,
    ): void {
        if ($newStatus->requiresReason() && trim((string) $reason) === '') {
            throw new InvalidArgumentException(
                "A reason is required when transitioning a finding to [{$newStatus->value}].",
            );
        }

        $isFirstTransition = ! FindingStatusHistory::query()
            ->where('finding_id', $finding->id)
            ->exists();

        $previousStatus = $isFirstTransition ? null : $finding->status;

        DB::transaction(function () use ($finding, $newStatus, $previousStatus, $actorType, $actorIdentifier, $reason, $scan): void {
            FindingStatusHistory::create([
                'finding_id' => $finding->id,
                'scan_id' => $scan?->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
                'actor_type' => $actorType,
                'actor_identifier' => $actorIdentifier,
            ]);

            $finding->status = $newStatus;
            $finding->status_reason = $reason;
            $finding->save();
        });
    }
}
