<?php

namespace App\Http\Requests\Projects;

use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\Severity;
use App\Audit\QualityGates\Policy\AnalyzerCoverageRule;
use App\Audit\QualityGates\Policy\AnalyzerStatusRule;
use App\Audit\QualityGates\Policy\CoverageRequirement;
use App\Audit\QualityGates\Policy\GateRule;
use App\Audit\QualityGates\Policy\InvalidQualityGatePolicy;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\NoNewSeverityRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Structured (never free-form) Quality Gate form: enabled flag, per-
 * severity limits, the new-findings threshold, required analyzers and
 * required coverage. Authorization is the `staff` route middleware
 * (Owner/Admin; a User gets a 404 before this class runs). Every value is
 * type/range checked here and again by the strict {@see QualityGatePolicy}
 * value objects — there is no expression, SQL or shell content anywhere.
 */
class UpdateQualityGateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $analyzerIds = array_map(fn ($analyzer): string => (string) $analyzer->id(), app(AnalyzerRegistry::class)->all());

        return [
            'enabled' => ['required', 'boolean'],
            // A blank limit means "not enforced"; 0 means "none allowed" — distinct.
            'max_open' => ['nullable', 'array'],
            'max_open.*' => ['nullable', 'integer', 'min:0', 'max:'.MaxOpenFindingsRule::MAX_LIMIT],
            'no_new' => ['nullable', 'array'],
            'no_new.enabled' => ['nullable', 'boolean'],
            'no_new.min_severity' => ['nullable', Rule::in(array_map(fn (Severity $s): string => $s->value, Severity::ranked()))],
            'analyzer_status' => ['nullable', 'array', 'max:'.QualityGatePolicy::MAX_ANALYZERS],
            'analyzer_status.*' => ['string', Rule::in($analyzerIds)],
            'coverage' => ['nullable', 'array'],
            'coverage.*' => ['nullable', Rule::in(array_map(fn (CoverageRequirement $r): string => $r->value, CoverageRequirement::cases()))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $severities = array_map(fn (Severity $s): string => $s->value, [...Severity::ranked(), Severity::Unknown]);
            $analyzerIds = array_map(fn ($analyzer): string => (string) $analyzer->id(), app(AnalyzerRegistry::class)->all());

            foreach (array_keys((array) $this->input('max_open', [])) as $key) {
                if (! in_array((string) $key, $severities, true)) {
                    $validator->errors()->add('max_open', 'Unknown severity.');
                }
            }

            foreach (array_keys((array) $this->input('coverage', [])) as $key) {
                if (! in_array((string) $key, $analyzerIds, true)) {
                    $validator->errors()->add('coverage', 'Unknown analyzer.');
                }
            }
        });
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }

    /**
     * @throws InvalidQualityGatePolicy
     */
    public function toPolicy(): QualityGatePolicy
    {
        /** @var list<GateRule> $rules */
        $rules = [];

        $limits = [];

        foreach ((array) $this->input('max_open', []) as $severity => $max) {
            if ($max !== null && $max !== '') {
                $limits[(string) $severity] = (int) $max;
            }
        }

        if ($limits !== []) {
            $rules[] = new MaxOpenFindingsRule($limits);
        }

        if ($this->boolean('no_new.enabled')) {
            $rules[] = new NoNewSeverityRule(Severity::from((string) ($this->input('no_new.min_severity') ?: Severity::High->value)));
        }

        $analyzers = array_values(array_filter((array) $this->input('analyzer_status', []), fn ($a): bool => is_string($a) && $a !== ''));

        if ($analyzers !== []) {
            $rules[] = new AnalyzerStatusRule($analyzers);
        }

        $requirements = [];

        foreach ((array) $this->input('coverage', []) as $analyzer => $value) {
            if ($value !== null && $value !== '') {
                $requirements[(string) $analyzer] = CoverageRequirement::from((string) $value);
            }
        }

        if ($requirements !== []) {
            $rules[] = new AnalyzerCoverageRule($requirements);
        }

        return new QualityGatePolicy($rules);
    }
}
