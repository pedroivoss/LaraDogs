<?php

namespace App\Audit\Findings\Ingestion;

use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Support\Facades\DB;

/**
 * Turns one {@see FindingCandidate} ("an analyzer observed this") into
 * persisted state for one scan: find-or-create the logical {@see Finding},
 * record a {@see FindingOccurrence} for this scan, and update
 * first_seen/last_seen — all inside one transaction per candidate.
 *
 * Deliberately does NOT touch findings that weren't observed this scan —
 * that's {@see FindingReconciler}'s job, and only when it's actually safe.
 */
final class FindingIngestor
{
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly EvidenceRedactor $redactor,
        private readonly FindingLifecycleService $lifecycle,
    ) {}

    public function ingest(Project $project, Scan $scan, FindingCandidate $candidate): FindingOccurrence
    {
        $fingerprint = $this->fingerprinter->fingerprint($candidate);

        return DB::transaction(function () use ($project, $scan, $candidate, $fingerprint): FindingOccurrence {
            // Locks the matching row (a no-op on SQLite, whose grammar
            // compiles FOR UPDATE to nothing — SQLite already serializes
            // writers at the connection level; a real row lock on
            // MySQL/PostgreSQL) so two concurrent scans for the same
            // project can't both decide the same fingerprint is new and
            // insert a duplicate Finding.
            $finding = Finding::query()
                ->where('project_id', $project->id)
                ->where('fingerprint', $fingerprint)
                ->where('fingerprint_version', Fingerprinter::VERSION)
                ->lockForUpdate()
                ->first();

            $finding = $finding === null
                ? $this->createFinding($project, $scan, $candidate, $fingerprint)
                : $this->reobserveFinding($finding, $scan, $candidate);

            return FindingOccurrence::updateOrCreate(
                ['finding_id' => $finding->id, 'scan_id' => $scan->id],
                [
                    'file_path' => $candidate->filePath,
                    'line_start' => $candidate->lineStart,
                    'line_end' => $candidate->lineEnd,
                    'code_snippet' => $this->redactor->redact($candidate->codeSnippet),
                    'context_code' => $this->redactor->redact($candidate->contextCode),
                    'evidence' => $this->redactor->redactArray($candidate->metadata),
                    'rule_version' => $candidate->ruleVersion,
                    'analyzer_version' => $candidate->analyzerVersion,
                    'observed_at' => now(),
                ],
            );
        });
    }

    private function createFinding(Project $project, Scan $scan, FindingCandidate $candidate, string $fingerprint): Finding
    {
        $finding = new Finding($this->descriptiveAttributes($candidate));
        $finding->project_id = $project->id;
        $finding->fingerprint = $fingerprint;
        $finding->fingerprint_version = Fingerprinter::VERSION;
        $finding->status = FindingStatus::Open;
        $finding->first_seen_scan_id = $scan->id;
        $finding->first_seen_at = now();
        $finding->last_seen_scan_id = $scan->id;
        $finding->last_seen_at = now();
        $finding->save();

        $this->lifecycle->transition(
            $finding,
            FindingStatus::Open,
            ActorType::System,
            reason: "Created: first observed in scan {$scan->public_id}.",
            scan: $scan,
        );

        return $finding;
    }

    private function reobserveFinding(Finding $finding, Scan $scan, FindingCandidate $candidate): Finding
    {
        // A suppressed status (accepted-risk/false-positive/ignored) is a
        // deliberate human decision that must survive re-detection — only
        // a previously-Resolved finding reopens automatically on
        // reappearance.
        if ($finding->status === FindingStatus::Resolved) {
            $this->lifecycle->transition(
                $finding,
                FindingStatus::Open,
                ActorType::System,
                reason: "Reopened automatically: reappeared in scan {$scan->public_id} after being marked resolved.",
                scan: $scan,
            );
        }

        $finding->fill($this->descriptiveAttributes($candidate));
        $finding->last_seen_scan_id = $scan->id;
        $finding->last_seen_at = now();
        $finding->save();

        return $finding;
    }

    /**
     * @return array<string,mixed>
     */
    private function descriptiveAttributes(FindingCandidate $candidate): array
    {
        return [
            'rule_id' => $candidate->ruleId,
            'analyzer_id' => $candidate->analyzerId,
            'category' => $candidate->category,
            'severity' => $candidate->severity,
            'confidence' => $candidate->confidence,
            'title' => $candidate->title,
            'description' => $candidate->description,
            'impact' => $candidate->impact,
            'recommendation' => $candidate->recommendation,
            'cwe' => $candidate->cwe,
            'cve' => $candidate->cve,
            'references' => $candidate->references,
            'metadata' => $this->redactor->redactArray($candidate->metadata),
        ];
    }
}
