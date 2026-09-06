<?php

use App\Audit\Findings\ActorType;
use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Fingerprint\Fingerprinter;
use App\Audit\Findings\Ingestion\FindingIngestor;
use App\Audit\Findings\Lifecycle\FindingLifecycleService;
use App\Audit\Findings\Redaction\EvidenceRedactor;
use App\Models\Audit\Finding;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Findings\SyntheticCandidates;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeLifecycleFinding(): Finding
{
    $project = Project::query()->create(['name' => 'Example', 'path' => '/workspace/example']);
    $scan = Scan::query()->create([
        'project_id' => $project->id,
        'status' => 'running',
        'started_at' => now(),
        'project_profile' => ['project' => ['type' => 'laravel']],
    ]);

    $ingestor = new FindingIngestor(new Fingerprinter, new EvidenceRedactor, new FindingLifecycleService);
    $ingestor->ingest($project, $scan, SyntheticCandidates::sqlInjection());

    return Finding::query()->firstOrFail();
}

it('moves from open to confirmed without requiring a reason', function () {
    $finding = makeLifecycleFinding();
    $lifecycle = new FindingLifecycleService;

    $lifecycle->transition($finding, FindingStatus::Confirmed, ActorType::User, 'triager-1');

    expect($finding->fresh()->status)->toBe(FindingStatus::Confirmed);
});

it('requires a reason to mark a finding as false positive', function () {
    $finding = makeLifecycleFinding();
    $lifecycle = new FindingLifecycleService;

    expect(fn () => $lifecycle->transition($finding, FindingStatus::FalsePositive, ActorType::User, 'triager-1'))
        ->toThrow(InvalidArgumentException::class);

    $lifecycle->transition($finding, FindingStatus::FalsePositive, ActorType::User, 'triager-1', 'Detector misfires on this pattern.');

    expect($finding->fresh()->status)->toBe(FindingStatus::FalsePositive);
});

it('requires a reason to mark a finding as accepted risk', function () {
    $finding = makeLifecycleFinding();
    $lifecycle = new FindingLifecycleService;

    expect(fn () => $lifecycle->transition($finding, FindingStatus::AcceptedRisk, ActorType::User, 'triager-1', ''))
        ->toThrow(InvalidArgumentException::class);

    $lifecycle->transition($finding, FindingStatus::AcceptedRisk, ActorType::User, 'triager-1', 'Mitigated at the network layer.');

    expect($finding->fresh()->status)->toBe(FindingStatus::AcceptedRisk);
});

it('requires a reason to mark a finding as ignored', function () {
    $finding = makeLifecycleFinding();
    $lifecycle = new FindingLifecycleService;

    expect(fn () => $lifecycle->transition($finding, FindingStatus::Ignored, ActorType::User, 'triager-1'))
        ->toThrow(InvalidArgumentException::class);

    $lifecycle->transition($finding, FindingStatus::Ignored, ActorType::User, 'triager-1', 'Out of scope for this project.');

    expect($finding->fresh()->status)->toBe(FindingStatus::Ignored);
});

it('persists every transition as an append-only history row with previous/new status and actor', function () {
    $finding = makeLifecycleFinding();
    $lifecycle = new FindingLifecycleService;

    $lifecycle->transition($finding, FindingStatus::Confirmed, ActorType::User, 'triager-1', 'Verified manually.');
    $lifecycle->transition($finding, FindingStatus::AcceptedRisk, ActorType::User, 'triager-2', 'Accepted for this release.');

    $history = $finding->statusHistory()->orderBy('id')->get();

    expect($history)->toHaveCount(3); // created + confirmed + accepted_risk
    expect($history[1]->previous_status)->toBe(FindingStatus::Open);
    expect($history[1]->new_status)->toBe(FindingStatus::Confirmed);
    expect($history[1]->actor_type)->toBe(ActorType::User);
    expect($history[1]->actor_identifier)->toBe('triager-1');
    expect($history[2]->previous_status)->toBe(FindingStatus::Confirmed);
    expect($history[2]->new_status)->toBe(FindingStatus::AcceptedRisk);
    expect($history[2]->reason)->toBe('Accepted for this release.');
});
