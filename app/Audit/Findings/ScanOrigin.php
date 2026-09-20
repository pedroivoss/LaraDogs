<?php

namespace App\Audit\Findings;

/**
 * Why a Scan exists — provenance, not a general audit-log framework (see
 * `docs/auditing/audit-execution.md`). Persisted on `scans.origin`
 * (Phase 7.1.4).
 */
enum ScanOrigin: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Cli = 'cli';

    /**
     * User-facing label — the single source for how provenance is spelled
     * in the Dashboard (notably "CLI", not "Cli"). Says WHY a scan
     * happened, never WHO started it (Owner privacy, Phase 7.1.3).
     */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Scheduled => 'Scheduled',
            self::Cli => 'CLI',
        };
    }
}
