<?php

namespace App\Audit\Engine\Execution;

use JsonSerializable;

/**
 * An analyzer's own declaration of what its execution actually VERIFIED —
 * a distinct claim from whether it ran successfully
 * ({@see ExecutionStatus::Passed}). "This analyzer completed without
 * error" and "this analyzer's run verified rule X" are different
 * statements; only this type answers the second one, and only this type
 * may ever authorize auto-resolving a finding. See
 * docs/auditing/findings-lifecycle.md#auto-resolution-safety and
 * ADR-0010.
 *
 * Defaults to {@see CoverageMode::Unknown} everywhere an analyzer doesn't
 * explicitly report otherwise — the safe, conservative default.
 *
 * `rulesetVersion` is provenance only, carried for future traceability
 * (e.g. "ruleset 2026.09.1") — it is never consulted by {@see verifies()}
 * and can never by itself authorize or block a resolution. A ruleset
 * version bump with the same declared coverage changes nothing; coverage
 * changing (even under the same version) changes everything.
 */
final readonly class AnalyzerCoverage implements JsonSerializable
{
    /**
     * @param  list<string>  $ruleIds  Only meaningful when $mode is Explicit.
     */
    private function __construct(
        public CoverageMode $mode,
        public array $ruleIds = [],
        public ?string $rulesetVersion = null,
    ) {}

    public static function unknown(?string $rulesetVersion = null): self
    {
        return new self(CoverageMode::Unknown, [], $rulesetVersion);
    }

    /**
     * @param  list<string>  $ruleIds
     */
    public static function explicit(array $ruleIds, ?string $rulesetVersion = null): self
    {
        return new self(CoverageMode::Explicit, $ruleIds, $rulesetVersion);
    }

    public static function full(?string $rulesetVersion = null): self
    {
        return new self(CoverageMode::Full, [], $rulesetVersion);
    }

    /**
     * Whether this execution's coverage confirms that $ruleId was
     * actually verified — the ONLY question that may ever authorize
     * auto-resolving a finding produced by that rule. Never consults
     * `rulesetVersion`.
     */
    public function verifies(string $ruleId): bool
    {
        return match ($this->mode) {
            CoverageMode::Full => true,
            CoverageMode::Explicit => in_array($ruleId, $this->ruleIds, true),
            CoverageMode::Unknown => false,
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'mode' => $this->mode->value,
            'rule_ids' => $this->ruleIds,
            'ruleset_version' => $this->rulesetVersion,
        ];
    }
}
