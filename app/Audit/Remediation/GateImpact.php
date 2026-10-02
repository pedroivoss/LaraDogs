<?php

namespace App\Audit\Remediation;

/**
 * Whether a finding participates in a FAILED rule of the persisted Quality
 * Gate result of the project's newest terminal scan. Derived from persisted
 * evidence only — the gate is never re-evaluated to answer.
 */
enum GateImpact: string
{
    /** Listed by a Failed gate rule. */
    case Blocking = 'blocking';

    /** The gate result was evaluated and no Failed rule references this finding. */
    case NonBlocking = 'non_blocking';

    /** No gate result exists (gate disabled, no terminal scan, legacy scan). */
    case NotEvaluated = 'not_evaluated';

    /** A Failed rule's finding list was truncated and does not include this finding. */
    case Undetermined = 'undetermined';
}
