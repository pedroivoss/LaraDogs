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

    /**
     * The source itself did not report a severity for a real finding — e.g.
     * a Composer security advisory whose upstream `severity` field is
     * `null`. Distinct from {@see Info}, which means "not a problem"; this
     * means "is a problem, magnitude not stated by the source." Never
     * assigned by LaraDogs guessing a severity — only when the underlying
     * data genuinely has none. See docs/auditing/severity.md.
     */
    case Unknown = 'unknown';
}
