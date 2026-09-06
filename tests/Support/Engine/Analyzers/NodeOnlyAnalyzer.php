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
 * Exercises real applicability logic against a real {@see ProjectProfile}
 * — applicable only when a valid package.json was actually detected.
 */
final class NodeOnlyAnalyzer implements Analyzer
{
    public function id(): AnalyzerId
    {
        return new AnalyzerId('test.node-only');
    }

    public function name(): string
    {
        return 'Node Only (fake)';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Dependency;
    }

    public function applicability(ProjectProfile $profile): Applicability
    {
        return $profile->frontend->node->status === DetectionStatus::Detected
            ? Applicability::applicable('package.json was detected.')
            : Applicability::notApplicable('No package.json was detected for this project.');
    }

    public function availability(AuditContext $context): Availability
    {
        return Availability::available();
    }

    public function run(AuditContext $context): AnalyzerResult
    {
        return AnalyzerResult::passed('Node-specific check ran.');
    }
}
