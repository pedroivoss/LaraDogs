<?php

namespace App\Audit\Findings;

/**
 * How sure LaraDogs is that a finding is a real issue (as opposed to a
 * false positive) — independent of {@see Severity}, which tracks impact
 * if it IS real. See docs/auditing/confidence.md.
 *
 * A three-level enum rather than a numeric score: without calibrated data
 * from real scanners yet (none exist before Phase 4), a numeric scale
 * would imply a precision this project can't back up. Mirrors the
 * three-level shape already used for confidence in the target design
 * (docs/auditing/confidence.md) rather than inventing a new scale.
 */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
