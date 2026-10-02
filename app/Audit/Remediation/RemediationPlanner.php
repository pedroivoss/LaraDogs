<?php

namespace App\Audit\Remediation;

use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Redaction\OutputSanitizer;
use App\Audit\Source\Git\GitSnapshot;

/**
 * Pure, deterministic transformation: {@see RemediationEvidence} in,
 * {@see RemediationPlan} out (Phase 12).
 *
 * No database, no filesystem, no process, no network, no model — and it never
 * evaluates, executes or interpolates evidence: text taken from the audited
 * project is sanitized and confined to the `finding`/`evidence` fields, while
 * the guidance itself is composed only from LaraDogs-authored constants
 * ({@see RuleRemediationCatalog}, the templates below) and from a few
 * strictly-validated identifiers (package name, version range).
 *
 * Guidance describes CURRENT LaraDogs guidance for the STORED finding facts:
 * it is recomputed on every request, so improved wording reaches old findings
 * without rewriting any history. Historical scan and Quality Gate facts are
 * untouched.
 */
final class RemediationPlanner
{
    public const int MAX_STEPS = 8;

    public const int MAX_VALIDATION = 8;

    public const int MAX_REFERENCES = 10;

    public const int MAX_WARNINGS = 10;

    public const int MAX_LIMITATIONS = 5;

    public const int MAX_SUMMARY = 300;

    public const int MAX_ACTION = 1500;

    public const int MAX_STEP_TEXT = 400;

    private const string COMPOSER = 'composer-audit';

    private const string NPM = 'npm-audit';

    private const string PACKAGE_COMPOSER = '/^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/';

    private const string PACKAGE_NPM = '/^(?:@[a-z0-9~][a-z0-9._~-]*\/)?[a-z0-9~][a-z0-9._~-]*$/';

    private const string VERSION_RANGE = '/^[0-9A-Za-z.,|<>=!~^*+\s-]{1,200}$/';

    private const string SEMVER = '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]{1,50})?$/';

    private const string ADVISORY_ID = '/^[A-Za-z0-9][A-Za-z0-9:_.-]{0,99}$/';

    public function __construct(private OutputSanitizer $sanitizer = new OutputSanitizer) {}

    public function plan(RemediationEvidence $e): RemediationPlan
    {
        $dependency = $this->dependency($e);
        $catalog = $this->catalogGuidance($e);
        $guidance = $this->guidance($e, $catalog, $dependency);
        $sourceState = RemediationSourceAssessor::assess($e->observedSource, $e->currentSource);
        $impact = GateImpactAssessor::assess($e->findingId, $e->gate);

        return new RemediationPlan(
            findingId: $e->findingId,
            projectId: $e->projectId,
            ruleId: $this->sanitizer->text($e->ruleId, OutputSanitizer::MAX_TITLE) ?? '',
            automationLevel: AutomationLevel::GuidanceOnly,
            guidanceAvailable: $guidance['available'],
            guidance: $guidance['body'],
            finding: $this->findingBlock($e),
            dependency: $dependency === null ? null : $dependency['block'],
            evidence: $this->evidenceBlock($e),
            validation: $this->validation($e, $impact),
            references: SafeReference::normalizeAll($e->references, self::MAX_REFERENCES),
            warnings: $this->warnings($e, $sourceState, $impact, $guidance['available'], $dependency),
            lifecycle: $this->lifecycle($e->status),
            source: $this->sourceBlock($sourceState, $e->observedSource, $e->currentSource),
            qualityGate: $this->gateBlock($impact, $e->gate),
            context: $this->context($e),
            provenance: [
                'guidance_basis' => 'current LaraDogs guidance for the stored finding facts',
                'rule_version' => $this->sanitizer->text($e->ruleVersion, 50),
                'analyzer_version' => $this->sanitizer->text($e->analyzerVersion, 50),
            ],
            status: $e->status,
        );
    }

    // -- guidance ---------------------------------------------------------

