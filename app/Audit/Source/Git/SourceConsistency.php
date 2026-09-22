<?php

namespace App\Audit\Source\Git;

use App\Audit\Source\SourceIntegrity;
use App\Audit\Source\SourceIntegrityReason;

/**
 * Pure source-integrity rules. Git-derived: guarantees exist ONLY for a Git
 * repository; a genuine non-Git target carries no integrity claim.
 *
 * {@see assess()} judges ONE audit from the snapshot taken BEFORE the
 * analyzers and the one taken AFTER. Truth table (see docs/git/README.md):
 *
 * | before → after                                   | consistent | reason               |
 * | ------------------------------------------------ | ---------- | -------------------- |
 * | not a repository → not a repository              | null       | —                    |
 * | clean Git with a commit, identical               | true       | —                    |
 * | commit / branch / detached / dirty differs       | false      | changed_during_audit |
 * | kind of source differs (e.g. none → git)         | false      | changed_during_audit |
 * | dirty at start (even if still identical)         | false      | dirty_at_start       |
 * | Git repository with no commits                   | false      | no_commits           |
 * | bare repository                                  | false      | bare_repository      |
 * | Git could not be read (either side)              | false      | unavailable          |
 * | repository config refused (either side)          | false      | unsafe_config        |
 *
 * `dirty = true` staying `true` proves nothing: a file may have changed
 * again inside an already-dirty tree, so such an audit is never trusted for
 * absence (the exact mutation is still not detected — its absence-based
 * conclusions are simply no longer relied on).
 */
final class SourceConsistency
{
    public static function assess(GitSnapshot $before, GitSnapshot $after): SourceIntegrity
    {
        // Provenance that could not be read is never equivalent to "not Git".
        if ($before->state === GitRepositoryState::Unavailable || $after->state === GitRepositoryState::Unavailable) {
            $refused = $before->unavailableReason === 'unsafe_config' || $after->unavailableReason === 'unsafe_config';

            return SourceIntegrity::unverified($refused ? SourceIntegrityReason::UnsafeConfig : SourceIntegrityReason::Unavailable);
        }

        if ($before->state !== $after->state) {
            return SourceIntegrity::unverified(SourceIntegrityReason::ChangedDuringAudit);
        }

        return match ($before->state) {
            GitRepositoryState::NotRepository => SourceIntegrity::notApplicable(),
            GitRepositoryState::Bare => SourceIntegrity::unverified(SourceIntegrityReason::BareRepository),
            default => self::assessRepository($before, $after),
        };
    }

    /**
     * Whether two snapshots describe the SAME source — used to compare the
     * CURRENT state with the last audited one (dashboard "changed since last
     * audit"). `null` = not comparable / not provable (no Git repository, or
     * both trees dirty, where identical flags prove nothing).
     */
    public static function sameSource(GitSnapshot $a, GitSnapshot $b): ?bool
    {
        if ($a->state !== $b->state) {
            return false;
        }

        if ($a->state !== GitRepositoryState::Repository) {
            return null;
        }

        $identical = $a->commitSha === $b->commitSha
            && $a->branch === $b->branch
            && $a->detached === $b->detached
            && $a->dirty === $b->dirty;

        if (! $identical) {
            return false;
        }

        return $a->dirty === true ? null : true;
    }

    private static function assessRepository(GitSnapshot $before, GitSnapshot $after): SourceIntegrity
    {
        $identical = $before->commitSha === $after->commitSha
            && $before->branch === $after->branch
            && $before->detached === $after->detached
            && $before->dirty === $after->dirty;

        if (! $identical) {
            return SourceIntegrity::unverified(SourceIntegrityReason::ChangedDuringAudit);
        }

        if (! $before->hasCommit()) {
            return SourceIntegrity::unverified(SourceIntegrityReason::NoCommits);
        }

        if ($before->dirty !== false) {
            return SourceIntegrity::unverified(SourceIntegrityReason::DirtyAtStart);
        }

        return SourceIntegrity::verified();
    }
}
