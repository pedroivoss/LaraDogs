<?php

namespace Tests\Support\Engine\Analyzers;

use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Contracts\Analyzer;
use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Contracts\Applicability;
use App\Audit\Engine\Contracts\Availability;
use App\Audit\Engine\Execution\AnalyzerResult;

/**
 * Simulates a timeout by reporting one immediately — no real sleep/delay,
 * so this stays a fast, deterministic test. See docs/auditing/audit-engine.md
 * for why Phase 2 doesn't implement real wall-clock timeout enforcement:
 * a real timeout will be detected and reported by the future
 * ProcessRunner-based execution (Phase 4), and surfaced through exactly
 * this same `AnalyzerResult::timedOut()` path.
 */
final class TimedOutAnalyzer implements Analyzer
{
    public function __construct(private readonly string $idValue = 'test.timed-out') {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId($this->idValue);
    }

    public function name(): string
    {
        return 'Timed Out (fake)';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Performance;
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
        return AnalyzerResult::timedOut('Simulated timeout — exceeded the configured budget.');
    }
}
