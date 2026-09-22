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
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\Ingestion\ProducesFindingCandidates;
use Closure;

/**
 * A Passed analyzer with EXPLICIT coverage whose reported candidates — and
 * an optional side effect run while it "analyzes" (used to mutate the
 * repository mid-audit) — are set by the test. Both are re-settable between
 * scans.
 */
final class ScriptedAnalyzer implements Analyzer, ProducesFindingCandidates
{
    /** @var list<FindingCandidate> */
    public array $candidates = [];

    public ?Closure $whileRunning = null;

    /**
     * @param  list<string>  $coveredRules
     */
    public function __construct(
        private readonly string $idValue = 'semgrep',
        private readonly array $coveredRules = ['R1'],
    ) {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId($this->idValue);
    }

    public function name(): string
    {
        return 'Scripted (fake)';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Security;
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
        if ($this->whileRunning !== null) {
            ($this->whileRunning)($context);
        }

        return AnalyzerResult::passed('Scripted.', coverage: AnalyzerCoverage::explicit($this->coveredRules));
    }

    public function candidates(AuditContext $context, AnalyzerResult $result): array
    {
        return $this->candidates;
    }
}
