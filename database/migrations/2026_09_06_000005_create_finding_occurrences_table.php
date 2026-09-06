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
        // The evidence observed for a Finding in ONE specific scan — kept
        // separate from `findings` so a line moving, or code being
        // reformatted, never overwrites older evidence; every scan's
        // observation is its own row. See ADR-0010.
        Schema::create('finding_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();

            $table->string('file_path')->nullable();
            $table->unsignedInteger('line_start')->nullable();
            $table->unsignedInteger('line_end')->nullable();
            $table->text('code_snippet')->nullable();
            $table->text('context_code')->nullable();
            $table->json('evidence')->nullable();

            $table->string('rule_version')->nullable();
            $table->string('analyzer_version')->nullable();

            $table->timestamp('observed_at');
            $table->timestamps();

            // At most one occurrence per (finding, scan) — an analyzer
            // reports a fingerprint at most once per run; a second
            // ingestion attempt for the same pair updates rather than
            // duplicates.
            $table->unique(['finding_id', 'scan_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finding_occurrences');
    }
};
