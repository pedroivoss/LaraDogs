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
        // Finding is the stable, cross-scan LOGICAL IDENTITY of an issue —
        // not raw scanner output, not a single observation. See
        // ADR-0010 and docs/auditing/findings-lifecycle.md.
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Identity: NOT a primary key (see ADR-0010's fingerprint
            // collision note) — matching/correlation only. Uniqueness is
            // scoped per-project, per-algorithm-version.
            $table->string('fingerprint', 64);
            $table->string('fingerprint_version', 8);

            $table->string('rule_id');
            $table->string('analyzer_id');
            $table->string('category');
            $table->string('severity');
            $table->string('confidence');

            $table->string('title');
            $table->text('description')->nullable();
            $table->text('impact')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('cwe')->nullable();
            $table->string('cve')->nullable();
            $table->json('references')->nullable();
            $table->json('metadata')->nullable();

            $table->string('status');
            $table->text('status_reason')->nullable();

            // Scan deletion isn't a supported operation in this phase —
            // these FKs deliberately restrict (default, no cascade)
            // rather than cascade, so a Finding can never be silently
            // orphaned by removing one particular scan row it references.
            $table->foreignId('first_seen_scan_id')->constrained('scans');
            $table->timestamp('first_seen_at');
            $table->foreignId('last_seen_scan_id')->constrained('scans');
            $table->timestamp('last_seen_at');

            $table->timestamps();

            $table->unique(['project_id', 'fingerprint', 'fingerprint_version'], 'findings_project_fingerprint_unique');
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'analyzer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
