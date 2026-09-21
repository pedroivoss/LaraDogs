<?php

namespace Tests\Support\QualityGates;

use App\Audit\Discovery\ProjectDiscovery;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Contracts\Analyzer;
use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Findings\Confidence;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\Ingestion\ScanRecorder;
use App\Audit\Findings\Severity;
use App\Audit\Projects\RegisterProject;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\QualityGates\QualityGatePolicyService;
use App\Models\Audit\Project;
use App\Models\Audit\ProjectQualityGate;
use App\Models\Audit\Scan;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;

/**
 * Real-pipeline scenarios for Quality Gate tests: real Discovery, the real
 * Engine with fake analyzers, the container's real ScanRecorder (which
 * dispatches ScanFinished) and the real Finding domain (fingerprints,
 * occurrences, reconciliation). Nothing about the gate is mocked.
 */
final class GateScans
{
    public static function project(string $fixture = 'laravel-blade'): Project
    {
        return (new RegisterProject)->register(dirname(__DIR__, 2).'/Fixtures/discovery/'.$fixture)->project;
    }

    public static function enable(Project $project, QualityGatePolicy $policy): ProjectQualityGate
    {
        $gate = app(QualityGatePolicyService::class)->update($project, true, $policy);
        assert($gate !== null);

        return $gate;
    }

    /**
     * semgrep (Explicit R1..R3) + composer-audit (Unknown), both Passed —
     * the "healthy audit" analyzer set. Pass replacements by id.
     *
     * @param  array<string,Analyzer>  $override
     */
    public static function analyzers(array $override = []): AnalyzerRegistry
    {
        $set = array_merge([
            'semgrep' => new AlwaysPassAnalyzer('semgrep', AnalyzerCoverage::explicit(['R1', 'R2', 'R3'])),
            'composer-audit' => new AlwaysPassAnalyzer('composer-audit'),
        ], $override);

        $registry = new AnalyzerRegistry;

        foreach ($set as $analyzer) {
            $registry->register($analyzer);
        }

        return $registry;
    }

    /**
     * Runs the Engine over the project's real discovery and persists a
     * COMPLETED scan through the container's ScanRecorder.
     *
     * @param  array<string,list<FindingCandidate>>  $candidatesByAnalyzer
     */
    public static function scan(Project $project, ?AnalyzerRegistry $registry = null, array $candidatesByAnalyzer = []): Scan
    {
        $discovery = (new ProjectDiscovery)->discover($project->path);
        $context = new AuditContext(runId: uniqid('gate-', true), projectPath: $discovery->path, profile: $discovery->profile);
        $runResult = (new AuditEngine($registry ?? self::analyzers()))->run($context);

        $recorder = app(ScanRecorder::class);
        $scan = $recorder->startScan($project, $context->profile);

        return $recorder->completeScan($scan, $runResult, $candidatesByAnalyzer);
    }

    /**
     * A finding candidate whose logical identity is `$key` (path + code
     * snippet), so two different keys are two different Findings while the
     * same key across scans is the same Finding.
     */
    public static function candidate(string $key, Severity $severity = Severity::High, string $analyzerId = 'semgrep', string $ruleId = 'R1', int $line = 10): FindingCandidate
    {
        return new FindingCandidate(
            ruleId: $ruleId,
            analyzerId: $analyzerId,
            category: AnalyzerCategory::Security,
            severity: $severity,
            confidence: Confidence::High,
            title: "Finding {$key}",
            description: 'Synthetic.',
            filePath: "app/{$key}.php",
            lineStart: $line,
            lineEnd: $line + 1,
            codeSnippet: "risky({$key});",
        );
    }
}
