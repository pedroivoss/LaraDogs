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

/**
 * Never applicable, to any project. Its availability() and run() must
 * never be reached — both throw if they ever are.
 */
final class NotApplicableAnalyzer implements Analyzer
{
    public function __construct(private readonly string $idValue = 'test.not-applicable') {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId($this->idValue);
    }

    public function name(): string
    {
        return 'Not Applicable (fake)';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Quality;
    }

    public function applicability(ProjectProfile $profile): Applicability
    {
        return Applicability::notApplicable('This fake analyzer is never applicable to any project.');
    }

    public function availability(AuditContext $context): Availability
    {
        throw new RuntimeException('availability() must never be called for a not-applicable analyzer.');
    }

    public function run(AuditContext $context): AnalyzerResult
    {
        throw new RuntimeException('run() must never be called for a not-applicable analyzer.');
    }
}
