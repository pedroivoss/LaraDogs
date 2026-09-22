<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 (Git & Repository Integration). Purely additive and portable
 * (plain nullable string/boolean/timestamp columns — no vendor enum, no
 * generated column, no JSON tricks); nothing existing changes and no data is
 * rewritten.
 *
 * The immutable Git source snapshot of a Scan, captured BEFORE analyzers
 * run. `source_revision` already exists (Phase 3, always null until now)
 * and now holds the FULL commit SHA.
 *
 * - `source_type`: `git` | `none` | `bare` | `unavailable`. NULL means the
 *   source was never captured (every pre-Phase-9 scan) — history is never
 *   reconstructed from the current filesystem.
 * - `source_consistent`: TRUE = a CLEAN Git repository with a commit,
 *   identical before and after the analyzers (the only verified state);
 *   FALSE = source integrity NOT established, so absence of a finding proves
 *   nothing (no auto-resolution, gates never Pass on absence);
 *   NULL = a genuine non-Git target (no integrity claim exists) or not
 *   captured (legacy).
 * - `source_integrity_reason`: present exactly when `source_consistent` is
 *   FALSE — `changed_during_audit`, `dirty_at_start`, `no_commits`,
 *   `bare_repository`, `unavailable`, `unsafe_config` — so the UI can say
 *   "changed" only when it truly changed and "could not be proven" otherwise.
 * - No author name/email and no credentials are ever stored; `source_remote`
 *   is the SANITIZED origin URL (see RemoteUrlSanitizer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('source_type', 16)->nullable();
            $table->string('source_branch')->nullable();
            $table->boolean('source_detached')->nullable();
            $table->boolean('source_dirty')->nullable();
            $table->timestamp('source_commit_at')->nullable();
            $table->string('source_commit_subject')->nullable();
            $table->string('source_remote')->nullable();
            $table->boolean('source_consistent')->nullable();
            $table->string('source_integrity_reason', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn([
                'source_type', 'source_branch', 'source_detached', 'source_dirty',
                'source_commit_at', 'source_commit_subject', 'source_remote', 'source_consistent',
                'source_integrity_reason',
            ]);
        });
    }
};
