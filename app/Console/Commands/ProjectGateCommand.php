<?php

namespace App\Console\Commands;

use App\Audit\Findings\ScanStatus;
use App\Audit\QualityGates\QualityGateOutcome;
use App\Audit\QualityGates\Query\ProjectQualityGateQuery;
use App\Models\Audit\Project;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\QualityGateRuleResult;
use App\Models\Audit\Scan;
use Illuminate\Console\Command;

/**
 * Reads a scan's IMMUTABLE Quality Gate result and reports it with a
 * meaningful exit code — the contract a future CI job can rely on. It
 * never re-evaluates, never runs an analyzer and never changes anything:
 * the result was fixed when the scan finished, against the policy
 * revision recorded on it.
 *
 * Exit codes (V1 contract — documented in docs/quality-gates/README.md):
 *
 *   0  Passed
 *   1  Failed
 *   2  Indeterminate (not enough trustworthy evidence — fail closed)
 *   3  Operational error (unknown project / scan, no terminal scan)
 *   4  Not evaluated (no gate result for that scan: gate disabled at the
 *      time, evaluation failed, or a scan from before Quality Gates)
 *
 * Defaults to the project's latest TERMINAL scan; `--scan=<PUBLIC_ID>`
 * selects a specific one. Deliberately its own codes rather than Laravel's
 * generic 0/1/2, so a CI job can tell a policy failure from an
 * infrastructure problem.
 */
final class ProjectGateCommand extends Command
{
    public const int EXIT_OPERATIONAL_ERROR = 3;

    public const int EXIT_NOT_EVALUATED = 4;

    protected $signature = 'laradogs:project:gate
        {project : A project\'s public ID (see `laradogs:project:list`)}
        {--scan= : A scan\'s public ID (default: the latest terminal scan)}
        {--json : Output the result as JSON}';

    protected $description = 'Report a persisted scan\'s Quality Gate result; exit code 0 passed, 1 failed, 2 indeterminate, 3 error, 4 not evaluated';

    public function handle(ProjectQualityGateQuery $query): int
    {
        $publicId = (string) $this->argument('project');
        $project = Project::query()->where('public_id', $publicId)->first();

        if ($project === null) {
            return $this->reportFailure(null, null, self::EXIT_OPERATIONAL_ERROR, "No project found with ID: {$publicId}. See `laradogs:project:list`.");
        }

        $scan = $this->resolveScan($project);

        if (is_string($scan)) {
            return $this->reportFailure($project, null, self::EXIT_OPERATIONAL_ERROR, $scan);
        }

        $result = $query->resultForScan($scan);

        if ($result === null) {
            return $this->reportFailure($project, $scan, self::EXIT_NOT_EVALUATED, "Scan {$scan->public_id} has no Quality Gate result (the gate was disabled when it finished, it could not be evaluated, or the scan pre-dates Quality Gates).");
        }

        return $this->render($project, $scan, $result);
    }

    /**
     * @return Scan|string a scan, or an error message
     */
    private function resolveScan(Project $project): Scan|string
    {
        $requested = $this->option('scan');

        if (is_string($requested) && $requested !== '') {
            $scan = Scan::query()->where('project_id', $project->id)->where('public_id', $requested)->first();

            return $scan ?? "No scan {$requested} found for this project.";
        }

        $scan = Scan::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [ScanStatus::Completed, ScanStatus::Failed])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        return $scan ?? 'This project has no completed or failed scan yet.';
    }

    private function render(Project $project, Scan $scan, QualityGateResult $result): int
    {
        $exitCode = $result->outcome->exitCode();

        if ((bool) $this->option('json')) {
            $this->emitJson([
                'project' => $project->public_id,
                'scan' => $scan->public_id,
                'gate' => [
                    'outcome' => $result->outcome->value,
                    'policy_revision' => $result->policy_revision,
                    'evaluated_at' => $result->evaluated_at->toIso8601String(),
                    'baseline_scan' => $result->baselineScan?->public_id,
                    'rules_total' => $result->rules_total,
                    'rules_failed' => $result->rules_failed,
                    'rules_indeterminate' => $result->rules_indeterminate,
                    'rules' => $result->ruleResults->map(fn (QualityGateRuleResult $rule): array => [
                        'rule_id' => $rule->rule_id->value,
                        'subject' => $rule->subject,
                        'outcome' => $rule->outcome->value,
                        'summary' => $rule->summary,
                        'observed' => $rule->observed,
                        'expected' => $rule->expected,
                        'analyzer_id' => $rule->analyzer_id,
                        'severity' => $rule->severity,
                        'finding_count' => $rule->finding_count,
                        'finding_ids' => $rule->finding_ids ?? [],
                    ])->all(),
                ],
                'exit_code' => $exitCode,
            ]);

            return $exitCode;
        }

        $this->line("Quality Gate: {$result->outcome->label()} (policy revision {$result->policy_revision}, scan {$scan->public_id})");

        foreach ($result->ruleResults as $rule) {
            $marker = match ($rule->outcome) {
                QualityGateOutcome::Passed => 'PASS         ',
                QualityGateOutcome::Failed => 'FAIL         ',
                QualityGateOutcome::Indeterminate => 'INDETERMINATE',
            };
            $subject = $rule->subject === null ? '' : " [{$rule->subject}]";
            $this->line("  {$marker} {$rule->rule_id->value}{$subject} — {$rule->summary}");
        }

        return $exitCode;
    }

    private function reportFailure(?Project $project, ?Scan $scan, int $exitCode, string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->emitJson([
                'project' => $project?->public_id,
                'scan' => $scan?->public_id,
                'gate' => null,
                'error' => $message,
                'exit_code' => $exitCode,
            ]);
        } else {
            $this->error($message);
        }

        return $exitCode;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitJson(array $payload): void
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
