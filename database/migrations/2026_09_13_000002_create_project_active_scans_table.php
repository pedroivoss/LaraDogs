<?php

use App\Audit\Projects\RunProjectAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A portable, DB-enforced mutex — not a Redis lock, not an application-
 * level "check then insert" race (see App\Audit\Projects\RunProjectAudit's
 * docblock for why that alone was an accepted, documented trade-off
 * before this phase, when only a human-triggered CLI command could ever
 * start an audit). Now that audits are unattended (Dashboard + Scheduler
 * dispatch through the same queue), two nearly-simultaneous dispatches
 * for the same Project — a double-click, or a manual dispatch racing a
 * scheduled one — must be impossible to both succeed.
 *
 * `project_id` is the PRIMARY KEY: at most one row can ever exist per
 * project, on every supported database (SQLite/MySQL/MariaDB/
 * PostgreSQL) without any vendor-specific locking clause. Inserting a
 * second row for a project that already has one fails with a real
 * unique-constraint violation — the actual concurrency guarantee, not
 * merely the advisory "no Running scan found" query
 * {@see RunProjectAudit} already performed. The row
 * is deleted the moment the Scan it tracks leaves the active
 * (Queued/Running) state — completed, failed, or reclaimed as stale —
 * never left behind as permanent bookkeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_active_scans', function (Blueprint $table) {
            $table->foreignId('project_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_active_scans');
    }
};
