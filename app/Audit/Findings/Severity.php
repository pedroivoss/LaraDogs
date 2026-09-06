<?php

namespace App\Audit\Findings;

/**
 * How bad a finding is *if it's real* — independent of {@see Confidence},
 * which tracks how sure LaraDogs is that it's real at all. See
 * docs/auditing/severity.md.
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';
}
