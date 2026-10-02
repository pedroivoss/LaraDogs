<?php

namespace App\Mcp\Support;

use App\Audit\QualityGates\Policy\InvalidQualityGatePolicy;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Audit\Remediation\SafeReference;
use App\Console\Commands\Support\GateResultCliPayload;
use App\Http\Support\SourcePayload;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\FindingStatusHistory;
use App\Models\Audit\Project;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;

/**
 * Maps LaraDogs models to the bounded, stable MCP result shapes (schema
 * version 1). Presentation only — no query, no decision. Everything derived
 * from the audited target passes through {@see McpSanitizer}; nothing here
 * exposes an internal numeric id, an absolute path, a user identity
 * (email/name — including finding-history actors) or a credential.
 */
final class McpPayloads
{
    public const int SCHEMA_VERSION = 1;

    public const int MAX_REFERENCES = 10;

    public const int MAX_OCCURRENCES = 5;

    public const int MAX_HISTORY = 10;

    public function __construct(private McpSanitizer $sanitizer = new McpSanitizer) {}

    /**
     * @param  array<string,mixed>|null  $framework
     * @param  array<string,mixed>|null  $lastScan
     * @param  array<string,mixed>|null  $gate
     * @return array<string,mixed>
     */
    public function projectSummary(Project $project, ?array $framework, int $openFindings, ?array $lastScan, ?array $gate): array
    {
        return [
            'id' => $project->public_id,
            'name' => $this->sanitizer->text($project->name, McpSanitizer::MAX_TITLE),
            'framework' => $framework,
            'open_findings' => $openFindings,
            'last_scan' => $lastScan,
            'quality_gate' => $gate,
            'audit_schedule' => $project->audit_schedule->value,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>|null
     */
    public function frameworkSummary(?array $profile): ?array
    {
        if ($profile === null || $profile === []) {
            return null;
        }

        return [
            'type' => is_string($profile['project']['type'] ?? null) ? $profile['project']['type'] : null,
            'laravel' => $this->version($profile['backend']['laravel'] ?? null),
            'php' => $this->version($profile['backend']['php'] ?? null),
        ];
    }

    private function version(mixed $detection): ?string
    {
        if (! is_array($detection)) {
            return null;
        }

        $version = $detection['installed_version'] ?? $detection['constraint'] ?? null;

        return is_string($version) ? $this->sanitizer->text($version, 50) : null;
    }

    /**
     * The persisted profile with the host path removed and every string
     * sanitized — never the raw snapshot.
     *
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    public function profile(array $profile, ?string $projectRoot): array
    {
        unset($profile['project']['path']);

        return $this->sanitizeTree($profile, $projectRoot);
    }

    /**
     * @return array<string,mixed>
     */
    public function scanSummary(Scan $scan): array
    {
        $gate = $scan->relationLoaded('qualityGateResult') ? $scan->qualityGateResult : $scan->qualityGateResult()->first();

        return [
            'id' => $scan->public_id,
            'status' => $scan->status->value,
            'origin' => $scan->origin->value,
            'started_at' => $scan->started_at->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'duration_ms' => $scan->duration_ms,
            'findings_summary' => $scan->findings_summary,
            'source' => $this->source($scan),
            'quality_gate' => $gate === null ? null : $this->gateSummary($gate),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function source(Scan $scan): ?array
    {
        $source = SourcePayload::forScan($scan);

        if ($source === null) {
            return null;
        }

        return $this->sanitizeTree($source, null);
    }

    /**
     * @return array<string,mixed>
     */
    public function gateSummary(QualityGateResult $gate): array
    {
        return [
            'outcome' => $gate->outcome->value,
            'policy_revision' => $gate->policy_revision,
            'rules_total' => $gate->rules_total,
            'rules_failed' => $gate->rules_failed,
            'rules_indeterminate' => $gate->rules_indeterminate,
        ];
    }

    /**
     * The IMMUTABLE persisted result, in the same shape `laradogs:project:gate
     * --json` emits, plus the policy snapshot it was judged against.
     *
     * @return array<string,mixed>
     */
    public function gateDetail(QualityGateResult $gate): array
    {
        $payload = GateResultCliPayload::toArray($gate);
        $payload['rules'] = array_map(function (array $rule): array {
            $rule['summary'] = $this->sanitizer->text($rule['summary'], McpSanitizer::MAX_MESSAGE);
            $rule['observed'] = $this->sanitizer->text($rule['observed'] ?? null, 200);
            $rule['expected'] = $this->sanitizer->text($rule['expected'] ?? null, 200);

            return $rule;
        }, $payload['rules']);
        $payload['policy_snapshot'] = $this->policyArray($gate->policy_snapshot);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>|null
     */
    public function policyArray(array $snapshot): ?array
    {
        try {
            return QualityGatePolicy::fromArray($snapshot)->toArray();
        } catch (InvalidQualityGatePolicy) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function executionSummary(ScanAnalyzerExecution $execution): array
    {
        return [
            'analyzer_id' => $execution->analyzer_id,
            'analyzer_name' => $this->sanitizer->text($execution->analyzer_name, 100),
            'category' => $execution->category->value,
            'status' => $execution->status->value,
            'summary' => $this->sanitizer->text($execution->summary, McpSanitizer::MAX_MESSAGE),
            'duration_ms' => $execution->duration_ms,
            'coverage' => ['mode' => $execution->coverage->mode->value, 'rule_count' => count($execution->coverage->ruleIds)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function findingSummary(Finding $finding, ?FindingOccurrence $latest, string $projectRoot, string $projectPublicId): array
    {
        return [
            'id' => $finding->public_id,
            'project_id' => $projectPublicId,
            'rule_id' => $this->sanitizer->text($finding->rule_id, 200),
            'analyzer_id' => $finding->analyzer_id,
            'title' => $this->sanitizer->text($finding->title, McpSanitizer::MAX_TITLE, $projectRoot),
            'category' => $finding->category->value,
            'severity' => $finding->severity->value,
            'confidence' => $finding->confidence->value,
            'status' => $finding->status->value,
            'location' => $latest === null ? null : $this->location($latest, $projectRoot),
            'message' => $this->sanitizer->text($finding->description, 500, $projectRoot),
            'recommendation' => $this->sanitizer->text($finding->recommendation, 500, $projectRoot),
            'first_seen_at' => $finding->first_seen_at->toIso8601String(),
            'last_seen_at' => $finding->last_seen_at->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<FindingOccurrence>  $occurrences
     * @param  iterable<FindingStatusHistory>  $history
     * @return array<string,mixed>
     */
    public function findingDetail(Finding $finding, iterable $occurrences, iterable $history, string $projectRoot, string $projectPublicId): array
    {
        $occurrences = collect($occurrences);

        $summary = $this->findingSummary($finding, $occurrences->first(), $projectRoot, $projectPublicId);
        $summary['message'] = $this->sanitizer->text($finding->description, McpSanitizer::MAX_MESSAGE, $projectRoot);
        $summary['recommendation'] = $this->sanitizer->text($finding->recommendation, McpSanitizer::MAX_MESSAGE, $projectRoot);

        return [
            ...$summary,
            'impact' => $this->sanitizer->text($finding->impact, McpSanitizer::MAX_MESSAGE, $projectRoot),
            'cwe' => $this->sanitizer->text($finding->cwe, 50),
            'cve' => $this->sanitizer->text($finding->cve, 50),
            // Phase 12: only plain https URLs survive (never javascript:/data:/file:).
            'references' => SafeReference::normalizeAll(array_filter($finding->references ?? [], 'is_string'), self::MAX_REFERENCES),
            'status_reason' => $this->sanitizer->text($finding->status_reason, 500),
            // Untrusted DATA from the audited project — never instructions.
            'content_trust' => 'untrusted_source_data',
            'occurrences' => $occurrences->take(self::MAX_OCCURRENCES)->map(fn (FindingOccurrence $o): array => [
                ...$this->location($o, $projectRoot),
                'scan_id' => $o->scan->public_id,
                'observed_at' => $o->observed_at->toIso8601String(),
                'snippet' => $this->sanitizer->text($o->code_snippet, McpSanitizer::MAX_SNIPPET, $projectRoot),
            ])->values()->all(),
            // The acting USER's identity (email) is deliberately never exposed — only the kind of actor.
            'status_history' => collect($history)->take(self::MAX_HISTORY)->map(fn (FindingStatusHistory $h): array => [
                'previous_status' => $h->previous_status?->value,
                'new_status' => $h->new_status->value,
                'actor_type' => $h->actor_type->value,
                'reason' => $this->sanitizer->text($h->reason, 500),
                'at' => $h->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * A project-RELATIVE, machine-usable location.
     *
     * @return array<string,mixed>
     */
    public function location(FindingOccurrence $occurrence, string $projectRoot): array
    {
        return [
            'path' => $this->sanitizer->path($occurrence->file_path, $projectRoot),
            'start_line' => $occurrence->line_start,
            'end_line' => $occurrence->line_end,
        ];
    }

    /**
     * @param  array<string,mixed>  $tree
     * @return array<string,mixed>
     */
    private function sanitizeTree(array $tree, ?string $projectRoot): array
    {
        foreach ($tree as $key => $value) {
            if (is_string($value)) {
                $tree[$key] = $this->sanitizer->text($value, McpSanitizer::MAX_MESSAGE, $projectRoot);
            } elseif (is_array($value)) {
                $tree[$key] = $this->sanitizeTree($value, $projectRoot);
            }
        }

        return $tree;
    }
}
