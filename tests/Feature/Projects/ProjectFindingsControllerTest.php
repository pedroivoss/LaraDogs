<?php

namespace Tests\Feature\Projects;

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Ingestion\FindingReconciler;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Audit\Projects\RegisterProject;
use App\Models\Audit\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\ThrowingAnalyzer;
use Tests\Support\Engine\Analyzers\TimedOutAnalyzer;
use Tests\Support\Findings\SyntheticCandidates;
use Tests\TestCase;

class ProjectFindingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function registerFixtureProject(string $fixture = 'laravel-blade'): Project
    {
        $path = dirname(__DIR__, 2).'/Fixtures/discovery/'.$fixture;

        return (new RegisterProject)->register($path)->project;
    }

    private function completeScan(Project $project, AnalyzerRegistry $registry, array $candidatesByAnalyzer): void
    {
        $recorder = new ScanRecorder(
            new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService),
            new FindingReconciler(new FindingLifecycleService),
        );
        $discovery = (new ProjectDiscovery)->discover($project->path);
        $context = new AuditContext(runId: 'test-'.uniqid(), projectPath: $discovery->path, profile: $discovery->profile);
        $runResult = (new AuditEngine($registry))->run($context);

        $scan = $recorder->startScan($project, $context->profile);
        $recorder->completeScan($scan, $runResult, $candidatesByAnalyzer);
    }

    public function test_guests_are_redirected()
    {
        $project = $this->registerFixtureProject();

        $this->get(route('projects.findings', $project))->assertRedirect(route('login'));
    }

    public function test_findings_are_paginated_server_side()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer('composer-security'));

        $this->completeScan($project, $registry, [
            'composer-security' => [
                SyntheticCandidates::sqlInjection(ruleId: 'RULE-1'),
                SyntheticCandidates::sqlInjection(ruleId: 'RULE-2'),
                SyntheticCandidates::sqlInjection(ruleId: 'RULE-3'),
            ],
        ]);

        $response = $this->actingAs($user)->get(route('projects.findings', $project).'?page=1');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('findings', 3)
            ->where('pagination.total', 3)
            ->where('pagination.current_page', 1)
        );
    }

    public function test_findings_are_filtered_server_side_by_severity()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer('composer-security'));

        $this->completeScan($project, $registry, [
            'composer-security' => [
                SyntheticCandidates::sqlInjection(), // High severity
                SyntheticCandidates::vulnerableDependency(), // Critical severity
            ],
        ]);

        $response = $this->actingAs($user)->get(route('projects.findings', $project).'?severity=critical');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('findings', 1)
            ->where('findings.0.severity', 'critical')
        );
    }

    public function test_scan_history_is_paginated_server_side()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer('composer-security'));

        $this->completeScan($project, $registry, []);
        $this->completeScan($project, $registry, []);

        $response = $this->actingAs($user)->get(route('projects.scans', $project));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('scans', 2)
            ->where('pagination.total', 2)
        );
    }

    public function test_scan_detail_uses_the_immutable_historical_snapshot_not_the_projects_current_state()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $registry = new AnalyzerRegistry;
        $registry->register(new AlwaysPassAnalyzer('composer-security', AnalyzerCoverage::explicit(['LARA-SEC-023'])));

        $this->completeScan($project, $registry, [
            'composer-security' => [SyntheticCandidates::sqlInjection()],
        ]);

        $scan = $project->scans()->firstOrFail();

        $response = $this->actingAs($user)->get(route('projects.scans.show', ['project' => $project, 'scan' => $scan]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('scan.id', $scan->public_id)
            ->has('observed_findings', 1)
            // The snapshot captured Laravel — even if the real fixture
            // directory changed later, this scan's own record must not.
            ->where('scan.project_profile.project.type', 'laravel')
        );
    }

    public function test_analyzer_failed_is_never_presented_as_clean()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $registry = new AnalyzerRegistry;
        $registry->register(new ThrowingAnalyzer('broken-analyzer'));

        $this->completeScan($project, $registry, []);

        $scan = $project->scans()->firstOrFail();

        $response = $this->actingAs($user)->get(route('projects.scans.show', ['project' => $project, 'scan' => $scan]));

        $response->assertInertia(fn ($page) => $page
            ->where('analyzer_executions.0.status', 'failed')
        );
    }

    public function test_analyzer_timed_out_is_never_presented_as_clean()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $registry = new AnalyzerRegistry;
        $registry->register(new TimedOutAnalyzer('slow-analyzer'));

        $this->completeScan($project, $registry, []);

        $scan = $project->scans()->firstOrFail();

        $response = $this->actingAs($user)->get(route('projects.scans.show', ['project' => $project, 'scan' => $scan]));

        $response->assertInertia(fn ($page) => $page
            ->where('analyzer_executions.0.status', 'timed_out')
        );
    }

    public function test_unknown_coverage_is_represented_explicitly()
    {
        $user = User::factory()->create();
        $project = $this->registerFixtureProject();

        $registry = new AnalyzerRegistry;
        // No explicit coverage given -> Unknown, exactly like real
        // Composer/npm analyzers today.
        $registry->register(new AlwaysPassAnalyzer('composer-security'));

        $this->completeScan($project, $registry, []);

        $scan = $project->scans()->firstOrFail();

        $response = $this->actingAs($user)->get(route('projects.scans.show', ['project' => $project, 'scan' => $scan]));

        $response->assertInertia(fn ($page) => $page
            ->where('analyzer_executions.0.coverage.mode', 'unknown')
        );
    }
}
