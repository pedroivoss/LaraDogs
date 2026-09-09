<?php

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Audit\Findings\Severity;
use App\Audit\Projects\Query\CurrentFindingsQuery;
use App\Audit\Projects\Query\FindingFilters;
use App\Audit\Projects\Query\ProjectListQuery;
use App\Audit\Projects\Query\ProjectSummaryQuery;
use App\Audit\Projects\Query\ScanHistoryQuery;
use App\Audit\Projects\RegisterProject;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Findings\SyntheticCandidates;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function queryFixtureProject(string $fixture = 'laravel-blade'): Project
{
    $path = dirname(__DIR__, 4).'/Fixtures/discovery/'.$fixture;

    return (new RegisterProject)->register($path)->project;
}

function completeQueryScan(Project $project, AnalyzerRegistry $registry, string $runId, array $candidatesByAnalyzer): Scan
{
    $recorder = new ScanRecorder(
        new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
        new FindingReconciler(new FindingLifecycleService),
    );
    $discovery = (new ProjectDiscovery)->discover($project->path);
    $context = new AuditContext(runId: $runId, projectPath: $discovery->path, profile: $discovery->profile);
    $runResult = (new AuditEngine($registry))->run($context);

    $scan = $recorder->startScan($project, $context->profile);

    return $recorder->completeScan($scan, $runResult, $candidatesByAnalyzer);
}

it('lists every project with its latest scan and open finding count, without N+1', function () {
    $projectOne = queryFixtureProject('laravel-blade');
    $projectTwo = queryFixtureProject('laravel-api');

    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));
    completeQueryScan($projectOne, $registry, 'list-1', [
        'composer-security' => [SyntheticCandidates::sqlInjection(), SyntheticCandidates::vulnerableDependency()],
    ]);

    $queryCountBefore = 0;
    DB::listen(function () use (&$queryCountBefore) {
        $queryCountBefore++;
    });

    $projects = (new ProjectListQuery)->all();

    // One query for projects, one for the latestScan subquery join, one
    // for the withCount aggregate — never N queries for N projects.
    expect($queryCountBefore)->toBeLessThanOrEqual(3);

    $withScan = $projects->firstWhere('id', $projectOne->id);
    $withoutScan = $projects->firstWhere('id', $projectTwo->id);

    expect($withScan->latestScan)->not->toBeNull()
        ->and($withScan->open_findings_count)->toBe(2)
        ->and($withoutScan->latestScan)->toBeNull()
        ->and($withoutScan->open_findings_count)->toBe(0);
});

it('returns recent scan history for a project, newest first, and one scan detail with its executions', function () {
    $project = queryFixtureProject();
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));

    $first = completeQueryScan($project, $registry, 'history-1', []);
    // Two scans started within the same second must still sort
    // deterministically (started_at is only second-precision) — see
    // ScanHistoryQuery::recentFor()'s own `id` tiebreaker comment. No
    // artificial delay is introduced here on purpose.
    $second = completeQueryScan($project, $registry, 'history-2', []);

    $history = (new ScanHistoryQuery)->recentFor($project);

    expect($history)->toHaveCount(2)
        ->and($history->first()->id)->toBe($second->id)
        ->and($history->last()->id)->toBe($first->id);

    $detail = (new ScanHistoryQuery)->detail($second->public_id);

    expect($detail)->not->toBeNull()
        ->and($detail->id)->toBe($second->id)
        ->and($detail->analyzerExecutions)->toHaveCount(1);
});

it('queries current findings for a project and filters them by status/severity/category/analyzer/rule', function () {
    $project = queryFixtureProject();
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));

    completeQueryScan($project, $registry, 'filter-1', [
        'composer-security' => [
            SyntheticCandidates::sqlInjection(),
            SyntheticCandidates::vulnerableDependency(),
        ],
    ]);

    $all = (new CurrentFindingsQuery)->forProject($project);
    expect($all)->toHaveCount(2);

    $bySeverity = (new CurrentFindingsQuery)->forProject($project, new FindingFilters(severity: [Severity::Critical]));
    expect($bySeverity)->toHaveCount(1)
        ->and($bySeverity->first()->severity)->toBe(Severity::Critical);

    $byCategory = (new CurrentFindingsQuery)->forProject($project, new FindingFilters(category: [AnalyzerCategory::Dependency]));
    expect($byCategory)->toHaveCount(1)
        ->and($byCategory->first()->category)->toBe(AnalyzerCategory::Dependency);

    $byRule = (new CurrentFindingsQuery)->forProject($project, new FindingFilters(ruleId: 'LARA-SEC-023'));
    expect($byRule)->toHaveCount(1)
        ->and($byRule->first()->rule_id)->toBe('LARA-SEC-023');

    $byStatus = (new CurrentFindingsQuery)->forProject($project, new FindingFilters(status: [FindingStatus::Resolved]));
    expect($byStatus)->toHaveCount(0);

    $noMatch = (new CurrentFindingsQuery)->forProject($project, new FindingFilters(analyzerId: 'nonexistent-analyzer'));
    expect($noMatch)->toHaveCount(0);
});

it('returns findings observed in one specific scan, distinct from a project\'s current findings', function () {
    $project = queryFixtureProject();

    $registryOne = new AnalyzerRegistry;
    $registryOne->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
    completeQueryScan($project, $registryOne, 'scan-a', [
        'composer-security' => [SyntheticCandidates::sqlInjection()],
    ]);

    $registryTwo = new AnalyzerRegistry;
    $registryTwo->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));
    $scanTwo = completeQueryScan($project, $registryTwo, 'scan-b', []);

    // Scan B observed nothing (the finding auto-resolved), even though the
    // project's current findings still include it (now Resolved).
    expect((new CurrentFindingsQuery)->forScan($scanTwo))->toHaveCount(0);
    expect((new CurrentFindingsQuery)->forProject($project))->toHaveCount(1);
});

it('computes an efficient project summary: totals, open breakdown by severity/category, analyzer statuses, last scan', function () {
    $project = queryFixtureProject();
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('composer-security'));

    $scan = completeQueryScan($project, $registry, 'summary-1', [
        'composer-security' => [
            SyntheticCandidates::sqlInjection(),
            SyntheticCandidates::vulnerableDependency(),
            SyntheticCandidates::configIssue(),
        ],
    ]);

    $summary = (new ProjectSummaryQuery)->forProject($project);

    expect($summary->totalFindings)->toBe(3)
        ->and($summary->openFindings)->toBe(3)
        ->and($summary->openFindingsBySeverity)->toBe(['high' => 1, 'critical' => 1, 'low' => 1])
        ->and($summary->openFindingsByCategory['security'])->toBe(1)
        ->and($summary->openFindingsByCategory['dependency'])->toBe(1)
        ->and($summary->lastScanAnalyzerStatuses)->toBe(['composer-security' => 'passed'])
        ->and($summary->lastScan->id)->toBe($scan->id);
});