    /**
     * Only a LaraDogs-OWNED rule (bundled semgrep ruleset) has trusted
     * guidance. The finding's own persisted `recommendation` is deliberately
     * NOT used: it is stored data, never a LaraDogs constant.
     *
     * @return array{summary: string, action: string, steps: list<string>, limitations: list<string>, laravel: bool}|null
     */
    private function catalogGuidance(RemediationEvidence $e): ?array
    {
        return $e->analyzerId === 'semgrep' ? RuleRemediationCatalog::find($e->ruleId) : null;
    }

    /**
     * @param  array{summary: string, action: string, steps: list<string>, limitations: list<string>, laravel: bool}|null  $catalog
     * @param  array{block: array<string,mixed>, summary: string, steps: list<string>, limitations: list<string>}|null  $dependency
     * @return array{available: bool, body: array<string,mixed>}
     */
    private function guidance(RemediationEvidence $e, ?array $catalog, ?array $dependency): array
    {
        if ($catalog !== null) {
            $limitations = $catalog['limitations'];

            if ($catalog['laravel'] && $this->profileType($e) !== null && $this->profileType($e) !== 'laravel') {
                $limitations[] = 'This guidance uses Laravel APIs, but the project profile does not identify a Laravel project; adapt it to the framework in use.';
            }

            return ['available' => true, 'body' => [
                'source' => 'rule_catalog',
                'summary' => $catalog['summary'],
                'recommended_action' => mb_substr($catalog['action'], 0, self::MAX_ACTION),
                'steps' => $this->numbered($catalog['steps']),
                'limitations' => array_slice($limitations, 0, self::MAX_LIMITATIONS),
            ]];
        }

        if ($dependency !== null) {
            return ['available' => true, 'body' => [
                'source' => 'dependency_advisory',
                'summary' => $dependency['summary'],
                'recommended_action' => $dependency['summary'],
                'steps' => $this->numbered($dependency['steps']),
                'limitations' => array_slice($dependency['limitations'], 0, self::MAX_LIMITATIONS),
            ]];
        }

        return ['available' => false, 'body' => [
            'source' => 'none',
            'summary' => 'No rule-specific remediation guidance is available for this finding.',
            'recommended_action' => null,
            'steps' => [],
            'limitations' => ['LaraDogs has no remediation guidance for this rule; the finding\'s own description (untrusted source data) is the only detail available.'],
        ]];
    }

    /**
     * @param  list<string>  $steps
     * @return list<array{order: int, text: string}>
     */
    private function numbered(array $steps): array
    {
        $out = [];

        foreach (array_slice($steps, 0, self::MAX_STEPS) as $i => $text) {
            $out[] = ['order' => $i + 1, 'text' => mb_substr($text, 0, self::MAX_STEP_TEXT)];
        }

        return $out;
    }

    // -- dependency advisories (composer / npm) -----------------------------

