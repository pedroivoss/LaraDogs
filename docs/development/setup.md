# Local Setup (Without Docker)

## Requirements

Verified working with, during Phase 0 bootstrap:

- PHP **8.3+** (Laravel 13 requires 8.3–8.5; developed against 8.3.12)
- Composer 2.x
- Node.js **20+** (developed against 22.22.2) and npm
- A database: SQLite (`pdo_sqlite`, `sqlite3` — bundled with most PHP
  installs) is the Quick Start default below, but MySQL, MariaDB, and
  PostgreSQL are equally supported — set `DB_CONNECTION` (and
  `DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`) in `.env` to the
  vendor of your choice, with the matching PHP PDO extension
  (`pdo_mysql`/`pdo_pgsql`) installed. See
  [ADR-0007](../architecture/decisions/ADR-0007-database-agnostic-persistence.md).

## Steps

```bash
git clone <this-repo>
cd LaraDogs

composer install
cp .env.example .env
php artisan key:generate

npm install
npm run build   # or `npm run dev` for a watching Vite dev server

php artisan migrate
php artisan serve
```

Visit `http://localhost:8000`. The `database/database.sqlite` file is
created automatically by the installer/migration step; it's gitignored.

## One-shot convenience script

`composer.json` already defines a `setup` script equivalent to the steps
above:

```bash
composer run setup
```

## Day-to-day development

```bash
composer run dev
```

This runs `php artisan dev`, which (via `laravel/pao`) starts the PHP
dev server, queue listener, log watcher (Pail), and Vite dev server
together. Stop with Ctrl+C.

## Pre-commit / pre-PR checks

```bash
composer run test    # config:clear + Pint (check mode) + PHPStan/Larastan + Pest
npm run check         # ESLint/Prettier via vite-plus
npm run types:check   # tsc --noEmit
```

See [`testing.md`](testing.md) and [`conventions.md`](conventions.md) for
what each of these actually does.
