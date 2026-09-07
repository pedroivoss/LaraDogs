<?php

namespace App\Audit\Analyzers\Npm;

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
 * The second real analyzer: runs `npm audit` against a project's locked
 * npm dependencies and normalizes its advisories into
 * {@see FindingCandidate}s. Deliberately mirrors the shape and safety
 * posture of `App\Audit\Analyzers\Composer\ComposerAuditAnalyzer`
 * (same Engine/Findings contracts, same fail-closed philosophy) without
 * sharing an abstract base class with it — the two tools' safety details
 * differ enough (npm's registry-redirection risk and per-user `.npmrc`
 * credential surface have no Composer equivalent) that a shared base
 * would either leak npm-specific concerns into Composer's analyzer or
 * hide npm-specific decisions behind generic-looking method names. See
 * docs/auditing/analyzers/npm-audit.md for the full design rationale
 * (schema, exit codes, registry/config security model, coverage policy)
 * verified against the real `npm` CLI rather than assumed.
 *
 * Security posture (see docs/development/process-execution.md and
 * ADR-0011):
 * - never runs `npm install`/`npm ci`/`npm audit fix` — `--package-lock-only`
 *   audits from the lockfile alone; confirmed empirically that plain
 *   `npm audit` never creates `node_modules` or runs lifecycle scripts
 *   even without this flag, which is added anyway as defense-in-depth;
 * - always passes `--ignore-scripts` as a second, redundant layer of the
 *   same guarantee;
 * - the executed binary is resolved by {@see NpmBinaryResolver} —
 *   LaraDogs' own config/PATH only, never `./node_modules/.bin/npm` from
 *   the target;
 * - the registry is always pinned via an explicit `--registry=` flag
 *   (`config('laradogs.npm.registry')`, CLI-flag precedence — the
 *   highest in npm's own config resolution order). Verified against the
 *   real, installed `AuditReport` source
 *   (`@npmcli/arborist/lib/audit-report.js`) that `npm audit` submits
 *   the ENTIRE dependency tree — scoped packages included — as one bulk
 *   request to a single registry (`options.registry`, i.e. exactly what
 *   this flag pins); a project's own `@scope:registry=` override (Phase
 *   4.2.1 research) therefore never affects an audit request at all, no
 *   matter what it points at — confirmed empirically with a local
 *   HTTP server that received zero requests during a real audit of a
 *   scoped dependency;
 * - the registry is also protected from a DIFFERENT vector (Phase 4.2.1
 *   finding): `--proxy=false --https-proxy=false --strict-ssl=true` are
 *   always passed, because a target's own `.npmrc` `proxy=`/
 *   `https-proxy=` setting genuinely DOES reroute the (correctly
 *   registry-pinned) audit request through a server the target
 *   controls — reproduced directly with a local server that received
 *   two real `CONNECT registry.npmjs.org:443` attempts from an
 *   unmitigated run, and zero once these flags were added;
 * - `NPM_CONFIG_USERCONFIG`/`NPM_CONFIG_CACHE` are always forced to
 *   LaraDogs-controlled paths — never the real `$HOME/.npmrc` — so a
 *   developer's own personal registry credentials can never reach this
 *   subprocess, regardless of what environment LaraDogs itself inherited;
 * - {@see NpmConfigInspector} statically checks the target's `.npmrc` for
 *   `cafile`/`cert`/`certfile`/`key`/`keyfile` — the one category of
 *   npmrc config not neutralized by a flag above (verified against
 *   source: `cafile` makes npm read an arbitrary file from disk) — and
 *   `run()` fails closed (never falsely `Passed`) if any is present.
 *
 * Coverage policy: always reports {@see AnalyzerCoverage::unknown()}, for
 * the same reason as `composer-audit` (see that analyzer's own docblock
 * and docs/auditing/analyzers/composer-audit.md#dependency-coverage-research-phase-41)
 * — npm's audit model has no "rules executed" universe either. This
 * remains true regardless of the Phase 4.2.1 hardening above: closing a
 * trust gap is not evidence of a "rules executed" universe suddenly
 * existing, so it does not change this policy.
 */
final class NpmAuditAnalyzer implements Analyzer, ProducesFindingCandidates
{
    /**
     * The `auditReportVersion: 2` JSON schema this analyzer's parser
     * targets was introduced alongside npm's "Bulk Advisory" endpoint in
     * npm 7 (verified against official npm CLI documentation) — npm 6's
     * audit output uses a materially different, older shape this parser
     * was never built against. A pre-7 binary is rejected here rather
     * than risked against a parser that was never verified against its
     * actual output.
     */
    private const string MIN_SUPPORTED_VERSION = '7.0.0';

    private ?string $resolvedBinary = null;

    private ?string $resolvedVersion = null;

    public function __construct(
        private readonly NpmBinaryResolver $binaryResolver,
        private readonly NpmAuditParser $parser,
        private readonly ProcessRunner $processRunner,
        private readonly NpmConfigInspector $configInspector = new NpmConfigInspector,
    ) {}

    public function id(): AnalyzerId
    {
        return new AnalyzerId('npm-audit');
    }

    public function name(): string
    {
        return 'Npm Audit';
    }

    public function category(): AnalyzerCategory
    {
        return AnalyzerCategory::Dependency;
    }

    public function applicability(ProjectProfile $profile): Applicability
    {
        if (! $profile->frontend->node->isDetected()) {
            return Applicability::notApplicable('No valid package.json detected — not an npm-auditable project.');
        }

        if (! $profile->frontend->npmLockfile->isDetected()) {
            return Applicability::notApplicable(
                'No package-lock.json or npm-shrinkwrap.json detected — npm audit requires an npm-native lockfile, '.
                'and LaraDogs never runs `npm install`/`npm ci` to create one. A yarn.lock or pnpm-lock.yaml alone '.
                'does not count: those are different package managers\' lockfiles, not npm\'s.',
            );
        }

        return Applicability::applicable();
    }

    public function availability(AuditContext $context): Availability
    {
        $binary = $this->binaryResolver->resolve();

        if ($binary === null) {
            return Availability::unavailable(
                'npm executable not found (configure laradogs.npm.binary or install npm on LaraDogs\' own PATH).',
            );
        }

        $result = $this->processRunner->run(new ProcessCommand(
            argv: [$binary, '--version'],
            workingDirectory: $context->projectPath,
            environment: $this->npmEnv(),
            timeoutSeconds: 5,
        ));

        if ($result->timedOut || $result->processStartFailed() || ! $result->successful()) {
            return Availability::unavailable('npm version check failed to complete.');
        }

        $version = $this->extractVersion($result->stdout);

        if ($version === null) {
            return Availability::unavailable('Could not determine the installed npm version from its `--version` output.');
        }

        if (version_compare($version, self::MIN_SUPPORTED_VERSION, '<')) {
            return Availability::unavailable(
                sprintf('npm %s found, but this analyzer requires >= %s.', $version, self::MIN_SUPPORTED_VERSION),
            );
        }

        $this->resolvedBinary = $binary;
        $this->resolvedVersion = $version;

        return Availability::available(sprintf('npm %s detected.', $version));
    }

    public function run(AuditContext $context): AnalyzerResult
    {
        $binary = $this->resolvedBinary ?? $this->binaryResolver->resolve();

        if ($binary === null) {
            return AnalyzerResult::failed(
                'npm executable could not be resolved.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, 'No npm binary configured or found on LaraDogs\' own PATH.')],
            );
        }

        $unsafeConfig = $this->inspectTargetConfig($context->projectPath);

        if ($unsafeConfig !== null) {
            return AnalyzerResult::failed(
                'UNSAFE_TARGET_NPM_CONFIG: the target\'s own .npmrc declares configuration this analyzer cannot safely neutralize.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, $unsafeConfig)],
            );
        }

        $timeout = (int) config('laradogs.npm.timeout_seconds', 30);
        $registry = (string) config('laradogs.npm.registry', 'https://registry.npmjs.org');
        $proxy = config('laradogs.npm.proxy');
        $httpsProxy = config('laradogs.npm.https_proxy');

        $result = $this->processRunner->run(new ProcessCommand(
            argv: [
                $binary, 'audit',
                '--json',
                '--package-lock-only',
                '--ignore-scripts',
                '--registry='.$registry,
                // Phase 4.2.1: a target's own .npmrc `proxy=`/`https-proxy=`
                // can reroute this (correctly registry-pinned) request
                // through a server it controls — verified empirically.
                // Trust for a real proxy comes only from LaraDogs' own
                // config, never the target; left unconfigured, both are
                // forced off outright.
                '--proxy='.(is_string($proxy) && $proxy !== '' ? $proxy : 'false'),
                '--https-proxy='.(is_string($httpsProxy) && $httpsProxy !== '' ? $httpsProxy : 'false'),
                '--strict-ssl=true',
            ],
            workingDirectory: $context->projectPath,
            environment: $this->npmEnv(),
            timeoutSeconds: $timeout,
        ));

        if ($result->timedOut) {
            return AnalyzerResult::timedOut(
                sprintf('npm audit timed out after %ds.', $timeout),
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, 'Process timed out before completing.')],
            );
        }

        if ($result->processStartFailed()) {
            return AnalyzerResult::failed(
                'Failed to start the npm audit process.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, $this->boundedExcerpt($result->stderr))],
            );
        }

        if ($result->outputTruncated) {
            return AnalyzerResult::failed(
                'npm audit output exceeded the captured output limit; results cannot be trusted as complete.',
                [new AnalyzerDiagnostic(DiagnosticLevel::Error, 'Output was truncated before it could be fully captured.')],
                ['exit_code' => $result->exitCode],
            );
        }

        // Deliberately NOT gating on $result->exitCode: npm audit exits
        // non-zero whenever any vulnerability at or above the "low"
        // threshold is found (npm's own default audit-level) — a normal,
        // informative outcome, not an analyzer failure. A registry/network
        // failure produces a COMPLETELY different JSON shape (verified
        // empirically — no `vulnerabilities`/`metadata`/`auditReportVersion`
        // keys at all), which the parser below rejects the same way it
        // would reject any other malformed output — there is no separate
        // "unreachable registry" signal to check first, unlike Composer.
        $report = $this->parser->parse($result->stdout);

        if ($report === null) {
            return AnalyzerResult::failed(
                'npm audit produced output that could not be parsed as a valid audit report.',
                [new AnalyzerDiagnostic(
                    DiagnosticLevel::Error,
                    $this->boundedExcerpt($result->stdout !== '' ? $result->stdout : $result->stderr),
                )],
                ['exit_code' => $result->exitCode],
            );
        }

        return AnalyzerResult::passed(
            sprintf(
                '%d advisor%s found across locked dependencies.',
                count($report->advisories),
                count($report->advisories) === 1 ? 'y' : 'ies',
            ),
            [],
            [
                'exit_code' => $result->exitCode,
                'advisories' => array_map($this->advisoryToArray(...), $report->advisories),
                'severity_counts' => $report->severityCounts,
                'npm_version' => $this->resolvedVersion,
            ],
            // See this class's docblock: npm gives no explicit "rules
            // executed" universe either, so coverage always stays Unknown.
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

        $npmVersion = is_string($result->rawMetadata['npm_version'] ?? null)
            ? $result->rawMetadata['npm_version']
            : null;

        $candidates = [];

        foreach ($advisories as $advisory) {
            if (is_array($advisory)) {
                $candidates[] = $this->toCandidate($advisory, $npmVersion);
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $advisory
     */
    private function toCandidate(array $advisory, ?string $analyzerVersion): FindingCandidate
    {
        $packageName = is_string($advisory['package_name'] ?? null) ? $advisory['package_name'] : 'unknown-package';
        $source = is_int($advisory['source'] ?? null) ? $advisory['source'] : 0;
        $title = is_string($advisory['title'] ?? null) ? $advisory['title'] : sprintf('Security advisory for %s', $packageName);
        $range = is_string($advisory['range'] ?? null) ? $advisory['range'] : null;
        $url = is_string($advisory['url'] ?? null) ? $advisory['url'] : null;

        return new FindingCandidate(
            // Package name + advisory source id: stable and unique even
            // though `source` alone (an internal npm-audit-report id) is
            // very likely already globally unique — folding in the
            // package name removes any dependency on that scheme's
            // uniqueness guarantee holding forever, mirroring the same
            // choice made for composer-audit's rule identity.
            ruleId: sprintf('%s:%d', $packageName, $source),
            analyzerId: (string) $this->id(),
            category: $this->category(),
            severity: $this->mapSeverity($advisory['severity'] ?? null),
            // Always High: this candidate exists only because npm matched
            // a real advisory against a locked package version — no
            // heuristic/partial match to hedge against here. High reflects
            // confidence in the MATCH, not the advisory's real-world
            // impact (that's severity's job).
            confidence: Confidence::High,
            title: $title,
            description: $range !== null
                ? sprintf('Affects %s (range: %s).', $packageName, $range)
                : sprintf('Affects %s.', $packageName),
            // npm's audit schema does not surface a CVE identifier
            // directly (verified empirically — only a GHSA-style `url`
            // and a `cwe` list) — left null rather than derived/guessed
            // from the URL.
            cve: null,
            references: $url !== null ? [$url] : [],
            metadata: [
                'package_name' => $packageName,
                'source' => $source,
                'is_direct' => (bool) ($advisory['is_direct'] ?? false),
                'cwe' => is_array($advisory['cwe'] ?? null) ? $advisory['cwe'] : [],
                'range' => $range,
                'package_range' => is_string($advisory['package_range'] ?? null) ? $advisory['package_range'] : null,
                // Informational only — never acted on. See this class's
                // docblock and docs/auditing/analyzers/npm-audit.md:
                // LaraDogs never runs `npm audit fix`/install/update.
                'fix_available' => $advisory['fix_available'] ?? false,
                'nodes' => is_array($advisory['nodes'] ?? null) ? $advisory['nodes'] : [],
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
            'moderate' => Severity::Medium,
            'low' => Severity::Low,
            'info' => Severity::Info,
            default => Severity::Unknown,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function advisoryToArray(NpmAdvisory $advisory): array
    {
        return [
            'package_name' => $advisory->packageName,
            'is_direct' => $advisory->isDirect,
            'source' => $advisory->source,
            'title' => $advisory->title,
            'url' => $advisory->url,
            'severity' => $advisory->severity,
            'cwe' => $advisory->cwe,
            'range' => $advisory->range,
            'package_range' => $advisory->packageRange,
            'fix_available' => $advisory->fixAvailable,
            'nodes' => $advisory->nodes,
        ];
    }

    private function extractVersion(string $output): ?string
    {
        // Plain `npm --version` output is just the version number itself
        // (e.g. "10.9.7"), unlike Composer's "Composer version X.Y.Z ..."
        // — verified directly, not assumed from familiarity with Composer.
        if (preg_match('/(\d+\.\d+\.\d+)/', trim($output), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Returns a human-readable diagnostic message when the target's own
     * `.npmrc` cannot be safely audited against (either it declares TLS
     * trust material this analyzer has no way to verify, or it's too
     * large to inspect at all) — `null` when it's safe to proceed.
     * Deliberately never includes any matched line's VALUE (only the key
     * names), and never reads/logs the `.npmrc`'s content beyond what
     * {@see NpmConfigInspector} itself already bounds and redacts.
     */
    private function inspectTargetConfig(string $projectPath): ?string
    {
        try {
            $dangerousKeys = $this->configInspector->inspect($projectPath);
        } catch (NpmConfigTooLargeException) {
            return 'The target\'s .npmrc exceeds the size this analyzer can safely inspect.';
        }

        if ($dangerousKeys === []) {
            return null;
        }

        return sprintf(
            'The target\'s .npmrc declares TLS trust material this analyzer cannot verify is safe: %s. '.
            'Values are never read into this diagnostic.',
            implode(', ', $dangerousKeys),
        );
    }

    private function boundedExcerpt(string $text, int $limit = 2000): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }

    /**
     * @return array<string,string>
     */
    private function npmEnv(): array
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
        // forwarded-if-set like the allowlist above. A developer's own
        // personal $HOME/.npmrc (which may carry real registry auth
        // tokens) must never reach this subprocess, in Docker or locally.
        // Verified empirically: pointing NPM_CONFIG_USERCONFIG at a path
        // that doesn't even exist yet is sufficient — npm treats a
        // missing per-user config file as "no per-user config", not an
        // error (see docs/auditing/analyzers/npm-audit.md).
        $env['NPM_CONFIG_USERCONFIG'] = (string) config('laradogs.npm.userconfig_path');
        $env['NPM_CONFIG_CACHE'] = (string) config('laradogs.npm.cache_path');

        return $env;
    }
}
