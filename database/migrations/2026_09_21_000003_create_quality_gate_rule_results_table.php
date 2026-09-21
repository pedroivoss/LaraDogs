<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per evaluated rule (per subject: a severity or an analyzer)
     * of a Quality Gate result. Kept relational so future history / CI /
     * API queries can filter by `rule_id` and `outcome` without decoding a
     * blob. Evidence is deliberately bounded: a short summary, the
     * observed/expected values as short strings, and at most a few dozen
     * finding PUBLIC ids (`finding_ids`) for navigation — never full
     * finding payloads (those stay in `findings`/`finding_occurrences`).
     */
    public function up(): void
    {
        Schema::create('quality_gate_rule_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_gate_result_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            $table->string('rule_id', 64);
            $table->string('subject', 64)->nullable();
            $table->string('outcome', 16);
            $table->string('summary', 500);
            $table->string('observed', 255)->nullable();
            $table->string('expected', 255)->nullable();
            $table->string('analyzer_id', 64)->nullable();
            $table->string('severity', 16)->nullable();
            $table->unsignedInteger('finding_count')->default(0);
            $table->json('finding_ids')->nullable();

            $table->timestamps();

            $table->index(['quality_gate_result_id', 'position'], 'qg_rule_results_result_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_gate_rule_results');
    }
};
