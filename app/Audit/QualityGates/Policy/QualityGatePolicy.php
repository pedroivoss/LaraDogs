<?php

namespace App\Audit\QualityGates\Policy;

use App\Audit\Findings\Severity;
use App\Audit\QualityGates\GateRuleId;

/**
 * A Project's Quality Gate policy: a small, bounded, typed set of
 * declarative rules (V1: at most one rule per {@see GateRuleId}).
 *
 * Storage is a versioned JSON document (`{"schema":1,"rules":[…]}`), but
 * it is ONLY ever produced by {@see toArray()} and consumed by the strict
 * {@see fromArray()} — unknown rule types, unknown keys, out-of-range
 * numbers and oversized lists are rejected. There is no expression
 * language: nothing in a policy is evaluated as code, SQL or shell.
 */
final readonly class QualityGatePolicy
{
    public const int SCHEMA = 1;

    public const int MAX_ANALYZERS = 20;

    /** @var list<GateRule> */
    public array $rules;

    /**
     * @param  list<GateRule>  $rules
     */
    public function __construct(array $rules)
    {
        $seen = [];

        foreach ($rules as $rule) {
            if (isset($seen[$rule->id()->value])) {
                throw new InvalidQualityGatePolicy("Rule [{$rule->id()->value}] may only appear once.");
            }

            $seen[$rule->id()->value] = true;
        }

        $this->rules = $rules;
    }

    public static function assertAnalyzerId(string $analyzer): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $analyzer) !== 1) {
            throw new InvalidQualityGatePolicy('Invalid analyzer identifier.');
        }
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    public function rule(GateRuleId $id): ?GateRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->id() === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array{schema: int, rules: list<array<string,mixed>>}
     */
    public function toArray(): array
    {
        $rules = array_map(fn (GateRule $rule): array => $rule->toArray(), $this->rules);

        // Canonical catalog order (not alphabetical): the order results are shown in.
        $order = array_flip(array_map(fn (GateRuleId $id): string => $id->value, GateRuleId::cases()));
        usort($rules, fn (array $a, array $b): int => $order[(string) $a['type']] <=> $order[(string) $b['type']]);

        return ['schema' => self::SCHEMA, 'rules' => $rules];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        self::assertKeys($data, ['schema', 'rules'], 'policy');

        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidQualityGatePolicy('Unsupported policy schema version.');
        }

        $rawRules = $data['rules'] ?? null;

        if (! is_array($rawRules) || count($rawRules) > count(GateRuleId::cases())) {
            throw new InvalidQualityGatePolicy('A policy has a bounded list of rules.');
        }

        $rules = [];

        foreach ($rawRules as $raw) {
            if (! is_array($raw)) {
                throw new InvalidQualityGatePolicy('Each rule must be an object.');
            }

            $rules[] = self::ruleFromArray($raw);
        }

        return new self($rules);
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function ruleFromArray(array $raw): GateRule
    {
        $type = GateRuleId::tryFrom((string) ($raw['type'] ?? ''));

        if ($type === null) {
            throw new InvalidQualityGatePolicy('Unknown rule type.');
        }

        return match ($type) {
            GateRuleId::MaxOpenFindings => self::maxOpenFromArray($raw),
            GateRuleId::NoNewSeverity => self::noNewFromArray($raw),
            GateRuleId::AnalyzerStatus => self::analyzerStatusFromArray($raw),
            GateRuleId::AnalyzerCoverage => self::analyzerCoverageFromArray($raw),
        };
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function maxOpenFromArray(array $raw): MaxOpenFindingsRule
    {
        self::assertKeys($raw, ['type', 'limits'], 'max-open-findings');

        if (! is_array($raw['limits'] ?? null)) {
            throw new InvalidQualityGatePolicy('max-open-findings needs a limits object.');
        }

        /** @var array<string,int> $limits */
        $limits = $raw['limits'];

        return new MaxOpenFindingsRule($limits);
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function noNewFromArray(array $raw): NoNewSeverityRule
    {
        self::assertKeys($raw, ['type', 'min_severity'], 'no-new-severity');

        $severity = Severity::tryFrom((string) ($raw['min_severity'] ?? ''));

        if ($severity === null) {
            throw new InvalidQualityGatePolicy('Unknown severity in no-new-severity.');
        }

        return new NoNewSeverityRule($severity);
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function analyzerStatusFromArray(array $raw): AnalyzerStatusRule
    {
        self::assertKeys($raw, ['type', 'analyzers'], 'analyzer-status');

        if (! is_array($raw['analyzers'] ?? null) || ! array_is_list($raw['analyzers'])) {
            throw new InvalidQualityGatePolicy('analyzer-status needs a list of analyzers.');
        }

        /** @var list<string> $analyzers */
        $analyzers = array_map(fn ($a): string => (string) $a, $raw['analyzers']);

        return new AnalyzerStatusRule($analyzers);
    }

    /**
     * @param  array<mixed>  $raw
     */
    private static function analyzerCoverageFromArray(array $raw): AnalyzerCoverageRule
    {
        self::assertKeys($raw, ['type', 'requirements'], 'analyzer-coverage');

        if (! is_array($raw['requirements'] ?? null)) {
            throw new InvalidQualityGatePolicy('analyzer-coverage needs a requirements object.');
        }

        $requirements = [];

        foreach ($raw['requirements'] as $analyzer => $value) {
            $requirement = CoverageRequirement::tryFrom((string) $value);

            if ($requirement === null) {
                throw new InvalidQualityGatePolicy('Unknown coverage requirement.');
            }

            $requirements[(string) $analyzer] = $requirement;
        }

        return new AnalyzerCoverageRule($requirements);
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $allowed
     */
    private static function assertKeys(array $data, array $allowed, string $context): void
    {
        $extra = array_diff(array_map('strval', array_keys($data)), $allowed);

        if ($extra !== []) {
            throw new InvalidQualityGatePolicy("Unexpected keys in {$context}.");
        }
    }
}