    /**
     * Factual dependency guidance from PERSISTED advisory evidence only. No
     * registry lookup, no fixed version is invented; identifiers interpolated
     * into text are validated against strict patterns first.
     *
     * @return array{block: array<string,mixed>, summary: string, steps: list<string>, limitations: list<string>}|null
     */
    private function dependency(RemediationEvidence $e): ?array
    {
        if ($e->analyzerId !== self::COMPOSER && $e->analyzerId !== self::NPM) {
            return null;
        }

        $npm = $e->analyzerId === self::NPM;
        $rawName = $e->metadata['package_name'] ?? null;
        $package = is_string($rawName) && preg_match($npm ? self::PACKAGE_NPM : self::PACKAGE_COMPOSER, $rawName) === 1 && strlen($rawName) <= 214 ? $rawName : null;

        $rawRange = $npm ? ($e->metadata['range'] ?? null) : ($e->metadata['affected_versions'] ?? null);
        $affected = is_string($rawRange) && preg_match(self::VERSION_RANGE, $rawRange) === 1 ? trim($rawRange) : null;

        $advisory = $npm ? ($e->metadata['source'] ?? null) : ($e->metadata['advisory_id'] ?? null);
        $advisoryId = (is_int($advisory) || (is_string($advisory) && preg_match(self::ADVISORY_ID, $advisory) === 1)) ? (string) $advisory : null;

        [$fixAvailable, $fixVersion, $fixMajor] = $npm ? $this->npmFix($e->metadata['fix_available'] ?? null) : [null, null, null];
        $direct = $npm && is_bool($e->metadata['is_direct'] ?? null) ? $e->metadata['is_direct'] : null;

        $name = $package !== null ? "`{$package}`" : 'the affected package';
        $range = $affected !== null ? " (affected: {$affected})" : '';
        $tool = $npm ? 'npm' : 'Composer';
        $manifest = $npm ? 'package-lock.json' : 'composer.lock';

        $steps = [
            "Review the advisory (see references) and confirm the installed version of {$name} is inside the affected range{$range}.",
        ];

        if ($npm && $fixVersion !== null) {
            $steps[] = "npm reports {$fixVersion} as a version that resolves this advisory".($fixMajor === true ? ' — this is a semver-major change, so review the release notes for breaking changes before adopting it.' : '; review its release notes for breaking changes before adopting it.');
        } elseif ($npm && $fixAvailable === false) {
            $steps[] = 'npm reports that no fixed version is available yet: consider a maintained alternative or a mitigation, and if you accept the risk, record it with a reason on the finding.';
        } elseif ($npm && $fixAvailable === true) {
            $steps[] = 'npm reports that a fix is available; determine the exact target version from the advisory and the package\'s release notes.';
        } else {
            $steps[] = "Choose a release of {$name} outside the affected range, read its changelog for breaking changes, and adjust your version constraint if required.";
        }

        if ($direct === false) {
            $steps[] = 'This is a transitive dependency: update the direct dependency that requires it (or apply a reviewed override) rather than editing the lockfile by hand.';
        }

        $steps[] = "Update the dependency yourself with your normal process and commit the changed {$manifest}; LaraDogs never runs {$tool} update/install or modifies your dependency files. Then re-run the audit.";

        $limitations = $npm
            ? ['Fixed-version data is only what npm reported at scan time; LaraDogs does not query any registry during remediation.']
            : ['Composer\'s advisory data does not include a fixed version and the installed version is not stored on the finding; LaraDogs does not query Packagist during remediation.'];

        return [
            'block' => [
                'ecosystem' => $npm ? 'npm' : 'composer',
                'package' => $package,
                'affected_versions' => $affected,
                'fixed_version' => $fixVersion,
                'fix_available' => $fixAvailable,
                'fix_is_semver_major' => $fixMajor,
                'direct_dependency' => $direct,
                'advisory_id' => $advisoryId,
            ],
            'summary' => "Upgrade {$name} to a version outside the affected range after reviewing compatibility{$range}.",
            'steps' => $steps,
            'limitations' => $limitations,
        ];
    }

    /**
     * @return array{0: bool|null, 1: string|null, 2: bool|null}
     */
    private function npmFix(mixed $raw): array
    {
        if (is_bool($raw)) {
            return [$raw, null, null];
        }

        if (! is_array($raw)) {
            return [null, null, null];
        }

        $version = $raw['version'] ?? null;
        $version = is_string($version) && preg_match(self::SEMVER, $version) === 1 ? $version : null;
        $major = is_bool($raw['is_semver_major'] ?? null) ? $raw['is_semver_major'] : null;

        return [true, $version, $version === null ? null : $major];
    }

