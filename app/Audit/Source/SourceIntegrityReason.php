<?php

namespace App\Audit\Source;

/**
 * WHY a scan's source integrity was not established (`scans.source_integrity_reason`,
 * stored as a plain string — never a database enum). Present exactly when
 * `scans.source_consistent` is `false`.
 *
 * Deliberately distinguishes a demonstrated mutation ({@see ChangedDuringAudit})
 * from a source whose stability simply could not be PROVEN — the UI/CLI must
 * never claim "the source changed" when it only could not be verified.
 */
enum SourceIntegrityReason: string
{
    /** Commit, branch/detached state, dirty flag or kind of source differed before vs after. */
    case ChangedDuringAudit = 'changed_during_audit';

    /** The working tree was already dirty when the audit began — it cannot be proven immutable. */
    case DirtyAtStart = 'dirty_at_start';

    /** A Git repository with no commit: there is no canonical revision to attest. */
    case NoCommits = 'no_commits';

    /** A bare repository: there is no working tree to have audited. */
    case BareRepository = 'bare_repository';

    /** Git provenance could not be read (no Git binary, timeout, Git error, ...). */
    case Unavailable = 'unavailable';

    /** The repository configuration was refused (includes, work-tree redirection, ...). */
    case UnsafeConfig = 'unsafe_config';

    /** True only for a demonstrated mutation (as opposed to "could not be proven"). */
    public function isMutation(): bool
    {
        return $this === self::ChangedDuringAudit;
    }

    /**
     * Truthful, user-facing wording (dashboard, CLI, gate summaries).
     */
    public function explanation(): string
    {
        return match ($this) {
            self::ChangedDuringAudit => 'The source changed while the audit was running.',
            self::DirtyAtStart => 'Source integrity could not be proven because the working tree was dirty when the audit began.',
            self::NoCommits => 'Source integrity could not be proven because the repository has no commits.',
            self::BareRepository => 'A bare repository has no working tree, so no audited source can be attested.',
            self::Unavailable, self::UnsafeConfig => 'Git source integrity could not be verified.',
        };
    }

    /**
     * Short clause for a Quality Gate summary ("... the audit was not fully verified (<clause>)").
     */
    public function gateClause(): string
    {
        return match ($this) {
            self::ChangedDuringAudit => 'the source changed while the audit was running',
            self::DirtyAtStart => 'the working tree was dirty when the audit began, so source integrity could not be proven',
            self::NoCommits => 'the repository has no commits, so no committed revision was audited',
            self::BareRepository => 'the target is a bare repository with no working tree',
            self::Unavailable, self::UnsafeConfig => 'Git source integrity could not be verified',
        };
    }
}
