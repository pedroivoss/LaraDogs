<?php

namespace App\Audit\QualityGates\Evaluation;

use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\QualityGates\GateRuleId;
use App\Audit\QualityGates\Policy\AnalyzerCoverageRule;
use App\Audit\QualityGates\Policy\AnalyzerStatusRule;
use App\Audit\QualityGates\Policy\GateRule;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\NoNewSeverityRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\QualityGates\QualityGateOutcome;

/**
 * Pure, deterministic evaluation of a {@see QualityGatePolicy} against
 * already-loaded {@see GateEvidence} — no database, no filesystem, no
 * network, no clock, no analyzers, no mutation of findings or lifecycle.
 * Same input, same verdict.
 *
 * Guiding principle: absence of evidence is not evidence of absence.
 *
 * - A PROVEN violation (findings above a limit, an analyzer that
 *   Failed/TimedOut, coverage that is not there, a definite new finding)
 *   is Failed — even if other evidence is incomplete.
 * - A conclusion that rests on the ABSENCE of findings (a count within its
 *   limit, "nothing new") may only be Passed when the audit that produced
 *   it is trustworthy: the scan completed and no in-scope analyzer
 *   Failed / TimedOut / was Unavailable / Skipped. Otherwise it is
 *   Indeterminate.
 * - Rules about an analyzer's own execution/coverage are statements about
 *   the evidence itself, judged from that execution.
 *
 * Rule outcomes combine with Failed > Indeterminate > Passed.
 */
final class QualityGateEvaluator
{
    private const int MAX_NAMED = 5;

    public function evaluate(QualityGatePolicy $policy, GateEvidence $evidence): QualityGateEvaluation
    {
        $results = [];

        foreach ($policy->rules as $rule) {
            foreach ($this->evaluateRule($rule, $evidence) as $result) {
                $results[] = $result;
            }
        }

        return new QualityGateEvaluation($results, $evidence->baselineScanId);
    }

    /**
     * @return list<RuleResult>
     */
    private function evaluateRule(GateRule $rule, GateEvidence $evidence): array
    {
        return match (true) {
            $rule instanceof MaxOpenFindingsRule => $this->maxOpenFindings($rule, $evidence),
            $rule instanceof NoNewSeverityRule => [$this->noNewSeverity($rule, $evidence)],
            $rule instanceof AnalyzerStatusRule => $this->analyzerStatus($rule, $evidence),
            $rule instanceof AnalyzerCoverageRule => $this->analyzerCoverage($rule, $evidence),
            default => [],
        };
    }

    /**
     * @return list<RuleResult>
     */
    private function maxOpenFindings(MaxOpenFindingsRule $rule, GateEvidence $evidence): array
    {
        $gaps = $this->evidenceGaps($evidence);
        $results = [];

        foreach ($rule->orderedLimits() as [$severity, $max]) {
            $count = $evidence->openCountsBySeverity[$severity->value] ?? 0;
            $ids = $evidence->openFindingIdsBySeverity[$severity->value] ?? [];
            $label = $severity->value;

            if ($count > $max) {
                $outcome = QualityGateOutcome::Failed;
                $summary = "{$count} open {$label} finding(s); at most {$max} allowed.";
            } elseif ($gaps !== []) {
                $outcome = QualityGateOutcome::Indeterminate;
                $summary = "{$count} open {$label} finding(s), within the limit of {$max}, but the audit was not fully verified ({$this->describeGaps($gaps)}) so compliance cannot be asserted.";
            } else {
                $outcome = QualityGateOutcome::Passed;
                $summary = "{$count} open {$label} finding(s); at most {$max} allowed.";
            }

            $results[] = new RuleResult(
                ruleId: GateRuleId::MaxOpenFindings,
                subject: $label,
                outcome: $outcome,
                summary: $summary,
                observed: (string) $count,
                expected: "<= {$max}",
                severity: $label,
                findingCount: $count,
                findingIds: $ids,
            );
        }

        return $results;
    }

    private function noNewSeverity(NoNewSeverityRule $rule, GateEvidence $evidence): RuleResult
    {
        $min = $rule->minSeverity;
        $base = [
            'ruleId' => GateRuleId::NoNewSeverity,
            'subject' => $min->value,
            'expected' => "0 new at or above {$min->value}",
            'severity' => $min->value,
        ];

        if ($evidence->baselineScanId === null) {
            return new RuleResult(
                ...$base,
                outcome: QualityGateOutcome::Indeterminate,
                summary: 'No baseline: there is no previous completed audit to compare against, so "new" findings cannot be determined.',
            );
        }

        $newIds = [];
        $unverifiable = 0;
        $new = 0;
        $regressed = 0;

        foreach ($evidence->newCandidates as $candidate) {
            if (! $candidate->severity->isAtOrAbove($min)) {
                continue;
            }

            if ($candidate->firstSeenInThisScan) {
                $new++;
                $newIds[] = $candidate->publicId;

                continue;
            }

            $baselineExecution = $evidence->baselineExecutions[$candidate->analyzerId] ?? null;

            if ($baselineExecution !== null
                && $baselineExecution->status === ExecutionStatus::Passed
                && $baselineExecution->coverage->verifies($candidate->ruleId)) {
                $regressed++;
                $newIds[] = $candidate->publicId;
            } else {
                $unverifiable++;
            }
        }

        $violations = $new + $regressed;

        if ($violations > 0) {
            return new RuleResult(
                ...$base,
                outcome: QualityGateOutcome::Failed,
                summary: "{$new} new and {$regressed} regressed finding(s) at or above {$min->value} since the baseline audit.",
                observed: (string) $violations,
                findingCount: $violations,
                findingIds: $newIds,
            );
        }

        $gaps = $this->evidenceGaps($evidence);

        if ($unverifiable > 0 || $gaps !== []) {
            $why = [];

            if ($unverifiable > 0) {
                $why[] = "{$unverifiable} finding(s) absent from the baseline cannot be proven new (the baseline did not verify their rule)";
            }

            if ($gaps !== []) {
                $why[] = "the audit was not fully verified ({$this->describeGaps($gaps)})";
            }

            return new RuleResult(
                ...$base,
                outcome: QualityGateOutcome::Indeterminate,
                summary: 'No new findings proven, but '.implode(' and ', $why).'.',
                observed: '0',
            );
        }

        return new RuleResult(
            ...$base,
            outcome: QualityGateOutcome::Passed,
            summary: "No new or regressed finding at or above {$min->value} since the baseline audit.",
            observed: '0',
        );
    }

