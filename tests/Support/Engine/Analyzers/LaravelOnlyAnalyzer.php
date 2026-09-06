<?php

namespace Tests\Support\Engine\Analyzers;

use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Discovery\Support\DetectionStatus;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Contracts\Analyzer;
use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Contracts\Applicability;
use App\Audit\Engine\Contracts\Availability;
use App\Audit\Engine\Execution\AnalyzerResult;

/**
 * Exercises real applicability logic (not a fixed answer) against a real
 * {@see ProjectProfile} produced by Project Discovery — applicable only
 * when Laravel was actually detected.
 */
final class LaravelOnlyAnalyzer implements Analyzer
{
    public function id(): AnalyzerId
    {
        return new AnalyzerId('test.laravel-only');
    }

    public function name(): string
    {
        return 'Laravel Only (fake)';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Configuration;
    }

    public function applicability(ProjectProfile $profile): Applicability
    {
        return $profile->backend->laravel->status === DetectionStatus::Detected
            ? Applicability::applicable('Laravel was detected.')
            : Applicability::notApplicable('Laravel was not detected for this project.');
    }

    public function availability(AuditContext $context): Availability
    {
        return Availability::available();
    }

    public function run(AuditContext $context): AnalyzerResult
    {
        return AnalyzerResult::passed('Laravel-specific check ran.');
    }
}
