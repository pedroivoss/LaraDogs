<?php

namespace App\Integrations\GitHub;

use App\Audit\Engine\Execution\ExecutionStatus;
use App\Audit\QualityGates\QualityGateOutcome;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\Scan;
use App\Models\Audit\ScanAnalyzerExecution;
use App\Models\Integrations\GitHubCheckReport;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reports ONE scan's Quality Gate result to GitHub as a Check Run — the
 * only place that connects the audit/gate domain to GitHub. It consumes
 * an already-finished {@see Scan} and its (possibly absent)
 * {@see QualityGateResult}; it never evaluates a gate,
 * never runs an analyzer, and never changes either. See
 * docs/integrations/github.md.
 *
 * Never throws — every failure becomes a {@see GitHubReportResult} with
 * `reported = false`, so a GitHub outage can never affect the CI
 * command's own exit code (docs/integrations/github.md#failure-isolation).
 */
final readonly class RecordGitHubCheckRun
{
    public function __construct(
        private GitHubApiClient $client,
        private string $checkName,
        private int $summaryMaxLength,
        private ?string $publicUrl,
    ) {}

    public function record(Scan $scan, GitHubContext $context, ?string $token): GitHubReportResult
    {
        try {
            return $this->attempt($scan, $context, $token);
        } catch (Throwable) {
            // Defensive: this method must NEVER throw, whatever the cause —
            // an integration failure is data, not an exception the caller
            // has to handle.
            return GitHubReportResult::notReported('http_error');
        }
    }

    private function attempt(Scan $scan, GitHubContext $context, ?string $token): GitHubReportResult
    {
        if (! $context->hasRepository()) {
            return GitHubReportResult::notReported('no_github_context');
        }

        if ($token === null || $token === '') {
            return GitHubReportResult::notReported('no_token');
        }

        if ($scan->source_revision === null) {
            // Nothing to attach a Check Run to — never fabricate a SHA.
            return GitHubReportResult::notReported('no_git_revision');
        }

        $existing = GitHubCheckReport::query()->where('scan_id', $scan->id)->first();

        if ($existing !== null) {
            return GitHubReportResult::alreadyReported($existing->check_run_id, $existing->html_url);
        }

        $gate = $scan->qualityGateResult;
        $conclusion = $gate === null
            ? GitHubCheckConclusion::notEvaluated()
            : GitHubCheckConclusion::forGateOutcome($gate->outcome);

        $result = $this->client->createCheckRun($context, $token, [
            'name' => $this->checkName,
            'head_sha' => $scan->source_revision,
            'status' => 'completed',
            'conclusion' => $conclusion->value,
            'external_id' => $scan->public_id,
            'output' => [
                'title' => $gate === null ? 'Quality Gate not evaluated' : $gate->outcome->label(),
                'summary' => $this->summary($scan, $gate),
            ],
        ]);

        if (! $result->successful) {
            return GitHubReportResult::notReported((string) $result->failureReason);
        }

        GitHubCheckReport::query()->create([
            'scan_id' => $scan->id,
            'check_run_id' => $result->checkRunId,
            'html_url' => $result->htmlUrl,
            'conclusion' => $conclusion->value,
            'reported_at' => now(),
        ]);

        return GitHubReportResult::reported($result->checkRunId, $result->htmlUrl);
    }

    private function summary(Scan $scan, ?QualityGateResult $gate): string
    {
        $lines = ["Scan `{$scan->public_id}` — commit `".substr((string) $scan->source_revision, 0, 12).'`.'];

        if ($scan->source_consistent === false) {
            $lines[] = 'Source integrity was not established for this audit — see the LaraDogs docs on source consistency.';
        }

        if ($gate === null) {
            $lines[] = 'No Quality Gate policy was evaluated for this scan (disabled, or the scan pre-dates Quality Gates).';
        } else {
            $lines[] = "**{$gate->outcome->label()}** — {$gate->rules_total} rule(s), {$gate->rules_failed} failed, {$gate->rules_indeterminate} indeterminate.";

            foreach ($gate->ruleResults as $rule) {
                if ($rule->outcome !== QualityGateOutcome::Passed) {
                    $lines[] = "- {$rule->outcome->label()}: {$rule->summary}";
                }
            }
        }

        $analyzerNote = $this->analyzerFailureNote($scan);

        if ($analyzerNote !== null) {
            $lines[] = $analyzerNote;
        }

        if ($this->publicUrl !== null) {
            $lines[] = "[View in LaraDogs]({$this->publicUrl}/projects/{$scan->project->public_id}/scans/{$scan->public_id})";
        }

        $text = implode("\n", $lines);
        $suffix = "\n\n…(truncated)";

        // `Str::limit()` truncates to its length argument and then APPENDS
        // the marker — passed the full budget, the result would exceed it
        // by `strlen($suffix)`. Reserve room for the marker up front so the
        // TOTAL length never exceeds `$this->summaryMaxLength`.
        return mb_strlen($text) <= $this->summaryMaxLength
            ? $text
            : Str::limit($text, max(0, $this->summaryMaxLength - mb_strlen($suffix)), $suffix);
    }

    private function analyzerFailureNote(Scan $scan): ?string
    {
        $failed = ScanAnalyzerExecution::query()
            ->where('scan_id', $scan->id)
            ->whereIn('status', [ExecutionStatus::Failed->value, ExecutionStatus::TimedOut->value, ExecutionStatus::Unavailable->value])
            ->count();

        return $failed > 0 ? "{$failed} analyzer(s) did not complete successfully." : null;
    }
}
