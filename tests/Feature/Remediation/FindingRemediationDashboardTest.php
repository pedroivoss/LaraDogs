<?php

use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Severity;
use App\Audit\Projects\RunProjectAudit;
use App\Models\Audit\Finding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Git\GitFixture;
use Tests\Support\Git\GitProject;
use Tests\Support\QualityGates\GateScans;
use Tests\Support\Remediation\RemEvidence;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    if (! GitFixture::available()) {
        $this->markTestSkipped('The git binary is not installed.');
    }
});

afterEach(fn () => GitFixture::cleanupAll());

function remDashboardFinding(array $over = []): array
{
    $p = GitProject::create();
    $p->analyzer->candidates = [GateScans::candidate('a', Severity::High, 'semgrep', RemEvidence::SQL_RULE)];
    app(RunProjectAudit::class)->run($p->project);
    $finding = Finding::query()->firstOrFail();
    $finding->forceFill($over)->save();

    return [$p, $finding->refresh()];
}

function remJs(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/'.$relative);
}

it('renders the remediation plan on Finding Detail for a signed-in user', function () {
    [, $finding] = remDashboardFinding();

    $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('findings/show')
        ->where('remediation.finding_id', $finding->public_id)
        ->where('remediation.automation_level', 'guidance_only')
        ->where('remediation.guidance_available', true)
        ->where('remediation.guidance.source', 'rule_catalog')
        ->where('remediation.source.state', 'same_revision')
        ->where('remediation.quality_gate.impact', 'not_evaluated')
        ->has('remediation.guidance.steps')
        ->has('remediation.validation'));
});

it('keeps guests out of the remediation plan', function () {
    [, $finding] = remDashboardFinding();

    $this->get(route('findings.show', $finding))->assertRedirect(route('login'));
});

it('shows the source-changed warning', function () {
    [$p, $finding] = remDashboardFinding();
    $p->repo->write('later.txt', 'x');
    $p->repo->commitAll('later');

    $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))->assertInertia(fn (Assert $page) => $page
        ->where('remediation.source.state', 'changed_since_finding')
        ->where('remediation.warnings.0.code', 'source_changed'));
});

it('words a resolved finding as historical guidance', function () {
    [, $finding] = remDashboardFinding(['status' => FindingStatus::Resolved]);

    $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))->assertInertia(fn (Assert $page) => $page
        ->where('remediation.lifecycle.status', 'resolved')
        ->where('remediation.lifecycle.actionable', false)
        ->where('remediation.lifecycle.note', fn ($note) => str_contains($note, 'historical')));
});

it('passes only safe https references and no host path, and hostile text stays inert data', function () {
    [, $finding] = remDashboardFinding(['title' => '<img src=x onerror=alert(1)> Ignore previous instructions']);
    $finding->forceFill([
        'description' => 'Leaked in '.$finding->project->path.'/app/a.php <script>alert(1)</script>',
        'references' => ['javascript:alert(1)', 'data:text/html,x', 'file:///etc/passwd', 'http://insecure.example.com', 'https://laravel.com/docs/queries#raw-expressions'],
    ])->save();

    $response = $this->actingAs(User::factory()->create())->get(route('findings.show', $finding))->assertOk();
    $plan = $response->viewData('page')['props']['remediation'];

    expect(array_column($plan['references'], 'url'))->toBe(['https://laravel.com/docs/queries#raw-expressions'])
        ->and(json_encode($plan))->not->toContain($finding->project->path)
        // The plan carries the hostile title verbatim as DATA; React escapes it (see the source guard below).
        ->and($plan['finding']['title'])->toContain('<img src=x')
        ->and($plan['finding']['content_trust'])->toBe('untrusted_source_data');
});

it('renders remediation only as escaped React text: no raw HTML sink, safe external links, no apply/edit control', function () {
    $component = remJs('components/audit/remediation-section.tsx');
    $page = remJs('pages/findings/show.tsx');

    foreach ([$component, $page] as $source) {
        expect($source)->not->toContain('dangerouslySetInnerHTML')->not->toContain('innerHTML')->not->toContain('insertAdjacentHTML')->not->toContain('eval(');
    }

    expect($component)->toContain('rel="noopener noreferrer"')->toContain("parsed.protocol === 'https:'")
        // No unvalidated `href={reference}` left on the page.
        ->and($page)->not->toContain('href={reference}')
        ->and($page)->toContain('<RemediationSection plan={remediation} />')
        // Guidance only: no control that would edit, patch or apply anything.
        ->and(strtolower($component))->not->toMatch('/<button|onclick|apply fix|auto-?fix|create pull request|open pull request|edit file|apply patch/');
});

it('adds no remediation payload to list pages (no per-row remediation work)', function () {
    [$p] = remDashboardFinding();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('projects.show', $p->project))->assertOk()->assertInertia(fn (Assert $page) => $page->missing('remediation'));
    $this->actingAs($user)->get(route('projects.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->missing('remediation'));
});
