<?php

namespace App\Console\Commands\Support;

use App\Console\Commands\CiAuditCommand;

/**
 * The exact predicate `laradogs:ci:audit` uses, twice, to decide whether an
 * audited revision satisfies CI's expectation (see
 * {@see CiAuditCommand}):
 *
 * - once BEFORE running the (possibly expensive) audit, against a fresh
 *   `GitRepositoryInspector::inspect()` call — a cheap fail-fast;
 * - once AFTER, against the scan's own immutable `source_revision` — the
 *   AUTHORITATIVE check, closing the (narrow) race between the two.
 *
 * Extracted into its own pure function — rather than left as an inline
 * `!==` comparison in two places — specifically so this decision is
 * directly unit-testable without needing a real Git timing race to
 * exercise the enforcement branch: see docs/ci/README.md#revision-verification.
 */
final class CiRevisionVerification
{
    /**
     * `null` means "no verification was requested" — never a mismatch.
     */
    public static function mismatched(?string $expectedRevision, ?string $auditedRevision): bool
    {
        return $expectedRevision !== null && $auditedRevision !== $expectedRevision;
    }
}