    // -- blocks -------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function findingBlock(RemediationEvidence $e): array
    {
        $root = $e->projectRoot;

        return [
            'content_trust' => RemediationPlan::CONTENT_TRUST_UNTRUSTED,
            'title' => $this->sanitizer->text($e->title, OutputSanitizer::MAX_TITLE, $root),
            'message' => $this->sanitizer->text($e->description, OutputSanitizer::MAX_MESSAGE, $root),
            'impact' => $this->sanitizer->text($e->impact, OutputSanitizer::MAX_MESSAGE, $root),
            'analyzer' => $this->sanitizer->text($e->analyzerId, 64),
            'category' => $e->category->value,
            'severity' => $e->severity->value,
            'confidence' => $e->confidence->value,
            'status' => $e->status->value,
            'cwe' => $this->sanitizer->text($e->cwe, 200),
            'cve' => $this->sanitizer->text($e->cve, 64),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceBlock(RemediationEvidence $e): array
    {
        $path = $this->sanitizer->path($e->filePath, $e->projectRoot);

        return [
            'content_trust' => RemediationPlan::CONTENT_TRUST_UNTRUSTED,
            'location' => $path === null ? null : [
                'path' => $path,
                'line_start' => $e->lineStart,
                'line_end' => $e->lineEnd,
            ],
            'snippet' => $this->sanitizer->text($e->snippet, OutputSanitizer::MAX_SNIPPET, $e->projectRoot),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function context(RemediationEvidence $e): ?array
    {
        $type = $this->profileType($e);

        if ($type === null) {
            return null;
        }

        $laravel = $e->profile['backend']['laravel'] ?? null;
        $version = is_array($laravel) ? ($laravel['installed_version'] ?? $laravel['constraint'] ?? null) : null;
        $php = $e->profile['backend']['php'] ?? null;
        $phpVersion = is_array($php) ? ($php['installed_version'] ?? $php['constraint'] ?? null) : null;

        return [
            'framework' => [
                'type' => $type,
                'laravel' => $this->version($version),
                'php' => $this->version($phpVersion),
            ],
            'observed_on' => 'the scan that last reported this finding',
        ];
    }

    private function profileType(RemediationEvidence $e): ?string
    {
        $type = $e->profile['project']['type'] ?? null;

        return is_string($type) && preg_match('/^[a-z0-9_-]{1,32}$/', $type) === 1 ? $type : null;
    }

    private function version(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9A-Za-z.,|<>=!~^*+\s-]{1,50}$/', $value) === 1 ? $value : null;
    }

    // -- lifecycle ------------------------------------------------------------

    /**
     * @return array{status: string, actionable: bool, note: string}
     */
    private function lifecycle(FindingStatus $status): array
    {
        [$actionable, $note] = match ($status) {
            FindingStatus::Open => [true, 'Open — actionable guidance.'],
            FindingStatus::Confirmed => [true, 'Confirmed — actionable guidance.'],
            FindingStatus::Resolved => [false, 'Resolved — this guidance is historical; the finding is not active work.'],
            FindingStatus::FalsePositive => [false, 'Marked false positive — guidance is shown for reference; the finding is not treated as an active issue.'],
            FindingStatus::Ignored => [false, 'Intentionally ignored — guidance is shown for reference only.'],
            FindingStatus::AcceptedRisk => [false, 'Risk accepted — guidance is shown for reference; the risk was accepted on purpose.'],
        };

        return ['status' => $status->value, 'actionable' => $actionable, 'note' => $note];
    }

    // -- source provenance ------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function sourceBlock(RemediationSourceState $state, ?GitSnapshot $observed, ?GitSnapshot $current): array
    {
        return [
            'state' => $state->value,
            'observed' => $this->snapshot($observed),
            'current' => $this->snapshot($current),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function snapshot(?GitSnapshot $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        return [
            'type' => $snapshot->state->value,
            'revision' => $snapshot->shortSha(),
            'dirty' => $snapshot->dirty,
        ];
    }

    // -- quality gate ------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function gateBlock(GateImpact $impact, ?GateFacts $facts): array
    {
        return [
            'impact' => $impact->value,
            'basis' => $facts === null
                ? 'No Quality Gate result is available for the newest terminal scan.'
                : 'Persisted Quality Gate result of the newest terminal scan; it is never re-evaluated here.',
            'gate_scan_id' => $facts?->scanPublicId,
            'evaluated_at' => $facts?->evaluatedAt,
        ];
    }

    // -- validation --------------------------------------------------------------

    /**
     * Only LaraDogs-owned, structured actions plus prose. Never an
     * executable command string for the TARGET project.
     *
     * @return list<array<string,mixed>>
     */
    private function validation(RemediationEvidence $e, GateImpact $impact): array
    {
        $items = [
            [
                'type' => 'manual',
                'text' => 'Review and test the change yourself, and run your project\'s own test suite; LaraDogs does not execute your tests or modify your code.',
            ],
            [
                'type' => 'laradogs_tool',
                'name' => 'laradogs.run_project_audit',
                'arguments' => ['project_id' => $e->projectId],
                'text' => 'Queue a new audit of the project (MCP; requires an audit-scope token).',
            ],
            [
                'type' => 'laradogs_command',
                'command' => 'laradogs:project:audit',
                'arguments' => ['project' => $e->projectId],
                'text' => 'Or run a persisted audit of the project from the CLI.',
            ],
            [
                'type' => 'manual',
                'text' => 'When the audit completes, confirm this finding is no longer reported as open.',
            ],
        ];

        if ($impact !== GateImpact::NotEvaluated) {
            $items[] = [
                'type' => 'laradogs_command',
                'command' => 'laradogs:project:gate',
                'arguments' => ['project' => $e->projectId],
                'text' => 'Then check the new scan\'s Quality Gate result.',
            ];
        }

        return array_slice($items, 0, self::MAX_VALIDATION);
    }

    // -- warnings ------------------------------------------------------------------

    /**
     * @param  array{block: array<string,mixed>, summary: string, steps: list<string>, limitations: list<string>}|null  $dependency
     * @return list<array{code: string, message: string}>
     */
    private function warnings(RemediationEvidence $e, RemediationSourceState $source, GateImpact $impact, bool $guidanceAvailable, ?array $dependency): array
    {
        $w = [];

        match ($source) {
            RemediationSourceState::ChangedSinceFinding => $w[] = ['code' => 'source_changed', 'message' => 'Current source differs from the source revision where this finding was observed. The reported location may have moved or no longer exist.'],
            RemediationSourceState::Dirty => $w[] = ['code' => 'source_dirty', 'message' => 'The working tree has uncommitted changes (or had them when audited), so the code at the reported location cannot be assumed unchanged.'],
            RemediationSourceState::Unavailable => $w[] = ['code' => 'source_unavailable', 'message' => 'The current source could not be inspected, so it cannot be compared with the revision where this finding was observed.'],
            RemediationSourceState::NotVersioned => $w[] = ['code' => 'source_not_versioned', 'message' => 'The project is not a Git repository, so LaraDogs cannot tell whether the source changed since this finding was observed.'],
            RemediationSourceState::Unknown => $w[] = ['code' => 'source_unknown', 'message' => 'No comparable source revision was recorded for this finding (for example a scan that predates source tracking), so it cannot be compared with the current source.'],
            RemediationSourceState::SameRevision => null,
        };

        match ($e->status) {
            FindingStatus::Resolved => $w[] = ['code' => 'finding_resolved', 'message' => 'This finding is resolved; the guidance below is historical.'],
            FindingStatus::FalsePositive => $w[] = ['code' => 'finding_false_positive', 'message' => 'This finding is marked as a false positive.'],
            FindingStatus::Ignored => $w[] = ['code' => 'finding_ignored', 'message' => 'This finding is intentionally ignored.'],
            FindingStatus::AcceptedRisk => $w[] = ['code' => 'finding_accepted_risk', 'message' => 'The risk of this finding was accepted.'],
            FindingStatus::Open, FindingStatus::Confirmed => null,
        };

        if (! $guidanceAvailable) {
            $w[] = ['code' => 'no_rule_guidance', 'message' => 'No rule-specific remediation guidance is available for this finding.'];
        }

        if ($dependency !== null && ($dependency['block']['fix_is_semver_major'] ?? null) === true) {
            $w[] = ['code' => 'dependency_fix_major', 'message' => 'The reported fixed version is a semver-major change; review compatibility before upgrading.'];
        }

        if ($dependency !== null && ($dependency['block']['fix_available'] ?? null) === false) {
            $w[] = ['code' => 'dependency_no_fix', 'message' => 'npm reports no fixed version is available yet.'];
        }

        if ($impact === GateImpact::Blocking && ! $e->status->countsTowardQualityGate()) {
            $w[] = ['code' => 'gate_result_predates_status', 'message' => 'The persisted gate result lists this finding, but its status has since changed; re-run the audit to refresh the gate.'];
        }

        return array_slice($w, 0, self::MAX_WARNINGS);
    }
}
