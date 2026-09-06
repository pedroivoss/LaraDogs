<?php

namespace App\Audit\Analyzers\Composer;

use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\Contracts\Analyzer;
use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Contracts\Applicability;
use App\Audit\Engine\Contracts\Availability;
use App\Audit\Engine\Execution\AnalyzerCoverage;
use App\Audit\Engine\Execution\AnalyzerDiagnostic;
use App\Audit\Engine\Execution\AnalyzerResult;
use App\Audit\Engine\Execution\DiagnosticLevel;
use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\Engine\Process\ProcessCommand;
use App\Audit\Engine\Process\ProcessRunner;
use App\Audit\Findings\Confidence;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\Ingestion\ProducesFindingCandidates;
use App\Audit\Findings\Severity;

/**
 * The first real analyzer: runs `composer audit` against a project's
 * locked dependencies and normalizes its advisories into
 * {@see FindingCandidate}s. See docs/auditing/analyzers/composer-audit.md
 * for the full design rationale (schema, exit codes, coverage policy,
 * abandoned-package handling) verified against the `composer/composer`
 * source rather than assumed.
 *
 * Security posture (see docs/development/process-execution.md and
 * ADR-0011):
 * - never runs `composer install` or otherwise mutates the target —
 *   `--locked` audits directly from `composer.lock`;
 * - always passes `--no-plugins --no-scripts`, so even a malicious
 *   `composer.json` cannot execute target-defined code through this path;
 * - the executed binary is resolved by {@see ComposerBinaryResolver} —
 *   LaraDogs' own config/PATH only, never anything read from the target;
 * - environment sent to the subprocess is an explicit allowlist (see
 *   `config('laradogs.process.env_allowlist')`), never a full inherited
 *   environment.
 *
 * Coverage policy: always reports {@see AnalyzerCoverage::unknown()}.
 * Composer's audit model has no concept of "the exact set of rule ids this
 * run verified" the way a static-analysis ruleset does — it re-checks
 * every locked package against the CURRENT advisory database and reports
 * only what currently matches. Declaring `Explicit` coverage using
 * "advisory ids found this run" would be actively wrong (it only lists
 * matches, never confirmed-absent checks) and `Full` would be an
 * unjustified upgrade. The accepted, documented consequence: Composer
 * findings never auto-resolve in this version — see "Known limitations"
 * in composer-audit.md.
 */
final class ComposerAuditAnalyzer implements Analyzer, ProducesFindingCandidates
{
    private const string MIN_SUPPORTED_VERSION = '2.4.0';

    private ?string $resolvedBinary = null;

    private ?string $resolvedVersion = null;

    public function __construct(
        private readonly ComposerBinaryResolver $binaryResolver,
        private readonly ComposerAuditParser $parser,
        private readonly ProcessRunner $processRunner,
    ) {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId('composer-audit');
    }

    public function name(): string
    {
        return 'Composer Audit';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Dependency;
    }

    public function applicability(ProjectProfile $profile): Applicability
    {
        if (! $profile->backend->composer->isDetected()) {
            return Applicability::notApplicable('No composer.json detected — not a Composer project.');
        }

        if (! $profile->backend->composerLock->isDetected()) {
            return Applicability::notApplicable(
                'No composer.lock detected — composer audit requires a lock file, and LaraDogs never runs `composer install` to create one.',
            );
        }

        return Applicability::applicable();
    }

    public function availability(AuditContext $context): Availability
    {
        $binary = $this->binaryResolver->resolve();

        if ($binary === null) {
            return Availability::unavailable(
                'Composer executable not found (configure laradogs.composer.binary or install composer on LaraDogs\' own PATH).',
            );
        }

        $result = $this->processRunner->run(new ProcessCommand(
            argv: [$binary, '--version', '--no-ansi', '--no-interaction'],
            workingDirectory: $context->projectPath,
            environment: $this->composerEnv(),
            timeoutSeconds: 5,
        ));

        if ($result->timedOut || $result->processStartFailed() || ! $result->successful()) {
            return Availability::unavailable('Composer version check failed to complete.');
        }

        $version = $this->extractVersion($result->stdout);

        if ($version === null) {
            return Availability::unavailable('Could not determine the installed Composer version from its `--version` output.');
        }

        if (version_compare($version, self::MIN_SUPPORTED_VERSION, '<')) {
            return Availability::unavailable(
                sprintf('Composer %s found, but `composer audit` requires >= %s.', $version, self::MIN_SUPPORTED_VERSION),
            );
        }

        $this->resolvedBinary = $binary;
        $this->resolvedVersion = $version;

        return Availability::available(sprintf('Composer %s detected.', $version));
    }

