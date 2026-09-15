<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-project scheduled audits (Phase 7.1.4). `audit_schedule`
 * is `disabled`/`daily`/`weekly`/`monthly` — a portable string, not a
 * database ENUM, matching the same reasoning as `users.role` (Phase
 * 7.1.3). Defaults to `disabled`: registering a project must never
 * silently start periodic resource consumption.
 *
 * Time-of-day is deliberately NOT per-project — see
 * `config('laradogs.projects.scheduled_audit_time')` — a single
 * instance-wide configured time (V1 scope control; see
 * App\Audit\Projects\ProjectAuditScheduler's docblock). Day-of-week
 * (`audit_schedule_day_of_week`, 0=Sunday..6=Saturday) and day-of-month
 * (`audit_schedule_day_of_month`, 1-31, clamped to the last real day of
 * a shorter month at calculation time — see that same class) ARE
 * per-project, since "which day" is the one genuinely per-project
 * choice this phase supports.
 *
 * `next_audit_at` is a persisted, indexed, precomputed due-timestamp —
 * the scheduler's due-query is a single indexed range scan
 * (`next_audit_at <= now()`), never a per-project recomputation of
 * "is this due" against live discovery/profile data.
 * `last_scheduled_audit_at` records when a scheduled (not manual/CLI)
 * audit was last actually dispatched — used only for the one-catch-up
 * "was LaraDogs down when this was due" decision, never a full missed-
 * occurrence backlog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('audit_schedule')->default('disabled')->after('path');
            $table->unsignedTinyInteger('audit_schedule_day_of_week')->nullable()->after('audit_schedule');
            $table->unsignedTinyInteger('audit_schedule_day_of_month')->nullable()->after('audit_schedule_day_of_week');
            $table->timestamp('next_audit_at')->nullable()->index()->after('audit_schedule_day_of_month');
            $table->timestamp('last_scheduled_audit_at')->nullable()->after('next_audit_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'audit_schedule',
                'audit_schedule_day_of_week',
                'audit_schedule_day_of_month',
                'next_audit_at',
                'last_scheduled_audit_at',
            ]);
        });
    }
};
