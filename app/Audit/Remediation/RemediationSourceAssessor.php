<?php

namespace App\Audit\Remediation;

use App\Audit\Source\Git\GitRepositoryState;
use App\Audit\Source\Git\GitSnapshot;

/**
 * Pure comparison of the source a finding was OBSERVED on with the source
 * LaraDogs sees NOW, using Phase 9 provenance. The comparison is by COMMIT (a
 * renamed branch on the same commit is the same code); a dirty working tree
 * on either side means the code cannot be proven identical.
 */
final class RemediationSourceAssessor
{
    public static function assess(?GitSnapshot $observed, ?GitSnapshot $current): RemediationSourceState
    {
        if ($observed === null) {
            return RemediationSourceState::Unknown; // legacy scan: history is never reconstructed
        }

        if ($current === null || $current->state === GitRepositoryState::Unavailable) {
            return RemediationSourceState::Unavailable;
        }

        if ($observed->state === GitRepositoryState::Unavailable) {
            return RemediationSourceState::Unknown;
        }

        if ($observed->state !== $current->state) {
            return RemediationSourceState::ChangedSinceFinding;
        }

        if ($current->state === GitRepositoryState::NotRepository || $current->state === GitRepositoryState::Bare) {
            return RemediationSourceState::NotVersioned;
        }

        if (! $observed->hasCommit() || ! $current->hasCommit()) {
            return RemediationSourceState::Unknown;
        }

        if ($observed->commitSha !== $current->commitSha) {
            return RemediationSourceState::ChangedSinceFinding;
        }

        if ($current->dirty === true) {
            return RemediationSourceState::Dirty;
        }

        // Same commit, clean now — but the audited tree itself may have been dirty.
        if ($observed->dirty !== false) {
            return RemediationSourceState::Unknown;
        }

        return RemediationSourceState::SameRevision;
    }
}
