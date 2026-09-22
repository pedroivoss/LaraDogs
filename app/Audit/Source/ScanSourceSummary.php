<?php

namespace App\Audit\Source;

use App\Audit\Source\Git\GitRepositoryState;
use App\Audit\Source\Git\GitSnapshot;
use App\Models\Audit\Scan;

/**
 * The bounded, structured source block CLI JSON output carries for a
 * persisted scan (Phase 9): type, FULL commit, branch, dirty flag and
 * whether the source's integrity was established (and, if not, why). No absolute host path, no
 * credentials, no author identity, no Owner identity. `null` for a scan
 * whose source was never captured (pre-Phase-9).
 */
final class ScanSourceSummary
{
    /**
     * @return array<string,mixed>|null
     */
    public static function forScan(Scan $scan): ?array
    {
        $snapshot = GitSnapshot::fromScan($scan);

        if ($snapshot === null) {
            return null;
        }

        if ($snapshot->state !== GitRepositoryState::Repository) {
            return ['type' => $snapshot->state->value];
        }

        return [
            'type' => 'git',
            'commit' => $snapshot->commitSha,
            'branch' => $snapshot->branch,
            'detached' => $snapshot->detached,
            'dirty' => $snapshot->dirty,
            'consistent' => $scan->source_consistent,
            'integrity_reason' => $scan->source_integrity_reason,
        ];
    }

    /**
     * One human line describing a scan's source, e.g.
     * `abc1234 · main · clean`; null when there is nothing to say.
     */
    public static function line(Scan $scan): ?string
    {
        $snapshot = GitSnapshot::fromScan($scan);

        if ($snapshot === null) {
            return null;
        }

        if (! $snapshot->isRepository()) {
            return $snapshot->state->label();
        }

        $where = $snapshot->detached ? 'detached HEAD' : ($snapshot->branch ?? 'no branch');

        return implode(' · ', [
            $snapshot->shortSha() ?? 'no commits yet',
            $where,
            $snapshot->dirty === null ? 'working tree unknown' : ($snapshot->dirty ? 'dirty' : 'clean'),
        ]);
    }
}
