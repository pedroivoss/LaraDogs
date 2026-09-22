<?php

use App\Integrations\GitHub\RecordGitHubCheckRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 (CI & GitHub Integration). Purely additive and portable — plain
 * nullable string/bigint/timestamp columns, no vendor enum, no generated
 * column. Deliberately NOT a generic "GitHub state" table bolted onto the
 * Finding domain (see docs/integrations/github.md): one bounded row per
 * Scan, holding only what GitHub itself returned, never a response blob.
 *
 * `scan_id` is unique — at most one reported Check Run per Scan, which is
 * also how {@see RecordGitHubCheckRun} makes a
 * retried `--github-report` invocation for the SAME scan a safe no-op
 * (`already_reported`) instead of creating a duplicate Check Run.
 * `cascadeOnDelete` mirrors `quality_gate_results.scan_id` (Phase 8) — this
 * row has no meaning once its Scan is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_check_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('check_run_id');
            $table->string('html_url')->nullable();
            $table->string('conclusion', 32);
            $table->timestamp('reported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_check_reports');
    }
};
