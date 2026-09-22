<?php

namespace App\Audit\Source\Git;

/**
 * What LaraDogs could establish about a project directory's Git state.
 * Persisted as a plain string (`scans.source_type`) — never a database
 * enum — so it stays portable across SQLite/MySQL/PostgreSQL. A `null`
 * `source_type` on a scan means "not captured" (a pre-Phase-9 scan) and is
 * deliberately NOT one of these cases.
 */
enum GitRepositoryState: string
{
    /** A readable directory that is not (the root of) a Git repository. */
    case NotRepository = 'none';

    /** A Git repository with a working tree (possibly with no commits yet). */
    case Repository = 'git';

    /** A bare repository — it has no working tree, so it cannot be audited as source. */
    case Bare = 'bare';

    /** Inspection could not be completed safely (no Git binary, timeout, unsafe config, ...). */
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::NotRepository => 'Not a Git repository',
            self::Repository => 'Git repository',
            self::Bare => 'Bare Git repository',
            self::Unavailable => 'Git state unavailable',
        };
    }
}
