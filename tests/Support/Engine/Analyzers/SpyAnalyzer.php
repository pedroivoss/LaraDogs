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
 * Counts how many times each hook was actually called, to prove the
 * engine calls each one exactly as the contract prescribes — no implicit
 * extra calls, no hidden re-invocation.
 */
final class SpyAnalyzer implements Analyzer
{
    public int $applicabilityCalls = 0;

    public int $availabilityCalls = 0;

    public int $runCalls = 0;

    public function __construct(private readonly string $idValue = 'test.spy') {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId($this->idValue);
    }

    public function name(): string
    {
        return 'Spy (fake)';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Quality;
    }

    public function applicability(ProjectProfile $profile): Applicability
    {
        $this->applicabilityCalls++;

        return Applicability::applicable();
    }

    public function availability(AuditContext $context): Availability
    {
        $this->availabilityCalls++;

        return Availability::available();
    }

    public function run(AuditContext $context): AnalyzerResult
    {
        $this->runCalls++;

        return AnalyzerResult::passed('Spy ran.');
    }
}
