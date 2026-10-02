<?php

namespace App\Audit\Remediation;

/**
 * How the source LaraDogs sees NOW relates to the source a finding was last
 * OBSERVED on (Phase 9 provenance). It exists so guidance never implies that
 * the flagged code still exists unchanged.
 */
enum RemediationSourceState: string
{
    /** Same commit, clean working tree — the observed location is still valid. */
    case SameRevision = 'same_revision';

    /** The current commit differs from the one the finding was observed on. */
    case ChangedSinceFinding = 'changed_since_finding';

    /** Same commit, but the working tree has uncommitted changes (or did when audited). */
    case Dirty = 'dirty';

    /** The current source could not be inspected (missing, unreadable, refused). */
    case Unavailable = 'unavailable';

    /** Not a Git repository (or bare): no revision to compare. */
    case NotVersioned = 'not_versioned';

    /** No comparable evidence (legacy scan without source metadata, no commits, ...). */
    case Unknown = 'unknown';
}
