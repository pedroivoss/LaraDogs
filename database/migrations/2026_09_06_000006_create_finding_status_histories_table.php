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
        // Append-only audit trail of a Finding's lifecycle transitions
        // (created, status changed, auto-resolved, reopened/regressed).
        // Deliberately does NOT log every re-observation — that's what
        // finding_occurrences already is; this table is scoped to
        // IDENTITY/STATUS events only, per ADR-0010 (no full event
        // sourcing).
        Schema::create('finding_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            // Nullable + survives scan deletion: the audit trail matters
            // more than the scan link once a scan is (hypothetically)
            // removed.
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();

            // Null only for the very first row (finding creation) — every
            // subsequent row has a real previous_status.
            $table->string('previous_status')->nullable();
            $table->string('new_status');
            $table->text('reason')->nullable();

            $table->string('actor_type');
            $table->string('actor_identifier')->nullable();

            $table->timestamp('created_at');

            $table->index(['finding_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finding_status_histories');
    }
};
