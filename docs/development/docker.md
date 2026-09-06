# Docker (Personal Profile)

Phase 0 ships a single-container Docker setup for local/self-hosted use,
running SQLite. The single-container shape is the "Personal" profile
described in
[ADR-0005](../architecture/decisions/ADR-0005-persistence-and-deployment-profiles.md).
SQLite is this image's current **implementation** choice, not an
architectural one — LaraDogs officially supports MySQL, MariaDB, and
PostgreSQL too; see
[ADR-0007](../architecture/decisions/ADR-0007-database-agnostic-persistence.md)
and [Database support](#database-support) below. There is no "Server"
profile (Redis, workers, scheduler, reverse proxy) yet — see the roadmap.

## Start

```bash
cp .env.example .env
php artisan key:generate --show   # copy the output into APP_KEY in .env
docker compose up --build
```

Visit `http://localhost:8000` (override the host port with `APP_PORT` in
`.env` if 8000 is taken).

Configuration — including `APP_KEY` — is supplied entirely via
`docker-compose.yml`'s `env_file: .env`, i.e. real process environment
variables inside the container. There is no `.env` file inside the image
or container itself, so `APP_KEY` must already be set in the host's `.env`
before starting; the entrypoint fails fast with a clear error if it isn't
(a blank key would otherwise silently break session/cookie encryption).

The container's entrypoint (`docker/entrypoint.sh`) is idempotent: on every
start it creates `database/database.sqlite` if missing and runs `php
artisan migrate --force`. It's safe to run repeatedly.

## Database support

This image currently installs only the `pdo_sqlite`/`sqlite3` PHP
extensions in the `runtime` stage, so it only runs SQLite out of the box.
This is a **current implementation limitation of this Docker image**, not
an architectural restriction — LaraDogs itself supports MySQL, MariaDB,
and PostgreSQL equally (ADR-0007). Adding the `pdo_mysql`/`pdo_pgsql`
extensions to the runtime stage is straightforward future work, not done
in this pass to avoid unrelated Docker changes.

To use MySQL/MariaDB/PostgreSQL today, run LaraDogs outside this image
(see [`setup.md`](setup.md)) with `DB_CONNECTION`/`DB_HOST`/`DB_DATABASE`/
etc. pointed at an external database instance that has the matching PHP
PDO extension available.

## Stop

```bash
docker compose down
```

Data persists in two named Docker volumes (`laradogs-database`,
`laradogs-storage`) across `up`/`down` cycles. To fully reset local data:

```bash
docker compose down -v
```

## What the image does

Two-stage build (`Dockerfile`):

1. **`builder`** — `php:8.3-cli-bookworm` + Node 22, installs Composer and
   npm dependencies, builds the Vite bundle (this is also where Wayfinder
   generates typed route helpers, which requires the full application code
   to introspect routes/controllers), then dumps an optimized
   classmap-authoritative autoloader. Discarded after build.
2. **`runtime`** — a fresh `php:8.3-cli-bookworm` with only `pdo_sqlite`,
   `sqlite3`, and `curl` (for the healthcheck) added. Runs as a non-root
   user (`laradogs`, uid 1000), not root. No Node, no dev dependencies,
   no build toolchain ship in this layer.

A `HEALTHCHECK` hits the app's built-in `/up` route (registered via
Laravel's `health:` routing option in `bootstrap/app.php`, not custom
code).

## Why not Nginx + PHP-FPM (yet)

Phase 0 explicitly avoids over-building Docker before there's an audit
domain to justify it — see ADR-0005. `php artisan serve` is adequate for
local/self-hosted single-user use. A production-grade Server profile
(Nginx/FPM split, queue workers, scheduler, PostgreSQL, Redis) is future
work, tracked in [`../roadmap/roadmap.md`](../roadmap/roadmap.md).

## Known limitations

- The build stage temporarily writes a throwaway `.env` (copied from
  `.env.example`) purely so `php artisan key:generate` and Wayfinder's
  route introspection can run during `npm run build`; it's removed before
  the runtime stage copies anything, and no `.env` file exists inside the
  final image. Real configuration is supplied at runtime via
  `docker-compose.yml`'s `env_file: .env`, which the user is expected to
  have created with their own values (including a real `APP_KEY` — see
  above).
- `docker compose build && docker compose up` has been verified
  end-to-end: image builds, container reaches `healthy`, and `GET /up`
  returns `200` on a fresh volume. Two bugs found during that verification
  were fixed:
    1. The `runtime` stage installed `libsqlite3-0` (runtime lib) but not
       `libsqlite3-dev`/`pkg-config`, so `docker-php-ext-install pdo_sqlite`
       failed to configure. Fixed by installing the dev headers and
       `pkg-config` for the build, then purging them
       (`apt-get purge -y --auto-remove`) so the final layer keeps only the
       runtime `.so`.
    2. `WORKDIR /app` runs as root before `USER laradogs` is set, so `/app`
       itself (not just the subdirectories explicitly `chown`'d) was
       root-owned; the entrypoint's old `cp .env.example .env` step failed
       with `Permission denied`. Fixed by `chown laradogs:laradogs /app`
       right after the `WORKDIR`, and by removing the `.env`/`APP_KEY`
       generation logic from the entrypoint entirely (see above — it could
       never have worked given `env_file`-supplied configuration; it just
       printed a confusing error while the app still ran, because
       `APP_KEY` was in fact already present as a real environment
       variable).
