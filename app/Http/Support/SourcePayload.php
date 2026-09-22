<?php

namespace App\Http\Support;

use App\Audit\Source\Git\GitRepositoryState;
use App\Audit\Source\Git\GitSnapshot;
use App\Audit\Source\SourceIntegrityReason;
use App\Models\Audit\Scan;

/**
 * Bounded, presentation-ready Git source metadata (Phase 9) for the
 * dashboard. Never exposes an absolute host path, credentials, an author
 * identity or the Owner — only what {@see GitSnapshot} carries.
 */
final class SourcePayload
{
    /**
     * The immutable source snapshot of a scan; null for a scan whose
     * source was never captured (pre-Phase-9) — never derived from the
     * current filesystem.
     *
     * @return array<string,mixed>|null
     */
    public static function forScan(Scan $scan): ?array
    {
        $snapshot = GitSnapshot::fromScan($scan);

        if ($snapshot === null) {
            return null;
        }

        $reason = SourceIntegrityReason::tryFrom((string) $scan->source_integrity_reason);

        return [
            ...self::snapshot($snapshot),
            'consistent' => $scan->source_consistent,
            // Why integrity was NOT established (null when verified or when
            // there is no Git claim). `integrity_changed` distinguishes a
            // demonstrated mutation from "could not be proven".
            'integrity_reason' => $reason?->value,
            'integrity_changed' => $reason?->isMutation() ?? false,
            'integrity_message' => $reason?->explanation(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function snapshot(GitSnapshot $snapshot): array
    {
        return [
            'type' => $snapshot->state->value,
            'label' => $snapshot->state->label(),
            'commit' => $snapshot->commitSha,
            'short_commit' => $snapshot->shortSha(),
            'branch' => $snapshot->branch,
            'detached' => $snapshot->isRepository() ? $snapshot->detached : null,
            'dirty' => $snapshot->dirty,
            'commit_at' => $snapshot->commitTimestamp?->format(DATE_ATOM),
            'commit_subject' => $snapshot->commitSubject,
            'remote' => $snapshot->remoteOrigin,
            'reproducible' => $snapshot->isReproducible(),
            'reason' => $snapshot->state === GitRepositoryState::Unavailable
                ? self::reasonLabel($snapshot->unavailableReason)
                : null,
        ];
    }

    private static function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'git_unavailable' => 'The Git binary is not available to LaraDogs.',
            'timeout' => 'Git did not answer within the time budget.',
            'unsafe_config' => 'The repository configuration was refused as unsafe.',
            'unsafe_ownership' => 'Git refused the directory (unsafe ownership).',
            'output_too_large' => 'Git produced more output than LaraDogs accepts.',
            'path_unavailable' => 'The project path is not available.',
            default => 'Git could not be inspected.',
        };
    }
}
