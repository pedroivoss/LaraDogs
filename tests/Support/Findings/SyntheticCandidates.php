<?php

namespace Tests\Support\Findings;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\Confidence;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\Severity;

/**
 * Synthetic FindingCandidates covering different categories/severities/
 * locations, for testing ingestion without any real scanner. Never used
 * in production code.
 */
final class SyntheticCandidates
{
    public static function sqlInjection(
        ?string $filePath = null,
        ?int $lineStart = null,
        ?int $lineEnd = null,
        ?string $codeSnippet = null,
        ?string $ruleId = null,
        ?string $analyzerId = null,
    ): FindingCandidate {
        return new FindingCandidate(
            ruleId: $ruleId ?? 'LARA-SEC-023',
            analyzerId: $analyzerId ?? 'composer-security',
            category: AnalyzerCategory::Security,
            severity: Severity::High,
            confidence: Confidence::High,
            title: 'Possible SQL Injection',
            description: 'User input reaches a raw query without parameter binding.',
            recommendation: 'Use parameter binding instead of string concatenation.',
            filePath: $filePath ?? 'app/Repositories/UserRepository.php',
            lineStart: $lineStart ?? 42,
            lineEnd: $lineEnd ?? 44,
            codeSnippet: $codeSnippet ?? 'DB::select("SELECT * FROM users WHERE id = " . $id);',
            cwe: 'CWE-89',
            ruleVersion: 'v1',
            analyzerVersion: '1.0.0',
        );
    }

    public static function nPlusOne(?string $filePath = null, ?int $lineStart = null): FindingCandidate
    {
        return new FindingCandidate(
            ruleId: 'LARA-PERF-004',
            analyzerId: 'laravel-rules',
            category: AnalyzerCategory::Performance,
            severity: Severity::Medium,
            confidence: Confidence::Medium,
            title: 'Possible N+1 query',
            description: 'A relation is accessed inside a loop without eager loading.',
            filePath: $filePath ?? 'app/Http/Controllers/PostController.php',
            lineStart: $lineStart ?? 20,
            lineEnd: ($lineStart ?? 20) + 2,
            codeSnippet: 'foreach ($posts as $post) { $post->author->name; }',
            ruleVersion: 'v1',
        );
    }

    public static function vulnerableDependency(?string $ruleId = null): FindingCandidate
    {
        return new FindingCandidate(
            ruleId: $ruleId ?? 'OSV-2024-1234',
            analyzerId: 'composer-audit',
            category: AnalyzerCategory::Dependency,
            severity: Severity::Critical,
            confidence: Confidence::High,
            title: 'Known-vulnerable dependency: guzzlehttp/psr7 < 2.6.0',
            description: 'This version is affected by a known CVE.',
            cve: 'CVE-2024-99999',
            references: ['https://example.test/advisory/OSV-2024-1234'],
            metadata: ['package' => 'guzzlehttp/psr7', 'installed_version' => '2.5.0'],
        );
    }

    public static function configIssue(): FindingCandidate
    {
        return new FindingCandidate(
            ruleId: 'LARA-CFG-011',
            analyzerId: 'laravel-rules',
            category: AnalyzerCategory::Configuration,
            severity: Severity::Low,
            confidence: Confidence::Medium,
            title: 'APP_DEBUG enabled outside local environment',
            description: 'Debug mode should be disabled in non-local environments.',
            recommendation: 'Set APP_DEBUG=false.',
        );
    }
}
