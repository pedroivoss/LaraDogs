<?php

namespace App\Console\Commands;

use App\Audit\Ci\CiOutcome;
use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\RegisterProject;
use App\Audit\Projects\RunProjectAudit;
use App\Audit\Projects\RunProjectAuditOutcome;
use App\Audit\Projects\RunProjectAuditResult;
use App\Audit\QualityGates\Query\ProjectQualityGateQuery;
use App\Audit\Source\Git\GitRepositoryInspector;
use App\Audit\Source\ScanSourceSummary;
use App\Audit\Source\SourceIntegrityReason;
use App\Console\Commands\Support\CiRevisionVerification;
use App\Console\Commands\Support\GateResultCliPayload;
use App\Integrations\GitHub\GitHubContext;
use App\Integrations\GitHub\RecordGitHubCheckRun;
use App\Models\Audit\Project;
use App\Models\Audit\QualityGateResult;
use App\Models\Audit\Scan;
use Illuminate\Console\Command;

/**
 * The ONE canonical entry point for machine-oriented (CI) usage —
 * converges on the exact same pipeline every other trigger uses
 * ({@see RegisterProject} -> {@see RunProjectAudit} -> the persisted Scan
 * -> its Quality Gate result): no CI-specific analyzer orchestration, no
 * GitHub-specific Finding lifecycle, no second audit pipeline. GitHub
 * reporting (`--github-report`) is a thin, optional, failure-isolated
 * consumer of the result this command already computed — see
 * {@see RecordGitHubCheckRun} and docs/integrations/github.md.
 *
 * Exit codes (the SAME V1 contract as `laradogs:project:gate`, extended
 * with operational cases this command's own path can produce — see
 * docs/ci/README.md#exit-codes; no new code is introduced beyond the five
 * Phase 8 already defines):
 *
 *   0  Passed
 *   1  Failed
 *   2  Indeterminate
 *   3  Operational error (bad project path, an audit that could not
 *      complete, or — critically — the audited revision did not match
 *      `--expected-revision`. The gate verdict of such a run is still reported
 *      truthfully in the JSON, but it is NEVER the CI result: the final
 *      outcome (see {@see CiOutcome}) is `OperationalError`, in the exit code
 *      and in any GitHub Check alike)
 *   4  Not evaluated (gate disabled, or this scan predates Quality Gates)
 *
 * `--json`: stdout carries ONLY the JSON envelope — nothing else is ever
 * written there in that mode (no progress line, no framework boilerplate).
 * Human progress notes go to stderr instead (see {@see note()}), which a
 * CI log viewer still shows live while a long analyzer pass runs.
 */
final class CiAuditCommand extends Command
{
    protected $signature = 'laradogs:ci:audit
        {path : Path to the checked-out repository to audit}
        {--expected-revision= : Full Git commit SHA CI expects to be auditing (falls back to GITHUB_SHA under GITHUB_ACTIONS=true)}
        {--github-report : Also report the result to GitHub as a Check Run (reads GITHUB_TOKEN; never a CLI argument)}
        {--json : Emit ONLY a machine-readable JSON envelope on stdout}';

    protected $description = 'Run a persisted CI audit and evaluate its Quality Gate; exit code 0 passed, 1 failed, 2 indeterminate, 3 operational error, 4 not evaluated';

