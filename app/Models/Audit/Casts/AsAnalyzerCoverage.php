<?php

namespace App\Models\Audit\Casts;

use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Execution\CoverageMode;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists an {@see AnalyzerCoverage} (Phase 2) as plain JSON — storage
 * only, never queried by internal structure (per ADR-0007). A missing or
 * unparseable value casts to {@see AnalyzerCoverage::unknown()}, the safe
 * default, rather than throwing or silently upgrading to a more
 * permissive mode.
 *
 * @implements CastsAttributes<AnalyzerCoverage, AnalyzerCoverage>
 */
final class AsAnalyzerCoverage implements CastsAttributes
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): AnalyzerCoverage
    {
        if (! is_string($value) || $value === '') {
            return AnalyzerCoverage::unknown();
        }

        $decoded = json_decode($value, associative: true);

        if (! is_array($decoded)) {
            return AnalyzerCoverage::unknown();
        }

        $mode = CoverageMode::tryFrom((string) ($decoded['mode'] ?? '')) ?? CoverageMode::Unknown;
        $rulesetVersion = is_string($decoded['ruleset_version'] ?? null) ? $decoded['ruleset_version'] : null;
        $ruleIds = array_values(array_filter((array) ($decoded['rule_ids'] ?? []), 'is_string'));

        return match ($mode) {
            CoverageMode::Full => AnalyzerCoverage::full($rulesetVersion),
            CoverageMode::Explicit => AnalyzerCoverage::explicit($ruleIds, $rulesetVersion),
            CoverageMode::Unknown => AnalyzerCoverage::unknown($rulesetVersion),
        };
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $coverage = $value instanceof AnalyzerCoverage ? $value : AnalyzerCoverage::unknown();

        $encoded = json_encode($coverage);

        // AnalyzerCoverage::jsonSerialize() only ever emits scalars/null,
        // so json_encode() cannot realistically fail here — this literal
        // fallback exists purely so the return type stays `string`
        // without widening it to accommodate json_encode()'s general
        // `string|false` signature.
        return [$key => $encoded === false ? '{"mode":"unknown","rule_ids":[],"ruleset_version":null}' : $encoded];
    }
}
