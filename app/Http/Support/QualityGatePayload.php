<?php

namespace App\Http\Support;

use App\Audit\Findings\Severity;
use App\Audit\QualityGates\GateRuleId;
use App\Audit\QualityGates\Policy\AnalyzerCoverageRule;
use App\Audit\QualityGates\Policy\AnalyzerStatusRule;
use App\Audit\QualityGates\Policy\InvalidQualityGatePolicy;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\NoNewSeverityRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\QualityGates\QualityGateOutcome;
use App\Models\Audit\ProjectQualityGate;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\QualityGateRuleResult;

/**
 * Inertia payload shapes for Quality Gates — the single place that maps
 * gate models to arrays, shared by Project Detail, Scan History and Scan
 * Detail. Never includes the person who changed a policy or started a
 * scan, and never anything from the target project.
 */
final class QualityGatePayload
{
    /**
     * One-line summary (history rows, Project Detail).
     *
     * @return array<string,mixed>
     */
    public static function summary(QualityGateResult $result, bool $withHeadline = false): array
    {
        $payload = [
            'outcome' => $result->outcome->value,
            'label' => $result->outcome->label(),
            'policy_revision' => $result->policy_revision,
            'rules_total' => $result->rules_total,
            'rules_failed' => $result->rules_failed,
            'rules_indeterminate' => $result->rules_indeterminate,
        ];

        if ($withHeadline) {
            $rules = $result->relationLoaded('ruleResults') ? $result->ruleResults : $result->ruleResults()->get();
            $first = $rules->first(fn (QualityGateRuleResult $r): bool => $r->outcome === QualityGateOutcome::Failed)
                ?? $rules->first(fn (QualityGateRuleResult $r): bool => $r->outcome === QualityGateOutcome::Indeterminate);
            $payload['headline'] = $first?->summary;
        }

        return $payload;
    }

    /**
     * Full historical detail for Scan Detail.
     *
     * @return array<string,mixed>
     */
    public static function detail(QualityGateResult $result): array
    {
        return [
            ...self::summary($result, withHeadline: true),
            'evaluated_at' => $result->evaluated_at->toIso8601String(),
            'baseline_scan_id' => $result->baselineScan?->public_id,
            'policy' => self::canonicalSnapshot($result->policy_snapshot),
            'rules' => $result->ruleResults->map(fn (QualityGateRuleResult $rule): array => [
                'rule_id' => $rule->rule_id->value,
                'subject' => $rule->subject,
                'outcome' => $rule->outcome->value,
                'label' => $rule->outcome->label(),
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

    /**
     * The policy as the structured form edits it. A never-configured
     * project gets suggested starting values (nothing is stored, and the
     * gate stays disabled, until an Owner/Admin saves).
     *
     * @return array<string,mixed>
     */
    public static function form(?ProjectQualityGate $gate): array
    {
        $maxOpen = array_fill_keys(array_map(fn (Severity $s): string => $s->value, [...Severity::ranked(), Severity::Unknown]), null);
        $noNew = ['enabled' => false, 'min_severity' => Severity::High->value];
        $analyzerStatus = [];
        $coverage = [];

        try {
            $policy = $gate?->policy();
        } catch (InvalidQualityGatePolicy) {
            $policy = null;
        }

        if ($policy === null) {
            $maxOpen['critical'] = 0;
            $maxOpen['high'] = 0;
            $noNew['enabled'] = true;
        } else {
            foreach ($policy->rules as $rule) {
                if ($rule instanceof MaxOpenFindingsRule) {
                    foreach ($rule->limits as $severity => $max) {
                        $maxOpen[$severity] = $max;
                    }
                } elseif ($rule instanceof NoNewSeverityRule) {
                    $noNew = ['enabled' => true, 'min_severity' => $rule->minSeverity->value];
                } elseif ($rule instanceof AnalyzerStatusRule) {
                    $analyzerStatus = $rule->analyzers;
                } elseif ($rule instanceof AnalyzerCoverageRule) {
                    foreach ($rule->requirements as $analyzer => $requirement) {
                        $coverage[$analyzer] = $requirement->value;
                    }
                }
            }
        }

        return [
            'max_open' => $maxOpen,
            'no_new' => $noNew,
            'analyzer_status' => $analyzerStatus,
            'coverage' => $coverage,
        ];
    }

    /**
     * Human names for the stable rule ids (Scan Detail).
     *
     * @return array<string,string>
     */
    public static function ruleTitles(): array
    {
        return [
            GateRuleId::MaxOpenFindings->value => 'Maximum open findings',
            GateRuleId::NoNewSeverity->value => 'No new findings',
            GateRuleId::AnalyzerStatus->value => 'Analyzer must pass',
            GateRuleId::AnalyzerCoverage->value => 'Analyzer coverage required',
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private static function canonicalSnapshot(array $snapshot): array
    {
        try {
            return QualityGatePolicy::fromArray($snapshot)->toArray();
        } catch (InvalidQualityGatePolicy) {
            return $snapshot;
        }
    }
}
