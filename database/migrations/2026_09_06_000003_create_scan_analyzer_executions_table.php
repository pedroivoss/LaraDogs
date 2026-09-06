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
        // Persists one row per App\Audit\Engine\Execution\AnalyzerExecution
        // (Phase 2) for a given scan. Critical for auto-resolution safety:
        // a finding may only be auto-resolved when ITS analyzer completed
        // with status=passed in this scan — never inferred from the scan
        // "existing" alone. See App\Audit\Findings\Ingestion\FindingReconciler.
        Schema::create('scan_analyzer_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('analyzer_id');
            $table->string('analyzer_name');
            $table->string('category');
            $table->string('status');
            $table->text('summary')->nullable();
            // A bounded list of {level, message, code} — analyzer
            // execution diagnostics (binary missing, malformed output,
            // internal error), never raw unbounded stdout/stderr.
            $table->json('diagnostics')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['scan_id', 'analyzer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_analyzer_executions');
    }
};
