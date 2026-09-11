<?php

use App\Audit\Engine\Contracts\AnalyzerCategory;
use App\Audit\Findings\Confidence;
use App\Audit\Findings\FindingCandidate;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Audit\Findings\Severity;
use App\Models\Audit\Finding;
use App\Models\Audit\FindingOccurrence;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Findings\SyntheticCandidates;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeIngestor(): FindingIngestor
{
    return new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService);
}

function makeIngestionProject(string $name = 'Example'): Project
{
    return Project::query()->create(['name' => $name, 'path' => '/workspace/'.strtolower($name)]);
}

function makeIngestionScan(Project $project): Scan
{
    return Scan::query()->create([
        'project_id' => $project->id,
        'status' => 'running',
        'started_at' => now(),
        'project_profile' => ['project' => ['type' => 'laravel']],
    ]);
}

it('creates a new Finding and an occurrence on first observation', function () {
    $project = makeIngestionProject();
    $scan = makeIngestionScan($project);

    $occurrence = makeIngestor()->ingest($project, $scan, SyntheticCandidates::sqlInjection());

    expect(Finding::query()->count())->toBe(1);
    expect(FindingOccurrence::query()->count())->toBe(1);

    $finding = Finding::query()->first();
    expect($finding->status)->toBe(FindingStatus::Open);
    expect($finding->severity)->toBe(Severity::High);
    expect($finding->confidence)->toBe(Confidence::High);
    expect($finding->first_seen_scan_id)->toBe($scan->id);
    expect($finding->last_seen_scan_id)->toBe($scan->id);
    expect($occurrence->finding_id)->toBe($finding->id);
    expect($occurrence->scan_id)->toBe($scan->id);
    expect($occurrence->file_path)->toBe('app/Repositories/UserRepository.php');
});

it('records a "created" history entry for a brand-new finding', function () {
    $project = makeIngestionProject();
    $scan = makeIngestionScan($project);

    makeIngestor()->ingest($project, $scan, SyntheticCandidates::sqlInjection());
    $finding = Finding::query()->first();

    $history = $finding->statusHistory()->first();
    expect($history->previous_status)->toBeNull();
    expect($history->new_status)->toBe(FindingStatus::Open);
    expect($history->reason)->toContain('Created');
});

it('reuses the same Finding for the same fingerprint observed in a later scan, creating a new occurrence', function () {
    $project = makeIngestionProject();
    $scanOne = makeIngestionScan($project);
    $scanTwo = makeIngestionScan($project);
    $ingestor = makeIngestor();

    $ingestor->ingest($project, $scanOne, SyntheticCandidates::sqlInjection());
    $ingestor->ingest($project, $scanTwo, SyntheticCandidates::sqlInjection());

    expect(Finding::query()->count())->toBe(1);
    expect(FindingOccurrence::query()->count())->toBe(2);

    $finding = Finding::query()->first();
    expect($finding->first_seen_scan_id)->toBe($scanOne->id);
    expect($finding->last_seen_scan_id)->toBe($scanTwo->id);
});

it('continues correlating the same issue when only its line number moves', function () {
    $project = makeIngestionProject();
    $scanOne = makeIngestionScan($project);
    $scanTwo = makeIngestionScan($project);
    $ingestor = makeIngestor();

    $ingestor->ingest($project, $scanOne, SyntheticCandidates::sqlInjection(lineStart: 42, lineEnd: 44));
    $ingestor->ingest($project, $scanTwo, SyntheticCandidates::sqlInjection(lineStart: 58, lineEnd: 60));

    expect(Finding::query()->count())->toBe(1);
    expect(FindingOccurrence::query()->count())->toBe(2);

    $occurrences = FindingOccurrence::query()->orderBy('id')->get();
    expect($occurrences[0]->line_start)->toBe(42);
    expect($occurrences[1]->line_start)->toBe(58);
});

it('treats the same file/code under a different rule as a different Finding', function () {
    $project = makeIngestionProject();
    $scan = makeIngestionScan($project);
    $ingestor = makeIngestor();

    $ingestor->ingest($project, $scan, SyntheticCandidates::sqlInjection(ruleId: 'LARA-SEC-023'));
    $ingestor->ingest($project, $scan, SyntheticCandidates::sqlInjection(ruleId: 'LARA-SEC-099'));

    expect(Finding::query()->count())->toBe(2);
});

it('treats the same fingerprint inputs in a different project as a different Finding', function () {
    $projectA = makeIngestionProject('Alpha');
    $projectB = makeIngestionProject('Beta');
    $scanA = makeIngestionScan($projectA);
    $scanB = makeIngestionScan($projectB);
    $ingestor = makeIngestor();

    $ingestor->ingest($projectA, $scanA, SyntheticCandidates::sqlInjection());
    $ingestor->ingest($projectB, $scanB, SyntheticCandidates::sqlInjection());

    expect(Finding::query()->count())->toBe(2);
    expect(Finding::query()->where('project_id', $projectA->id)->count())->toBe(1);
    expect(Finding::query()->where('project_id', $projectB->id)->count())->toBe(1);
});

