<?php

namespace App\Audit\Analyzers\Semgrep;

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
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Audit\Findings\Severity;

/**
 * The third real analyzer, and the first Static Application Security
 * Testing (SAST) one: runs LaraDogs' own small, bundled Semgrep ruleset
 * ({@see SemgrepRuleCatalog}) against a project's first-party PHP source
 * and normalizes matches into {@see FindingCandidate}s. See
 * docs/auditing/analyzers/semgrep.md for the full design rationale,
 * verified against the real, locally-installed `semgrep` CLI (v1.176.0)
 * rather than assumed.
 *
 * Deliberately mirrors the shape and fail-closed posture of
 * `App\Audit\Analyzers\Composer\ComposerAuditAnalyzer` and
 * `App\Audit\Analyzers\Npm\NpmAuditAnalyzer` (same Engine/Findings
 * contracts) without sharing a base class — Semgrep's safety details
 * (rule-source trust, target-side ignore-file bypass, symlink handling,
 * coverage semantics) are different enough from a dependency-advisory
 * scanner that a shared abstraction would hide analyzer-specific
 * decisions behind generic-looking method names, the same judgment call
 * already made for Composer vs. npm.
 *
 * Security posture (see docs/development/process-execution.md, ADR-0011,
 * and this phase's own ADR-0012):
 * - the executed binary is resolved by {@see SemgrepBinaryResolver} —
 *   LaraDogs' own config/PATH only, never anything from the target (a
 *   target's own `.venv`/`node_modules`/`vendor` is never consulted);
 * - **rules are never sourced from the target.** Only
 *   `SemgrepRuleCatalog::rulesFilePath()` — a file LaraDogs itself ships —
 *   is ever passed via `--config`. A target's own `.semgrep.yml`/
 *   `.semgrep.yaml` is never read, referenced, or auto-discovered.
 *   `--config auto` (which logs in to the Semgrep Registry — verified
 *   against the real CLI's own help text) is never used;
 * - **targets are an explicit file list, never a directory.** Verified
 *   empirically that pointing Semgrep at a directory lets the target's own
 *   `.semgrepignore` hide a file from analysis entirely (a real,
 *   genuinely-vulnerable fixture file disappeared from `results`/
 *   `paths.scanned` once listed there) — {@see SemgrepTargetCollector}
 *   performs LaraDogs' own bounded, symlink-rejecting, realpath-contained
 *   walk instead, and every collected path is passed directly as a
 *   `semgrep scan` argv target, bypassing `.semgrepignore`/`.gitignore`
 *   entirely (verified: an explicitly-named target is scanned regardless
 *   of what either file says about it);
 * - `--metrics=off` plus `SEMGREP_SEND_METRICS=off` (belt-and-suspenders —
 *   the CLI flag alone is authoritative, verified against the real help
 *   text) disable all telemetry; no scan depends on network reachability;
 * - `SEMGREP_APP_TOKEN` (Semgrep's own login/API-token environment
 *   variable, confirmed by reading the installed CLI's own
 *   `semgrep/app/auth.py` source) is never forwarded — this analyzer's env
 *   is an explicit allowlist (`config('laradogs.process.env_allowlist')`),
 *   and that variable is never in it;
 * - `SEMGREP_SETTINGS_FILE` is always forced to a LaraDogs-controlled path
 *   (never the real invoking user's `~/.semgrep/settings.yaml`, confirmed
 *   by reading the installed CLI's own `semgrep/settings.py` resolution
 *   order) — mirrors the same pattern already used for
 *   `NPM_CONFIG_USERCONFIG`/`COMPOSER_HOME`;
 * - `--oss-only` forces the OSS engine explicitly (defense in depth against
 *   an operator's Semgrep install having Pro-engine behavior toggled on);
 * - target PHP source is only ever read as data — Semgrep parses it for
 *   pattern matching, it is never executed, included, or evaluated by
 *   LaraDogs or by Semgrep itself.
 *
 * Coverage policy: see {@see SemgrepCoverageEvaluator} — the first analyzer
 * in this codebase that can honestly declare
 * {@see AnalyzerCoverage::explicit()}, precisely because (unlike Composer/
 * npm's dependency-advisory model) this bundled ruleset IS a fixed,
 * enumerable "rules executed" universe. Only declared when the run
 * reported zero `errors[]` and no non-benign `paths.skipped[]` reason —
 * {@see CoverageMode::Unknown} otherwise, per this phase's "se houver
 * dúvida: UNKNOWN" policy.
 */
