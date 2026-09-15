<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.1.4 (Audit Execution & Scheduling). Purely additive — no
 * existing column's type/nullability changes (this project has no
 * `doctrine/dbal` dependency, which Laravel's `->change()` requires, and
 * adding one solely for this would be an unnecessary new dependency for
 * what additive columns already solve):
 *
 * - `origin`/`initiated_by_user_id`: provenance (see
 *   App\Audit\Findings\ScanOrigin). `initiated_by_user_id` is a real FK,
 *   not a denormalized string snapshot like `FindingStatusHistory.actor_identifier`
 *   — safe because LaraDogs never hard-deletes a User (Phase 7.1.3
 *   deactivation only), so `nullOnDelete` is a defensive fallback, not a
 *   real expected path. Deliberately never rendered as another user's
 *   identity in any Owner/Admin/User-shared UI (Scan History, Project
 *   Detail) — only the `origin` category is ever displayed — so this
 *   column cannot become an Owner-privacy leak (see Phase 7.1.3's
 *   authorization model) even though the raw data exists for internal
 *   provenance.
 * - `running_at`: existing `started_at` keeps its Phase 3 meaning
 *   unchanged ("when this Scan row was created" — i.e. queued time; for
 *   every Scan before this phase, queued and running were the same
 *   instant, so no historical data is reinterpreted incorrectly).
 *   `running_at` is the NEW, separate "a worker actually began
 *   executing analyzers" timestamp, nullable because a `Queued` scan
 *   doesn't have one yet.
 * - `heartbeat_at`: updated between analyzer stages while `Running` (see
 *   App\Audit\Findings\Ingestion\ScanRunner) — a `Running` scan whose
 *   heartbeat has gone stale is a crashed worker, distinct from one that
 *   is just a genuinely long-running Semgrep pass with a fresh
 *   heartbeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('origin')->default('cli')->after('status');
            $table->foreignId('initiated_by_user_id')->nullable()->after('origin')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('running_at')->nullable()->after('started_at');
            $table->timestamp('heartbeat_at')->nullable()->after('running_at');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('initiated_by_user_id');
            $table->dropColumn(['origin', 'running_at', 'heartbeat_at']);
        });
    }
};
