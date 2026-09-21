<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The immutable Quality Gate EVALUATION of ONE scan (Phase 8) —
     * what LaraDogs observed against a policy, kept apart from the policy
     * itself. At most one result per scan (`scan_id` unique); a scan
     * evaluated while no gate was enabled simply has no row — there is
     * deliberately no fake "Passed" for a disabled gate, and no row is
     * ever invented for scans that pre-date this feature.
     *
     * `outcome` is a plain string (`passed` / `failed` / `indeterminate`,
     * see QualityGateOutcome), never a vendor enum. `policy_revision` +
     * `policy_snapshot` record exactly which rules judged this scan, so the
     * result stays interpretable and truthful after the project's policy
     * changes. Rule-level detail lives in `quality_gate_rule_results`.
     */
    public function up(): void
    {
        Schema::create('quality_gate_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('outcome', 16);
            $table->unsignedInteger('policy_revision');
            $table->json('policy_snapshot');
            $table->foreignId('baseline_scan_id')->nullable()->constrained('scans')->nullOnDelete();

            $table->unsignedSmallInteger('rules_total')->default(0);
            $table->unsignedSmallInteger('rules_failed')->default(0);
            $table->unsignedSmallInteger('rules_indeterminate')->default(0);

            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['project_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_gate_results');
    }
};
