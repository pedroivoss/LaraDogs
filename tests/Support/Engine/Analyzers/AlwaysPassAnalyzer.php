<?php

namespace Tests\Support\Engine\Analyzers;

use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Contracts\Analyzer;
use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Contracts\Applicability;
use App\Audit\Engine\Contracts\Availability;
use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Execution\AnalyzerResult;

/**
 * Passes `$coverage` straight through to its {@see AnalyzerResult} —
 * defaults to {@see AnalyzerCoverage::unknown()} (the safe default) so
 * tests must opt in explicitly to a stronger coverage claim, never get
 * one implicitly just because this analyzer always reports Passed.
 */
final class AlwaysPassAnalyzer implements Analyzer
{
    public function __construct(
        private readonly string $idValue = 'test.always-pass',
        private readonly ?AnalyzerCoverage $coverage = null,
    ) {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId($this->idValue);
    }

    public function name(): string
    {
        return 'Always Pass (fake)';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Quality;
    }

    public function applicability(ProjectProfile $profile): Applicability
    {
        return Applicability::applicable();
    }

    public function availability(AuditContext $context): Availability
    {
        return Availability::available();
    }

    public function run(AuditContext $context): AnalyzerResult
    {
        return AnalyzerResult::passed('Always passes.', coverage: $this->coverage ?? AnalyzerCoverage::unknown());
    }
}