    /**
     * @return list<RuleResult>
     */
    private function analyzerStatus(AnalyzerStatusRule $rule, GateEvidence $evidence): array
    {
        $results = [];

        foreach ($rule->analyzers as $analyzer) {
            $execution = $evidence->executions[$analyzer] ?? null;

            [$outcome, $summary, $observed] = match (true) {
                $execution === null => [QualityGateOutcome::Indeterminate, "No execution of {$analyzer} was recorded for this scan.", 'no execution'],
                $execution->status === ExecutionStatus::Passed => [QualityGateOutcome::Passed, "{$analyzer} completed and passed.", 'passed'],
                in_array($execution->status, [ExecutionStatus::Failed, ExecutionStatus::TimedOut, ExecutionStatus::Unavailable], true) => [QualityGateOutcome::Failed, "{$analyzer} did not complete successfully ({$execution->status->value}).", $execution->status->value],
                default => [QualityGateOutcome::Indeterminate, "{$analyzer} produced no usable result ({$execution->status->value}), so it cannot be shown to have passed.", $execution->status->value],
            };

            $results[] = new RuleResult(
                ruleId: GateRuleId::AnalyzerStatus,
                subject: $analyzer,
                outcome: $outcome,
                summary: $summary,
                observed: $observed,
                expected: 'passed',
                analyzerId: $analyzer,
            );
        }

        return $results;
    }

    /**
     * @return list<RuleResult>
     */
    private function analyzerCoverage(AnalyzerCoverageRule $rule, GateEvidence $evidence): array
    {
        $results = [];

        foreach ($rule->requirements as $analyzer => $requirement) {
            $execution = $evidence->executions[$analyzer] ?? null;
            $expected = $requirement->label();

            if ($execution === null) {
                [$outcome, $summary, $observed] = [QualityGateOutcome::Indeterminate, "No execution of {$analyzer} was recorded for this scan.", 'no execution'];
            } elseif ($execution->status === ExecutionStatus::Passed) {
                $mode = $execution->coverage->mode;
                $ok = $requirement->isSatisfiedBy($mode);
                [$outcome, $summary, $observed] = [
                    $ok ? QualityGateOutcome::Passed : QualityGateOutcome::Failed,
                    $ok
                        ? "{$analyzer} declared {$mode->value} coverage (required: {$expected})."
                        : "{$analyzer} declared {$mode->value} coverage; {$expected} coverage is required.",
                    $mode->value,
                ];
            } elseif (in_array($execution->status, [ExecutionStatus::Failed, ExecutionStatus::TimedOut, ExecutionStatus::Unavailable], true)) {
                [$outcome, $summary, $observed] = [QualityGateOutcome::Failed, "{$analyzer} did not complete ({$execution->status->value}), so it provided no coverage evidence (required: {$expected}).", $execution->status->value];
            } else {
                [$outcome, $summary, $observed] = [QualityGateOutcome::Indeterminate, "{$analyzer} produced no usable result ({$execution->status->value}), so its coverage cannot be judged.", $execution->status->value];
            }

            $results[] = new RuleResult(
                ruleId: GateRuleId::AnalyzerCoverage,
                subject: $analyzer,
                outcome: $outcome,
                summary: $summary,
                observed: $observed,
                expected: $expected,
                analyzerId: $analyzer,
            );
        }

        return $results;
    }

    /**
     * Why an absence-based conclusion cannot be trusted for this scan.
     *
     * @return list<string>
     */
    private function evidenceGaps(GateEvidence $evidence): array
    {
        if (! $evidence->scanCompleted) {
            return ['the audit did not complete'];
        }

        $gaps = [];

        foreach ($evidence->executions as $analyzer => $execution) {
            if (in_array($execution->status, [ExecutionStatus::Failed, ExecutionStatus::TimedOut, ExecutionStatus::Unavailable, ExecutionStatus::Skipped, ExecutionStatus::Planned], true)) {
                $gaps[] = "{$analyzer} {$execution->status->value}";
            }
        }

        sort($gaps);

        return $gaps;
    }

    /**
     * @param  list<string>  $gaps
     */
    private function describeGaps(array $gaps): string
    {
        $shown = array_slice($gaps, 0, self::MAX_NAMED);
        $more = count($gaps) - count($shown);

        return implode(', ', $shown).($more > 0 ? " and {$more} more" : '');
    }
}
