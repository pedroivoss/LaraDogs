<?php

use App\Audit\Ci\CiOutcome;
use App\Audit\QualityGates\QualityGateOutcome;
use App\Console\Commands\ProjectGateCommand;
use App\Integrations\GitHub\GitHubCheckConclusion;

it('keeps the exact, stable exit-code contract (0/1/2/3/4, no sixth code)', function () {
    expect(CiOutcome::Passed->exitCode())->toBe(0)
        ->and(CiOutcome::Failed->exitCode())->toBe(1)
        ->and(CiOutcome::Indeterminate->exitCode())->toBe(2)
        ->and(CiOutcome::OperationalError->exitCode())->toBe(ProjectGateCommand::EXIT_OPERATIONAL_ERROR)->toBe(3)
        ->and(CiOutcome::NotEvaluated->exitCode())->toBe(ProjectGateCommand::EXIT_NOT_EVALUATED)->toBe(4)
        ->and(CiOutcome::cases())->toHaveCount(5);
});

it('agrees with the Quality Gate outcome exit codes it maps from', function () {
    foreach (QualityGateOutcome::cases() as $gate) {
        expect(CiOutcome::resolve(false, $gate)->exitCode())->toBe($gate->exitCode());
    }
});

it('resolves an absent gate result to Not Evaluated', function () {
    expect(CiOutcome::resolve(false, null))->toBe(CiOutcome::NotEvaluated);
});

it('lets an operational error override EVERY gate outcome, including Passed', function () {
    foreach ([null, ...QualityGateOutcome::cases()] as $gate) {
        expect(CiOutcome::resolve(true, $gate))->toBe(CiOutcome::OperationalError);
    }
});

it('maps each final CI outcome to exactly one GitHub conclusion', function () {
    expect(GitHubCheckConclusion::forCiOutcome(CiOutcome::Passed))->toBe(GitHubCheckConclusion::Success)
        ->and(GitHubCheckConclusion::forCiOutcome(CiOutcome::Failed))->toBe(GitHubCheckConclusion::Failure)
        ->and(GitHubCheckConclusion::forCiOutcome(CiOutcome::Indeterminate))->toBe(GitHubCheckConclusion::ActionRequired)
        ->and(GitHubCheckConclusion::forCiOutcome(CiOutcome::OperationalError))->toBe(GitHubCheckConclusion::Failure)
        ->and(GitHubCheckConclusion::forCiOutcome(CiOutcome::NotEvaluated))->toBe(GitHubCheckConclusion::Neutral);
});

it('can NEVER produce success (or neutral) for an operational error or an Indeterminate gate', function () {
    foreach ([null, ...QualityGateOutcome::cases()] as $gate) {
        $conclusion = GitHubCheckConclusion::forCiOutcome(CiOutcome::resolve(true, $gate));

        expect($conclusion)->toBe(GitHubCheckConclusion::Failure);
    }

    expect(GitHubCheckConclusion::forCiOutcome(CiOutcome::Indeterminate))->not->toBe(GitHubCheckConclusion::Success)->not->toBe(GitHubCheckConclusion::Neutral);
});

it('only a fully successful CI outcome maps to success', function () {
    $successes = array_filter(CiOutcome::cases(), fn (CiOutcome $o) => GitHubCheckConclusion::forCiOutcome($o) === GitHubCheckConclusion::Success);

    expect($successes)->toBe([CiOutcome::Passed]);
});
