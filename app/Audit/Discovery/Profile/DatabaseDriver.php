<?php

namespace App\Audit\Discovery\Profile;

/**
 * Database drivers Project Discovery can recognize in an *audited*
 * project's static configuration. This is unrelated to which databases
 * LaraDogs itself supports for its own persistence (see
 * docs/architecture/decisions/ADR-0007-database-agnostic-persistence.md) —
 * a LaraDogs instance running on any of its own supported databases must
 * be able to report any of these for a project it inspects.
 */
enum DatabaseDriver: string
{
    case Sqlite = 'sqlite';
    case Mysql = 'mysql';
    case MariaDb = 'mariadb';
    case PostgreSql = 'pgsql';
    case SqlServer = 'sqlsrv';
}
