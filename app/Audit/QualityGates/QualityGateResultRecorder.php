<?php

namespace App\Audit\QualityGates;

use App\Audit\QualityGates\Evaluation\QualityGateEvaluation;
use App\Audit\QualityGates\Evaluation\RuleResult;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Models\Audit\ProjectQualityGate;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\QualityGateRuleResult;
use App\Models\Audit\Scan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Persists a {@see QualityGateEvaluation} as the scan's immutable result:
 * the outcome, the revision and a snapshot of the policy it was judged
 * against, and one row per rule result. At most one result per scan (a
 * database unique constraint is the final guard): recording twice returns
 * the first result untouched. Never updates an existing result.
 */
final class QualityGateResultRecorder
{
    public function record(Scan $scan, ProjectQualityGate $gate, QualityGatePolicy $policy, QualityGateEvaluation $evaluation): QualityGateResult
    {
        $existing = QualityGateResult::query()->where('scan_id', $scan->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($scan, $gate, $policy, $evaluation): QualityGateResult {
                $result = QualityGateResult::query()->create([
                    'project_id' => $scan->project_id,
                    'scan_id' => $scan->id,
                    'outcome' => $evaluation->outcome,
                    'policy_revision' => $gate->revision,
                    'policy_snapshot' => $policy->toArray(),
                    'baseline_scan_id' => $evaluation->baselineScanId,
                    'rules_total' => count($evaluation->ruleResults),
                    'rules_failed' => $evaluation->countWhere(QualityGateOutcome::Failed),
                    'rules_indeterminate' => $evaluation->countWhere(QualityGateOutcome::Indeterminate),
                    'evaluated_at' => now(),
                ]);

                foreach ($evaluation->ruleResults as $position => $ruleResult) {
                    $this->recordRule($result, $position, $ruleResult);
                }

                return $result;
            });
        } catch (QueryException $exception) {
            // Lost a race with a concurrent evaluation of the same scan —
            // the unique constraint on scan_id already holds the winner.
            $winner = QualityGateResult::query()->where('scan_id', $scan->id)->first();

            if ($winner === null) {
                throw $exception;
            }

            return $winner;
        }
    }

    private function recordRule(QualityGateResult $result, int $position, RuleResult $rule): void
    {
        QualityGateRuleResult::query()->create([
            'quality_gate_result_id' => $result->id,
            'position' => $position,
            'rule_id' => $rule->ruleId,
            'subject' => $rule->subject,
            'outcome' => $rule->outcome,
            'summary' => mb_substr($rule->summary, 0, 500),
            'observed' => $rule->observed === null ? null : mb_substr($rule->observed, 0, 255),
            'expected' => $rule->expected === null ? null : mb_substr($rule->expected, 0, 255),
            'analyzer_id' => $rule->analyzerId,
            'severity' => $rule->severity,
            'finding_count' => $rule->findingCount,
            'finding_ids' => $rule->findingIds === [] ? null : $rule->findingIds,
        ]);
    }
}
