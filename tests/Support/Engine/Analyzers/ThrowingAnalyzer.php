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
use RuntimeException;

final class ThrowingAnalyzer implements Analyzer
{
    public function __construct(private readonly string $idValue = 'test.throwing') {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId($this->idValue);
    }

    public function name(): string
    {
        return 'Throwing (fake)';
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
        throw new RuntimeException('Simulated internal analyzer failure.');
    }
}
