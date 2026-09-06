<?php

namespace App\Audit\Discovery\Support;

/**
 * The state of a single detection signal produced by Project Discovery.
 *
 * Distinguishes "we looked and it's not there" from "we couldn't tell" —
 * collapsing those into one boolean would misrepresent an absence of
 * evidence as evidence of absence.
 */
enum DetectionStatus: string
{
    /** Positive, evidence-backed detection. */
    case Detected = 'detected';

    /** Evidence sources were readable and don't indicate the feature. */
    case NotDetected = 'not_detected';

    /** No usable evidence source was available to decide either way. */
    case Unknown = 'unknown';

    /** An evidence source exists but could not be parsed (e.g. malformed JSON). */
    case Invalid = 'invalid';

    /** Recognized but outside what this inspector is able to evaluate. */
    case Unsupported = 'unsupported';
}
