<?php

namespace Tests\Feature\QualityGates;

use App\Audit\Findings\Severity;
use App\Audit\QualityGates\Policy\AnalyzerStatusRule;
use App\Audit\QualityGates\Policy\MaxOpenFindingsRule;
use App\Audit\QualityGates\Policy\QualityGatePolicy;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\ProjectQualityGate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\QualityGates\GateScans;
use Tests\TestCase;

class QualityGateHttpTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = GateScans::project();
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'enabled' => true,
            'max_open' => ['critical' => '0', 'high' => '0', 'medium' => ''],
            'no_new' => ['enabled' => true, 'min_severity' => 'high'],
            'analyzer_status' => ['semgrep'],
            'coverage' => ['semgrep' => 'explicit_or_full'],
        ], $override);
    }

    private function savePolicy(User $user, array $payload)
    {
        return $this->actingAs($user)->put(route('projects.quality-gate.update', $this->project), $payload);
    }

    // ---------------- authorization ----------------

    public function test_guests_are_redirected_to_login(): void
    {
        $this->putJson(route('projects.quality-gate.update', $this->project), $this->payload())->assertUnauthorized();
    }

    public function test_a_regular_user_cannot_modify_the_policy_and_gets_a_404(): void
    {
        $this->savePolicy(User::factory()->create(), $this->payload())->assertNotFound();

        $this->assertSame(0, ProjectQualityGate::query()->count());
    }

    public function test_the_owner_can_enable_the_gate(): void
    {
        $this->savePolicy(User::factory()->owner()->create(), $this->payload())->assertRedirect();

        $gate = ProjectQualityGate::query()->firstOrFail();
        $this->assertTrue($gate->enabled);
        $this->assertSame(1, $gate->revision);
        $this->assertEqualsCanonicalizing(['critical' => 0, 'high' => 0], collect($gate->policy['rules'])->firstWhere('type', 'laradogs.gate.max-open-findings')['limits']);
    }

    public function test_an_admin_can_modify_the_policy_and_the_revision_increments(): void
    {
        $this->savePolicy(User::factory()->owner()->create(), $this->payload());
        $this->savePolicy(User::factory()->admin()->create(), $this->payload(['max_open' => ['high' => '3']]))->assertRedirect();

        $gate = ProjectQualityGate::query()->firstOrFail();
        $this->assertSame(2, $gate->revision);
        $this->assertSame(3, collect($gate->policy['rules'])->firstWhere('type', 'laradogs.gate.max-open-findings')['limits']['high']);
    }

    public function test_saving_an_unchanged_policy_does_not_bump_the_revision(): void
    {
        $owner = User::factory()->owner()->create();
        $this->savePolicy($owner, $this->payload());
        $this->savePolicy($owner, $this->payload());

        $this->assertSame(1, ProjectQualityGate::query()->firstOrFail()->revision);
    }

    public function test_an_unchanged_save_is_recognized_even_when_the_database_reorders_json_keys(): void
    {
        $owner = User::factory()->owner()->create();
        $this->savePolicy($owner, $this->payload());

        // MySQL's native JSON type stores object keys in its own order; simulate that.
        $reverse = function (array $value) use (&$reverse): array {
            $value = array_map(fn ($v) => is_array($v) ? $reverse($v) : $v, $value);

            return array_is_list($value) ? $value : array_reverse($value, true);
        };
        DB::table('project_quality_gates')->update(['policy' => json_encode($reverse(ProjectQualityGate::query()->firstOrFail()->policy))]);

        $this->savePolicy($owner, $this->payload());

        $this->assertSame(1, ProjectQualityGate::query()->firstOrFail()->revision);
    }

    public function test_disabling_bumps_the_revision_and_keeps_the_rules(): void
    {
        $owner = User::factory()->owner()->create();
        $this->savePolicy($owner, $this->payload());
        $this->savePolicy($owner, $this->payload(['enabled' => false]));

        $gate = ProjectQualityGate::query()->firstOrFail();
        $this->assertFalse($gate->enabled);
        $this->assertSame(2, $gate->revision);
        $this->assertNotEmpty($gate->policy['rules']);
    }

    // ---------------- validation ----------------

    public function test_a_blank_limit_is_not_enforced_while_zero_is_enforced(): void
    {
        $this->savePolicy(User::factory()->owner()->create(), $this->payload(['max_open' => ['critical' => '0', 'high' => '', 'medium' => '']]));

        $limits = collect(ProjectQualityGate::query()->firstOrFail()->policy['rules'])->firstWhere('type', 'laradogs.gate.max-open-findings')['limits'];

        $this->assertSame(['critical' => 0], $limits);
        $this->assertArrayNotHasKey('high', $limits);
    }

    public function test_invalid_policies_are_rejected_without_persisting(): void
    {
        $owner = User::factory()->owner()->create();

        foreach ([
            'negative limit' => ['max_open' => ['high' => '-1']],
            'huge limit' => ['max_open' => ['high' => '100001']],
            'non numeric' => ['max_open' => ['high' => 'abc']],
            'sql-ish value' => ['max_open' => ['high' => '1; DROP TABLE findings']],
            'unknown severity key' => ['max_open' => ['catastrophic' => '1']],
            'unknown min severity' => ['no_new' => ['min_severity' => 'unknown']],
            'unknown analyzer (status)' => ['analyzer_status' => ['../../etc/passwd']],
            'unknown analyzer (coverage)' => ['coverage' => ['not-registered' => 'explicit_or_full']],
            'bad coverage value' => ['coverage' => ['semgrep' => 'whatever']],
        ] as $label => $override) {
            $this->savePolicy($owner, $this->payload($override))->assertSessionHasErrors();
            $this->assertSame(0, ProjectQualityGate::query()->count(), "{$label} must not persist");
        }
    }

    public function test_enabling_without_any_rule_is_rejected(): void
    {
        $this->savePolicy(User::factory()->owner()->create(), [
            'enabled' => true, 'max_open' => ['critical' => ''], 'no_new' => ['enabled' => false], 'analyzer_status' => [], 'coverage' => [],
        ])->assertSessionHasErrors('enabled');

        $this->assertSame(0, ProjectQualityGate::query()->count());
    }

    public function test_saving_a_disabled_empty_policy_for_a_project_without_one_creates_nothing(): void
    {
        $this->savePolicy(User::factory()->owner()->create(), [
            'enabled' => false, 'max_open' => ['critical' => ''], 'no_new' => ['enabled' => false], 'analyzer_status' => [], 'coverage' => [],
        ])->assertRedirect();

        $this->assertSame(0, ProjectQualityGate::query()->count());
    }

    // ---------------- Project Detail payload ----------------

    public function test_project_detail_defaults_to_a_disabled_gate_with_suggested_form_values_for_every_role(): void
    {
        foreach ([User::factory()->owner()->create(), User::factory()->admin()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get(route('projects.show', $this->project))->assertInertia(fn ($page) => $page
                ->where('quality_gate.enabled', false)
                ->where('quality_gate.revision', 0)
                ->where('quality_gate.latest', null)
                ->where('quality_gate.form.max_open.critical', 0)
                ->where('quality_gate.form.max_open.medium', null)
                ->has('quality_gate.analyzers', 3)
            );
        }
        // Nothing was stored just by viewing.
        $this->assertSame(0, ProjectQualityGate::query()->count());
    }

    public function test_a_user_sees_the_current_gate_read_only_and_the_can_manage_flag_is_false(): void
    {
        GateScans::enable($this->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 2])]));

        $this->actingAs(User::factory()->create())->get(route('projects.show', $this->project))->assertInertia(fn ($page) => $page
            ->where('can_manage_audits', false)
            ->where('quality_gate.enabled', true)
            ->where('quality_gate.revision', 1)
            ->where('quality_gate.form.max_open.high', 2)
        );
    }

    public function test_project_detail_shows_the_latest_terminal_scans_gate_result_separately_from_scan_status(): void
    {
        GateScans::enable($this->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0]), new AnalyzerStatusRule(['semgrep'])]));
        $scan = GateScans::scan($this->project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);

        $this->actingAs(User::factory()->create())->get(route('projects.show', $this->project))->assertInertia(fn ($page) => $page
            ->where('summary.last_scan.status', 'completed')          // scan status untouched...
            ->where('quality_gate.latest.outcome', 'failed')           // ...gate is its own dimension
            ->where('quality_gate.latest.label', 'Failed')
            ->where('quality_gate.latest.scan_id', $scan->public_id)
            ->where('quality_gate.latest.rules_failed', 1)
            ->where('quality_gate.latest.headline', fn ($h) => str_contains($h, 'open high'))
        );
    }

    public function test_a_scan_that_was_not_evaluated_has_no_latest_gate_result_rather_than_a_fake_pass(): void
    {
        GateScans::scan($this->project);                                     // legacy: no gate yet
        GateScans::enable($this->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));

        $this->actingAs(User::factory()->create())->get(route('projects.show', $this->project))->assertInertia(fn ($page) => $page
            ->where('quality_gate.enabled', true)
            ->where('quality_gate.latest', null)
        );
    }

    // ---------------- Scan History / Detail payloads ----------------

    public function test_scan_history_exposes_each_scans_gate_outcome_or_null(): void
    {
        $legacy = GateScans::scan($this->project);
        GateScans::enable($this->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
        $evaluated = GateScans::scan($this->project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);

        $this->actingAs(User::factory()->create())->get(route('projects.scans', $this->project))->assertInertia(fn ($page) => $page
            ->where('scans.0.id', $evaluated->public_id)
            ->where('scans.0.gate.outcome', 'failed')
            ->where('scans.0.gate.policy_revision', 1)
            ->where('scans.0.status', 'completed')                       // status/origin not overwritten by the gate
            ->where('scans.0.origin', 'cli')
            ->where('scans.1.id', $legacy->public_id)
            ->where('scans.1.gate', null)
        );
    }

    public function test_scan_detail_shows_the_historical_result_with_rule_results_and_policy_revision(): void
    {
        GateScans::enable($this->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0]), new AnalyzerStatusRule(['semgrep'])]));
        $scan = GateScans::scan($this->project, candidatesByAnalyzer: ['semgrep' => [GateScans::candidate('a')]]);
        $findingId = Finding::query()->value('public_id');

        $this->actingAs(User::factory()->create())->get(route('projects.scans.show', ['project' => $this->project, 'scan' => $scan]))->assertInertia(fn ($page) => $page
            ->where('quality_gate.outcome', 'failed')
            ->where('quality_gate.policy_revision', 1)
            ->has('quality_gate.rules', 2)
            ->where('quality_gate.rules.0.rule_id', 'laradogs.gate.max-open-findings')
            ->where('quality_gate.rules.0.subject', 'high')
            ->where('quality_gate.rules.0.outcome', 'failed')
            ->where('quality_gate.rules.0.observed', '1')
            ->where('quality_gate.rules.0.expected', '<= 0')
            ->where('quality_gate.rules.0.finding_ids', [$findingId])
            ->where('quality_gate.rules.1.outcome', 'passed')
            ->has('quality_gate_rule_titles')
        );
    }

    public function test_scan_detail_of_a_scan_without_a_result_has_no_gate(): void
    {
        $scan = GateScans::scan($this->project);

        $this->actingAs(User::factory()->create())->get(route('projects.scans.show', ['project' => $this->project, 'scan' => $scan]))
            ->assertInertia(fn ($page) => $page->where('quality_gate', null));
    }

    public function test_gate_payloads_never_expose_who_changed_the_policy_or_started_the_scan(): void
    {
        $owner = User::factory()->owner()->create(['email' => 'gate-owner@example.test', 'name' => 'Gate Owner Person']);
        $this->savePolicy($owner, $this->payload());
        $scan = GateScans::scan($this->project);

        foreach ([route('projects.show', $this->project), route('projects.scans', $this->project), route('projects.scans.show', ['project' => $this->project, 'scan' => $scan])] as $url) {
            $body = $this->actingAs(User::factory()->create())->get($url)->getContent();
            $this->assertStringNotContainsString('gate-owner@example.test', $body);
            $this->assertStringNotContainsString('Gate Owner Person', $body);
        }
    }

    // ---------------- performance ----------------

    public function test_scan_history_does_not_issue_a_query_per_scan_for_gate_results(): void
    {
        GateScans::enable($this->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 5])]));
        $viewer = User::factory()->create();

        $count = function () use ($viewer): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->actingAs($viewer)->get(route('projects.scans', $this->project))->assertOk();

            return $queries;
        };

        GateScans::scan($this->project);
        $few = $count();

        foreach (range(1, 5) as $ignored) {
            GateScans::scan($this->project);
        }
        $many = $count();

        $this->assertSame($few, $many, 'history query count must not grow with the number of scans');
    }

    public function test_project_detail_gate_reads_are_bounded(): void
    {
        GateScans::enable($this->project, new QualityGatePolicy([new MaxOpenFindingsRule(['high' => 0])]));
        GateScans::scan($this->project, candidatesByAnalyzer: ['semgrep' => array_map(fn ($i) => GateScans::candidate("f{$i}"), range(1, 30))]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->actingAs(User::factory()->create())->get(route('projects.show', $this->project))->assertOk();

        $this->assertLessThan(45, $queries);
    }

    public function test_severity_enum_is_the_only_severity_vocabulary_accepted_for_thresholds(): void
    {
        $this->assertNotContains('unknown', array_map(fn (Severity $s) => $s->value, Severity::ranked()));
    }
}
