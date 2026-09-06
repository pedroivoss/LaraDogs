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
 * Applicable to any project, but its (fake) tooling is never available on
 * this host — `run()` must never be reached; it throws if it ever is, so
 * a test asserting "unavailable never executes" fails loudly if the
 * engine regresses instead of silently passing.
 */
final class UnavailableAnalyzer implements Analyzer
{
    public function __construct(private readonly string $idValue = 'test.unavailable') {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId($this->idValue);
    }

    public function name(): string
    {
        return 'Unavailable (fake)';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Dependency;
    }

    public function applicability(ProjectProfile $profile): Applicability
    {
        return Applicability::applicable();
    }

    public function availability(AuditContext $context): Availability
    {
        return Availability::unavailable('fake binary not present on this host');
    }

    public function run(AuditContext $context): AnalyzerResult
    {
        throw new RuntimeException('run() must never be called for an unavailable analyzer.');
    }
}