final class SemgrepAnalyzer implements Analyzer, ProducesFindingCandidates
{
    /**
     * The only version this analyzer's JSON schema assumptions have been
     * verified against (reproduced directly against the real, installed
     * CLI — see docs/auditing/analyzers/semgrep.md). Deliberately NOT
     * derived from "when a feature shipped" the way Composer's/npm's
     * floors are — Semgrep's own versioning/changelog granularity for the
     * exact fields this parser depends on (`paths.skipped[].reason`
     * requiring `--verbose`, the dual severity vocabulary) was not
     * independently confirmed across a version range, so this floor is
     * conservative by construction rather than research-backed the same
     * way. Bump only after re-verifying against a newly-tested version.
     */
    private const string MIN_SUPPORTED_VERSION = '1.176.0';

    private const int MAX_SNIPPET_SOURCE_BYTES = 2_000_000;

    private const int MAX_SNIPPET_LINES = 30;

    private ?string $resolvedBinary = null;

    private ?string $resolvedVersion = null;

    public function __construct(
        private readonly SemgrepBinaryResolver $binaryResolver,
        private readonly SemgrepParser $parser,
        private readonly SemgrepTargetCollector $targetCollector,
        private readonly SemgrepCoverageEvaluator $coverageEvaluator,
        private readonly ProcessRunner $processRunner,
        private readonly EvidenceRedactor $redactor = new EvidenceRedactor,
    ) {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId('semgrep');
    }

    public function name(): string
    {
        return 'Semgrep';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Security;
    }

    public function applicability(ProjectProfile $profile): Applicability
    {
        if (! $profile->backend->php->isDetected()) {
            return Applicability::notApplicable(
                'No PHP detected — this bundled ruleset only declares PHP rules (see SemgrepRuleCatalog).',
            );
        }

        return Applicability::applicable();
    }

    public function availability(AuditContext $context): Availability
    {
        $binary = $this->binaryResolver->resolve();

        if ($binary === null) {
            return Availability::unavailable(
                'semgrep executable not found (configure laradogs.semgrep.binary or install semgrep on LaraDogs\' own PATH).',
            );
        }

        $result = $this->processRunner->run(new ProcessCommand(
            argv: [$binary, '--version'],
            workingDirectory: dirname(SemgrepRuleCatalog::rulesFilePath()),
            environment: $this->semgrepEnv(),
            timeoutSeconds: 5,
        ));

        if ($result->timedOut || $result->processStartFailed() || ! $result->successful()) {
            return Availability::unavailable('semgrep version check failed to complete.');
        }

        $version = $this->extractVersion($result->stdout);

        if ($version === null) {
            return Availability::unavailable('Could not determine the installed semgrep version from its `--version` output.');
        }

        if (version_compare($version, self::MIN_SUPPORTED_VERSION, '<')) {
            return Availability::unavailable(
                sprintf('semgrep %s found, but this analyzer requires >= %s.', $version, self::MIN_SUPPORTED_VERSION),
            );
        }

        $this->resolvedBinary = $binary;
        $this->resolvedVersion = $version;

        return Availability::available(sprintf('semgrep %s detected.', $version));
    }

