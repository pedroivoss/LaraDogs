<?php

use App\Audit\Projects\AuditSchedule;
use App\Audit\Projects\ProjectAuditScheduler;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['laradogs.projects.scheduled_audit_time' => '02:00']);
});

it('returns null for a Disabled schedule — never computes a next run', function () {
    $scheduler = new ProjectAuditScheduler;

    $next = $scheduler->nextRunAfter(AuditSchedule::Disabled, null, null, CarbonImmutable::parse('2026-09-13 10:00:00'));

    expect($next)->toBeNull();
});

it('computes the next Daily run at the configured time, today if still ahead, otherwise tomorrow', function () {
    $scheduler = new ProjectAuditScheduler;

    // Before 02:00 today -> today at 02:00.
    $next = $scheduler->nextRunAfter(AuditSchedule::Daily, null, null, CarbonImmutable::parse('2026-09-13 01:00:00'));
    expect($next->toDateTimeString())->toBe('2026-09-13 02:00:00');

    // After 02:00 today -> tomorrow at 02:00, not "24 hours from now".
    $next = $scheduler->nextRunAfter(AuditSchedule::Daily, null, null, CarbonImmutable::parse('2026-09-13 15:30:00'));
    expect($next->toDateTimeString())->toBe('2026-09-14 02:00:00');
});

it('computes the next Weekly run on the configured weekday and time, never more than 7 days ahead', function () {
    $scheduler = new ProjectAuditScheduler;

    // 2026-09-13 is a Sunday (dayOfWeek 0). Target Wednesday (3).
    $next = $scheduler->nextRunAfter(AuditSchedule::Weekly, 3, null, CarbonImmutable::parse('2026-09-13 10:00:00'));
    expect($next->toDateTimeString())->toBe('2026-09-16 02:00:00')
        ->and((int) $next->dayOfWeek)->toBe(3);

    // If today itself is the target weekday but the time already passed,
    // it must roll to NEXT week, not fire again today.
    $next = $scheduler->nextRunAfter(AuditSchedule::Weekly, 0, null, CarbonImmutable::parse('2026-09-13 10:00:00'));
    expect($next->toDateTimeString())->toBe('2026-09-20 02:00:00');
});

it('computes the next Monthly run on the configured day-of-month and time', function () {
    $scheduler = new ProjectAuditScheduler;

    // Day 20, still ahead this month.
    $next = $scheduler->nextRunAfter(AuditSchedule::Monthly, null, 20, CarbonImmutable::parse('2026-09-13 10:00:00'));
    expect($next->toDateTimeString())->toBe('2026-09-20 02:00:00');

    // Day already passed this month -> next month, same day.
    $next = $scheduler->nextRunAfter(AuditSchedule::Monthly, null, 5, CarbonImmutable::parse('2026-09-13 10:00:00'));
    expect($next->toDateTimeString())->toBe('2026-10-05 02:00:00');
});

it('clamps a Monthly day-of-month to the last real day of a shorter month instead of overflowing into the next one', function () {
    $scheduler = new ProjectAuditScheduler;

    // April has only 30 days — day 31 clamps to the 30th, not "May 1st".
    $next = $scheduler->nextRunAfter(AuditSchedule::Monthly, null, 31, CarbonImmutable::parse('2026-04-01 00:00:00'));
    expect($next->toDateTimeString())->toBe('2026-04-30 02:00:00');

    // February (2026, not a leap year) has 28 days.
    $next = $scheduler->nextRunAfter(AuditSchedule::Monthly, null, 31, CarbonImmutable::parse('2026-02-01 00:00:00'));
    expect($next->toDateTimeString())->toBe('2026-02-28 02:00:00');
});
