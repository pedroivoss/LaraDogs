<?php

use App\Audit\Projects\RegisterProject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Known behavior on an existing installation that already has duplicate
 * `projects.path` values (only possible via direct DB manipulation or a
 * pre-`RegisterProject` custom script — no shipped code path has ever
 * created a `Project` row without going through
 * {@see RegisterProject}, which enforces uniqueness
 * itself before this index existed): this migration will FAIL LOUDLY —
 * the database rejects `ADD UNIQUE` when the column already contains
 * duplicates, so the migration aborts and the deployment/upgrade stops,
 * rather than silently succeeding or silently dropping/merging rows.
 * This is the deliberately safe outcome. No automatic data-fixing
 * migration is provided — deduplicating `projects.path` (deciding which
 * duplicate row is canonical, and what happens to its scans/findings) is
 * an operational decision only a human running that specific installation
 * can safely make. See docs/auditing/projects.md's Known limitations.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unique('path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['path']);
        });
    }
};
