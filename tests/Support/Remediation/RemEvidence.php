<?php

namespace Tests\Support\Remediation;

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\Confidence;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Severity;
use App\Audit\Remediation\GateFacts;
use App\Audit\Remediation\RemediationEvidence;
use App\Audit\Source\Git\GitRepositoryState;
use App\Audit\Source\Git\GitSnapshot;

/**
 * Pure builders for planner tests: plain values, no database, fake data only.
 */
final class RemEvidence
{
    public const string ROOT = '/srv/projects/demo-app';

    public const string SQL_RULE = 'laradogs.security.sql.tainted-raw-query';

    /** @param  array<string,mixed>  $over */
    public static function make(array $over = []): RemediationEvidence
    {
        $args = [
            'findingId' => '01hzzzzzzzzzzzzzzzzzzzzzz1',
            'projectId' => '01hzzzzzzzzzzzzzzzzzzzzzp1',
            'projectRoot' => self::ROOT,
            'ruleId' => self::SQL_RULE,
            'analyzerId' => 'semgrep',
            'category' => AnalyzerCategory::Security,
            'severity' => Severity::High,
            'confidence' => Confidence::Medium,
            'status' => FindingStatus::Open,
            'title' => 'Possible SQL injection',
            'description' => 'A request value reaches whereRaw().',
            'impact' => null,
            'cwe' => 'CWE-89',
            'cve' => null,
            'references' => ['https://cwe.mitre.org/data/definitions/89.html'],
            'metadata' => [],
            'filePath' => 'app/Http/Controllers/UserController.php',
            'lineStart' => 42,
            'lineEnd' => 42,
            'snippet' => 'User::whereRaw("id = $id")->get();',
            'ruleVersion' => '2026.09.3',
            'analyzerVersion' => '1.0',
            'profile' => ['project' => ['type' => 'laravel'], 'backend' => ['laravel' => ['installed_version' => '13.17.0'], 'php' => ['constraint' => '^8.3']]],
            'observedSource' => self::git('a', false),
            'currentSource' => self::git('a', false),
            'gate' => null,
            ...$over,
        ];

        return new RemediationEvidence(...$args);
    }

    public static function git(string $shaChar, ?bool $dirty = false, GitRepositoryState $state = GitRepositoryState::Repository): GitSnapshot
    {
        return new GitSnapshot(state: $state, commitSha: str_repeat($shaChar, 40), branch: 'main', detached: false, dirty: $dirty);
    }

    /** @param  list<array{finding_ids: list<string>, finding_count: int}>  $failed */
    public static function gate(array $failed): GateFacts
    {
        return new GateFacts('01hzzzzzzzzzzzzzzzzzzzzzs1', '2026-09-25T12:00:00+00:00', $failed);
    }

    public static function composer(array $over = []): RemediationEvidence
    {
        return self::make([
            'ruleId' => 'vendor/pkg:PKSA-abcd-1234',
            'analyzerId' => 'composer-audit',
            'cwe' => null,
            'cve' => 'CVE-2026-0001',
            'title' => 'vendor/pkg: Remote code execution',
            'description' => 'Affects vendor/pkg versions >=1.0,<1.4.2.',
            'filePath' => null, 'lineStart' => null, 'lineEnd' => null, 'snippet' => null,
            'references' => ['https://github.com/advisories/GHSA-xxxx'],
            'metadata' => ['package_name' => 'vendor/pkg', 'advisory_id' => 'PKSA-abcd-1234', 'affected_versions' => '>=1.0,<1.4.2'],
            ...$over,
        ]);
    }

    public static function npm(array $metadata = [], array $over = []): RemediationEvidence
    {
        return self::make([
            'ruleId' => 'lodash:1234567',
            'analyzerId' => 'npm-audit',
            'cwe' => null,
            'title' => 'lodash: Prototype Pollution',
            'description' => 'Affects lodash (range: <4.17.21).',
            'filePath' => null, 'lineStart' => null, 'lineEnd' => null, 'snippet' => null,
            'references' => ['https://github.com/advisories/GHSA-yyyy'],
            'metadata' => [
                'package_name' => 'lodash', 'source' => 1234567, 'is_direct' => true, 'range' => '<4.17.21',
                'fix_available' => ['name' => 'lodash', 'version' => '4.17.21', 'is_semver_major' => false],
                ...$metadata,
            ],
            ...$over,
        ]);
    }
}
