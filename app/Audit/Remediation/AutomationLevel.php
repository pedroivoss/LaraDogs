<?php

namespace App\Audit\Remediation;

/**
 * How much of a remediation LaraDogs can do for you (Phase 12).
 *
 * V1 ships exactly ONE level: LaraDogs advises, it never edits. A
 * `PatchSuggestion` or `AutoFixable` level is deliberately NOT declared here
 * — a level that does not exist yet must not be representable (and therefore
 * cannot be "pretended"); it is added together with a safe patch system, if
 * that is ever built. See docs/remediation/README.md#future-work.
 */
enum AutomationLevel: string
{
    /** Deterministic written guidance; the developer (or their tooling) makes the change. */
    case GuidanceOnly = 'guidance_only';
}
