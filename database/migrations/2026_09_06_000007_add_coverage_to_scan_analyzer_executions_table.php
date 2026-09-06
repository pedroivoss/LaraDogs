<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Phase 3.1 correction: an analyzer completing PASSED is not
        // equivalent to it having verified the specific rule(s) behind an
        // old finding — a rule can be removed/disabled/not-loaded while
        // the analyzer itself still runs cleanly. `coverage` is the
        // analyzer's own declaration of what it actually verified this
        // run (mode + explicit rule ids + ruleset version for
        // provenance only). Nullable/JSON, storage-only — no
        // vendor-specific JSON querying. See
        // App\Audit\Findings\Ingestion\FindingReconciler and ADR-0010.
        Schema::table('scan_analyzer_executions', function (Blueprint $table) {
            $table->json('coverage')->nullable()->after('diagnostics');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scan_analyzer_executions', function (Blueprint $table) {
            $table->dropColumn('coverage');
        });
    }
};
