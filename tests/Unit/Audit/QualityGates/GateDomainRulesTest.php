<?php

use App\Audit\Findings\FindingStatus;
use App\Audit\Findings\Severity;
use App\Audit\QualityGates\QualityGateOutcome;

// --- Severity: the single trusted ordering ---

it('ranks severities Critical > High > Medium > Low > Info and gives Unknown no rank', function () {
    expect(Severity::Critical->rank())->toBeGreaterThan(Severity::High->rank())
        ->and(Severity::High->rank())->toBeGreaterThan(Severity::Medium->rank())
        ->and(Severity::Medium->rank())->toBeGreaterThan(Severity::Low->rank())
        ->and(Severity::Low->rank())->toBeGreaterThan(Severity::Info->rank())
        ->and(Severity::Unknown->rank())->toBeNull();
});

it('lists ranked severities most severe first and never includes Unknown', function () {
    expect(Severity::ranked())->toBe([Severity::Critical, Severity::High, Severity::Medium, Severity::Low, Severity::Info]);
});

it('treats a severity as at-or-above a threshold by rank, inclusive', function () {
    expect(Severity::High->isAtOrAbove(Severity::High))->toBeTrue()
        ->and(Severity::Critical->isAtOrAbove(Severity::High))->toBeTrue()
        ->and(Severity::Medium->isAtOrAbove(Severity::High))->toBeFalse()
        ->and(Severity::Info->isAtOrAbove(Severity::Low))->toBeFalse();
});

it('never lets an Unknown severity slip under any threshold (fail closed)', function () {
    foreach (Severity::ranked() as $threshold) {
        expect(Severity::Unknown->isAtOrAbove($threshold))->toBeTrue();
    }
});

it('refuses an unranked severity as a threshold', function () {
    Severity::High->isAtOrAbove(Severity::Unknown);
})->throws(InvalidArgumentException::class);

// --- Finding status eligibility: the central rule ---

it('counts exactly Open and Confirmed findings toward a Quality Gate', function (FindingStatus $status, bool $counts) {
    expect($status->countsTowardQualityGate())->toBe($counts);
})->with([
    'open counts' => [FindingStatus::Open, true],
    'confirmed counts' => [FindingStatus::Confirmed, true],
    'resolved does not' => [FindingStatus::Resolved, false],
    'false positive does not' => [FindingStatus::FalsePositive, false],
    'ignored does not' => [FindingStatus::Ignored, false],
    'accepted risk does not (V1)' => [FindingStatus::AcceptedRisk, false],
]);

it('exposes the eligible statuses from the same single rule', function () {
    expect(FindingStatus::qualityGateEligible())->toBe([FindingStatus::Open, FindingStatus::Confirmed]);
});

// --- Outcome combination ---

it('combines rule outcomes with Failed > Indeterminate > Passed', function () {
    $P = QualityGateOutcome::Passed;
    $F = QualityGateOutcome::Failed;
    $I = QualityGateOutcome::Indeterminate;

    expect(QualityGateOutcome::combine([$P, $P]))->toBe($P)
        ->and(QualityGateOutcome::combine([$P, $I]))->toBe($I)
        ->and(QualityGateOutcome::combine([$I, $F, $P]))->toBe($F)
        ->and(QualityGateOutcome::combine([$F]))->toBe($F);
});

it('never fabricates a Pass from zero rule results', function () {
    expect(QualityGateOutcome::combine([]))->toBe(QualityGateOutcome::Indeterminate);
});

it('maps outcomes to the documented V1 exit codes and labels', function () {
    expect(QualityGateOutcome::Passed->exitCode())->toBe(0)
        ->and(QualityGateOutcome::Failed->exitCode())->toBe(1)
        ->and(QualityGateOutcome::Indeterminate->exitCode())->toBe(2)
        ->and(QualityGateOutcome::Passed->label())->toBe('Passed')
        ->and(QualityGateOutcome::Failed->label())->toBe('Failed')
        ->and(QualityGateOutcome::Indeterminate->label())->toBe('Indeterminate');
});
