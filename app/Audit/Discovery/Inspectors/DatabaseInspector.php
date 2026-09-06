<?php

namespace App\Audit\Discovery\Inspectors;

use App\Audit\Discovery\Filesystem\ProjectFilesystem;
use App\Audit\Discovery\Profile\DatabaseDriver;
use App\Audit\Discovery\Profile\DatabaseProfile;
use App\Audit\Discovery\Support\Detection;
use Closure;

/**
 * Detects which database driver a project actually appears configured to
 * use — deliberately NOT by inspecting which connections Laravel's stock
 * config/database.php *supports*. Every Laravel application ships
 * connection blocks for sqlite/mysql/mariadb/pgsql/sqlsrv regardless of
 * which one the project actually uses, so scanning that file's connection
 * keys would mark every Laravel project as using all five drivers — the
 * exact "framework supports X" vs. "project uses X" confusion Project
 * Discovery must not make (see docs/auditing/project-discovery.md).
 *
 * The only evidence trusted here is an explicit, uncommented
 * `DB_CONNECTION=` line in .env.example — the project's own declared
 * default connection. `.env.example` is read as plain text; the project's
 * real `.env` is never read.
 */
final class DatabaseInspector
{
    public function inspect(ProjectFilesystem $fs): DatabaseProfile
    {
        $contents = $fs->readFile('.env.example');

        if ($contents === null) {
            return new DatabaseProfile($this->allDrivers(
                fn (DatabaseDriver $driver) => Detection::unknown('no .env.example present'),
            ));
        }

        $declared = $this->declaredConnection($contents);

        if ($declared === null) {
            return new DatabaseProfile($this->allDrivers(
                fn (DatabaseDriver $driver) => Detection::unknown('.env.example present but has no DB_CONNECTION line'),
            ));
        }

        return new DatabaseProfile($this->allDrivers(
            fn (DatabaseDriver $driver) => $driver->value === $declared
                ? Detection::detected('.env.example: DB_CONNECTION='.$declared)
                : Detection::notDetected(),
        ));
    }

    /**
     * @return array<string,Detection>
     */
    private function allDrivers(Closure $resolve): array
    {
        $drivers = [];

        foreach (DatabaseDriver::cases() as $driver) {
            $drivers[$driver->value] = $resolve($driver);
        }

        return $drivers;
    }

    private function declaredConnection(string $envExample): ?string
    {
        if (preg_match('/^\s*DB_CONNECTION\s*=\s*"?([a-zA-Z0-9_]+)"?/m', $envExample, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
