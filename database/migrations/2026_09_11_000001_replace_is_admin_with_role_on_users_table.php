<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.1.3: replaces `users.is_admin` (Phase 7.1.2) with a portable
 * string-backed `role` column (`owner`/`admin`/`user` — see
 * {@see Role}), deliberately NOT a vendor-specific database
 * ENUM type, so this stays valid on SQLite/MySQL/MariaDB/PostgreSQL alike.
 *
 * Upgrade semantics for an existing installation (deterministic, requires
 * no interactive input — see docs/self-hosting.md's "Upgrading" section):
 *
 * - `is_admin = false` -> `role = 'user'` (the column default already
 *   covers this; no row-by-row update needed).
 * - `is_admin = true`, exactly ONE such row -> `role = 'owner'`. Safe
 *   because a single pre-existing admin is unambiguously "the person who
 *   ran `laradogs:user:create-admin`" — there is no other candidate to
 *   confuse it with.
 * - `is_admin = true`, MORE THAN ONE such row -> `role = 'admin'` for
 *   ALL of them, deliberately NOT auto-selecting one as Owner. Silently
 *   picking one by arbitrary row order (e.g. lowest id) could hand
 *   Owner-only capabilities (managing every other Admin, promoting/
 *   demoting) to whichever account happened to be created first, which
 *   is not necessarily who the operator would choose. The operator
 *   instead runs `laradogs:user:claim-owner {email}` once, explicitly,
 *   after upgrading — see that command's docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('password');
        });

        $adminIds = DB::table('users')->where('is_admin', true)->pluck('id');

        if ($adminIds->count() === 1) {
            DB::table('users')->where('id', $adminIds->first())->update(['role' => 'owner']);
        } elseif ($adminIds->count() > 1) {
            DB::table('users')->whereIn('id', $adminIds)->update(['role' => 'admin']);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        DB::table('users')->where('role', '!=', 'user')->update(['is_admin' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
