<?php

namespace App\Audit\QualityGates\Evaluation;

use App\Audit\Findings\Severity;

/**
 * A gate-eligible finding observed in the current scan but NOT observed in
 * the baseline scan — identity is the persisted Finding (fingerprint),
 * never titles or line numbers. `firstSeenInThisScan` is true when the
 * Finding did not exist at all before this scan (definitely new); false
 * when it had been seen by an earlier scan (a reappearance, i.e. a
 * possible regression — only provable when the baseline's coverage
 * actually verified this rule).
 */
final readonly class NewFindingCandidate
{
    public function __construct(
        public string $publicId,
        public Severity $severity,
        public string $analyzerId,
        public string $ruleId,
        public bool $firstSeenInThisScan,
    ) {}
}
