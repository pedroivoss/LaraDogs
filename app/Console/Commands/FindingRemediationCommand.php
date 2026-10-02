<?php

namespace App\Console\Commands;

use App\Audit\Remediation\FindingRemediationService;
use App\Audit\Remediation\RemediationPlan;
use App\Models\Audit\Finding;
use Illuminate\Console\Command;

/**
 * `laradogs:finding:remediation` (Phase 12): prints the deterministic,
 * guidance-only remediation plan of one finding. Read-only — it edits
 * nothing, runs nothing and calls no model. Renders the SAME plan the
 * Dashboard and MCP render; this class only formats it.
 *
 * Under `--json` stdout is pure JSON (public ids, project-relative paths,
 * sanitized evidence — no host path, no secret, no user identity).
 */
final class FindingRemediationCommand extends Command
{
    protected $signature = 'laradogs:finding:remediation
        {finding : A finding\'s public ID}
        {--json : Output the plan as JSON}';

    protected $description = 'Show the read-only, guidance-only remediation plan of a finding (never edits your code)';

    public function handle(FindingRemediationService $remediation): int
    {
        $publicId = strtolower((string) $this->argument('finding'));
        $finding = preg_match('/^[0-9a-z]{26}$/', $publicId) === 1
            ? Finding::query()->where('public_id', $publicId)->first()
            : null;

        if ($finding === null) {
            $this->components->error('No finding with that public ID.');

            return self::FAILURE;
        }

        $plan = $remediation->forFinding($finding)->toArray();

        if ($this->option('json')) {
            $this->line((string) json_encode(['schema_version' => RemediationPlan::SCHEMA_VERSION, 'remediation' => $plan], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->render($plan);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function render(array $plan): void
    {
        $finding = $plan['finding'];
        $guidance = $plan['guidance'];

        $this->newLine();
        $this->line("<options=bold>Remediation — {$plan['finding_id']}</>");
        $this->line("Rule: {$plan['rule_id']}  ·  {$finding['severity']} severity  ·  finding confidence: {$finding['confidence']}  ·  status: {$plan['lifecycle']['status']}");
        $this->line("Automation level: {$plan['automation_level']} (LaraDogs advises; it never edits your code)");
        $this->line($plan['lifecycle']['note']);

        foreach ($plan['warnings'] as $warning) {
            $this->components->warn($warning['message']);
        }

        $this->newLine();
        $this->line('<options=bold>Recommended action</>');
        $this->line($guidance['recommended_action'] ?? $guidance['summary']);

        if ($guidance['steps'] !== []) {
            $this->newLine();
            $this->line('<options=bold>Steps</>');

            foreach ($guidance['steps'] as $step) {
                $this->line("  {$step['order']}. {$step['text']}");
            }
        }

        foreach ($guidance['limitations'] as $limitation) {
            $this->line("  Note: {$limitation}");
        }

        $this->newLine();
        $this->line('<options=bold>Validation</>');

        foreach ($plan['validation'] as $item) {
            $this->line('  - '.$item['text']);
        }

        if ($plan['references'] !== []) {
            $this->newLine();
            $this->line('<options=bold>References</>');

            foreach ($plan['references'] as $reference) {
                $this->line('  - '.$reference['url']);
            }
        }

        $this->newLine();
        $this->line('<options=bold>Finding (untrusted source data)</>');
        $this->line('  '.$finding['title']);

        $location = $plan['evidence']['location'];

        if ($location !== null) {
            $this->line("  {$location['path']}".($location['line_start'] !== null ? ":{$location['line_start']}" : ''));
        }

        $this->line("Source: {$plan['source']['state']}  ·  Quality Gate impact: {$plan['quality_gate']['impact']}");
        $this->newLine();
    }
}