    public function run(AuditContext $context): AnalyzerResult
    {
        $binary = $this->resolvedBinary ?? $this->binaryResolver->resolve();

        if ($binary === null) {
            return AnalyzerResult::failed(
                'semgrep executable could not be resolved.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, 'No semgrep binary configured or found on LaraDogs\' own PATH.')],
            );
        }

        $ruleIds = SemgrepRuleCatalog::ruleIds();
        $files = $this->targetCollector->collect($context->projectPath);

        if ($files === []) {
            // A genuinely empty, bounded scope (no PHP files LaraDogs'
            // own walker could find, after excluding vendor/node_modules/
            // etc.) is trivially fully covered — every rule in the
            // ruleset was "verified" against everything in scope, because
            // scope is empty. See SemgrepCoverageEvaluator's docblock.
            return AnalyzerResult::passed(
                'No first-party PHP files found to scan.',
                [],
                ['files_collected' => 0, 'findings' => [], 'semgrep_version' => $this->resolvedVersion],
                AnalyzerCoverage::explicit($ruleIds, SemgrepRuleCatalog::RULESET_VERSION),
            );
        }

        $rulesFile = SemgrepRuleCatalog::rulesFilePath();
        $timeout = (int) config('laradogs.semgrep.timeout_seconds', 60);
        $perFileTimeout = (int) config('laradogs.semgrep.per_file_timeout_seconds', 5);
        $maxTargetBytes = (int) config('laradogs.semgrep.max_target_bytes', 1_000_000);

        $result = $this->processRunner->run(new ProcessCommand(
            argv: [
                $binary, 'scan',
                '--config', basename($rulesFile),
                '--json',
                '--verbose',
                '--metrics=off',
                '--no-git-ignore',
                '--oss-only',
                '--timeout='.$perFileTimeout,
                '--max-target-bytes='.$maxTargetBytes,
                ...$files,
            ],
            // The bundled rules' own directory, not the target's project
            // path — deliberately, so `--config` can be a bare filename
            // (see SemgrepParser's docblock on rule-identity/check_id).
            // Target files are passed as absolute paths above regardless
            // of cwd.
            workingDirectory: dirname($rulesFile),
            environment: $this->semgrepEnv(),
            timeoutSeconds: $timeout,
        ));

        if ($result->timedOut) {
            return AnalyzerResult::timedOut(
                sprintf(
                    'semgrep scan timed out after %ds — the scan was incomplete, so no findings are '.
                    'reported and coverage is Unknown (nothing was auto-resolved either). Large projects '.
                    '(hundreds of first-party PHP/Blade files) can genuinely need more than %ds — raise '.
                    'LARADOGS_SEMGREP_TIMEOUT_SECONDS in LaraDogs\' own environment (never the target\'s) '.
                    'if this keeps happening. See docs/auditing/analyzers/semgrep.md#performance.',
                    $timeout,
                    $timeout,
                ),
                [new AnalyzerDiagnostic(
                    DiagnosticLevel::Error,
                    sprintf('Process timed out before completing (%d file(s) collected, %ds limit).', count($files), $timeout),
                )],
            );
        }

        if ($result->processStartFailed()) {
            return AnalyzerResult::failed(
                'Failed to start the semgrep scan process.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, $this->boundedExcerpt($result->stderr))],
            );
        }

        if ($result->outputTruncated) {
            return AnalyzerResult::failed(
                'semgrep output exceeded the captured output limit; results cannot be trusted as complete.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, 'Output was truncated before it could be fully captured.')],
                ['exit_code' => $result->exitCode],
            );
        }

        // Unlike Composer/npm, a non-zero exit here is NOT a normal
        // "findings found" outcome — verified empirically (v1.176.0):
        // `semgrep scan` exits 0 whenever it completed, whether or not it
        // produced results, and non-zero specifically for a config/rule
        // load failure (invalid rule YAML: exit 7) or a CLI usage error
        // (exit 2). Never gated on findings count.
        if (! $result->successful()) {
            return AnalyzerResult::failed(
                'semgrep scan exited non-zero — this indicates a configuration or tool failure, not findings.',
                [new AnalyzerDiagnostic(
                    DiagnosticLevel::Error,
                    $this->boundedExcerpt($result->stderr !== '' ? $result->stderr : $result->stdout),
                )],
                ['exit_code' => $result->exitCode],
            );
        }

        $report = $this->parser->parse($result->stdout, $ruleIds);

        if ($report === null) {
            return AnalyzerResult::failed(
                'semgrep scan produced output that could not be parsed as a valid JSON report.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, $this->boundedExcerpt($result->stdout))],
                ['exit_code' => $result->exitCode],
            );
        }

        $coverage = $this->coverageEvaluator->isFullyCovered($report)
            ? AnalyzerCoverage::explicit($ruleIds, SemgrepRuleCatalog::RULESET_VERSION)
            : AnalyzerCoverage::unknown(SemgrepRuleCatalog::RULESET_VERSION);

        return AnalyzerResult::passed(
            sprintf(
                '%d finding(s) across %d PHP file(s) scanned (coverage: %s).',
                count($report->findings),
                count($report->scanned),
                $coverage->mode->value,
            ),
            $this->operationalDiagnostics($report),
            [
                'exit_code' => $result->exitCode,
                'semgrep_version' => $this->resolvedVersion,
                'project_path' => $context->projectPath,
                'files_collected' => count($files),
                'findings' => array_map($this->findingToArray(...), $report->findings),
            ],
            $coverage,
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

        $findings = $result->rawMetadata['findings'] ?? null;

        if (! is_array($findings)) {
            return [];
        }

        $projectPath = is_string($result->rawMetadata['project_path'] ?? null)
            ? $result->rawMetadata['project_path']
            : $context->projectPath;

        $analyzerVersion = is_string($result->rawMetadata['semgrep_version'] ?? null)
            ? $result->rawMetadata['semgrep_version']
            : null;

        $candidates = [];

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $candidate = $this->toCandidate($finding, $projectPath, $analyzerVersion);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function toCandidate(array $finding, string $projectPath, ?string $analyzerVersion): ?FindingCandidate
    {
        $ruleId = is_string($finding['rule_id'] ?? null) ? $finding['rule_id'] : null;
        $path = is_string($finding['path'] ?? null) ? $finding['path'] : null;

        if ($ruleId === null || $path === null) {
            return null;
        }

        $relativePath = $this->normalizePath($projectPath, $path);

        if ($relativePath === null) {
            // A result pointing outside the project root should never
            // happen (SemgrepTargetCollector only ever feeds Semgrep
            // paths it collected from inside the root) — fail closed by
            // dropping it rather than persisting an absolute host path.
            return null;
        }

        $catalogRule = SemgrepRuleCatalog::find($ruleId);
        $category = $catalogRule !== null ? $catalogRule->category : AnalyzerCategory::Quality;
        $confidence = $catalogRule !== null ? $catalogRule->confidence : Confidence::Low;

        $startLine = is_int($finding['start_line'] ?? null) ? $finding['start_line'] : 0;
        $endLine = is_int($finding['end_line'] ?? null) ? $finding['end_line'] : $startLine;
        $message = is_string($finding['message'] ?? null) ? $finding['message'] : '';
        $metadata = is_array($finding['metadata'] ?? null) ? $finding['metadata'] : [];

        return new FindingCandidate(
            ruleId: $ruleId,
            analyzerId: (string) $this->id(),
            category: $category,
            severity: $this->mapSeverity(is_string($finding['raw_severity'] ?? null) ? $finding['raw_severity'] : ''),
            confidence: $confidence,
            title: $this->titleFor($ruleId, $message),
            description: $message !== '' ? $message : null,
            recommendation: is_string($metadata['remediation'] ?? null) ? $metadata['remediation'] : null,
            filePath: $relativePath,
            lineStart: $startLine > 0 ? $startLine : null,
            lineEnd: $endLine > 0 ? $endLine : null,
            codeSnippet: $this->redactor->redact($this->readSnippet($path, $startLine, $endLine)),
            cwe: $this->firstStringFrom($metadata['cwe'] ?? null),
            references: $this->listOfStringsFrom($metadata['references'] ?? null),
            metadata: [
                'start_column' => $finding['start_column'] ?? null,
                'end_column' => $finding['end_column'] ?? null,
                'raw_severity' => $finding['raw_severity'] ?? null,
            ],
            ruleVersion: SemgrepRuleCatalog::RULESET_VERSION,
            analyzerVersion: $analyzerVersion,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function findingToArray(SemgrepFinding $finding): array
    {
        return [
            'rule_id' => $finding->ruleId,
            'path' => $finding->path,
            'start_line' => $finding->startLine,
            'start_column' => $finding->startColumn,
            'end_line' => $finding->endLine,
            'end_column' => $finding->endColumn,
            'message' => $finding->message,
            'raw_severity' => $finding->rawSeverity,
            'metadata' => $finding->metadata,
        ];
    }

    /**
     * @return list<AnalyzerDiagnostic>
     */
    private function operationalDiagnostics(SemgrepScanReport $report): array
    {
        $diagnostics = [];

        foreach (array_slice($report->errors, 0, 10) as $error) {
            $diagnostics[] = new AnalyzerDiagnostic(
                DiagnosticLevel::Warning,
                sprintf('semgrep reported %s: %s', $error['type'], $this->boundedExcerpt($error['message'], 500)),
            );
        }

        $nonBenignSkips = array_filter(
            $report->skipped,
            static fn (array $skip): bool => ! in_array($skip['reason'], ['wrong_language', 'excluded_by_config'], true),
        );

        foreach (array_slice(array_values($nonBenignSkips), 0, 10) as $skip) {
            $diagnostics[] = new AnalyzerDiagnostic(
                DiagnosticLevel::Warning,
                sprintf('semgrep skipped a file (%s): %s', $skip['reason'], $skip['path']),
            );
        }

        return $diagnostics;
    }

    private function mapSeverity(string $raw): Severity
    {
        return match (strtoupper($raw)) {
            'CRITICAL' => Severity::Critical,
            'ERROR', 'HIGH' => Severity::High,
            'WARNING', 'MEDIUM' => Severity::Medium,
            'LOW' => Severity::Low,
            'INFO' => Severity::Info,
            default => Severity::Unknown,
        };
    }

    private function titleFor(string $ruleId, string $message): string
    {
        $firstLine = trim((string) strtok($message, "\n"));

        return $firstLine !== '' ? $firstLine : sprintf('Semgrep rule %s matched.', $ruleId);
    }

    /**
     * Normalizes an absolute path Semgrep reported back to project-relative
     * — persisted paths must never be an absolute host path (see this
     * class's own docblock and docs/auditing/analyzers/semgrep.md). Returns
     * null when the path does not fall within the project root at all.
     */
    private function normalizePath(string $projectPath, string $absolutePath): ?string
    {
        $root = rtrim($projectPath, '/');

        if ($absolutePath === $root) {
            return '';
        }

        if (str_starts_with($absolutePath, $root.'/')) {
            return substr($absolutePath, strlen($root) + 1);
        }

        return null;
    }

    /**
     * Reads a bounded snippet directly from the source file using the
     * start/end line Semgrep reported — Semgrep's OWN `extra.lines` field
     * is deliberately never used: verified empirically that it (and
     * `extra.fingerprint`) literally contain the string `"requires login"`
     * when Semgrep is run unauthenticated (LaraDogs V1's only supported
     * mode — no Semgrep account is ever required). Bounded by both a
     * maximum source-file size (files larger than this were already at
     * risk of being skipped by `--max-target-bytes` anyway, so any file
     * that DID produce a finding is expected to be well under this cap)
     * and a maximum number of lines, so one giant match can't pull an
     * unbounded snippet into a Finding.
     */
    private function readSnippet(string $absolutePath, int $startLine, int $endLine): ?string
    {
        if ($startLine < 1) {
            return null;
        }

        $size = @filesize($absolutePath);

        if ($size === false || $size > self::MAX_SNIPPET_SOURCE_BYTES) {
            return null;
        }

        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents);

        if ($lines === false || $startLine > count($lines)) {
            return null;
        }

        $cappedEnd = min(max($endLine, $startLine), $startLine + self::MAX_SNIPPET_LINES - 1, count($lines));
        $slice = array_slice($lines, $startLine - 1, $cappedEnd - $startLine + 1);

        return $slice === [] ? null : implode("\n", $slice);
    }

    private function firstStringFrom(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && is_string($value[0] ?? null)) {
            return $value[0];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function listOfStringsFrom(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private function extractVersion(string $output): ?string
    {
        if (preg_match('/(\d+\.\d+\.\d+)/', trim($output), $matches) === 1) {
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
    private function semgrepEnv(): array
    {
        $allowlist = config('laradogs.process.env_allowlist', []);
        $env = [];

        if (is_array($allowlist)) {
            foreach ($allowlist as $key) {
                if (! is_string($key)) {
                    continue;
                }

                $value = getenv($key);

                if (is_string($value)) {
                    $env[$key] = $value;
                }
            }
        }

        // Security-critical, unconditional overrides — never merely
        // forwarded-if-set like the allowlist above. `SEMGREP_APP_TOKEN`
        // (Semgrep's own login/API-token variable — confirmed by reading
        // the installed CLI's own `semgrep/app/auth.py` source) is
        // deliberately never added to `laradogs.process.env_allowlist` in
        // the first place, so it is never forwarded regardless of what
        // LaraDogs' own process happens to have set. `SEMGREP_SETTINGS_FILE`
        // is always forced so a developer's own real `~/.semgrep/settings.yaml`
        // (which may carry a stored login/anonymous id) is never read.
        $env['SEMGREP_SETTINGS_FILE'] = (string) config('laradogs.semgrep.settings_path');
        $env['SEMGREP_SEND_METRICS'] = 'off';

        return $env;
    }
}