    public function handle(
        RegisterProject $register,
        RunProjectAudit $runner,
        GitRepositoryInspector $git,
        ProjectQualityGateQuery $gateQuery,
        RecordGitHubCheckRun $githubReporter,
    ): int {
        $path = (string) $this->argument('path');
        $context = GitHubContext::fromEnvironment(
            configApiUrl: (string) config('laradogs.github.api_url'),
            configServerUrl: (string) config('laradogs.github.server_url'),
        );
        $expectedRevision = $this->resolveExpectedRevision($context);

        $this->note("Resolving project at {$path}…");
        $registration = $register->register($path);

        if (! $registration->succeeded() || $registration->project === null) {
            $message = $registration->discoveryFailure === null
                ? 'Could not resolve the project path.'
                : $registration->discoveryFailure->status->describe($registration->discoveryFailure->path);

            return $this->reportFailure(null, null, $expectedRevision, null, $message);
        }

        $project = $registration->project;

        if ($expectedRevision !== null) {
            $mismatch = $this->verifyBeforeAudit($project, $git, $expectedRevision);

            if ($mismatch !== null) {
                return $this->reportFailure($project, null, $expectedRevision, false, $mismatch);
            }
        }

        $this->note('Running audit…');
        $result = $runner->run($project, ScanOrigin::Cli);

        if ($result->outcome !== RunProjectAuditOutcome::Completed || $result->scan === null) {
            return $this->reportFailure($project, null, $expectedRevision, null, $this->describeAuditFailure($result->outcome, $result));
        }

        $scan = $result->scan;

        if ($scan->status !== ScanStatus::Completed) {
            return $this->reportFailure($project, $scan, $expectedRevision, null, "Scan {$scan->public_id} did not complete (status: {$scan->status->value}).");
        }

        // ONE path for every completed scan (Phase 10.1): the FINAL CI
        // outcome is decided once — an operational condition (the persisted
        // revision does not match the expected one) overrides whatever the
        // Quality Gate said — and that single outcome drives the exit code,
        // the JSON envelope AND the GitHub Check conclusion. The scan and the
        // gate result are reported truthfully and never modified.
        $mismatched = CiRevisionVerification::mismatched($expectedRevision, $scan->source_revision);
        $revisionVerified = $expectedRevision === null ? null : ! $mismatched;
        $gate = $gateQuery->resultForScan($scan);
        $outcome = CiOutcome::resolve($mismatched, $gate?->outcome);

        $error = $mismatched
            ? "Audited revision {$scan->source_revision} does not match the expected revision {$expectedRevision}."
            : null;

        $github = $this->maybeReportToGithub($scan, $outcome, $context, $githubReporter);

        $this->render($project, $scan, $gate, $expectedRevision, $revisionVerified, $github, $outcome->exitCode(), $error);

        return $outcome->exitCode();
    }

    /**
     * `--expected-revision` wins whenever given (it is explicit, authored
     * by the CI job); GITHUB_SHA is only an implicit default, and ONLY
     * under `GITHUB_ACTIONS=true` — see docs/ci/README.md#revision-verification
     * for why this is NOT auto-detected from `pull_request` event payload
     * fields (those need explicit `ref`/`--expected-revision` from the
     * workflow itself — see docs/integrations/github.md#pull-request-semantics).
     */
    private function resolveExpectedRevision(GitHubContext $context): ?string
    {
        $option = $this->option('expected-revision');

        if (is_string($option) && $option !== '') {
            if (preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i', $option) !== 1) {
                $this->note("--expected-revision '{$option}' is not a valid full Git commit SHA — ignoring GITHUB_SHA fallback and treating this as unverifiable.");

                return strtolower($option); // still enforced: will never match a real SHA, so audit fails closed as a mismatch rather than silently skipping verification.
            }

            return strtolower($option);
        }

        return $context->actionsMode ? $context->sha : null;
    }

    /**
     * Fast, cheap fail-fast BEFORE running a (possibly expensive) audit.
     * Not the authoritative check — {@see handle()} re-verifies against the
     * scan's OWN persisted snapshot afterwards, which is what actually
     * decides the exit code (closes the race between this call and the
     * audit's own before/after Git inspection).
     */
    private function verifyBeforeAudit(Project $project, GitRepositoryInspector $git, string $expectedRevision): ?string
    {
        $snapshot = $git->inspect($project->path);

        if (CiRevisionVerification::mismatched($expectedRevision, $snapshot->commitSha)) {
            return "Expected revision {$expectedRevision} but the current source is ".
                ($snapshot->commitSha ?? 'not at a matching commit ('.$snapshot->state->label().')').'.';
        }

        return null;
    }

