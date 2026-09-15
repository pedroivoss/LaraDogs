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
}
