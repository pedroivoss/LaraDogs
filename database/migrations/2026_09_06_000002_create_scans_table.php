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
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('laradogs_version')->nullable();
            // Not populated yet (no Git integration until a later phase) —
            // column exists now so history stays meaningful once it is.
            $table->string('source_revision')->nullable();
            // Snapshot of the ProjectProfile (Phase 1) at scan time, so a
            // Scan is self-describing independent of the project's
            // CURRENT detected stack. Storage only — never queried by its
            // internal JSON structure (see ADR-0007/ADR-0010).
            $table->json('project_profile');
            $table->json('environment')->nullable();
            // Basic aggregate counts for this scan (e.g. observed/
            // auto-resolved/by-severity) — NOT the full NEW/RESOLVED/
            // UNCHANGED/REGRESSED scan-to-scan comparison, which is
            // Phase 8 scope.
            $table->json('findings_summary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