it('tracks severity and confidence independently', function () {
    $project = makeIngestionProject();
    $scan = makeIngestionScan($project);

    // Severity: CRITICAL, Confidence: HIGH (vulnerable dependency).
    makeIngestor()->ingest($project, $scan, SyntheticCandidates::vulnerableDependency());
    $dependencyFinding = Finding::query()->latest('id')->first();
    expect($dependencyFinding->severity)->toBe(Severity::Critical);
    expect($dependencyFinding->confidence)->toBe(Confidence::High);

    // Severity: LOW, Confidence: MEDIUM (config issue) — independent axes,
    // not derived from one another.
    makeIngestor()->ingest($project, $scan, SyntheticCandidates::configIssue());
    $configFinding = Finding::query()->latest('id')->first();
    expect($configFinding->severity)->toBe(Severity::Low);
    expect($configFinding->confidence)->toBe(Confidence::Medium);
});

it('handles a candidate with no file/line/snippet (e.g. a dependency finding)', function () {
    $project = makeIngestionProject();
    $scan = makeIngestionScan($project);

    $occurrence = makeIngestor()->ingest($project, $scan, SyntheticCandidates::vulnerableDependency());

    expect($occurrence->file_path)->toBeNull();
    expect($occurrence->line_start)->toBeNull();
    expect($occurrence->code_snippet)->toBeNull();
});

it('redacts an obvious secret found in candidate metadata before persisting it', function () {
    $project = makeIngestionProject();
    $scan = makeIngestionScan($project);

    $candidate = new FindingCandidate(
        ruleId: 'LARA-SEC-050',
        analyzerId: 'env-scanner',
        category: AnalyzerCategory::Security,
        severity: Severity::High,
        confidence: Confidence::High,
        title: 'Secret committed to .env.example',
        codeSnippet: 'AWS_SECRET_ACCESS_KEY=abcdefghijklmnopqrstuvwxyz123456',
    );

    $occurrence = makeIngestor()->ingest($project, $scan, $candidate);

    expect($occurrence->code_snippet)->not->toContain('abcdefghijklmnopqrstuvwxyz123456');
    expect($occurrence->code_snippet)->toContain('****');
});

it('relies on the database unique constraint, not application locking alone, as the final guarantee against a duplicate Finding', function () {
    // lockForUpdate() only locks a ROW THAT ALREADY EXISTS — it cannot
    // prevent two transactions that both observe "no matching Finding
    // yet" from both attempting to insert one for the same (project,
    // fingerprint, fingerprint_version). This test proves the actual
    // final safeguard against a duplicate: the database's unique
    // constraint on that triple. See
    // docs/auditing/findings-lifecycle.md#concurrency-limits.
    $project = makeIngestionProject();
    $scan = makeIngestionScan($project);
    $candidate = SyntheticCandidates::sqlInjection();
    $fingerprint = (new Fingerprinter)->fingerprint($candidate);

    $attributes = [
        'project_id' => $project->id,
        'fingerprint' => $fingerprint,
        'fingerprint_version' => Fingerprinter::VERSION,
        'rule_id' => $candidate->ruleId,
        'analyzer_id' => $candidate->analyzerId,
        'category' => $candidate->category,
        'severity' => $candidate->severity,
        'confidence' => $candidate->confidence,
        'title' => $candidate->title,
        'status' => FindingStatus::Open,
        'first_seen_scan_id' => $scan->id,
        'first_seen_at' => now(),
        'last_seen_scan_id' => $scan->id,
        'last_seen_at' => now(),
    ];

    // The "winning" transaction of a hypothetical race.
    Finding::query()->create($attributes);

    // The "losing" transaction attempting the same insert must fail
    // loudly at the database level, never silently succeed and create a
    // second row for the same logical identity.
    expect(fn () => Finding::query()->create($attributes))->toThrow(QueryException::class);
    expect(Finding::query()->count())->toBe(1);
});

it('truncates an overly long candidate title before persisting, regardless of which analyzer produced it', function () {
    // Regression test (Phase 7.1.2 real UAT): findings.title is
    // `$table->string('title')` (varchar(255)) — a candidate title longer
    // than that overflowed the column with a hard SQLSTATE[22001] on
    // MySQL. FindingIngestor is the single choke point every analyzer's
    // FindingCandidate passes through, so the bound is enforced here
    // once, not duplicated per analyzer.
    $project = makeIngestionProject();
    $scan = makeIngestionScan($project);

    $longTitle = str_repeat('a very long title fragment ', 20);
    expect(strlen($longTitle))->toBeGreaterThan(255);

    $candidate = new FindingCandidate(
        ruleId: 'LARA-TEST-001',
        analyzerId: 'synthetic',
        category: AnalyzerCategory::Quality,
        severity: Severity::Info,
        confidence: Confidence::Low,
        title: $longTitle,
    );

    makeIngestor()->ingest($project, $scan, $candidate);

    $finding = Finding::query()->firstOrFail();
    expect(strlen($finding->title))->toBeLessThan(255)
        ->and($finding->title)->not->toBe($longTitle)
        ->and($finding->title)->toEndWith('...');
});
