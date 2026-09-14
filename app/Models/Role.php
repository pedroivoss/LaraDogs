<?php

namespace App\Models;

use App\Policies\UserPolicy;

/**
 * LaraDogs' entire authorization model (Phase 7.1.3) — deliberately three
 * flat cases, no general-purpose RBAC:
 *
 * - Owner: the person controlling this self-hosted installation. Exactly
 *   one active Owner at a time (enforced in {@see UserPolicy}
 *   and by application logic, not a database constraint — see that
 *   class's docblock). Never manageable through the Settings → Users UI,
 *   including by themselves — self-service is the existing Settings →
 *   Profile/Security pages, same as every other role.
 * - Admin: can register projects and manage User (never Admin or Owner)
 *   accounts.
 * - User: normal Dashboard access, no management capabilities.
 *
 * Replaces the earlier `users.is_admin` boolean (Phase 7.1.2) — see
 * `database/migrations/2026_09_11_000001_replace_is_admin_with_role_on_users_table.php`
 * for the backfill semantics. A plain string-backed enum column
 * (`users.role`), not a vendor-specific database ENUM type, so it stays
 * portable across SQLite/MySQL/MariaDB/PostgreSQL.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case User = 'user';
}
