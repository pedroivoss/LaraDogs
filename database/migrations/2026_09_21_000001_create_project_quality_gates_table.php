<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Project's optional Quality Gate POLICY (Phase 8) — what should be
     * true, never what was observed (that is `quality_gate_results`).
     *
     * One row per project, created only when a policy is first saved: a
     * project with NO row has no gate, i.e. it is disabled — no existing
     * project can suddenly start failing because of this migration, and
     * nothing here ever enables a gate automatically.
     *
     * `policy` is a small bounded JSON document that is ONLY ever read
     * back through the typed `QualityGatePolicy` value object (strict
     * validation, no expressions, no executable content) — never
     * interpreted as free-form config. It is kept when a gate is disabled
     * so re-enabling restores it. `revision` increases monotonically on
     * every effective change (enabled flag or rules) and is copied, with a
     * snapshot of the policy, onto every result evaluated under it — a
     * later edit therefore never rewrites what an earlier scan was judged
     * against.
     *
     * Portable: plain integer/boolean/json columns, no vendor enum.
     */
    public function up(): void
    {
        Schema::create('project_quality_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('revision')->default(0);
            $table->json('policy')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_quality_gates');
    }
};
