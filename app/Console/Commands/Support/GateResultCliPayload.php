<?php

namespace App\Console\Commands\Support;

use App\Models\Audit\QualityGateResult;
use App\Models\Audit\QualityGateRuleResult;

/**
 * The `gate` object shape of `laradogs:project:gate --json` (Phase 8) —
 * extracted so `laradogs:ci:audit` (Phase 10) reports the EXACT same shape
 * rather than a second, subtly different one. This is the CLI's own JSON
 * contract, kept separate from `App\Http\Support\QualityGatePayload` (the
 * Inertia/Dashboard shape, which differs — e.g. it includes `label` and
 * `headline` and omits `evaluated_at`/`baseline_scan`): changing either
 * independently must never silently change the other.
 */
final class GateResultCliPayload
{
    /**
     * @return array<string,mixed>
     */
    public static function toArray(QualityGateResult $result): array
    {
        return [
            'outcome' => $result->outcome->value,
            'policy_revision' => $result->policy_revision,
            'evaluated_at' => $result->evaluated_at->toIso8601String(),
            'baseline_scan' => $result->baselineScan?->public_id,
            'rules_total' => $result->rules_total,
            'rules_failed' => $result->rules_failed,
            'rules_indeterminate' => $result->rules_indeterminate,
            'rules' => $result->ruleResults->map(fn (QualityGateRuleResult $rule): array => [
                'rule_id' => $rule->rule_id->value,
                'subject' => $rule->subject,
                'outcome' => $rule->outcome->value,
                'summary' => $rule->summary,
                'observed' => $rule->observed,
                'expected' => $rule->expected,
                'analyzer_id' => $rule->analyzer_id,
                'severity' => $rule->severity,
                'finding_count' => $rule->finding_count,
                'finding_ids' => $rule->finding_ids ?? [],
            ])->all(),
        ];
    }
}