    private function describeAuditFailure(RunProjectAuditOutcome $outcome, RunProjectAuditResult $result): string
    {
        return match ($outcome) {
            RunProjectAuditOutcome::AlreadyRunning => $result->conflictingScan === null
                ? 'Another audit for this project is already queued or running.'
                : "Another audit for this project is already {$result->conflictingScan->status->value} (scan {$result->conflictingScan->public_id}).",
            RunProjectAuditOutcome::PathUnavailable => $result->discoveryFailure === null
                ? 'Project path is no longer available.'
                : $result->discoveryFailure->status->describe($result->discoveryFailure->path),
            RunProjectAuditOutcome::Queued => 'Unexpected: audit is still queued after a synchronous run.',
            RunProjectAuditOutcome::Completed => 'Unexpected: audit completed but no scan was returned.',
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function maybeReportToGithub(Scan $scan, CiOutcome $outcome, GitHubContext $context, RecordGitHubCheckRun $reporter): ?array
    {
        if (! (bool) $this->option('github-report')) {
            return null;
        }

        $this->note('Reporting to GitHub…');
        // Read ONLY from the environment — never a CLI argument (a token
        // would then appear in `ps`/shell history on a shared runner).
        $token = getenv('GITHUB_TOKEN');
        $result = $reporter->record($scan, $outcome, $context, $token === false ? null : $token);

        return $result->toArray();
    }

    private function reportFailure(
        ?Project $project,
        ?Scan $scan,
        ?string $expectedRevision,
        ?bool $revisionVerified,
        string $message,
    ): int {
        // No trustworthy scan/revision exists on these paths (bad path,
        // pre-audit mismatch, audit that did not run/complete) — nothing to
        // attach a Check Run to, so nothing is ever reported to GitHub.
        $exitCode = CiOutcome::OperationalError->exitCode();

        $this->render($project, $scan, null, $expectedRevision, $revisionVerified, null, $exitCode, $message);

        return $exitCode;
    }

    private function note(string $message): void
    {
        if ((bool) $this->option('json')) {
            // stdout carries ONLY the final JSON envelope in this mode —
            // progress notes go to stderr instead, never stdout.
            fwrite(STDERR, $message.PHP_EOL);

            return;
        }

        $this->line($message);
    }

    /**
     * @param  array<string,mixed>|null  $github
     */
    private function render(
        ?Project $project,
        ?Scan $scan,
        ?QualityGateResult $gate,
        ?string $expectedRevision,
        ?bool $revisionVerified,
        ?array $github,
        int $exitCode,
        ?string $error,
    ): void {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'project' => $project === null ? null : ['id' => $project->public_id, 'name' => $project->name],
                'scan' => $scan === null ? null : ['id' => $scan->public_id, 'status' => $scan->status->value, 'source' => ScanSourceSummary::forScan($scan)],
                'ci' => ['expected_revision' => $expectedRevision, 'revision_verified' => $revisionVerified],
                'gate' => $gate === null ? null : GateResultCliPayload::toArray($gate),
                'github' => $github,
                'exit_code' => $exitCode,
                'error' => $error,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if ($error !== null) {
            $this->error($error);
        }

        if ($scan !== null) {
            $this->components->info("Scan {$scan->public_id}: {$scan->status->value}");

            $sourceLine = ScanSourceSummary::line($scan);

            if ($sourceLine !== null) {
                $this->line("  Source: {$sourceLine}");
            }

            if ($scan->source_consistent === false) {
                $reason = SourceIntegrityReason::tryFrom((string) $scan->source_integrity_reason) ?? SourceIntegrityReason::Unavailable;
                $this->components->warn('  '.$reason->explanation());
            }
        }

        if ($expectedRevision !== null) {
            $this->line('  Expected revision: '.($revisionVerified === true ? "{$expectedRevision} (verified)" : "{$expectedRevision} (NOT verified)"));
        }

        if ($gate !== null) {
            $this->line("  Quality Gate: {$gate->outcome->label()} (policy revision {$gate->policy_revision})");
        } elseif ($scan !== null && $error === null) {
            $this->line('  Quality Gate: not evaluated');
        }

        if ($github !== null) {
            $this->line('  GitHub: '.($github['reported'] ? 'reported' : 'not reported ('.$github['reason'].')'));
        }

        $this->line("Exit code: {$exitCode}");
    }
}
