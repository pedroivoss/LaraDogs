<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 (MCP / IDE Integration). Purely additive and portable — plain
 * string/timestamp columns, no vendor enum, no generated column. Nothing is
 * created by the migration: no user receives a token automatically, and no
 * default credential exists.
 *
 * A token is `ldmcp_<token_id>_<secret>`. Only `token_id` (a public,
 * indexed lookup key — NOT a secret) and `secret_hash` (SHA-256 of a 256-bit
 * random secret) are stored; the plaintext token is shown exactly once, at
 * creation, and never persisted or logged. Authorization is always judged
 * against the principal's CURRENT role/activation at request time, never a
 * snapshot in this row: `scope` only caps what the token may EVER do (`read`
 * or `audit`), it never grants more than the user's role allows.
 *
 * `revoked_at` is the revocation switch (rows are kept for auditability).
 * `user_id` restricts to delete: LaraDogs never hard-deletes a user
 * (deactivation only, Phase 7.1.3), so tokens can never dangle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_id', 24)->unique();
            $table->string('secret_hash', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('scope', 16);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
