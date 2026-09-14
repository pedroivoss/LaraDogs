<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Policies\UserPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deactivation revokes access without deleting the account — see
 * {@see EnsureUserIsActive} (blocks both login and
 * any already-authenticated session on the very next request) and
 * {@see UserPolicy} (Owner can never be deactivated).
 * Existing rows default to `true` (active), so no installation loses
 * access on upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
