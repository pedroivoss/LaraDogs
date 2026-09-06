<?php

use App\Audit\Findings\FindingStatus;

it('requires a reason only for accepted-risk, false-positive, and ignored', function () {
    expect(FindingStatus::AcceptedRisk->requiresReason())->toBeTrue();
    expect(FindingStatus::FalsePositive->requiresReason())->toBeTrue();
    expect(FindingStatus::Ignored->requiresReason())->toBeTrue();
    expect(FindingStatus::Open->requiresReason())->toBeFalse();
    expect(FindingStatus::Confirmed->requiresReason())->toBeFalse();
    expect(FindingStatus::Resolved->requiresReason())->toBeFalse();
});

it('treats accepted-risk, false-positive, and ignored as suppressed', function () {
    expect(FindingStatus::AcceptedRisk->isSuppressed())->toBeTrue();
    expect(FindingStatus::FalsePositive->isSuppressed())->toBeTrue();
    expect(FindingStatus::Ignored->isSuppressed())->toBeTrue();
    expect(FindingStatus::Open->isSuppressed())->toBeFalse();
    expect(FindingStatus::Confirmed->isSuppressed())->toBeFalse();
    expect(FindingStatus::Resolved->isSuppressed())->toBeFalse();
});
