<?php

namespace App\Audit\Projects;

use Carbon\CarbonImmutable;

/**
 * Pure date/time calculation for "when is this project's next scheduled
 * audit due" — no database access, no side effects, so it's trivially
 * testable against fixed reference instants (see the required daily/
 * weekly/monthly/edge-day test scenarios).
 *
 * Time-of-day is a single instance-wide setting
 * (`config('laradogs.projects.scheduled_audit_time')`, `HH:MM`,
 * interpreted in `config('app.timezone')` — UTC by default), not
 * per-project — a deliberate V1 scope limit (see
 * `docs/auditing/audit-execution.md`) rather than building a per-project
 * time picker. Every returned instant is in the application timezone;
 * nothing here ever touches a browser/request timezone.
 *
 * Monthly `dayOfMonth` is clamped to the actual last day of the target
 * month (e.g. 31 in April becomes the 30th) — chosen over restricting
 * selectable days in the UI, so "last day of the month" genuinely means
 * that in every month, not "skip months with fewer days."
 */
final readonly class ProjectAuditScheduler
{
    public function nextRunAfter(
        AuditSchedule $schedule,
        ?int $dayOfWeek,
        ?int $dayOfMonth,
        CarbonImmutable $after,
    ): ?CarbonImmutable {
        [$hour, $minute] = $this->configuredTimeOfDay();

        return match ($schedule) {
            AuditSchedule::Disabled => null,
            AuditSchedule::Daily => $this->nextDaily($after, $hour, $minute),
            AuditSchedule::Weekly => $this->nextWeekly($after, $dayOfWeek ?? 0, $hour, $minute),
            AuditSchedule::Monthly => $this->nextMonthly($after, $dayOfMonth ?? 1, $hour, $minute),
        };
    }

    /**
     * @return array{int,int} [hour, minute]
     */
    private function configuredTimeOfDay(): array
    {
        $configured = (string) config('laradogs.projects.scheduled_audit_time', '02:00');
        [$hour, $minute] = array_pad(explode(':', $configured, 2), 2, '0');

        return [
            max(0, min(23, (int) $hour)),
            max(0, min(59, (int) $minute)),
        ];
    }

    private function nextDaily(CarbonImmutable $after, int $hour, int $minute): CarbonImmutable
    {
        $candidate = $after->setTime($hour, $minute, 0);

        return $candidate->greaterThan($after) ? $candidate : $candidate->addDay();
    }

    private function nextWeekly(CarbonImmutable $after, int $dayOfWeek, int $hour, int $minute): CarbonImmutable
    {
        $candidate = $after->setTime($hour, $minute, 0);

        // Step forward at most 7 times to reach the target weekday —
        // bounded, never an unbounded/infinite loop.
        for ($i = 0; $i < 8; $i++) {
            if ((int) $candidate->dayOfWeek === $dayOfWeek && $candidate->greaterThan($after)) {
                return $candidate;
            }

            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    private function nextMonthly(CarbonImmutable $after, int $dayOfMonth, int $hour, int $minute): CarbonImmutable
    {
        $candidate = $this->clampedDateInMonth($after->year, $after->month, $dayOfMonth, $hour, $minute);

        if ($candidate->greaterThan($after)) {
            return $candidate;
        }

        $next = $after->addMonthNoOverflow();

        return $this->clampedDateInMonth($next->year, $next->month, $dayOfMonth, $hour, $minute);
    }

    private function clampedDateInMonth(int $year, int $month, int $dayOfMonth, int $hour, int $minute): CarbonImmutable
    {
        $daysInMonth = CarbonImmutable::createFromDate($year, $month, 1)->daysInMonth;
        $clampedDay = max(1, min($daysInMonth, $dayOfMonth));

        return CarbonImmutable::create($year, $month, $clampedDay, $hour, $minute, 0);
    }
}
