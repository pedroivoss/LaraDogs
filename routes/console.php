<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 7.1.4: the ONLY thing the Scheduler ever does is dispatch —
// `DispatchDueProjectAudits` itself never runs an analyzer (see that
// class's own docblock). Every minute is cheap: the due-query is a
// single indexed range scan against `projects.next_audit_at`, and no
// project's schedule this phase supports resolves to a sub-minute
// cadence anyway (Daily/Weekly/Monthly), so a minute of latency between
// "due" and "enqueued" is immaterial.
Schedule::command('laradogs:project:dispatch-due-audits')
    ->everyMinute()
    ->withoutOverlapping();
