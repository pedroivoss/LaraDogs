<?php

namespace Tests\Feature\Database;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Exercises the Phase 7.1.2 -> 7.1.3 upgrade migration
 * (`2026_09_11_000001_replace_is_admin_with_role_on_users_table`) against
 * simulated PRE-migration data, not just the final schema shape.
 * RefreshDatabase already ran every migration (including this one) before
 * any test starts; each upgrade-scenario test below first reconstructs
 * the OLD `is_admin`-based schema, inserts data as it would exist on a
 * real upgrading installation, re-runs this migration's `up()` in
 * isolation, and asserts the resulting `role` values. The test suite's
 * SQLite connection supports transactional DDL, so RefreshDatabase's
 * per-test transaction rollback cleanly undoes all of this afterward —
 * no state leaks into later tests.
 */
class RoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_database_defaults_every_user_to_the_user_role(): void
    {
        $user = User::factory()->create();

        $this->assertSame(Role::User, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_existing_non_admin_users_migrate_to_the_user_role(): void
    {
        $this->reconstructPreMigrationSchema();
        $id = $this->insertLegacyUser('plain@example.com', isAdmin: false);

        $this->replayMigration()->up();

        $this->assertSame('user', DB::table('users')->where('id', $id)->value('role'));
    }

    public function test_a_single_existing_admin_becomes_owner(): void
    {
        $this->reconstructPreMigrationSchema();
        $ownerId = $this->insertLegacyUser('sole-admin@example.com', isAdmin: true);
        $this->insertLegacyUser('nobody@example.com', isAdmin: false);

        $this->replayMigration()->up();

        $this->assertSame('owner', DB::table('users')->where('id', $ownerId)->value('role'));
    }

    public function test_multiple_existing_admins_all_become_admin_never_owner(): void
    {
        $this->reconstructPreMigrationSchema();
        $firstId = $this->insertLegacyUser('admin-one@example.com', isAdmin: true);
        $secondId = $this->insertLegacyUser('admin-two@example.com', isAdmin: true);

        $this->replayMigration()->up();

        $this->assertSame('admin', DB::table('users')->where('id', $firstId)->value('role'));
        $this->assertSame('admin', DB::table('users')->where('id', $secondId)->value('role'));
        $this->assertSame(0, DB::table('users')->where('role', 'owner')->count());
    }

    public function test_the_role_column_is_a_plain_portable_string_not_a_vendor_enum_type(): void
    {
        // Driver-reported type names differ (SQLite here reports
        // "varchar"), so the meaningful assertion is the negative one:
        // never the database's own vendor-specific ENUM type, which
        // `$table->string('role')` (used by the migration under test)
        // never produces on any supported driver.
        $this->assertNotSame('enum', strtolower(Schema::getColumnType('users', 'role')));
    }

    private function replayMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_11_000001_replace_is_admin_with_role_on_users_table.php');

        return $migration;
    }

    private function reconstructPreMigrationSchema(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
            $table->boolean('is_admin')->default(false);
        });
    }

    private function insertLegacyUser(string $email, bool $isAdmin): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => $email,
            'email' => $email,
            'password' => 'hashed-password-placeholder',
            'is_admin' => $isAdmin,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
