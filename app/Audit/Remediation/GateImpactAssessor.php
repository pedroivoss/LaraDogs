<?php

namespace App\Audit\Remediation;

/**
 * Pure derivation of {@see GateImpact} from persisted {@see GateFacts}.
 */
final class GateImpactAssessor
{
    public static function assess(string $findingPublicId, ?GateFacts $facts): GateImpact
    {
        if ($facts === null) {
            return GateImpact::NotEvaluated;
        }

        $truncated = false;

        foreach ($facts->failedRules as $rule) {
            if (in_array($findingPublicId, $rule['finding_ids'], true)) {
                return GateImpact::Blocking;
            }

            // The rule counted more findings than it listed: this one may be among them.
            if ($rule['finding_count'] > count($rule['finding_ids'])) {
                $truncated = true;
            }
        }

        return $truncated ? GateImpact::Undetermined : GateImpact::NonBlocking;
    }
}