    public function run(AuditContext $context): AnalyzerResult
    {
        $binary = $this->resolvedBinary ?? $this->binaryResolver->resolve();

        if ($binary === null) {
            return AnalyzerResult::failed(
                'Composer executable could not be resolved.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, 'No composer binary configured or found on LaraDogs\' own PATH.')],
            );
        }

        $timeout = (int) config('laradogs.composer.timeout_seconds', 30);

        $result = $this->processRunner->run(new ProcessCommand(
            argv: [
                $binary, 'audit',
                '--locked',
                '--format=json',
                '--no-interaction',
                '--no-plugins',
                '--no-scripts',
                '--no-ansi',
            ],
            workingDirectory: $context->projectPath,
            environment: $this->composerEnv(),
            timeoutSeconds: $timeout,
        ));

        if ($result->timedOut) {
            return AnalyzerResult::timedOut(
                sprintf('composer audit timed out after %ds.', $timeout),
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, 'Process timed out before completing.')],
            );
        }

        if ($result->processStartFailed()) {
            return AnalyzerResult::failed(
                'Failed to start the composer audit process.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, $this->boundedExcerpt($result->stderr))],
            );
        }

        if ($result->outputTruncated) {
            return AnalyzerResult::failed(
                'composer audit output exceeded the captured output limit; results cannot be trusted as complete.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, 'Output was truncated before it could be fully captured.')],
                ['exit_code' => $result->exitCode],
            );
        }

        // Deliberately NOT gating on $result->exitCode: composer audit's
        // exit code is 1 whenever advisories/abandoned packages are found
        // — that is a normal, informative outcome, not an analyzer
        // failure. Whether the run is trustworthy is decided entirely by
        // whether its JSON parsed and whether repositories were reachable.
        $report = $this->parser->parse($result->stdout);

        if ($report === null) {
            return AnalyzerResult::failed(
                'composer audit produced output that could not be parsed as JSON.',
                [new AnalyzerDiagnostic(
                    DiagnosticLevel::Error,
                    $this->boundedExcerpt($result->stdout !== '' ? $result->stdout : $result->stderr),
                )],
                ['exit_code' => $result->exitCode],
            );
        }

        if ($report->hasUnreachableRepositories) {
            // A network/tool failure must never be reported as a clean
            // scan — fail closed rather than silently omitting whatever
            // packages could not be checked.
            return AnalyzerResult::failed(
                sprintf(
                    'Composer could not reach %d advisory repositor%s; results are incomplete.',
                    count($report->unreachableRepositories),
                    count($report->unreachableRepositories) === 1 ? 'y' : 'ies',
                ),
                [new AnalyzerDiagnostic(
                    DiagnosticLevel::Error,
                    'Unreachable repositories: '.implode(', ', $report->unreachableRepositories),
                )],
                ['exit_code' => $result->exitCode, 'unreachable_repositories' => $report->unreachableRepositories],
            );
        }

        return AnalyzerResult::passed(
            sprintf(
                '%d advisor%s found across locked dependencies%s.',
                count($report->advisories),
                count($report->advisories) === 1 ? 'y' : 'ies',
                $report->abandoned !== []
                    ? sprintf(', %d abandoned package(s) detected', count($report->abandoned))
                    : '',
            ),
            $this->abandonedDiagnostics($report->abandoned),
            [
                'exit_code' => $result->exitCode,
                'advisories' => array_map($this->advisoryToArray(...), $report->advisories),
                'abandoned' => $report->abandoned,
                'composer_version' => $this->resolvedVersion,
            ],
            // See this class's docblock: Composer gives no explicit "rules
            // executed" universe, so coverage always stays Unknown here.
            AnalyzerCoverage::unknown(),
        );
    }

    /**
     * @return list<FindingCandidate>
     */
    public function candidates(AuditContext $context, AnalyzerResult $result): array
    {
        if ($result->status !== ExecutionStatus::Passed) {
            return [];
        }

        $advisories = $result->rawMetadata['advisories'] ?? null;

        if (! is_array($advisories)) {
            return [];
        }

        $analyzerVersion = is_string($result->rawMetadata['composer_version'] ?? null)
            ? $result->rawMetadata['composer_version']
            : null;

        $candidates = [];

        foreach ($advisories as $advisory) {
            if (is_array($advisory)) {
                $candidates[] = $this->toCandidate($advisory, $analyzerVersion);
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $advisory
     */
    private function toCandidate(array $advisory, ?string $analyzerVersion): FindingCandidate
    {
        $packageName = is_string($advisory['package_name'] ?? null) ? $advisory['package_name'] : 'unknown/package';
        $advisoryId = is_string($advisory['advisory_id'] ?? null) ? $advisory['advisory_id'] : 'unknown';
        $title = is_string($advisory['title'] ?? null) ? $advisory['title'] : sprintf('Security advisory for %s', $packageName);
        $affectedVersions = is_string($advisory['affected_versions'] ?? null) ? $advisory['affected_versions'] : '';
        $link = is_string($advisory['link'] ?? null) ? $advisory['link'] : null;

        return new FindingCandidate(
            // Package name + advisory id, not advisory id alone: keeps
            // rule identity stable and unique even if Packagist's own id
            // scheme were ever ambiguous across packages.
            ruleId: sprintf('%s:%s', $packageName, $advisoryId),
            analyzerId: (string) $this->id(),
            category: $this->category(),
            severity: $this->mapSeverity($advisory['severity'] ?? null),
            // Always High: this candidate exists only when Composer
            // matched an official advisory against a locked package
            // version — there is no partial/heuristic match here to
            // hedge against. High reflects confidence in the MATCH, not
            // the advisory's real-world impact (that's severity's job).
            confidence: Confidence::High,
            title: $title,
            description: $affectedVersions !== ''
                ? sprintf('Affects %s versions %s.', $packageName, $affectedVersions)
                : sprintf('Affects %s.', $packageName),
            cve: is_string($advisory['cve'] ?? null) ? $advisory['cve'] : null,
            references: $link !== null ? [$link] : [],
            metadata: [
                'package_name' => $packageName,
                'advisory_id' => $advisoryId,
                'affected_versions' => $affectedVersions,
                'sources' => is_array($advisory['sources'] ?? null) ? $advisory['sources'] : [],
                'reported_at' => is_string($advisory['reported_at'] ?? null) ? $advisory['reported_at'] : null,
            ],
            analyzerVersion: $analyzerVersion,
        );
    }

    private function mapSeverity(mixed $raw): Severity
    {
        if (! is_string($raw)) {
            return Severity::Unknown;
        }

        return match (strtolower($raw)) {
            'critical' => Severity::Critical,
            'high' => Severity::High,
            'medium' => Severity::Medium,
            'low' => Severity::Low,
            default => Severity::Unknown,
        };
    }

    /**
     * @param  array<string,string|bool|null>  $abandoned
     * @return list<AnalyzerDiagnostic>
     */
    private function abandonedDiagnostics(array $abandoned): array
    {
        if ($abandoned === []) {
            return [];
        }

        $entries = [];
        $shown = 0;

        foreach ($abandoned as $package => $replacement) {
            if ($shown >= 10) {
                $entries[] = sprintf('+%d more', count($abandoned) - 10);

                break;
            }

            $entries[] = is_string($replacement)
                ? sprintf('%s (replacement suggested: %s)', $package, $replacement)
                : sprintf('%s (no replacement suggested)', $package);

            $shown++;
        }

        return [new AnalyzerDiagnostic(
            DiagnosticLevel::Info,
            sprintf('%d abandoned package(s) detected: %s', count($abandoned), implode(', ', $entries)),
        )];
    }

    /**
     * @return array<string,mixed>
     */
    private function advisoryToArray(ComposerAdvisory $advisory): array
    {
        return [
            'package_name' => $advisory->packageName,
            'advisory_id' => $advisory->advisoryId,
            'title' => $advisory->title,
            'affected_versions' => $advisory->affectedVersions,
            'cve' => $advisory->cve,
            'link' => $advisory->link,
            'severity' => $advisory->severity,
            'reported_at' => $advisory->reportedAt,
            'sources' => $advisory->sources,
        ];
    }

    private function extractVersion(string $output): ?string
    {
        // e.g. "Composer version 2.8.1 2024-11-08 16:39:55" — also matches
        // pre-release/build suffixes like "2.8.1-dev" or "2.8.1@abcdef1"
        // since only the leading X.Y.Z is captured.
        if (preg_match('/Composer(?:\s+version)?\s+(\d+\.\d+\.\d+)/i', $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function boundedExcerpt(string $text, int $limit = 2000): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }

    /**
     * @return array<string,string>
     */
    private function composerEnv(): array
    {
        $allowlist = config('laradogs.process.env_allowlist', []);
        $env = [];

        if (! is_array($allowlist)) {
            return $env;
        }

        foreach ($allowlist as $key) {
            if (! is_string($key)) {
                continue;
            }

            $value = getenv($key);

            if (is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
